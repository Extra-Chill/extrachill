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
                    <h1 class="page-title"><?php _e( "Well, that's not very chill of us.", 'extrachill' ); ?></h1>
                </header>
                <p><?php _e( "We can't find what you're looking for. Try a search instead.", 'extrachill' ); ?></p>
                <?php extrachill_search_form(); ?>
                <?php $main_site_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'main' ) : home_url(); ?>
                 <p><?php _e( 'Think this page should exist?', 'extrachill' ); ?> <a href="<?php echo esc_url( $main_site_url ); ?>/contact/"><?php _e( 'Let us know.', 'extrachill' ); ?></a></p>
                 <div class="error-404-links">
                     <a href="<?php echo esc_url( ec_get_site_url( 'docs' ) ); ?>" class="button-2 button-medium"><?php _e( 'Browse Documentation', 'extrachill' ); ?></a>
                     <a href="<?php echo esc_url( ec_get_site_url( 'community' ) . '/r/tech-support' ); ?>" class="button-2 button-medium"><?php _e( 'Tech Support Forum', 'extrachill' ); ?></a>
                 </div>
            </div><!-- .page-content -->
        </section><!-- .error-404 -->
     </section><!-- .main-content -->

     <?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>
