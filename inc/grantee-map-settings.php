<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds a Google Maps API Key field to the existing Theme Settings options page.
 * The key is picked up by ACF's map field picker in the admin.
 */

/** deprecated
* Google API Key
* define( 'GRANTEE_MAP_GOOGLE_API_KEY', 'xxxxxxxxx-xxxx-xxxxxx' );
* add_action('acf/init', function(){
*     acf_update_setting('google_api_key', GRANTEE_MAP_GOOGLE_API_KEY);
* });
 * 
 */

add_action( 'acf/init', function() {
    if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

    // Only attach if the options page exists — created by the theme.
    if ( function_exists( 'acf_get_options_page' ) && ! acf_get_options_page( 'theme-settings' ) ) return;

    $backfill_url   = wp_nonce_url( admin_url( '?backfill_grantee_coordinates=1' ), 'grantee_backfill_coords' );
    $clear_cache_url = wp_nonce_url( admin_url( '?clear_grantee_cache=1' ), 'grantee_clear_cache' );

    acf_add_local_field_group( [
        'key'    => 'group_grantee_map_api',
        'title'  => 'Map Settings',
        'fields' => [
            [
                'key'          => 'field_grantee_google_maps_api_key',
                'label'        => 'Google Maps API Key',
                'name'         => 'grantee_google_maps_api_key',
                'type'         => 'text',
                'instructions' => 'Required for the address picker on Grantee Org posts.',
                'wrapper'      => [ 'width' => '50' ],
            ],
            [
                'key'          => 'field_grantee_carto_api_key',
                'label'        => 'CARTO API Key',
                'name'         => 'grantee_carto_api_key',
                'type'         => 'text',
                'instructions' => 'From your CARTO account — required for map tiles.',
                'wrapper'      => [ 'width' => '50' ],
            ],
            [
                'key'      => 'field_grantee_backfill_coords_btn',
                'label'    => 'Backfill Coordinates',
                'name'     => '',
                'type'     => 'message',
                'message'  => '<a href="' . esc_url( $backfill_url ) . '" class="button button-secondary">Run coordinate backfill &rarr;</a><p class="description" style="margin-top:6px;">Copies lat/lng from the Google Maps field into the manual coordinate fields on every org that doesn\'t already have them set. Safe to run more than once.</p>',
                'new_lines' => '',
                'esc_html'  => 0,
            ],
            [
                'key'      => 'field_grantee_clear_cache_btn',
                'label'    => 'Clear Map Cache',
                'name'     => '',
                'type'     => 'message',
                'message'  => '<a href="' . esc_url( $clear_cache_url ) . '" class="button button-secondary">Clear grantee cache &rarr;</a><p class="description" style="margin-top:6px;">Deletes all cached map API responses so the next page load rebuilds them fresh. Run this after changing grant types, disciplines, or any field that appears in the map popup.</p>',
                'new_lines' => '',
                'esc_html'  => 0,
            ],
        ],
        'location' => [ [ [
            'param'    => 'options_page',
            'operator' => '==',
            'value'    => 'theme-settings',
        ] ] ],
    ] );
}, 5 );

// Feed the key into ACF so the map field picker works in the admin.
add_action( 'acf/init', function() {
    $key = get_field( 'grantee_google_maps_api_key', 'option' );
    if ( $key ) {
        acf_update_setting( 'google_api_key', $key );
    }
}, 20 );
