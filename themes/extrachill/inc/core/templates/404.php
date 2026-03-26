<?php
/**
 * 404 Error page template with ExtraChill branding
 *
 * Displays custom 404 message with search form integration.
 * Uses theme action hooks for extensible content sections.
 *
 * @package ExtraChill
 * @since 1.0
 */

get_header(); ?>

	<?php do_action( 'extrachill_before_body_content' ); ?>

	<section class="main-content">
		<section class="error-404 not-found">
			<?php extrachill_breadcrumbs(); ?>
			<div class="page-content">
				<header class="page-header">
					<h1 class="page-title"><?php echo esc_html( apply_filters( 'extrachill_404_heading', __( 'Page Not Found', 'extrachill' ) ) ); ?></h1>
				</header>
				<p><?php echo esc_html( apply_filters( 'extrachill_404_message', __( 'The page you\'re looking for doesn\'t exist.', 'extrachill' ) ) ); ?></p>
				<?php extrachill_search_form(); ?>
				<?php do_action( 'extrachill_404_content_links' ); ?>
			</div><!-- .page-content -->
		</section><!-- .error-404 -->
	</section><!-- .main-content -->

	<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
