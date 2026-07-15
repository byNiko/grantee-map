<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Grantee Map REST API
 *
 * GET /wp-json/grantees/v1/map
 *
 * Returns all published grantee_org posts with location, popup data,
 * and taxonomy arrays (years, disciplines, grant_types) for client-side filtering.
 */

add_action( 'rest_api_init', 'grantee_register_map_endpoint' );

function grantee_register_map_endpoint() {
    register_rest_route( 'grantees/v1', '/map', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'grantee_map_endpoint',
        'permission_callback' => '__return_true',
    ] );
}

function grantee_map_endpoint( WP_REST_Request $request ) {
    $org_posts = get_posts( [
        'post_type'      => 'grantee_org',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
    ] );

    $results = [];
    foreach ( $org_posts as $org ) {
        $data = grantee_get_org_map_data( $org );
        if ( $data ) $results[] = $data;
    }

    return rest_ensure_response( $results );
}

/**
 * Returns all map data for a single org, cached per org ID.
 * Cache is busted when the org or any of its linked awards is saved.
 */
function grantee_get_org_map_data( $org ) {
    $cache_key = 'grantee_org_map_' . $org->ID;
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) return $cached;

    $map_data = get_field( 'grantee_map', $org->ID );
    $lat      = (float) ( get_field( 'grantee_lat', $org->ID ) ?: ( $map_data['lat'] ?? 0 ) );
    $lng      = (float) ( get_field( 'grantee_lng', $org->ID ) ?: ( $map_data['lng'] ?? 0 ) );
    if ( ! $lat || ! $lng ) return null;

    $image_url = '';
    $image     = get_field( 'custom_image', $org->ID );
    if ( $image ) {
        $image_url = is_array( $image ) ? ( $image['url'] ?? '' ) : wp_get_attachment_url( $image );
    }

    $data = [
        'id'           => $org->ID,
        'title'        => html_entity_decode( get_the_title( $org->ID ), ENT_QUOTES, 'UTF-8' ),
        'excerpt'      => wp_trim_words( $org->post_content, 25 ),
        'lat'          => $lat,
        'lng'          => $lng,
        'address'      => $map_data['address'] ?? '',
        'image'        => $image_url,
        'website_url'  => esc_url_raw( get_field( 'website_url', $org->ID ) ?: '' ),
        'website_name' => get_field( 'website_nicename', $org->ID ) ?: '',
        'funding_goal' => get_field( 'funding_goal', $org->ID ) ?: '',
        'years'        => grantee_get_org_award_years( $org->ID ),
        'disciplines'  => grantee_get_org_tax_terms( $org->ID, 'disciplines' ),
        'grant_types'  => grantee_get_org_tax_terms( $org->ID, 'grant-types' ),
        'org_types'    => grantee_get_org_own_tax_terms( $org->ID, 'org-types' ),
        'awards'       => grantee_get_org_awards( $org->ID ),
        'permalink'    => get_permalink( $org->ID ),
    ];

    set_transient( $cache_key, $data, WEEK_IN_SECONDS );
    return $data;
}

// Bust an org's cache when it is saved directly.
add_action( 'save_post_grantee_org', function( $org_id ) {
    delete_transient( 'grantee_org_map_' . $org_id );
} );

// Bust the org's cache when one of its linked awards is saved.
add_action( 'save_post_wilhelm_grantee', function( $award_id ) {
    $ref    = get_field( 'grantee_org_ref', $award_id );
    $org_id = $ref ? (int) ( is_array( $ref ) ? reset( $ref ) : $ref ) : 0;
    if ( $org_id ) delete_transient( 'grantee_org_map_' . $org_id );
} );

/**
 * Returns {slug, name} term objects for a taxonomy assigned directly to the org post.
 */
function grantee_get_org_own_tax_terms( $org_id, $taxonomy ) {
    $terms = get_the_terms( $org_id, $taxonomy );
    if ( ! $terms || is_wp_error( $terms ) ) return [];
    return array_values( array_map( function( $t ) {
        $color = sanitize_hex_color( get_field( 'org_type_color', $t->taxonomy . '_' . $t->term_id ) ?: '' );
        return [ 'slug' => $t->slug, 'name' => $t->name, 'color' => $color ?: '' ];
    }, $terms ) );
}

/**
 * Returns {slug, name} term objects for a given taxonomy on an org's awards.
 */
function grantee_get_org_tax_terms( $org_id, $taxonomy ) {
    $linked = get_field( 'grantee', $org_id );
    if ( empty( $linked ) ) return [];

    $terms = [];
    foreach ( (array) $linked as $item ) {
        $award_id   = is_object( $item ) ? (int) $item->ID : (int) $item;
        $post_terms = get_the_terms( $award_id, $taxonomy );
        if ( $post_terms && ! is_wp_error( $post_terms ) ) {
            foreach ( $post_terms as $term ) {
                $terms[ $term->slug ] = $term->name;
            }
        }
    }

    $result = [];
    foreach ( $terms as $slug => $name ) {
        $result[] = [ 'slug' => $slug, 'name' => $name ];
    }
    return $result;
}

/**
 * Returns all unique grant cycle year names for an org.
 */
function grantee_get_org_award_years( $org_id ) {
    $linked = get_field( 'grantee', $org_id );
    if ( empty( $linked ) ) return [];

    $years = [];
    foreach ( (array) $linked as $item ) {
        $post_id = is_object( $item ) ? (int) $item->ID : (int) $item;
        $terms   = get_the_terms( $post_id, 'grant-cycle' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $years[] = $term->name;
            }
        }
    }

    return array_values( array_unique( array_filter( $years ) ) );
}

/**
 * Returns individual award posts for a given org — title, year, amount, permalink.
 */
function grantee_get_org_awards( $org_id ) {
    $linked = get_field( 'grantee', $org_id );
    if ( empty( $linked ) ) return [];

    $awards = [];
    foreach ( (array) $linked as $item ) {
        $post_id = is_object( $item ) ? (int) $item->ID : (int) $item;
        $post    = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) continue;

        $year  = '';
        $terms = get_the_terms( $post_id, 'grant-cycle' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            $year = implode( ', ', wp_list_pluck( $terms, 'name' ) );
        }

        $awards[] = [
            'id'        => $post_id,
            'title'     => $post->post_title,
            'year'      => $year,
            'amount'    => get_field( 'amount', $post_id ) ?: '',
            'permalink' => get_permalink( $post_id ),
        ];
    }

    usort( $awards, fn( $a, $b ) => strcmp( (string) $b['year'], (string) $a['year'] ) );

    return $awards;
}
