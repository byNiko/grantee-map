<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Grantee Org Migration
 *
 * Groups all existing wilhelm_grantee posts by title, creates one grantee_org
 * per unique name, and links all matching award posts via the ACF relationship field.
 *
 * Safe to run multiple times — existing orgs are skipped and their relationships re-synced.
 *
 * Dry run:  /wp-admin/?run_grantee_migration=1&dry_run=1
 * Live run: /wp-admin/?run_grantee_migration=1
 */

add_action( 'admin_init', 'gm_maybe_run_migration' );

function gm_maybe_run_migration() {
	if ( ! isset( $_GET['run_grantee_migration'] ) ) return;
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'grantee_migration_run' ) ) {
		gm_render_migration_confirm();
		exit;
	}
	$dry_run = isset( $_GET['dry_run'] ) && $_GET['dry_run'] === '1';
	gm_run_migration( $dry_run );
	exit;
}

function gm_render_migration_confirm() {
	$live_url = wp_nonce_url( admin_url( '?run_grantee_migration=1' ), 'grantee_migration_run' );
	$dry_url  = wp_nonce_url( admin_url( '?run_grantee_migration=1&dry_run=1' ), 'grantee_migration_run' );
	?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Grantee Migration</title>
<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;padding:40px 20px;color:#1d2327}.wrap{max-width:600px;margin:0 auto}h1{font-size:22px;font-weight:600;margin-bottom:10px}p{font-size:14px;color:#555;margin-bottom:28px}.actions{display:flex;gap:12px}.btn{display:inline-block;padding:10px 20px;border-radius:5px;font-size:13px;font-weight:600;text-decoration:none}.btn-primary{background:#2271b1;color:#fff}.btn-muted{background:#fff;color:#646970;border:1.5px solid #c3c4c7}</style>
</head><body><div class="wrap">
	<h1>Grantee Migration</h1>
	<p>This will create <code>grantee_org</code> posts from existing award posts. Run a dry run first to preview changes.</p>
	<div class="actions">
		<a class="btn btn-muted" href="<?php echo esc_url( $dry_url ); ?>">Dry run (preview)</a>
		<a class="btn btn-primary" href="<?php echo esc_url( $live_url ); ?>">Run for real →</a>
	</div>
</div></body></html>
<?php
}

