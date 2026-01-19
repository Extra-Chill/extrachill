<?php
/**
 * Fallback Template - Emergency fallback only
 *
 * This file should rarely be reached. Template routing is handled by
 * inc/core/template-router.php via WordPress's template_include filter.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

get_header();
?>

	<?php do_action( 'extrachill_before_body_content' ); ?>

	<section class="main-content">
		<section class="error-404 not-found">
			<div class="page-content">
				<header class="page-header">
					<h1 class="page-title"><?php echo esc_html( apply_filters( 'extrachill_fallback_error_heading', __( 'Something went wrong.', 'extrachill' ) ) ); ?></h1>
				</header>
				<p><?php esc_html_e( 'This is the last resort fallback template. You should not be seeing this.', 'extrachill' ); ?></p>
				<p><strong><?php esc_html_e( 'You were trying to reach:', 'extrachill' ); ?></strong> <code><?php echo esc_html( home_url( $_SERVER['REQUEST_URI'] ) ); ?></code></p>
				<p><?php esc_html_e( 'Help us fix this by telling us how you got here.', 'extrachill' ); ?></p>
				<p><?php do_action( 'extrachill_fallback_below_cta' ); ?></p>
			</div><!-- .page-content -->
		</section><!-- .error-404 -->
	</section><!-- .main-content -->

	<?php get_sidebar(); ?>

	<?php do_action( 'extrachill_after_body_content' ); ?>

<?php
get_footer();
?>
