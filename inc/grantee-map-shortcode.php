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

add_action('wp_enqueue_scripts', 'grantee_map_enqueue_assets');
add_shortcode('grantees_map', 'grantee_map_shortcode');

function grantee_map_enqueue_assets() {
    global $post;
    // Load on pages using the shortcode in content OR the Grantee Map page template
    $template_slug   = is_a( $post, 'WP_Post' ) ? get_page_template_slug( $post->ID ) : '';
    $is_map_template = in_array( $template_slug, [ 'template-grantee-map.php', 'templates/template-grantee-map.php' ], true );
    $has_shortcode   = is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'grantees_map');
    if (! $is_map_template && ! $has_shortcode) {
        return;
    }

    // Leaflet
    wp_enqueue_style(
        'leaflet',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
        [],
        '1.9.4'
    );
    wp_enqueue_script(
        'leaflet',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        [],
        '1.9.4',
        true
    );

    // Leaflet MarkerCluster
    wp_enqueue_style(
        'leaflet-markercluster',
        'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css',
        ['leaflet'],
        '1.5.3'
    );
    wp_enqueue_style(
        'leaflet-markercluster-default',
        'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css',
        ['leaflet'],
        '1.5.3'
    );
    wp_enqueue_script(
        'leaflet-markercluster',
        'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js',
        ['leaflet'],
        '1.5.3',
        true
    );

    // Our map script
    wp_enqueue_script(
        'grantee-map',
        GRANTEE_MAP_URL . 'assets/js/grantee-map.js',
        ['leaflet', 'leaflet-markercluster'],
        filemtime(GRANTEE_MAP_DIR . 'assets/js/grantee-map.js'),
        true
    );

    wp_enqueue_style(
        'grantee-map',
        GRANTEE_MAP_URL . 'assets/css/grantee-map.css',
        ['leaflet'],
        filemtime(GRANTEE_MAP_DIR . 'assets/css/grantee-map.css')
    );

    // Pass REST URL, nonce, and style JSON URL to JS
    wp_localize_script('grantee-map', 'GranteeMapConfig', [
        'restUrl'  => esc_url_raw(rest_url('grantees/v1')),
        'nonce'    => wp_create_nonce('wp_rest'),
        'styleUrl' => GRANTEE_MAP_URL . 'assets/js/grantee-map-style.json',
    ]);
}

function grantee_map_shortcode($atts) {
    $atts = shortcode_atts([
        'height'       => '560px',
        'default_year' => '',
        'center_lat'   => '39.5',
        'center_lng'   => '-98.35',
        'zoom'         => '4',
    ], $atts, 'grantees_map');

    $map_id = 'grantee-map-' . uniqid();

    ob_start();
?>
    <div class="grantee-map-wrap"
        data-default-year="<?php echo esc_attr($atts['default_year']); ?>"
        data-center-lat="<?php echo esc_attr($atts['center_lat']); ?>"
        data-center-lng="<?php echo esc_attr($atts['center_lng']); ?>"
        data-zoom="<?php echo esc_attr($atts['zoom']); ?>">



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
            <input type="range" class="grantee-timeline-slider" min="0" max="1" step="1" value="1" aria-label="Filter grantees by year awarded">
            <span class="grantee-timeline-year" aria-live="polite">All years</span>
        </div>

        <div class="grantee-map-filters">
            <div class="grantee-filter-group grantee-filter-types">
                <span class="grantee-filter-group-label" id="<?php echo esc_attr($map_id); ?>-types-label">Organization Type</span>
                <div class="grantee-type-chips" role="group" aria-labelledby="<?php echo esc_attr($map_id); ?>-types-label"></div>
            </div>
            <div class="grantee-filter-group">
                <label aria-hidden="true">&nbsp;</label>
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
