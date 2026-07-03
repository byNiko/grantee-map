<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Grantee bidirectional relationship sync.
 *
 * Keeps `grantee` (on grantee_org) and `grantee_org_ref` (on wilhelm_grantee)
 * in sync manually, since ACF's built-in bidirectional is unreliable here.
 *
 * Also exposes /wp-admin/?sync_grantee_bidir=1 for a one-time backfill.
 */

// ── Live sync on save ─────────────────────────────────────────────────────────

// Guard against re-entrant sync calls triggered by our own update_field() calls.
$_grantee_sync_running = false;

add_action( 'acf/save_post', 'grantee_sync_org_to_awards', 20 );

function grantee_sync_org_to_awards( $post_id ) {
	global $_grantee_sync_running;
	if ( $_grantee_sync_running || get_post_type( $post_id ) !== 'grantee_org' ) return;
	$_grantee_sync_running = true;

	$award_ids = get_field( 'grantee', $post_id );
	$award_ids = $award_ids ? array_map( 'intval', (array) $award_ids ) : [];

	// Find awards previously pointing to this org using an exact serialized-value match.
	$prev = grantee_get_posts_by_acf_rel( 'wilhelm_grantee', 'grantee_org_ref', $post_id );

	foreach ( $prev as $old_id ) {
		if ( ! in_array( $old_id, $award_ids, true ) ) {
			update_field( 'grantee_org_ref', null, $old_id );
		}
	}

	foreach ( $award_ids as $award_id ) {
		update_field( 'grantee_org_ref', [ $post_id ], $award_id );
	}

	$_grantee_sync_running = false;
}

add_action( 'acf/save_post', 'grantee_sync_award_to_org', 20 );

function grantee_sync_award_to_org( $post_id ) {
	global $_grantee_sync_running;
	if ( $_grantee_sync_running || get_post_type( $post_id ) !== 'wilhelm_grantee' ) return;
	$_grantee_sync_running = true;

	$org_ref = get_field( 'grantee_org_ref', $post_id );
	$new_org = $org_ref ? (int) ( is_array( $org_ref ) ? reset( $org_ref ) : $org_ref ) : 0;

	$prev_orgs = grantee_get_posts_by_acf_rel( 'grantee_org', 'grantee', $post_id );

	foreach ( $prev_orgs as $org_id ) {
		if ( $org_id === $new_org ) continue;
		$awards = get_field( 'grantee', $org_id ) ?: [];
		$awards = array_map( 'intval', (array) $awards );
		$awards = array_values( array_filter( $awards, fn( $id ) => $id !== $post_id ) );
		update_field( 'grantee', $awards ?: null, $org_id );
	}

	if ( $new_org ) {
		$awards = get_field( 'grantee', $new_org ) ?: [];
		$awards = array_map( 'intval', (array) $awards );
		if ( ! in_array( $post_id, $awards, true ) ) {
			$awards[] = $post_id;
			update_field( 'grantee', $awards, $new_org );
		}
	}

	$_grantee_sync_running = false;
}

/**
 * Finds posts whose ACF relationship field contains a specific post ID.
 * Uses exact serialized-integer matching to avoid partial-ID false positives
 * (e.g. searching for ID 5 must not match IDs 15, 25, 50).
 */
function grantee_get_posts_by_acf_rel( $post_type, $meta_key, $related_id ) {
	return get_posts( [
		'post_type'      => $post_type,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [ [
			'key'     => $meta_key,
			'value'   => '"' . (int) $related_id . '"',
			'compare' => 'LIKE',
		] ],
	] );
}

// ── One-time backfill tool ────────────────────────────────────────────────────

add_action( 'admin_init', 'grantee_maybe_run_bidir_sync' );

