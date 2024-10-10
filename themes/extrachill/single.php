<?php
/**
 * Theme Single Post Section for our theme.
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */

get_header(); ?>

<?php do_action( 'colormag_before_body_content' ); ?>

<section id="primary">

        <?php while ( have_posts() ) : the_post(); ?>

            <?php get_template_part( 'content', 'single' ); ?>

        <?php endwhile; ?>
<aside>

    <?php if ( ! class_exists( 'Auto_Load_Next_Post' ) ) { ?>

	    <?php
		    do_action('extrachill_insert_forum_link', get_the_ID());
    do_action( 'colormag_before_comments_template' );
    // If comments are open or we have at least one comment, load up the comment template
    if ( comments_open() || '0' != get_comments_number() ) {
        comments_template();
    }
    do_action( 'colormag_after_comments_template' );
    ?>
	
        <?php if ( get_theme_mod( 'colormag_related_posts_activate', 0 ) == 1 ) {
            get_template_part( 'inc/related-posts' );
        } ?>

    <?php } ?>
    </aside>
    </section><!-- #primary -->
<?php colormag_sidebar_select(); ?>

<?php do_action( 'colormag_after_body_content' ); ?>

<?php get_footer(); ?>