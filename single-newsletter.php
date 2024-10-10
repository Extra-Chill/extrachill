<?php
/**
 * Template for displaying single newsletter posts.
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 1.0
 */

get_header(); ?>

<section id="primary" class="content-area">
    <main id="main" class="site-main">

        <?php
        while ( have_posts() ) :
            the_post();

            get_template_part( 'content', 'newsletter' );

            // If comments are open or there is at least one comment, load up the comment template.
            if ( comments_open() || get_comments_number() ) :
                comments_template();
            endif;

        endwhile; // End of the loop.
        ?>

    </main><!-- #main -->
</section><!-- #primary -->

<?php
get_sidebar();
get_footer();
?>