function gm_run_migration( $dry_run = false ) {
	set_time_limit( 300 );

	$log   = [];
	$stats = [ 'orgs_created' => 0, 'orgs_skipped' => 0, 'links_written' => 0 ];

	gm_mlog( $log, $dry_run ? '🔍  DRY RUN — nothing will be written' : '🚀  LIVE RUN — writing to database' );
	gm_mlog( $log, '' );

	$grantees = get_posts( [
		'post_type'      => GM_SOURCE_CPT,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );

	if ( empty( $grantees ) ) {
		gm_mlog( $log, '⚠️  No published ' . GM_SOURCE_CPT . ' posts found.' );
		gm_render_migration( $log, $dry_run, $stats );
		return;
	}

	gm_mlog( $log, sprintf( '📋  Found %d award posts.', count( $grantees ) ) );
	gm_mlog( $log, '' );

	// Group award posts by normalized title → one org per unique name
	$grouped = [];
	foreach ( $grantees as $post ) {
		$key = gm_normalize_title( $post->post_title );
		$grouped[ $key ][] = $post;
	}

	gm_mlog( $log, sprintf( '🏢  %d unique organization names.', count( $grouped ) ) );
	gm_mlog( $log, '' );

	foreach ( $grouped as $posts ) {
		$org_name  = $posts[0]->post_title;
		$child_ids = array_map( 'intval', wp_list_pluck( $posts, 'ID' ) );

		// Check for existing org with this name
		$existing = get_posts( [
			'post_type'              => GM_ORG_CPT,
			'title'                  => $org_name,
			'post_status'            => 'any',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );

		if ( ! empty( $existing ) ) {
			$org_id = $existing[0];
			if ( ! $dry_run ) {
				update_field( GM_ORG_REL_FIELD, $child_ids, $org_id );
			}
			gm_mlog( $log, sprintf( '⏭   SKIP    "%s" (ID %d) — %d award%s re-linked', $org_name, $org_id, count( $child_ids ), count( $child_ids ) !== 1 ? 's' : '' ) );
			$stats['orgs_skipped']++;
			$stats['links_written'] += count( $child_ids );
			continue;
		}

		if ( ! $dry_run ) {
			$org_id = wp_insert_post( [
				'post_type'   => GM_ORG_CPT,
				'post_title'  => $org_name,
				'post_status' => 'publish',
			], true );

			if ( is_wp_error( $org_id ) ) {
				gm_mlog( $log, sprintf( '❌  ERROR creating "%s" — %s', $org_name, $org_id->get_error_message() ) );
				continue;
			}

			update_field( GM_ORG_REL_FIELD, $child_ids, $org_id );
		} else {
			$org_id = '(new)';
		}

		$cycles = gm_get_grant_cycles( $child_ids );
		gm_mlog( $log, sprintf(
			'✅  CREATED  "%s" (ID: %s) ← %d award%s [cycles: %s]',
			$org_name, $org_id,
			count( $child_ids ), count( $child_ids ) !== 1 ? 's' : '',
			implode( ', ', $cycles ) ?: '—'
		) );
		$stats['orgs_created']++;
		$stats['links_written'] += count( $child_ids );
	}

	gm_mlog( $log, '' );
	gm_mlog( $log, sprintf( '✅  Orgs created:    %d', $stats['orgs_created'] ) );
	gm_mlog( $log, sprintf( '⏭   Orgs skipped:    %d', $stats['orgs_skipped'] ) );
	gm_mlog( $log, sprintf( '🔗  Awards linked:   %d', $stats['links_written'] ) );

	if ( ! $dry_run ) {
		gm_mlog( $log, '' );
		gm_mlog( $log, '📍  Organizations created. Next step: open each org and add its map location.' );
	}

	gm_render_migration( $log, $dry_run, $stats );
}

function gm_get_grant_cycles( $post_ids ) {
	$years = [];
	foreach ( $post_ids as $id ) {
		$terms = get_the_terms( $id, 'grant-cycle' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) $years[] = $term->name;
		}
	}
	return array_unique( array_filter( $years ) );
}

function gm_normalize_title( $str ) {
	return strtolower( trim( preg_replace( '/\s+/', ' ', $str ) ) );
}

function gm_mlog( &$log, $message ) {
	$log[] = $message;
}

function gm_render_migration( $log, $dry_run, $stats ) {
	$title    = $dry_run ? 'Grantee Migration — Dry Run' : 'Grantee Migration — Complete';
	$live_url = wp_nonce_url( admin_url( '?run_grantee_migration=1' ), 'grantee_migration_run' );
	$dry_url  = wp_nonce_url( admin_url( '?run_grantee_migration=1&dry_run=1' ), 'grantee_migration_run' );
	$orgs_url = admin_url( 'edit.php?post_type=' . GM_ORG_CPT );
	?><!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title><?php echo esc_html( $title ); ?></title>
	<style>
		*{box-sizing:border-box;margin:0;padding:0}
		body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;padding:40px 20px;color:#1d2327;line-height:1.5}
		.wrap{max-width:900px;margin:0 auto}
		h1{font-size:22px;font-weight:600;margin-bottom:6px}
		.sub{font-size:13px;color:#666;margin-bottom:28px}
		.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:28px}
		.stat{background:#fff;border:1.5px solid #e2e4e7;border-radius:8px;padding:16px 20px}
		.stat-value{font-size:28px;font-weight:700}
		.stat-label{font-size:12px;color:#666;margin-top:3px}
		.log{background:#1e1e1e;color:#d4d4d4;border-radius:8px;padding:24px 28px;font-family:"SF Mono","Fira Code",monospace;font-size:12.5px;line-height:1.9;overflow-x:auto;margin-bottom:28px;white-space:pre-wrap;word-break:break-word}
		.actions{display:flex;gap:12px;flex-wrap:wrap}
		.btn{display:inline-block;padding:10px 20px;border-radius:5px;font-size:13px;font-weight:600;text-decoration:none}
		.btn-primary{background:#2271b1;color:#fff}
		.btn-muted{background:#fff;color:#646970;border:1.5px solid #c3c4c7}
	</style>
</head>
<body>
<div class="wrap">
	<h1><?php echo esc_html( $title ); ?></h1>
	<p class="sub"><?php echo $dry_run ? 'Preview only — no data was written.' : 'Done. Each org still needs its map location added manually.'; ?></p>

	<div class="stats">
		<div class="stat"><div class="stat-value"><?php echo intval( $stats['orgs_created'] ); ?></div><div class="stat-label">Orgs created</div></div>
		<div class="stat"><div class="stat-value"><?php echo intval( $stats['orgs_skipped'] ); ?></div><div class="stat-label">Already existed</div></div>
		<div class="stat"><div class="stat-value"><?php echo intval( $stats['links_written'] ); ?></div><div class="stat-label">Awards linked</div></div>
	</div>

	<div class="log"><?php echo esc_html( implode( "\n", $log ) ); ?></div>

	<div class="actions">
		<?php if ( ! $dry_run ) : ?>
			<a class="btn btn-primary" href="<?php echo esc_url( $orgs_url ); ?>">View Organizations →</a>
			<a class="btn btn-muted"   href="<?php echo esc_url( $dry_url );  ?>">Run dry run again</a>
		<?php else : ?>
			<a class="btn btn-primary" href="<?php echo esc_url( $live_url ); ?>">Run for real →</a>
			<a class="btn btn-muted"   href="<?php echo esc_url( $dry_url );  ?>">Run dry run again</a>
		<?php endif; ?>
	</div>
</div>
</body>
</html>
<?php
}
