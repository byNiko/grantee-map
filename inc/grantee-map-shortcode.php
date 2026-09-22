<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Grantee Map Shortcode
 *
 * Usage:
 *   [grantees_map]
 *   [grantees_map taxonomy="grant_category" height="600px"]
 *   [grantees_map default_year="2023"]
 *
 */

add_action( 'wp_enqueue_scripts', 'grantee_map_register_assets' );
add_shortcode( 'grantees_map', 'grantee_map_shortcode' );

function grantee_map_register_assets() {
    wp_register_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4' );
    wp_register_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true );

    wp_register_style( 'leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css', [ 'leaflet' ], '1.5.3' );
    wp_register_style( 'leaflet-markercluster-default', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css', [ 'leaflet' ], '1.5.3' );
    wp_register_script( 'leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js', [ 'leaflet' ], '1.5.3', true );

    wp_register_script( 'grantee-map', GRANTEE_MAP_URL . 'assets/js/grantee-map.js', [ 'leaflet', 'leaflet-markercluster' ], filemtime( GRANTEE_MAP_DIR . 'assets/js/grantee-map.js' ), true );
    wp_register_style( 'grantee-map', GRANTEE_MAP_URL . 'assets/css/grantee-map.css', [ 'leaflet' ], filemtime( GRANTEE_MAP_DIR . 'assets/css/grantee-map.css' ) );
}

function grantee_map_enqueue_assets() {
    wp_enqueue_style( 'leaflet' );
    wp_enqueue_style( 'leaflet-markercluster' );
    wp_enqueue_style( 'leaflet-markercluster-default' );
    wp_enqueue_style( 'grantee-map' );
    wp_enqueue_script( 'leaflet' );
    wp_enqueue_script( 'leaflet-markercluster' );
    wp_enqueue_script( 'grantee-map' );

    $org_types = get_taxonomy('org-types');

    wp_localize_script( 'grantee-map', 'GranteeMapConfig', [
        'restUrl'   => esc_url_raw( rest_url( 'grantees/v1' ) ),
        'nonce'     => wp_create_nonce( 'wp_rest' ),
        'styleUrl'  => GRANTEE_MAP_URL . 'assets/js/grantee-map-style.json',
        'cartoKey'  => get_field( 'grantee_carto_api_key', 'option' ) ?: '',
        'organizationTypeLabel' => $org_types
            ? $org_types->labels->singular_name
            : 'Organization Type',
    ] );

    // If wp_head has already fired (e.g. do_shortcode() called from a theme template),
    // styles won't have been printed yet — flush them in wp_footer before any JS runs.
    if ( did_action( 'wp_head' ) ) {
        add_action( 'wp_footer', function() {
            wp_print_styles( [ 'leaflet', 'leaflet-markercluster', 'leaflet-markercluster-default', 'grantee-map' ] );
        }, 1 );
    }
}

function grantee_map_shortcode($atts) {
    grantee_map_enqueue_assets();

$org_types = get_taxonomy( 'org-types' );



    $atts = shortcode_atts([
        'height'         => '560px',
        'default_year'   => '',
        'center_lat'     => '39.5',
        'center_lng'     => '-98.35',
        'zoom'           => '4',
        'mobile_zoom'    => '3',
        'cluster_radius' => '30',
    ], $atts, 'grantees_map');

    $map_id = 'grantee-map-' . uniqid();

    ob_start();
?>
    <div class="grantee-map-wrap"
        data-default-year="<?php echo esc_attr($atts['default_year']); ?>"
        data-center-lat="<?php echo esc_attr($atts['center_lat']); ?>"
        data-center-lng="<?php echo esc_attr($atts['center_lng']); ?>"
        data-zoom="<?php echo esc_attr($atts['zoom']); ?>"
        data-mobile-zoom="<?php echo esc_attr($atts['mobile_zoom']); ?>"
        data-cluster-radius="<?php echo esc_attr($atts['cluster_radius']); ?>">



        <p id="<?php echo esc_attr($map_id); ?>-instructions" class="screen-reader-text">
            Interactive map of grantee organizations. Use arrow keys to pan, plus and minus keys to zoom. Press Enter on a marker to open its details.
        </p>

        <div class="grantee-map-aspect">
            <div id="<?php echo esc_attr($map_id); ?>"
                class="grantee-map-canvas"
                role="application"
                aria-label="Grantee organizations map"
                aria-describedby="<?php echo esc_attr($map_id); ?>-instructions"></div>
        </div>

        <div class="grantee-map-loading" aria-live="polite">
            <span>Loading grantees…</span>
        </div>

        <div class="grantee-timeline" hidden>
            <button type="button" class="grantee-timeline-play" aria-label="Play timeline">
                <span class="grantee-timeline-play-icon" aria-hidden="true"></span>
            </button>
            <div class="grantee-timeline-track">
                <input type="range" class="grantee-timeline-slider" min="0" max="1" step="1" value="1" aria-label="Filter grantees by year awarded">
                <div class="grantee-timeline-bubble" aria-hidden="true"></div>
            </div>
            <span class="grantee-timeline-year" aria-live="polite">All years</span>
        </div>

        <div class="grantee-map-filters">
            <div class="grantee-filter-group grantee-filter-types">
                <div class="gm-dropdown">
                    <button type="button" class="gm-dropbtn" aria-expanded="false" aria-haspopup="true" id="<?php echo esc_attr($map_id); ?>-types-btn">
                        <span class="gm-dropbtn-label"><?php echo esc_html($org_types->labels->singular_name); ?></span>
                        <span class="gm-dropbtn-chevron" aria-hidden="true"></span>
                    </button>
                    <div class="gm-dropdown-content" role="group" aria-labelledby="<?php echo esc_attr($map_id); ?>-types-btn"></div>
                </div>
            </div>
            <div class="grantee-filter-group">
                <button type="button" class="grantee-filter-reset" aria-label="Reset filters" hidden>Reset</button>
            </div>
            <div class="grantee-filter-group grantee-filter-count">
                <span class="grantee-count-label">Showing <strong class="grantee-count">—</strong> grantees</span>
            </div>
        </div>

    </div>
<?php
    return ob_get_clean();
}
