<?php
/**
 * The template for displaying 404 pages (Page Not Found).
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 1.0
 */

get_header(); ?>

    <?php do_action( 'extrachill_before_body_content' ); ?>

    <section id="primary">
        <section class="error-404 not-found">
            <div class="page-content">
                <?php if ( ! dynamic_sidebar( 'colormag_error_404_page_sidebar' ) ) : ?>
                    <header class="page-header">
                        <h1 class="page-title"><?php _e( 'Well, that’s not very chill of us.', 'colormag-pro' ); ?></h1>
                    </header>
                    <p><?php _e( 'We can’t find what you’re looking for. Try a search instead.', 'colormag-pro' ); ?></p>
                    <?php get_search_form(); ?>
                <?php endif; ?>
            </div><!-- .page-content -->
        </section><!-- .error-404 -->
    </section><!-- #primary -->

    <?php get_sidebar(); ?>

    <?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
