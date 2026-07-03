<?php

define('GM_SOURCE_CPT',    'wilhelm_grantee'); // award posts CPT
define('GM_ORG_CPT',       'grantee_org');     // parent org CPT
define('GM_ORG_REL_FIELD', 'grantee');         // ACF relationship field ON grantee_org

// ── Grantee award CPT + taxonomies ────────────────────────────────────────────
// Only register if another plugin (e.g. the Wilhelm Database mu-plugin) hasn't already.

add_action( 'init', function() {
    if ( ! post_type_exists( 'wilhelm_grantee' ) ) {
        register_post_type( 'wilhelm_grantee', [
            'labels'       => [ 'name' => 'Grantees', 'singular_name' => 'Grantee' ],
            'public'       => true,
            'has_archive'  => true,
            'rewrite'      => [ 'slug' => 'grantee' ],
            'supports'     => [ 'title', 'thumbnail', 'editor' ],
            'show_in_rest' => true,
            'menu_icon'    => 'dashicons-art',
        ] );
    }

    if ( ! taxonomy_exists( 'grant-cycle' ) ) {
        register_taxonomy( 'grant-cycle', [ 'wilhelm_grantee' ], [
            'labels'            => [ 'name' => 'Grant Cycles', 'singular_name' => 'Grant Cycle', 'menu_name' => 'Grant Cycles' ],
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'grant-cycle' ],
            'show_in_rest'      => true,
        ] );
    }

    if ( ! taxonomy_exists( 'grant-types' ) ) {
        register_taxonomy( 'grant-types', [ 'wilhelm_grantee' ], [
            'labels'            => [ 'name' => 'Grant Types', 'singular_name' => 'Grant Type', 'menu_name' => 'Grant Types' ],
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'grant-types' ],
            'show_in_rest'      => true,
        ] );
    }

    if ( ! taxonomy_exists( 'disciplines' ) ) {
        register_taxonomy( 'disciplines', [ 'wilhelm_grantee' ], [
            'labels'            => [ 'name' => 'Disciplines', 'singular_name' => 'Discipline', 'menu_name' => 'Disciplines' ],
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'disciplines' ],
            'show_in_rest'      => true,
        ] );
    }
}, 5 ); // priority 5 — runs before default init hooks

// ── Organization Type taxonomy ────────────────────────────────────────────────

add_action( 'init', function() {
    register_taxonomy( 'org-types', [ GM_ORG_CPT ], [
        'labels'            => [ 'name' => 'Organization Types', 'singular_name' => 'Organization Type', 'menu_name' => 'Org Types' ],
        'hierarchical'      => true,
        'show_ui'           => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'org-type' ],
        'show_in_rest'      => true,
    ] );
}, 5 );

// ── Color picker field on org-types terms ────────────────────────────────────

add_action( 'acf/init', function() {
    if ( ! function_exists( 'acf_add_local_field_group' ) ) return;
    acf_add_local_field_group( [
        'key'      => 'group_org_type_color',
        'title'    => 'Organization Type Color',
        'fields'   => [ [
            'key'          => 'field_org_type_color',
            'label'        => 'Color',
            'name'         => 'org_type_color',
            'type'         => 'color_picker',
            'enable_opacity' => 0,
            'return_format'  => 'string',
        ] ],
        'location' => [ [ [
            'param'    => 'taxonomy',
            'operator' => '==',
            'value'    => 'org-types',
        ] ] ],
    ] );
} );

// ── Grantee org CPT ───────────────────────────────────────────────────────────

add_action('init', 'gm_register_grantee_org_cpt');

function gm_register_grantee_org_cpt() {
	register_post_type(GM_ORG_CPT, [
		'label'         => 'Grantee Organizations',
		'labels'        => [
			'name'          => 'Grantee Orgs',
			'singular_name' => 'Grantee Org',
			'add_new_item'  => 'Add New Organization',
			'edit_item'     => 'Edit Organization',
			'search_items'  => 'Search Organizations',
			'not_found'     => 'No organizations found.',
		],
		'public'        => true,
		'show_in_rest'  => true,
		'show_in_menu'  => false,
		'supports'      => ['title', 'editor', 'thumbnail'],
		'menu_icon'     => 'dashicons-location-alt',
		'rewrite'       => ['slug' => 'grantee-orgs'],
		'has_archive'   => false,
	]);
}

// Nest grantee_org and org-types under the grantee menu
add_action('admin_menu', function () {
	add_submenu_page(
		'edit.php?post_type=' . GM_SOURCE_CPT,
		'Grantee Organizations',
		'Organizations',
		'manage_options',
		'edit.php?post_type=' . GM_ORG_CPT
	);
	add_submenu_page(
		'edit.php?post_type=' . GM_SOURCE_CPT,
		'Organization Types',
		'Org Types',
		'manage_options',
		'edit-tags.php?taxonomy=org-types&post_type=' . GM_ORG_CPT
	);
});