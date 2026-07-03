<?php
/**
 * Grantee Org — Seed Random US Locations
 *
 * Generates a unique random lat/lng within the continental US
 * for every grantee_org that doesn't already have a location set.
 *
 * Dry run:  /wp-admin/?seed_grantee_locations=1&dry_run=1
 * Live run: /wp-admin/?seed_grantee_locations=1
 * Overwrite all existing: /wp-admin/?seed_grantee_locations=1&overwrite=1
 *
 * Remove from functions.php after use.
 */

add_action( 'admin_init', 'gsl_maybe_run' );

function gsl_maybe_run() {
	if ( ! isset( $_GET['seed_grantee_locations'] ) ) return;
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

	$dry_run   = isset( $_GET['dry_run'] )   && $_GET['dry_run']   === '1';
	$overwrite = isset( $_GET['overwrite'] ) && $_GET['overwrite'] === '1';

	$orgs = get_posts( [
		'post_type'      => 'grantee_org',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );

	$seeded  = 0;
	$skipped = 0;
	$rows    = [];

	foreach ( $orgs as $org ) {
		$existing = get_field( 'grantee_map', $org->ID );

		if ( ! empty( $existing['lat'] ) && ! empty( $existing['lng'] ) && ! $overwrite ) {
			$skipped++;
			$rows[] = [ 'status' => 'skip', 'title' => $org->post_title, 'id' => $org->ID, 'location' => $existing['address'] ?? '—' ];
			continue;
		}

		$loc = gsl_random_us_location();

		if ( ! $dry_run ) {
			update_field( 'grantee_map', [
				'address' => $loc['address'],
				'lat'     => $loc['lat'],
				'lng'     => $loc['lng'],
				'zoom'    => 14,
			], $org->ID );
		}

		$seeded++;
		$rows[] = [ 'status' => 'seeded', 'title' => $org->post_title, 'id' => $org->ID, 'location' => $loc['address'] ];
	}

	$live_url      = admin_url( '?seed_grantee_locations=1' );
	$overwrite_url = admin_url( '?seed_grantee_locations=1&overwrite=1' );
	$dry_url       = admin_url( '?seed_grantee_locations=1&dry_run=1' );
	?>
	<!DOCTYPE html>
	<html>
	<head>
		<meta charset="utf-8">
		<title>Seed Grantee Locations<?php echo $dry_run ? ' — Dry Run' : ''; ?></title>
		<style>
			* { box-sizing: border-box; margin: 0; padding: 0; }
			body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f0f0f1; padding: 40px 20px; color: #1d2327; line-height: 1.5; }
			.wrap { max-width: 900px; margin: 0 auto; }
			h1 { font-size: 22px; font-weight: 600; margin-bottom: 6px; }
			.subtitle { font-size: 13px; color: #666; margin-bottom: 28px; }
			.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 28px; }
			.stat { background: #fff; border: 1.5px solid #e2e4e7; border-radius: 8px; padding: 14px 18px; }
			.stat-value { font-size: 28px; font-weight: 700; color: #1d2327; }
			.stat-label { font-size: 11px; color: #666; margin-top: 2px; text-transform: uppercase; letter-spacing: .04em; }
			table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; border-radius: 8px; overflow: hidden; border: 1.5px solid #e2e4e7; margin-bottom: 28px; }
			th { background: #f6f7f7; padding: 10px 14px; text-align: left; font-weight: 600; font-size: 12px; color: #444; border-bottom: 1.5px solid #e2e4e7; }
			td { padding: 10px 14px; border-bottom: 1px solid #f0f0f1; vertical-align: top; }
			tr:last-child td { border-bottom: none; }
			tr:hover td { background: #fafafa; }
			.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
			.badge-green { background: #edfaef; color: #00612c; }
			.badge-gray  { background: #f0f0f1; color: #646970; }
			.actions { display: flex; gap: 12px; flex-wrap: wrap; }
			.btn { display: inline-block; padding: 10px 20px; border-radius: 5px; font-size: 13px; font-weight: 600; text-decoration: none; }
			.btn-primary { background: #2271b1; color: #fff; }
			.btn-muted { background: #fff; color: #646970; border: 1.5px solid #c3c4c7; }
			.btn:hover { opacity: .85; }
			a { color: #2271b1; text-decoration: none; }
			a:hover { text-decoration: underline; }
		</style>
	</head>
	<body>
	<div class="wrap">
		<h1>Seed Grantee Locations<?php echo $dry_run ? ' — Dry Run' : ''; ?></h1>
		<p class="subtitle"><?php echo $dry_run ? 'Preview only — no data was written.' : 'Unique random US coordinates written to grantee_map field.'; ?></p>

		<div class="stats">
			<div class="stat">
				<div class="stat-value"><?php echo count( $orgs ); ?></div>
				<div class="stat-label">Total orgs</div>
			</div>
			<div class="stat">
				<div class="stat-value"><?php echo $seeded; ?></div>
				<div class="stat-label"><?php echo $dry_run ? 'Would seed' : 'Seeded'; ?></div>
			</div>
			<div class="stat">
				<div class="stat-value"><?php echo $skipped; ?></div>
				<div class="stat-label">Already had location</div>
			</div>
		</div>

		<table>
			<thead>
				<tr><th>Org</th><th>Status</th><th>Coordinates assigned</th></tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>" target="_blank"><?php echo esc_html( $row['title'] ); ?> &#8599;</a></td>
					<td>
						<?php if ( $row['status'] === 'seeded' ) : ?>
							<span class="badge badge-green"><?php echo $dry_run ? 'would seed' : 'seeded'; ?></span>
						<?php else : ?>
							<span class="badge badge-gray">skipped</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $row['location'] ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="actions">
			<?php if ( $dry_run ) : ?>
				<a class="btn btn-primary" href="<?php echo esc_url( $live_url ); ?>">Run for real &rarr;</a>
				<a class="btn btn-muted"   href="<?php echo esc_url( $dry_url  ); ?>">Run dry run again</a>
			<?php else : ?>
				<a class="btn btn-primary" href="<?php echo esc_url( $overwrite_url ); ?>">Re-seed all (overwrite) &rarr;</a>
				<a class="btn btn-muted"   href="<?php echo esc_url( $dry_url );       ?>">Run dry run again</a>
			<?php endif; ?>
		</div>
	</div>
	</body>
	</html>
	<?php
	exit;
}

// Continental US bounding box:
// lat 24.4 (S Florida) to 49.4 (N Montana)
// lng -124.8 (W coast) to -66.9 (E coast)
function gsl_random_us_location() {
	$lat = round( 24.4 + ( mt_rand() / mt_getrandmax() ) * ( 49.4 - 24.4 ), 6 );
	$lng = round( -124.8 + ( mt_rand() / mt_getrandmax() ) * ( -66.9 - ( -124.8 ) ), 6 );
	return [
		'address' => 'United States',
		'lat'     => $lat,
		'lng'     => $lng,
	];
}
