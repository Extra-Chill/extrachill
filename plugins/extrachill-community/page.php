<?php
/**
 * The template for displaying all pages.
 *
 * This is the template that displays all pages by default.
 * Please note that this is the WordPress construct of pages
 * and that other 'pages' on your WordPress site will use a
 * different template.
 *
 * @package Extra ChillCommunity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

get_header(); 

// Call the custom breadcrumbs function
if ( function_exists( 'extrachill_breadcrumbs' ) ) {
    extrachill_breadcrumbs();
}
?>

	<div class="content-area">
		<main class="main-content">
			<?php
			/**
			 * Custom hook before main content.
			 */
			do_action( 'extra_chill_before_main_content' );

			if ( have_posts() ) {
				while ( have_posts() ) :
					the_post();
					?>
					<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
						<header class="entry-header">
							<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
						</header>

						<div class="entry-content">
							<?php
							the_content();
							wp_link_pages( array(
								'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'extra-chill-community' ),
								'after'  => '</div>',
							) );
							?>
						</div>
					</article>
					<?php
				endwhile;
			}

			/**
			 * Custom hook after main content.
			 */
			do_action( 'extra_chill_after_main_content' );
			?>
		</main>
	</div>

	<?php
	/**
	 * Custom hook after primary content area.
	 */
	do_action( 'extra_chill_after_primary_content_area' );

	get_footer();
?>
