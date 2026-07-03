<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Grantee Map Cache Flush
 *
 * Deletes the cached map-data transient for every published grantee_org,
 * forcing the next /wp-json/grantees/v1/map request to rebuild it. Useful
 * after a code change that alters what grantee_get_org_map_data() returns,
 * since normal edits only bust the cache for the org/award actually saved.
 *
 * Usage: /wp-admin/?clear_grantee_map_cache=1
 */

add_action( 'admin_init', 'grantee_maybe_clear_map_cache' );

function grantee_maybe_clear_map_cache() {
	if ( ! isset( $_GET['clear_grantee_map_cache'] ) ) return;
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

	$org_ids = get_posts( [
		'post_type'      => 'grantee_org',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	$cleared = 0;
	foreach ( $org_ids as $org_id ) {
		if ( delete_transient( 'grantee_org_map_' . $org_id ) ) $cleared++;
	}
	?>
	<!DOCTYPE html>
	<html>
	<head>
		<meta charset="utf-8">
		<title>Grantee Map Cache Flush</title>
		<style>
			* { box-sizing: border-box; margin: 0; padding: 0; }
			body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f0f0f1; padding: 40px 20px; color: #1d2327; line-height: 1.5; }
			.wrap { max-width: 560px; margin: 0 auto; }
			h1 { font-size: 22px; font-weight: 600; margin-bottom: 6px; }
			.subtitle { font-size: 13px; color: #666; margin-bottom: 28px; }
			.banner { padding: 14px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; background: #edfaef; border: 1.5px solid #00a32a; color: #00612c; }
			.btn { display: inline-block; padding: 10px 20px; border-radius: 5px; font-size: 13px; font-weight: 600; text-decoration: none; background: #fff; color: #646970; border: 1.5px solid #c3c4c7; margin-top: 24px; }
			.btn:hover { opacity: .85; }
		</style>
	</head>
	<body>
	<div class="wrap">
		<h1>Grantee Map Cache Flush</h1>
		<p class="subtitle">Cleared the map-data cache for every published organization.</p>
		<div class="banner">✅ Cleared <?php echo absint( $cleared ); ?> of <?php echo absint( count( $org_ids ) ); ?> cached org<?php echo count( $org_ids ) !== 1 ? 's' : ''; ?>.</div>
		<a class="btn" href="<?php echo esc_url( home_url( '/' ) ); ?>">← Back to site</a>
	</div>
	</body>
	</html>
	<?php
	exit;
}
