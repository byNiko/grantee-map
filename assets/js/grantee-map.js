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
		const mapEl         = wrap.querySelector( '.grantee-map-canvas' );
		const orgTypeSelect = wrap.querySelector( '.grantee-filter-org-type' );
		const countEl       = wrap.querySelector( '.grantee-count' );
		const loadingEl     = wrap.querySelector( '.grantee-map-loading' );
		const resetBtn      = wrap.querySelector( '.grantee-filter-reset' );

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
					html:     `<div class="gm-cluster">${count}</div>`,
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
				className:   '',
				iconSize:    [ size, size ],
				iconAnchor:  [ cx,   cy   ],
				popupAnchor: [ 0,    -( r + 4 ) ],
			} );
		}

		// ── Data ──────────────────────────────────────────────────
		let allOrgs = [];

		setLoading( true );

		fetch( `${ GranteeMapConfig.restUrl }/map`, { headers: { 'X-WP-Nonce': GranteeMapConfig.nonce } } )
			.then( ( r ) => r.json() )
			.then( ( orgs ) => {
				allOrgs = orgs;
				buildOrgTypeOptions();
				applyFilters();
				setLoading( false );
			} )
			.catch( ( err ) => {
				console.error( 'Grantee map: failed to load data', err );
				setLoading( false );
			} );

		// ── Build org type dropdown from data ─────────────────────
		function buildOrgTypeOptions() {
			if ( ! orgTypeSelect ) return;

			const counts = {};
			allOrgs.forEach( ( g ) => {
				( g.org_types || [] ).forEach( ( t ) => {
					if ( ! counts[ t.slug ] ) counts[ t.slug ] = { name: t.name, count: 0 };
					counts[ t.slug ].count++;
				} );
			} );

			Object.entries( counts )
				.sort( ( a, b ) => a[ 1 ].name.localeCompare( b[ 1 ].name ) )
				.forEach( ( [ slug, { name, count } ] ) => {
					const opt       = document.createElement( 'option' );
					opt.value       = slug;
					opt.textContent = `${ name } (${ count })`;
					orgTypeSelect.appendChild( opt );
				} );
		}

		// ── Filter change handler ─────────────────────────────────
		if ( orgTypeSelect ) orgTypeSelect.addEventListener( 'change', applyFilters );

		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', () => {
				if ( orgTypeSelect ) orgTypeSelect.value = '';
				applyFilters();
			} );
		}

		// ── Apply filter, update markers and count ────────────────
		function applyFilters() {
			const orgType = orgTypeSelect ? orgTypeSelect.value : '';

			const filtered = allOrgs.filter( ( g ) =>
				! orgType || ( g.org_types || [] ).some( ( t ) => t.slug === orgType )
			);

			renderMarkers( filtered );
			if ( countEl ) countEl.textContent = filtered.length;
			if ( resetBtn ) resetBtn.hidden = ! orgType;
		}

		// ── Render markers ────────────────────────────────────────
		function renderMarkers( orgs ) {
			markers.clearLayers();

			orgs.forEach( ( g ) => {
				if ( ! g.lat || ! g.lng ) return;
				const colors = ( g.org_types || [] ).map( ( t ) => t.color ).filter( Boolean );
				const icon   = makeIcon( colors );
				const marker = L.marker( [ g.lat, g.lng ], { icon } );
				marker.bindPopup( buildPopup( g ), { maxWidth: 340, className: 'grantee-popup' } );
				markers.addLayer( marker );
			} );

			if ( orgs.length > 0 ) {
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

			if ( g.website_url ) {
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