function grantee_maybe_run_bidir_sync() {
	if ( ! isset( $_GET['sync_grantee_bidir'] ) ) return;
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'grantee_bidir_sync' ) ) {
		$run_url = wp_nonce_url( admin_url( '?sync_grantee_bidir=1' ), 'grantee_bidir_sync' );
		wp_die( '<p>Confirm: <a href="' . esc_url( $run_url ) . '">Run bidirectional sync →</a></p>', 'Grantee Sync' );
	}

	$orgs = get_posts( [
		'post_type'      => 'grantee_org',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	$updated = 0;
	$skipped = 0;
	$log     = [];

	foreach ( $orgs as $org_id ) {
		$award_ids = get_field( 'grantee', $org_id );
		if ( empty( $award_ids ) ) { $skipped++; continue; }

		$award_ids = array_map( 'intval', (array) $award_ids );
		foreach ( $award_ids as $award_id ) {
			update_field( 'grantee_org_ref', [ $org_id ], $award_id );
		}

		$updated++;
		$log[] = [ 'id' => $org_id, 'title' => get_the_title( $org_id ), 'count' => count( $award_ids ) ];
	}

	?><!DOCTYPE html>
	<html><head><meta charset="utf-8"><title>Grantee Sync</title>
	<style>
		*{box-sizing:border-box;margin:0;padding:0}
		body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;padding:40px 20px;color:#1d2327;line-height:1.5}
		.wrap{max-width:860px;margin:0 auto}
		h1{font-size:22px;font-weight:600;margin-bottom:6px}
		p.sub{font-size:13px;color:#666;margin-bottom:28px}
		.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:28px}
		.stat{background:#fff;border:1.5px solid #e2e4e7;border-radius:8px;padding:14px 18px}
		.stat-value{font-size:28px;font-weight:700;color:#00a32a}
		.stat-label{font-size:11px;color:#666;margin-top:2px;text-transform:uppercase;letter-spacing:.04em}
		.banner{padding:14px 20px;border-radius:8px;font-size:14px;font-weight:600;margin-bottom:28px;background:#edfaef;border:1.5px solid #00a32a;color:#00612c}
		table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:8px;overflow:hidden;border:1.5px solid #e2e4e7}
		th{background:#f6f7f7;padding:10px 14px;text-align:left;font-weight:600;font-size:12px;color:#444;border-bottom:1.5px solid #e2e4e7}
		td{padding:10px 14px;border-bottom:1px solid #f0f0f1}
		tr:last-child td{border-bottom:none}
		a{color:#2271b1;text-decoration:none}
		.btn{display:inline-block;padding:10px 20px;border-radius:5px;font-size:13px;font-weight:600;text-decoration:none;background:#fff;color:#646970;border:1.5px solid #c3c4c7;margin-top:24px}
	</style></head><body>
	<div class="wrap">
		<h1>Grantee Bidirectional Sync</h1>
		<p class="sub">Wrote <code>grantee_org_ref</code> onto all linked award posts directly.</p>
		<div class="stats">
			<div class="stat"><div class="stat-value"><?php echo absint( count( $orgs ) ); ?></div><div class="stat-label">Total orgs</div></div>
			<div class="stat"><div class="stat-value"><?php echo absint( $updated ); ?></div><div class="stat-label">Synced</div></div>
			<div class="stat"><div class="stat-value" style="color:#666"><?php echo absint( $skipped ); ?></div><div class="stat-label">No awards</div></div>
		</div>
		<div class="banner">✅ Done — open any award post to confirm Grantee Organization is now populated.</div>
		<?php if ( $log ) : ?>
		<table>
			<thead><tr><th>Org</th><th>Awards</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $log as $r ) : ?>
				<tr><td><?php echo esc_html( $r['title'] ); ?></td><td><?php echo absint( $r['count'] ); ?></td><td><a href="<?php echo esc_url( get_edit_post_link( $r['id'] ) ); ?>" target="_blank">Edit ↗</a></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<a class="btn" href="<?php echo esc_url( admin_url() ); ?>">← Dashboard</a>
	</div></body></html>
	<?php exit;
}
