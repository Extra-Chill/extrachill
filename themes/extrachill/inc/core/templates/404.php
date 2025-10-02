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

    <section id="primary">
        <section class="error-404 not-found">
            <div class="page-content">
                <header class="page-header">
                    <h1 class="page-title"><?php _e( 'Well, that’s not very chill of us.', 'extrachill' ); ?></h1>
                </header>
                <p><?php _e( 'We can’t find what you’re looking for. Try a search instead.', 'extrachill' ); ?></p>
                <?php extrachill_search_form(); ?>
            </div><!-- .page-content -->
        </section><!-- .error-404 -->
    </section><!-- #primary -->

    <?php get_sidebar(); ?>

    <?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
