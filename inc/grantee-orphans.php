<?php
/**
 * Grantee Orphan Check
 *
 * Lists all wilhelm_grantee posts that are not linked to any grantee_org.
 *
 * Usage: /wp-admin/?check_grantee_orphans=1
 *
 * Add to functions.php:
 *   require_once get_template_directory() . '/inc/grantee-orphans.php';
 *
 * Remove after data is confirmed clean.
 */

add_action( 'admin_init', 'grantee_maybe_run_orphan_check' );

function grantee_maybe_run_orphan_check() {
	if ( ! isset( $_GET['check_grantee_orphans'] ) ) return;
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

	$awards = get_posts( [
		'post_type'      => 'wilhelm_grantee',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );

	// Build the set of award IDs that ARE linked via the grantee field on grantee_org
	$orgs = get_posts( [
		'post_type'      => 'grantee_org',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	$linked_award_ids = [];
	foreach ( $orgs as $org_id ) {
		$related = get_field( 'grantee', $org_id );
		if ( ! empty( $related ) ) {
			foreach ( (array) $related as $item ) {
				$linked_award_ids[] = is_object( $item ) ? (int) $item->ID : (int) $item;
			}
		}
	}
	$linked_award_ids = array_unique( $linked_award_ids );

	$orphans = [];
	$linked  = [];

	foreach ( $awards as $post ) {
		if ( in_array( $post->ID, $linked_award_ids, true ) ) {
			$linked[] = $post;
		} else {
			$orphans[] = $post;
		}
	}

	$total = count( $awards );
	?>
	<!DOCTYPE html>
	<html>
	<head>
		<meta charset="utf-8">
		<title>Grantee Orphan Check</title>
		<style>
			* { box-sizing: border-box; margin: 0; padding: 0; }
			body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f0f0f1; padding: 40px 20px; color: #1d2327; line-height: 1.5; }
			.wrap { max-width: 860px; margin: 0 auto; }
			h1 { font-size: 22px; font-weight: 600; margin-bottom: 6px; }
			.subtitle { font-size: 13px; color: #666; margin-bottom: 28px; }
			.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 28px; }
			.stat { background: #fff; border: 1.5px solid #e2e4e7; border-radius: 8px; padding: 14px 18px; }
			.stat-value { font-size: 28px; font-weight: 700; }
			.stat-label { font-size: 11px; color: #666; margin-top: 2px; text-transform: uppercase; letter-spacing: .04em; }
			.stat.good .stat-value { color: #00a32a; }
			.stat.bad  .stat-value { color: #d63638; }
			.banner { padding: 14px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; margin-bottom: 28px; }
			.banner.success { background: #edfaef; border: 1.5px solid #00a32a; color: #00612c; }
			.banner.error   { background: #fcf0f1; border: 1.5px solid #d63638; color: #8a1f1f; }
			table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; border-radius: 8px; overflow: hidden; border: 1.5px solid #e2e4e7; }
			th { background: #f6f7f7; padding: 10px 14px; text-align: left; font-weight: 600; font-size: 12px; color: #444; border-bottom: 1.5px solid #e2e4e7; }
			td { padding: 10px 14px; border-bottom: 1px solid #f0f0f1; vertical-align: top; }
			tr:last-child td { border-bottom: none; }
			tr:hover td { background: #fafafa; }
			a { color: #2271b1; text-decoration: none; }
			a:hover { text-decoration: underline; }
			.btn { display: inline-block; padding: 10px 20px; border-radius: 5px; font-size: 13px; font-weight: 600; text-decoration: none; background: #fff; color: #646970; border: 1.5px solid #c3c4c7; margin-top: 24px; }
			.btn:hover { opacity: .85; text-decoration: none; }
			h2 { font-size: 16px; font-weight: 600; margin-bottom: 14px; }
		</style>
	</head>
	<body>
	<div class="wrap">

		<h1>Grantee Orphan Check</h1>
		<p class="subtitle">wilhelm_grantee posts with no linked grantee_org.</p>

		<div class="stats">
			<div class="stat">
				<div class="stat-value"><?php echo $total; ?></div>
				<div class="stat-label">Total awards</div>
			</div>
			<div class="stat good">
				<div class="stat-value"><?php echo count( $linked ); ?></div>
				<div class="stat-label">Linked to org</div>
			</div>
			<div class="stat <?php echo count( $orphans ) ? 'bad' : 'good'; ?>">
				<div class="stat-value"><?php echo count( $orphans ); ?></div>
				<div class="stat-label">No org found</div>
			</div>
		</div>

		<?php if ( empty( $orphans ) ) : ?>
			<div class="banner success">✅ All <?php echo $total; ?> grantee posts are linked to an organization.</div>
		<?php else : ?>
			<div class="banner error">⚠️ <?php echo count( $orphans ); ?> grantee post<?php echo count( $orphans ) !== 1 ? 's are' : ' is'; ?> not linked to any organization.</div>
			<h2>Orphaned Grantees</h2>
			<table>
				<thead>
					<tr>
						<th>ID</th>
						<th>Title</th>
						<th>Grant Cycle</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $orphans as $post ) : ?>
					<tr>
						<td><?php echo $post->ID; ?></td>
						<td><?php echo esc_html( $post->post_title ); ?></td>
						<td><?php echo esc_html( get_field( 'grant_cycle', $post->ID ) ?: '—' ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" target="_blank">Edit ↗</a></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<a class="btn" href="<?php echo esc_url( admin_url( '?check_grantee_orphans=1' ) ); ?>">↺ Re-run</a>

	</div>
	</body>
	</html>
	<?php
	exit;
}
