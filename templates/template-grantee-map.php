<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Template Name: Grantee Map
 *
 * @package Wilhelm Family Foundation
 */

get_header();
if ( have_posts() ) while ( have_posts() ) : the_post(); ?>

<main role="main">
	<article id="page-<?php the_ID(); ?>" <?php post_class( 'inner' ); ?>>

		<header id="page-header">
			<?php the_title( '<h2 class="page--title">', '</h2>' ); ?>
		</header>

		<?php the_content(); ?>

	</article>
</main>

<?php endwhile;
get_footer();
