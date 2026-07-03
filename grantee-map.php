<?php

/**
 * Plugin Name: Grantee Map
 * Description: Interactive Leaflet map of grantee organizations with client-side filtering by organization type.
 * Version:     1.4.0
 * Author:      ByNiko and 3N Design
 * Text Domain: grantee-map
 * Plugin URI: https://github.com/byniko/grantee-map
 * Requires PHP: 7.4
 * Requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GRANTEE_MAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'GRANTEE_MAP_URL', plugin_dir_url( __FILE__ ) );

// Tell ACF to load (and save) field groups from this plugin's acf-json folder.
add_filter( 'acf/settings/load_json', function( $paths ) {
    $paths[] = GRANTEE_MAP_DIR . 'acf-json';
    return $paths;
} );

add_filter( 'acf/settings/save_json', function( $path ) {
    return GRANTEE_MAP_DIR . 'acf-json';
} );

// Register the page template from the plugin.
add_filter( 'theme_page_templates', function( $templates ) {
    $templates['templates/template-grantee-map.php'] = 'Grantee Map';
    return $templates;
} );

add_filter( 'template_include', function( $template ) {
    if ( is_page() && get_page_template_slug() === 'templates/template-grantee-map.php' ) {
        return GRANTEE_MAP_DIR . 'templates/template-grantee-map.php';
    }
    return $template;
} );




require GRANTEE_MAP_DIR . 'inc/grantee-map-settings.php';
require GRANTEE_MAP_DIR . 'inc/grantee-organization-cpt.php';
require GRANTEE_MAP_DIR . 'inc/grantee-sync-bidir.php';
require GRANTEE_MAP_DIR . 'inc/grantee-rest-api.php';
require GRANTEE_MAP_DIR . 'inc/grantee-map-shortcode.php';
require GRANTEE_MAP_DIR . 'inc/grantee-migration.php';
require GRANTEE_MAP_DIR . 'inc/grantee-orphans.php';
require GRANTEE_MAP_DIR . 'inc/grantee-seed-locations.php';
require GRANTEE_MAP_DIR . 'inc/grantee-org-type-seeder.php';
