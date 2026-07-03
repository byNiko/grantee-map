<?php
/**
 * Organization Type seeder
 *
 * Creates the four Organization Type terms and randomly assigns
 * 1–3 of them to every published grantee_org post.
 *
 * Run: /wp-admin/?seed_org_types=1
 */

add_action( 'admin_init', function() {
    if ( ! isset( $_GET['seed_org_types'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

    $term_names = [ 'Dance', 'Museum', 'Queer', 'Women Owned' ];
    $term_ids   = [];

    foreach ( $term_names as $name ) {
        $existing = get_term_by( 'name', $name, 'org-types' );
        if ( $existing ) {
            $term_ids[] = $existing->term_id;
        } else {
            $result = wp_insert_term( $name, 'org-types' );
            if ( ! is_wp_error( $result ) ) {
                $term_ids[] = $result['term_id'];
            }
        }
    }

    $orgs = get_posts( [
        'post_type'      => GM_ORG_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    $assigned = 0;
    foreach ( $orgs as $org_id ) {
        $count    = rand( 1, 3 );
        $shuffled = $term_ids;
        shuffle( $shuffled );
        $picked   = array_slice( $shuffled, 0, $count );
        wp_set_object_terms( $org_id, $picked, 'org-types' );
        delete_transient( 'grantee_org_map_' . $org_id );
        $assigned++;
    }

    ?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Org Type Seeder</title>
<style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;padding:40px 20px;color:#1d2327}
    .wrap{max-width:600px;margin:0 auto}
    h1{font-size:22px;font-weight:600;margin-bottom:20px}
    .banner{background:#edfaef;border:1.5px solid #00a32a;color:#00612c;border-radius:8px;padding:14px 20px;font-size:14px;font-weight:600;margin-bottom:20px}
    ul{background:#fff;border:1.5px solid #e2e4e7;border-radius:8px;padding:16px 20px;list-style:none;font-size:14px;line-height:2}
    a{display:inline-block;margin-top:20px;padding:10px 20px;background:#fff;border:1.5px solid #c3c4c7;border-radius:5px;font-size:13px;font-weight:600;color:#646970;text-decoration:none}
</style></head><body>
<div class="wrap">
    <h1>Organization Type Seeder</h1>
    <div class="banner">✅ Done — <?php echo count( $orgs ); ?> organizations updated</div>
    <ul>
        <?php foreach ( $term_names as $name ) : ?>
            <li>✓ <?php echo esc_html( $name ); ?></li>
        <?php endforeach; ?>
    </ul>
    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . GM_ORG_CPT ) ); ?>">← View Organizations</a>
</div>
</body></html>
    <?php
    exit;
} );
