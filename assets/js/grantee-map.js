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
		root.style.setProperty( '--gm-accent',     style.popup?.accentColor  || '#1a1a1a' );
		root.style.setProperty( '--gm-link',        style.popup?.linkColor    || '#1a1a1a' );
		root.style.setProperty( '--gm-cluster-bg',  style.cluster?.background || '#1a1a1a' );
		root.style.setProperty( '--gm-cluster-fg',  style.cluster?.color      || '#ffffff' );
	}

	function defaultStyle() {
		return {
			tiles: {
				url:         'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
				attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
				maxZoom:     18,
				retina:      false,
			},
			marker:  { color: '#1a1a1a', borderColor: '#ffffff', size: 11 },
			cluster: { background: '#1a1a1a', color: '#ffffff', opacity: 1 },
			popup:   { accentColor: '#1a1a1a', linkColor: '#1a1a1a' },
		};
	}

	// ── Single map init ───────────────────────────────────────────
	function initSingleMap( wrap, style ) {
		const mapEl           = wrap.querySelector( '.grantee-map-canvas' );
		const typeChipsEl     = wrap.querySelector( '.grantee-type-chips' );
		const searchInput     = wrap.querySelector( '.grantee-search' );
		const countEl         = wrap.querySelector( '.grantee-count' );
		const loadingEl       = wrap.querySelector( '.grantee-map-loading' );
		const resetBtn        = wrap.querySelector( '.grantee-filter-reset' );
		const timelineWrap    = wrap.querySelector( '.grantee-timeline' );
		const timelineSlider  = wrap.querySelector( '.grantee-timeline-slider' );
		const timelineYearEl  = wrap.querySelector( '.grantee-timeline-year' );
		const timelinePlayBtn = wrap.querySelector( '.grantee-timeline-play' );

		if ( ! mapEl ) return;

		const centerLat = parseFloat( wrap.dataset.centerLat ) || 39.5;
		const centerLng = parseFloat( wrap.dataset.centerLng ) || -98.35;
		const zoom      = parseInt( wrap.dataset.zoom, 10 )    || 4;

		// ── Leaflet map ───────────────────────────────────────────
		const map = L.map( mapEl, { center: [ centerLat, centerLng ], zoom, scrollWheelZoom: false } );

		const tiles = style.tiles || {};
		L.tileLayer( tiles.url, {
			attribution:  tiles.attribution || '',
			maxZoom:      tiles.maxZoom     || 18,
			detectRetina: !! tiles.retina,
		} ).addTo( map );

		// ── Marker cluster ────────────────────────────────────────
		const clusterBg = style.cluster?.background || '#1a1a1a';
		const clusterFg = style.cluster?.color      || '#ffffff';
		const clusterOp = style.cluster?.opacity    ?? 1;

		const markers = L.markerClusterGroup( {
			showCoverageOnHover: false,
			maxClusterRadius:    50,
			iconCreateFunction( cluster ) {
				const count = cluster.getChildCount();
				return L.divIcon( {
					html:     `<div class="gm-cluster" role="img" aria-label="${count} organizations in this area">${count}</div>`,
					className: '',
					iconSize:  L.point( 36, 36 ),
				} );
			},
		} );
		map.addLayer( markers );

		// ── Per-org pie-segment icon ──────────────────────────────
		const defaultMarkerColor = style.marker?.color       || '#1a1a1a';
		const markerBorder       = style.marker?.borderColor || '#ffffff';
		const markerR            = style.marker?.size        || 11;

		function makeIcon( colors ) {
			const r    = markerR;
			const size = r * 2 + 4;
			const cx   = r + 2;
			const cy   = r + 2;

			let svg;
			if ( colors.length <= 1 ) {
				const fill = colors[ 0 ] || defaultMarkerColor;
				svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
					<circle cx="${cx}" cy="${cy}" r="${r}" fill="${fill}" stroke="${markerBorder}" stroke-width="2"/>
				</svg>`;
			} else {
				const step   = ( 2 * Math.PI ) / colors.length;
				const offset = -Math.PI / 2;
				const slices = colors.map( ( color, i ) => {
					const a1 = offset + i * step;
					const a2 = offset + ( i + 1 ) * step;
					const x1 = ( cx + r * Math.cos( a1 ) ).toFixed( 3 );
					const y1 = ( cy + r * Math.sin( a1 ) ).toFixed( 3 );
					const x2 = ( cx + r * Math.cos( a2 ) ).toFixed( 3 );
					const y2 = ( cy + r * Math.sin( a2 ) ).toFixed( 3 );
					return `<path d="M${cx},${cy} L${x1},${y1} A${r},${r} 0 0,1 ${x2},${y2} Z" fill="${color}"/>`;
				} ).join( '' );
				svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
					${slices}
					<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${markerBorder}" stroke-width="2"/>
				</svg>`;
			}

			return L.divIcon( {
				html:        svg,
				className:   'gm-marker-pop',
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
		let searchDebounce = null;
		const activeTypes  = new Set();
		const markerIndex  = new Map(); // org id -> L.Marker currently on the map

		setLoading( true );

		fetch( `${ GranteeMapConfig.restUrl }/map`, { headers: { 'X-WP-Nonce': GranteeMapConfig.nonce } } )
			.then( ( r ) => r.json() )
			.then( ( orgs ) => {
				allOrgs = orgs;
				buildTypeChips();
				buildTimeline();
				applyFilters();
				setLoading( false );
			} )
			.catch( ( err ) => {
				console.error( 'Grantee map: failed to load data', err );
				setLoading( false );
			} );

		// ── Build org type filter chips from data (also serves as legend) ─
		function buildTypeChips() {
			if ( ! typeChipsEl ) return;

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
					const chipColor = color || defaultMarkerColor;
					const chip      = document.createElement( 'button' );
					chip.type       = 'button';
					chip.className  = 'grantee-type-chip';
					chip.dataset.slug = slug;
					chip.setAttribute( 'aria-pressed', 'false' );
					chip.style.setProperty( '--chip-color', chipColor );
					chip.style.setProperty( '--chip-text', contrastColor( chipColor ) );
					chip.innerHTML  = `<span class="grantee-type-chip-swatch"></span>${ escHtml( name ) } (${ count })`;
					chip.addEventListener( 'click', () => {
						if ( activeTypes.has( slug ) ) {
							activeTypes.delete( slug );
							chip.setAttribute( 'aria-pressed', 'false' );
						} else {
							activeTypes.add( slug );
							chip.setAttribute( 'aria-pressed', 'true' );
						}
						applyFilters();
					} );
					typeChipsEl.appendChild( chip );
				} );
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

		// ── Search input handler (debounced) ──────────────────────
		if ( searchInput ) {
			searchInput.addEventListener( 'input', () => {
				clearTimeout( searchDebounce );
				searchDebounce = setTimeout( () => applyFilters(), 250 );
			} );
		}

		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', () => {
				activeTypes.clear();
				typeChipsEl?.querySelectorAll( '.grantee-type-chip' ).forEach( ( chip ) => chip.setAttribute( 'aria-pressed', 'false' ) );
				if ( searchInput ) searchInput.value = '';
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
			const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';
			const yearCutoff = timelineSlider && timelineWrap && ! timelineWrap.hidden ? parseInt( timelineSlider.value, 10 ) : null;

			const filtered = allOrgs.filter( ( g ) => {
				if ( activeTypes.size > 0 && ! ( g.org_types || [] ).some( ( t ) => activeTypes.has( t.slug ) ) ) return false;
				if ( searchTerm && ! ( g.title || '' ).toLowerCase().includes( searchTerm ) ) return false;
				if ( yearCutoff !== null ) {
					const orgYears = getOrgYears( g );
					if ( orgYears.length > 0 && Math.min( ...orgYears ) > yearCutoff ) return false;
				}
				return true;
			} );

			renderMarkers( filtered, fit );
			if ( countEl ) countEl.textContent = filtered.length;
			if ( resetBtn ) resetBtn.hidden = activeTypes.size === 0 && ! searchTerm && yearCutoff === maxYear;
		}

		// ── Render markers (diffed so unchanged orgs don't re-animate) ─
		function renderMarkers( orgs, fit = true ) {
			const nextIds = new Set();

			orgs.forEach( ( g ) => {
				if ( ! g.lat || ! g.lng ) return;
				nextIds.add( g.id );

				if ( markerIndex.has( g.id ) ) return;

				const colors = ( g.org_types || [] ).map( ( t ) => t.color ).filter( Boolean );
				const icon   = makeIcon( colors );
				const marker = L.marker( [ g.lat, g.lng ], { icon, alt: g.title } );
				marker.bindPopup( buildPopup( g ), { maxWidth: 340, className: 'grantee-popup' } );
				marker.on( 'popupclose', () => {
					const el = marker.getElement();
					if ( el ) el.focus();
				} );
				markers.addLayer( marker );
				markerIndex.set( g.id, marker );
			} );

			markerIndex.forEach( ( marker, id ) => {
				if ( ! nextIds.has( id ) ) {
					markers.removeLayer( marker );
					markerIndex.delete( id );
				}
			} );

			if ( ! fit || nextIds.size === 0 ) return;

			if ( nextIds.size === 1 ) {
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

			const terms = ( g.org_types || [] ).map( ( t ) => t.name ).join( ', ' );

			let html = '<div class="grantee-popup-inner">';

			if ( g.image ) {
				html += `<div class="grantee-popup-img"><img src="${ escHtml( g.image ) }" alt="${ escHtml( g.title ) }"></div>`;
			}

			html += `<div class="grantee-popup-body">`;
			html += `<h3 class="grantee-popup-title">${ escHtml( g.title ) }</h3>`;

			if ( terms ) {
				html += `<p class="grantee-popup-terms">${ escHtml( terms ) }</p>`;
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
						<a class="grantee-award-title" href="${ escHtml( award.permalink ) }" target="_blank" rel="noopener">${ escHtml( award.title ) }</a>
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
