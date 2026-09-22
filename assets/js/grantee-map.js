/* global L, GranteeMapConfig */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', initGranteeMaps );

	function initGranteeMaps() {
		fetch( GranteeMapConfig.styleUrl )
			.then( ( r ) => r.json() )
			.then( ( style ) => {
				applyStyleVars( style );
				document.querySelectorAll( '.grantee-map-wrap' ).forEach( ( wrap ) => initSingleMap( wrap, style ) );
			} )
			.catch( ( err ) => {
				console.warn( 'Grantee map: could not load style JSON, using defaults.', err );
				const fallback = defaultStyle();
				applyStyleVars( fallback );
				document.querySelectorAll( '.grantee-map-wrap' ).forEach( ( wrap ) => initSingleMap( wrap, fallback ) );
			} );
	}

	function applyStyleVars( style ) {
		const root = document.documentElement;
		root.style.setProperty( '--gm-accent',        style.popup?.accentColor  || '#1a1a1a' );
		root.style.setProperty( '--gm-link',          style.popup?.linkColor    || '#1a1a1a' );
		root.style.setProperty( '--gm-marker-border', style.marker?.borderColor || '#ffffff' );
	}

	// Builds a hard-stop `linear-gradient(to right, ...)` — vertical color bands
	// on a circular div (border-radius clips it) rather than an SVG pie/donut.
	// `bands` is [{ color, weight }]; width is proportional to weight, so equal
	// weights give equal-width bands. `gapPct` (0 = none) inserts a thin seam of
	// `gapColor` between adjacent bands so they read as distinct segments
	// without drawing a border between them (see dataviz mark-spec notes: gaps,
	// not strokes, separate touching marks).
	function buildBandGradient( bands, gapPct = 0, gapColor = '#ffffff' ) {
		const n = bands.length;
		if ( n === 0 ) return gapColor;
		if ( n === 1 ) return bands[ 0 ].color;

		const totalWeight = bands.reduce( ( sum, b ) => sum + b.weight, 0 );
		const totalGap    = gapPct * ( n - 1 );
		const usablePct   = 100 - totalGap;

		const stops = [];
		let pos = 0;
		bands.forEach( ( band, i ) => {
			const width = ( band.weight / totalWeight ) * usablePct;
			const start = pos;
			const end   = pos + width;
			stops.push( `${ band.color } ${ start.toFixed( 2 ) }%`, `${ band.color } ${ end.toFixed( 2 ) }%` );
			pos = end;
			if ( i < n - 1 && gapPct > 0 ) {
				const gapEnd = pos + gapPct;
				stops.push( `${ gapColor } ${ pos.toFixed( 2 ) }%`, `${ gapColor } ${ gapEnd.toFixed( 2 ) }%` );
				pos = gapEnd;
			}
		} );

		return `linear-gradient(to right, ${ stops.join( ', ' ) })`;
	}

	function defaultStyle() {
		return {
			tiles: {
				url:         'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
				attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
				maxZoom:     18,
				retina:      false,
			},
			marker: { color: '#1a1a1a', borderColor: '#ffffff', size: 11 },
			popup:  { accentColor: '#1a1a1a', linkColor: '#1a1a1a' },
		};
	}

	// ── Single map init ───────────────────────────────────────────
	function initSingleMap( wrap, style ) {
		const mapEl           = wrap.querySelector( '.grantee-map-canvas' );
		const typeDropdownEl  = wrap.querySelector( '.gm-dropdown-content' );
		const typeDropbtnEl   = wrap.querySelector( '.gm-dropbtn' );
		const typeDropbtnLabel = typeDropbtnEl?.querySelector( '.gm-dropbtn-label' );
		const countEl         = wrap.querySelector( '.grantee-count' );
		const loadingEl       = wrap.querySelector( '.grantee-map-loading' );
		const resetBtn        = wrap.querySelector( '.grantee-filter-reset' );
		const timelineWrap    = wrap.querySelector( '.grantee-timeline' );
		const timelineSlider  = wrap.querySelector( '.grantee-timeline-slider' );
		const timelineYearEl  = wrap.querySelector( '.grantee-timeline-year' );
		const timelineBubble  = wrap.querySelector( '.grantee-timeline-bubble' );
		const timelinePlayBtn = wrap.querySelector( '.grantee-timeline-play' );

		if ( ! mapEl ) return;

		const centerLat     = parseFloat( wrap.dataset.centerLat )     || 39.5;
		const centerLng     = parseFloat( wrap.dataset.centerLng )     || -98.35;
		const isMobile      = window.innerWidth < 768;
		const zoom          = parseFloat( isMobile ? ( wrap.dataset.mobileZoom || wrap.dataset.zoom ) : wrap.dataset.zoom ) || 4;
		const clusterRadius = parseInt(   wrap.dataset.clusterRadius ) ?? 30;

		// ── Leaflet map ───────────────────────────────────────────
		const map = L.map( mapEl, {
			center: [ centerLat, centerLng ],
			zoom,
			zoomSnap:        0.25,
			scrollWheelZoom: false,
			dragging:        ! isMobile,
			tap:             ! isMobile,
		} );

		// On mobile: require two fingers to pan; show a hint on single-finger touch
		if ( isMobile ) {
			let hintTimer = null;
			const hintEl  = document.createElement( 'div' );
			hintEl.className = 'gm-scroll-hint';
			hintEl.textContent = 'Use two fingers to move the map';
			mapEl.appendChild( hintEl );

			mapEl.addEventListener( 'touchstart', ( e ) => {
				if ( e.touches.length >= 2 ) {
					map.dragging.enable();
					hintEl.classList.remove( 'is-visible' );
					clearTimeout( hintTimer );
				} else {
					map.dragging.disable();
					hintEl.classList.add( 'is-visible' );
					clearTimeout( hintTimer );
					hintTimer = setTimeout( () => hintEl.classList.remove( 'is-visible' ), 1500 );
				}
			}, { passive: true } );

			mapEl.addEventListener( 'touchend', () => {
				map.dragging.disable();
			}, { passive: true } );
		}

		const tiles   = style.tiles || {};
		const cartoKey = GranteeMapConfig.cartoKey;
		const tileUrl  = ( cartoKey && tiles.url )
			? tiles.url + '?key=' + cartoKey
			: ( tiles.url || '' );
		L.tileLayer( tileUrl, {
			attribution:  tiles.attribution || '',
			maxZoom:      tiles.maxZoom     || 18,
			detectRetina: !! tiles.retina,
		} ).addTo( map );

		// ── Marker tooltip vs. popup coordination ──────────────────
		// Listening on the map (not on each marker) because a marker's own
		// popupclose event doesn't reliably fire when the popup is closed via
		// the map's "click elsewhere closes it" behavior — only when the same
		// marker's own click toggles it shut. Map-level popupopen/popupclose
		// fire consistently regardless of how the popup was closed.
		//
		// closeTooltip(), not unbindTooltip() — unbindTooltip only removes the
		// binding for *future* opens, it doesn't hide one that's already open
		// (confirmed directly against the Leaflet API, not assumed). Once
		// closed this way it stays closed on its own: tooltip visibility is
		// driven by discrete mouseover/mouseout DOM events, not a continuous
		// "is the mouse still over this element" check, so it won't reappear
		// just because the cursor never left — only a fresh mouseover will
		// reopen it, which is what we want once a popup has closed too.
		map.on( 'popupopen', ( e ) => {
			const src = e.popup._source;
			if ( ! src ) return;
			if ( typeof src.closeTooltip === 'function' ) src.closeTooltip();
			const el = src.getElement?.();
			if ( el ) el.classList.add( 'gm-marker-selected' );
		} );
		map.on( 'popupclose', ( e ) => {
			const src = e.popup._source;
			if ( ! src ) return;
			const el = src.getElement?.();
			if ( el ) {
				el.classList.remove( 'gm-marker-selected' );
				// Returning focus here is for keyboard users (so tabbing/Escape
				// flows naturally after closing a popup) — but focusing a marker
				// also triggers Leaflet's own focus-based tooltip display (the
				// same mechanism that shows tooltips to keyboard users tabbing
				// onto a marker), which pops the tooltip back up for mouse users
				// too. Close it again immediately after.
				el.focus();
				if ( typeof src.closeTooltip === 'function' ) src.closeTooltip();
			}
		} );

		// ── Marker cluster ────────────────────────────────────────
		const markers = L.markerClusterGroup( {
			showCoverageOnHover: false,
			maxClusterRadius:    clusterRadius,
			iconCreateFunction( cluster ) {
				const childMarkers = cluster.getAllChildMarkers();
				const count        = childMarkers.length;

				// Tally org-type composition across every marker in the cluster,
				// keyed by type name (not color) so it can be ordered the same
				// alphabetical way as each org's own bands and the filter chips.
				const nameCounts = {};
				childMarkers.forEach( ( m ) => {
					const types = ( m.orgTypes && m.orgTypes.length ) ? m.orgTypes : [ { name: '', color: defaultMarkerColor } ];
					types.forEach( ( t ) => {
						if ( ! nameCounts[ t.name ] ) nameCounts[ t.name ] = { color: t.color || defaultMarkerColor, count: 0 };
						nameCounts[ t.name ].count++;
					} );
				} );

				// Bigger clusters get a bigger bubble, capped so it never dwarfs
				// the map.
				const size = Math.round( Math.min( 30 + Math.sqrt( count ) * 5, 60 ) );

				// Bands are proportional to how much of the cluster each type is,
				// pre-mixed toward white (not layered with CSS opacity) so the
				// count text drawn over them stays legible.
				const bands = Object.keys( nameCounts )
					.sort( ( a, b ) => a.localeCompare( b ) )
					.map( ( name ) => ( { color: mixWithWhite( nameCounts[ name ].color, 0.3 ), weight: nameCounts[ name ].count } ) );
				const background = buildBandGradient( bands );

				const html = `<div class="gm-cluster-wrap" role="img" aria-label="${count} organizations in this area" style="background:${background}">${count}</div>`;

				return L.divIcon( {
					html,
					className: '',
					iconSize:  L.point( size, size ),
				} );
			},
		} );
		map.addLayer( markers );

		// ── Per-org gradient icon ─────────────────────────────────
		const defaultMarkerColor = style.marker?.color       || '#1a1a1a';
		const markerBorder       = style.marker?.borderColor || '#ffffff';
		const markerR            = style.marker?.size        || 11;

		function buildMarkerGradient( colors ) {
			if ( colors.length <= 1 ) return colors[ 0 ] || defaultMarkerColor;
			if ( colors.length === 2 ) return `linear-gradient(to right, ${ colors[ 0 ] } 0%, ${ colors[ 1 ] } 100%)`;
			return `linear-gradient(to right, ${ colors[ 0 ] } 0%, ${ colors[ 1 ] } 50%, ${ colors[ 2 ] } 100%)`;
		}

		function makeIcon( colors, delayMs = 0 ) {
			const r    = markerR;
			const size = r * 2 + 4;
			const cx   = r + 2;
			const cy   = r + 2;

			const effectiveColors = colors.length > 0 ? colors : [ defaultMarkerColor ];
			const background      = buildMarkerGradient( effectiveColors );

			// The pop-in animation and the hover/selected scale both live on this
			// inner wrapper, not the outer icon element — Leaflet positions
			// markers via an inline `transform: translate3d(...)` on the icon's
			// own div, and any CSS also driving `transform` on that same element
			// would hijack it for the duration, making the marker briefly render
			// at (0,0) before snapping to its real position. The circular shape,
			// its border, and the band colors all live here too (background is
			// the only per-marker inline style; border color comes from the
			// --gm-marker-border CSS var set once for the whole map).
			//
			// delayMs === null means "no pop-in" — used once a marker's one-time
			// reveal has already played, so a later icon swap (e.g. after Leaflet
			// recreates this marker's DOM element when a cluster unfolds) doesn't
			// replay the pop from scratch.
			const innerClass = delayMs === null ? 'gm-marker-inner' : 'gm-marker-inner gm-marker-pop';
			const delayStyle  = delayMs === null ? '' : `animation-delay:${ delayMs }ms;`;
			const html        = `<div class="${ innerClass }" style="background:${ background };${ delayStyle }"></div>`;

			return L.divIcon( {
				html,
				className:   '',
				iconSize:    [ size, size ],
				iconAnchor:  [ cx,   cy   ],
				popupAnchor: [ 0,    -( r + 4 ) ],
			} );
		}

		// ── Data ──────────────────────────────────────────────────
		let allOrgs        = [];
		let minYear        = null;
		let maxYear        = null;
		let playTimer      = null;
		let pendingTimers  = []; // staggered insertion timers, cancelled on re-render
		const activeTypes  = new Set();
		const markerIndex  = new Map(); // org id -> L.Marker currently on the map

		setLoading( true );

		fetch( `${ GranteeMapConfig.restUrl }/map`, { headers: { 'X-WP-Nonce': GranteeMapConfig.nonce } } )
			.then( ( r ) => r.json() )
			.then( ( orgs ) => {
				allOrgs = orgs;
				buildTypeDropdown();
				buildTimeline();
				setLoading( false );

				// Markers and timeline only appear when the map scrolls into view
				const filtersEl = wrap.querySelector( '.grantee-map-filters' );
				const introTarget = filtersEl || wrap;
				let introFired = false;

				const observer = new IntersectionObserver( ( entries ) => {
					if ( introFired || ! entries[ 0 ].isIntersecting ) return;
					introFired = true;
					observer.disconnect();
					applyFilters();
					startPlayback();
				}, { threshold: 0.1 } );

				observer.observe( introTarget );
			} )
			.catch( ( err ) => {
				console.error( 'Grantee map: failed to load data', err );
				setLoading( false );
			} );

		// ── Build org type filter dropdown from data (also serves as legend) ─
		function buildTypeDropdown() {
			if ( ! typeDropdownEl ) return;

			const counts = {};
			allOrgs.forEach( ( g ) => {
				( g.org_types || [] ).forEach( ( t ) => {
					if ( ! counts[ t.slug ] ) counts[ t.slug ] = { name: t.name, count: 0, color: t.color };
					counts[ t.slug ].count++;
				} );
			} );

			Object.entries( counts )
				.sort( ( a, b ) => a[ 1 ].name.localeCompare( b[ 1 ].name ) )
				.forEach( ( [ slug, { name, count, color } ] ) => {
					const itemColor = color || defaultMarkerColor;
					const btn       = document.createElement( 'button' );
					btn.type        = 'button';
					btn.className   = 'gm-type-btn';
					btn.dataset.slug = slug;
					btn.setAttribute( 'aria-pressed', 'false' );
					btn.style.setProperty( '--gm-item-color', itemColor );
					btn.innerHTML   = `<span class="gm-type-swatch" aria-hidden="true"></span>${ escHtml( name ) } <span class="gm-type-count">(${ count })</span>`;
					btn.addEventListener( 'click', () => {
						if ( activeTypes.has( slug ) ) {
							activeTypes.delete( slug );
							btn.setAttribute( 'aria-pressed', 'false' );
						} else {
							activeTypes.add( slug );
							btn.setAttribute( 'aria-pressed', 'true' );
						}
						updateDropbtnLabel();
						applyFilters();
					} );
					typeDropdownEl.appendChild( btn );
				} );

			// Open / close
			if ( typeDropbtnEl ) {
				typeDropbtnEl.addEventListener( 'click', ( e ) => {
					e.stopPropagation();
					const open = typeDropbtnEl.getAttribute( 'aria-expanded' ) === 'true';
					typeDropbtnEl.setAttribute( 'aria-expanded', String( ! open ) );
				} );
				document.addEventListener( 'click', ( e ) => {
					if ( ! typeDropbtnEl.closest( '.gm-dropdown' ).contains( e.target ) ) {
						typeDropbtnEl.setAttribute( 'aria-expanded', 'false' );
					}
				} );
				document.addEventListener( 'keydown', ( e ) => {
					if ( e.key === 'Escape' ) typeDropbtnEl.setAttribute( 'aria-expanded', 'false' );
				} );
			}
		}

		function updateDropbtnLabel() {
			if ( !typeDropbtnLabel ) return;
			const label = GranteeMapConfig.organizationTypeLabel;

			typeDropbtnLabel.textContent =
				activeTypes.size > 0
					? `${label} (${activeTypes.size})`
					: 'Organization Type';
		}

		function contrastColor( hex ) {
			const c = String( hex || '' ).replace( '#', '' );
			if ( c.length !== 6 ) return '#fff';
			const r = parseInt( c.substr( 0, 2 ), 16 );
			const g = parseInt( c.substr( 2, 2 ), 16 );
			const b = parseInt( c.substr( 4, 2 ), 16 );
			const luminance = ( 0.299 * r + 0.587 * g + 0.114 * b ) / 255;
			return luminance > 0.6 ? '#111' : '#fff';
		}

		// Blends a hex color toward white by `ratio` (0 = white, 1 = full color),
		// returning an opaque rgb() rather than a translucent color, so cluster
		// bubble backgrounds stay faded without also fading the count text.
		function mixWithWhite( hex, ratio ) {
			const c = String( hex || '' ).replace( '#', '' );
			if ( c.length !== 6 ) return 'rgb(255,255,255)';
			const r = parseInt( c.substr( 0, 2 ), 16 );
			const g = parseInt( c.substr( 2, 2 ), 16 );
			const b = parseInt( c.substr( 4, 2 ), 16 );
			const mix = ( ch ) => Math.round( ch * ratio + 255 * ( 1 - ratio ) );
			return `rgb(${ mix( r ) },${ mix( g ) },${ mix( b ) })`;
		}

		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', () => {
				activeTypes.clear();
				typeDropdownEl?.querySelectorAll( '.gm-type-btn' ).forEach( ( btn ) => btn.setAttribute( 'aria-pressed', 'false' ) );
				typeDropbtnEl?.setAttribute( 'aria-expanded', 'false' );
				updateDropbtnLabel();
				stopPlayback();
				if ( timelineSlider && maxYear !== null ) {
					timelineSlider.value = maxYear;
					updateTimelineDisplay( maxYear );
				}
				applyFilters();
			} );
		}

		// ── Timeline: year awarded is read from each org's `years` array ─
		function getOrgYears( g ) {
			// parseInt tolerates grant-cycle labels like "2023-2024"
			return ( g.years || [] )
				.map( ( y ) => parseInt( y, 10 ) )
				.filter( ( y ) => ! isNaN( y ) );
		}

		function buildTimeline() {
			if ( ! timelineSlider || ! timelineWrap ) return;

			const years = allOrgs.flatMap( getOrgYears );
			if ( years.length === 0 ) return;

			minYear = Math.min( ...years );
			maxYear = Math.max( ...years );
			if ( minYear === maxYear ) return;

			timelineSlider.min   = minYear;
			timelineSlider.max   = maxYear;
			timelineSlider.value = maxYear;
			timelineWrap.hidden  = false;
			updateTimelineDisplay( maxYear );

			timelineSlider.addEventListener( 'input', () => {
				stopPlayback();
				updateTimelineDisplay( timelineSlider.value );
				applyFilters( { fit: false } );
			} );

			if ( timelinePlayBtn ) {
				timelinePlayBtn.addEventListener( 'click', () => {
					playTimer ? stopPlayback() : startPlayback();
				} );
			}
		}

		function updateTimelineDisplay( year ) {
			year = parseInt( year, 10 );
			if ( timelineYearEl ) timelineYearEl.textContent = year === maxYear ? `${ year } (all)` : String( year );
			if ( timelineSlider ) {
				timelineSlider.setAttribute( 'aria-valuetext', `Year ${ year }` );
				const pct = ( ( year - minYear ) / ( maxYear - minYear ) ) * 100;
				timelineSlider.style.background = `linear-gradient(to right, var(--gm-accent) ${ pct }%, #e5e7eb ${ pct }%)`;
				if ( timelineBubble ) {
					// Offset accounts for thumb width (16px) so bubble tracks the thumb center
					timelineBubble.textContent = String( year );
					timelineBubble.style.left = `calc(${ pct }% + ${ 8 - pct * 0.16 }px)`;
				}
			}
		}

		function startPlayback() {
			if ( ! timelineSlider || minYear === null ) return;
			timelineSlider.value = minYear;
			updateTimelineDisplay( minYear );
			applyFilters( { fit: false } );

			if ( timelinePlayBtn ) {
				timelinePlayBtn.classList.add( 'is-playing' );
				timelinePlayBtn.setAttribute( 'aria-label', 'Pause timeline' );
			}

			playTimer = setInterval( () => {
				const next = parseInt( timelineSlider.value, 10 ) + 1;
				if ( next > maxYear ) {
					stopPlayback();
					return;
				}
				timelineSlider.value = next;
				updateTimelineDisplay( next );
				applyFilters( { fit: false } );
			}, 900 );
		}

		function stopPlayback() {
			if ( playTimer ) {
				clearInterval( playTimer );
				playTimer = null;
			}
			if ( timelinePlayBtn ) {
				timelinePlayBtn.classList.remove( 'is-playing' );
				timelinePlayBtn.setAttribute( 'aria-label', 'Play timeline' );
			}
		}

		// ── Apply filter, update markers and count ────────────────
		function applyFilters( { fit = true } = {} ) {
			const yearCutoff = timelineSlider && timelineWrap && ! timelineWrap.hidden ? parseInt( timelineSlider.value, 10 ) : null;

			const filtered = allOrgs.filter( ( g ) => {
				if ( activeTypes.size > 0 && ! ( g.org_types || [] ).some( ( t ) => activeTypes.has( t.slug ) ) ) return false;
				if ( yearCutoff !== null ) {
					const orgYears = getOrgYears( g );
					if ( orgYears.length > 0 && Math.min( ...orgYears ) > yearCutoff ) return false;
				}
				return true;
			} );

			const isDefaultView = activeTypes.size === 0 && ( yearCutoff === null || yearCutoff === maxYear );

			renderMarkers( filtered, { fit, isDefaultView } );
			if ( countEl ) countEl.textContent = filtered.length;
			if ( resetBtn ) resetBtn.hidden = activeTypes.size === 0 && yearCutoff === maxYear;
		}

		// ── Render markers (diffed so unchanged orgs don't re-animate) ─
		function renderMarkers( orgs, { fit = true, isDefaultView = false } = {} ) {
			// Cancel any in-flight staggered insertions from a previous render
			pendingTimers.forEach( clearTimeout );
			pendingTimers = [];

			const isInitialLoad = markerIndex.size === 0;

			const nextIds = new Set();
			orgs.forEach( ( g ) => {
				if ( g.lat && g.lng ) nextIds.add( g.id );
			} );

			// Sort newly-appearing orgs west-to-east for a geographic sweep.
			const newOrgs = orgs
				.filter( ( g ) => g.lat && g.lng && ! markerIndex.has( g.id ) )
				.sort( ( a, b ) => a.lng - b.lng );

			// On initial load, actually delay each marker's insertion into the
			// layer so they appear one by one during the intro flyTo. On
			// subsequent renders (filter/timeline changes) add instantly.
			const stepMs = isInitialLoad ? 15  : 0;
			const capMs  = isInitialLoad ? 2000 : 0;

			newOrgs.forEach( ( g, i ) => {
				const delay        = Math.min( i * stepMs, capMs );
				const orderedTypes = ( g.org_types || [] ).slice().sort( ( a, b ) => a.name.localeCompare( b.name ) );
				const colors       = orderedTypes.map( ( t ) => t.color ).filter( Boolean );

				const t = setTimeout( () => {
					const icon   = makeIcon( colors, 0 );
					const marker = L.marker( [ g.lat, g.lng ], { icon, alt: g.title } );
					marker.orgTypes = orderedTypes;
					marker.bindPopup( buildPopup( g ), { maxWidth: 340, className: 'grantee-popup' } );
					marker.bindTooltip( escHtml( g.title ), { direction: 'top', offset: [ 0, -( markerR + 6 ) ], className: 'grantee-tooltip' } );
					markers.addLayer( marker );
					markerIndex.set( g.id, marker );
					setTimeout( () => marker.setIcon( makeIcon( colors, null ) ), 320 );
				}, delay );

				pendingTimers.push( t );
			} );

			markerIndex.forEach( ( marker, id ) => {
				if ( ! nextIds.has( id ) ) {
					markers.removeLayer( marker );
					markerIndex.delete( id );
				}
			} );

			if ( ! fit || nextIds.size === 0 ) return;

			if ( isDefaultView ) {
				// Respect the shortcode's configured center/zoom instead of fitting
				// to marker bounds — a few outlying orgs (e.g. Caribbean) shouldn't
				// zoom the whole map out over open ocean.
				map.setView( [ centerLat, centerLng ], zoom );
			} else if ( nextIds.size === 1 ) {
				const onlyId  = nextIds.values().next().value;
				const onlyOrg = orgs.find( ( g ) => g.id === onlyId );
				const marker  = markerIndex.get( onlyId );
				map.flyTo( [ onlyOrg.lat, onlyOrg.lng ], Math.max( map.getZoom(), 12 ), { duration: 0.8 } );
				if ( marker ) setTimeout( () => marker.openPopup(), 500 );
			} else {
				map.fitBounds( markers.getBounds(), { padding: [ 40, 40 ], maxZoom: 12 } );
			}
		}

		// ── Popup HTML ────────────────────────────────────────────
		function buildPopup( g ) {
			const accent = style.popup?.accentColor || '#1a1a1a';

			let html = '<div class="grantee-popup-inner">';

			if ( g.image ) {
				html += `<div class="grantee-popup-img"><img src="${ escHtml( g.image ) }" alt="${ escHtml( g.title ) }"></div>`;
			}

			html += `<div class="grantee-popup-body">`;
			html += `<h3 class="grantee-popup-title">${ escHtml( g.title ) }</h3>`;

			if ( g.disciplines && g.disciplines.length > 0 ) {
				const terms = g.disciplines.map( ( t ) => escHtml( t.name ) ).join( ', ' );
				html += `<p class="grantee-popup-terms">${ terms }</p>`;
			}

			if ( g.website_url && /^https?:\/\//i.test( g.website_url ) ) {
				const label = g.website_name || g.website_url;
				html += `<a class="grantee-popup-link" style="color:${ escHtml( accent ) };border-color:${ escHtml( accent ) }33;" href="${ escHtml( g.website_url ) }" target="_blank" rel="noopener">${ escHtml( label ) } &#8599;</a>`;
			}

			if ( Array.isArray( g.awards ) && g.awards.length > 0 ) {
				html += `<div class="grantee-popup-awards">`;
				html += `<h4 class="grantee-popup-awards-heading">Awards</h4>`;
				html += `<ul class="grantee-popup-awards-list">`;
				g.awards.forEach( ( award ) => {
					html += `<li class="grantee-award-item">
						<a class="grantee-award-title" href="${ escHtml( award.permalink ) }" target="_blank" rel="noopener">${ escHtml( award.grant_types || award.title ) }</a>
						<span class="grantee-award-year">${ award.year ? escHtml( String( award.year ) ) : '' }</span>
						<span class="grantee-award-amount">${ award.amount ? escHtml( award.amount ) : '' }</span>
					</li>`;
				} );
				html += `</ul></div>`;
			}

			html += '</div></div>';
			return html;
		}

		// ── Helpers ───────────────────────────────────────────────
		function setLoading( state ) {
			if ( loadingEl ) loadingEl.style.display = state ? 'flex' : 'none';
		}

		function escHtml( str ) {
			if ( ! str ) return '';
			return String( str )
				.replace( /&/g,  '&amp;'  )
				.replace( /</g,  '&lt;'   )
				.replace( />/g,  '&gt;'   )
				.replace( /"/g,  '&quot;' );
		}
	}
} )();
