<?php

/**
 * Template Name: ExtraChill Notifications
 *
 * This is the template that displays the notifications page.
 */

get_header(); ?>
<div <?php generate_do_attr( 'page' ); ?>>
    <?php
    /**
     * generate_inside_site_container hook.
     */
    do_action( 'generate_inside_site_container' );
    ?>
    <div <?php generate_do_attr( 'site-content' ); ?>>
        <?php
        /**
         * generate_inside_container hook.
         */
        do_action( 'generate_inside_container' );
        ?>
        <?php extrachill_breadcrumbs(); ?>

<div id="primary" class="content-area">
    
    <main id="main" class="site-main">

        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
            <header class="entry-header">
                <h1 class="entry-title">Notifications</h1>
            </header><!-- .entry-header -->

            <div class="entry-content">
                <?php
                // Display notifications
                extrachill_display_notifications();
                ?>
            </div><!-- .entry-content -->
        </article><!-- #post-<?php the_ID(); ?> -->

    </main><!-- #main -->
</div><!-- #primary -->

        </div><!-- .site-content -->
    </div><!-- .page -->
<?php
get_footer();
