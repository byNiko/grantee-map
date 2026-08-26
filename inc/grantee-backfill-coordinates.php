<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Backfills grantee_lat and grantee_lng from the existing ACF Google Maps field.
 *
 * Run: /wp-admin/?backfill_grantee_coordinates=1
 */

add_action( 'admin_init', function() {
    if ( ! isset( $_GET['backfill_grantee_coordinates'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );
    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'grantee_backfill_coords' ) ) {
        $run_url = wp_nonce_url( admin_url( '?backfill_grantee_coordinates=1' ), 'grantee_backfill_coords' );
        wp_die( '<p>This will copy lat/lng from the Google Maps field into the manual coordinate fields for every org that doesn\'t already have them set.<br><br><a href="' . esc_url( $run_url ) . '">Run backfill →</a></p>', 'Backfill Coordinates' );
    }

    $orgs = get_posts( [
        'post_type'      => GM_ORG_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    $filled  = 0;
    $skipped = 0;
    $missing = 0;
    $rows    = [];

    foreach ( $orgs as $org_id ) {
        $existing_lat = get_field( 'grantee_lat', $org_id );
        $existing_lng = get_field( 'grantee_lng', $org_id );

        if ( $existing_lat && $existing_lng ) {
            $skipped++;
            $rows[] = [ 'status' => 'skip', 'title' => get_the_title( $org_id ), 'lat' => $existing_lat, 'lng' => $existing_lng ];
            continue;
        }

        $map_data = get_field( 'grantee_map', $org_id );
        $lat      = $map_data['lat'] ?? '';
        $lng      = $map_data['lng'] ?? '';

        if ( ! $lat || ! $lng ) {
            $missing++;
            $rows[] = [ 'status' => 'missing', 'title' => get_the_title( $org_id ), 'lat' => '—', 'lng' => '—' ];
            continue;
        }

        update_field( 'grantee_lat', $lat, $org_id );
        update_field( 'grantee_lng', $lng, $org_id );
        delete_transient( 'grantee_org_map_' . $org_id );
        $filled++;
        $rows[] = [ 'status' => 'filled', 'title' => get_the_title( $org_id ), 'lat' => $lat, 'lng' => $lng ];
    }

    ?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Backfill Coordinates</title>
<style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;padding:40px 20px;color:#1d2327;line-height:1.5}
    .wrap{max-width:900px;margin:0 auto}
    h1{font-size:22px;font-weight:600;margin-bottom:6px}
    p.sub{font-size:13px;color:#666;margin-bottom:28px}
    .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:28px}
    .stat{background:#fff;border:1.5px solid #e2e4e7;border-radius:8px;padding:14px 18px}
    .stat-value{font-size:28px;font-weight:700}
    .stat-label{font-size:11px;color:#666;margin-top:2px;text-transform:uppercase;letter-spacing:.04em}
    table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:8px;overflow:hidden;border:1.5px solid #e2e4e7;margin-bottom:28px}
    th{background:#f6f7f7;padding:10px 14px;text-align:left;font-weight:600;font-size:12px;color:#444;border-bottom:1.5px solid #e2e4e7}
    td{padding:10px 14px;border-bottom:1px solid #f0f0f1}
    tr:last-child td{border-bottom:none}
    .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
    .badge-green{background:#edfaef;color:#00612c}
    .badge-gray{background:#f0f0f1;color:#646970}
    .badge-red{background:#fcf0f1;color:#8a1f1f}
    .btn{display:inline-block;padding:10px 20px;border-radius:5px;font-size:13px;font-weight:600;text-decoration:none;background:#fff;color:#646970;border:1.5px solid #c3c4c7}
</style></head><body>
<div class="wrap">
    <h1>Backfill Coordinates</h1>
    <p class="sub">Copied lat/lng from the Google Maps field into the manual coordinate fields.</p>
    <div class="stats">
        <div class="stat"><div class="stat-value" style="color:#00a32a"><?php echo absint( $filled ); ?></div><div class="stat-label">Filled</div></div>
        <div class="stat"><div class="stat-value" style="color:#666"><?php echo absint( $skipped ); ?></div><div class="stat-label">Already set</div></div>
        <div class="stat"><div class="stat-value" style="color:#d63638"><?php echo absint( $missing ); ?></div><div class="stat-label">No map data</div></div>
    </div>
    <table>
        <thead><tr><th>Org</th><th>Status</th><th>Lat</th><th>Lng</th></tr></thead>
        <tbody>
        <?php foreach ( $rows as $row ) : ?>
            <tr>
                <td><?php echo esc_html( $row['title'] ); ?></td>
                <td>
                    <?php if ( $row['status'] === 'filled' ) : ?>
                        <span class="badge badge-green">filled</span>
                    <?php elseif ( $row['status'] === 'skip' ) : ?>
                        <span class="badge badge-gray">already set</span>
                    <?php else : ?>
                        <span class="badge badge-red">no map data</span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $row['lat'] ); ?></td>
                <td><?php echo esc_html( $row['lng'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <a class="btn" href="<?php echo esc_url( admin_url() ); ?>">← Dashboard</a>
</div></body></html>
    <?php
    exit;
} );

/**
 * Clears all cached grantee org map transients.
 *
 * Run: /wp-admin/?clear_grantee_cache=1
 */
add_action( 'admin_init', function() {
    if ( ! isset( $_GET['clear_grantee_cache'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );
    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'grantee_clear_cache' ) ) {
        $run_url = wp_nonce_url( admin_url( '?clear_grantee_cache=1' ), 'grantee_clear_cache' );
        wp_die( '<p>This will delete all cached grantee map data so the REST API rebuilds fresh responses.<br><br><a href="' . esc_url( $run_url ) . '">Clear cache →</a></p>', 'Clear Grantee Cache' );
    }

    global $wpdb;
    $deleted = $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_grantee_org_map_%'
            OR option_name LIKE '_transient_timeout_grantee_org_map_%'"
    );

    $count = (int) ( $deleted / 2 );

    ?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Clear Grantee Cache</title>
<style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;padding:40px 20px;color:#1d2327;line-height:1.5}
    .wrap{max-width:600px;margin:0 auto}
    h1{font-size:22px;font-weight:600;margin-bottom:6px}
    p.sub{font-size:13px;color:#666;margin-bottom:28px}
    .stat{background:#fff;border:1.5px solid #e2e4e7;border-radius:8px;padding:14px 18px;margin-bottom:28px;display:inline-block}
    .stat-value{font-size:28px;font-weight:700;color:#00a32a}
    .stat-label{font-size:11px;color:#666;margin-top:2px;text-transform:uppercase;letter-spacing:.04em}
    .btn{display:inline-block;padding:10px 20px;border-radius:5px;font-size:13px;font-weight:600;text-decoration:none;background:#fff;color:#646970;border:1.5px solid #c3c4c7}
</style></head><body>
<div class="wrap">
    <h1>Grantee Cache Cleared</h1>
    <p class="sub">The REST API will rebuild fresh responses on next load.</p>
    <div class="stat">
        <div class="stat-value"><?php echo absint( $count ); ?></div>
        <div class="stat-label">Entries removed</div>
    </div>
    <br>
    <a class="btn" href="<?php echo esc_url( admin_url() ); ?>">← Dashboard</a>
</div></body></html>
    <?php
    exit;
} );
