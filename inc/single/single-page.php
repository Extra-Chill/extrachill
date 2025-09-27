<?php
/**
 * Complete Single Page Template
 *
 * Handles the complete single page experience: header, content, comments, footer.
 * Replaces theme root page.php via template override system.
 *
 * @package    ExtraChill
 * @since      1.0
 */

get_header(); ?>

<?php do_action( 'extrachill_before_body_content' ); ?>

<?php while ( have_posts() ) : the_post(); ?>

    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
        <?php do_action( 'extrachill_before_post_content' ); ?>

        <?php
        extrachill_breadcrumbs();
        ?>
        <header class="entry-header">
            <h1 class="entry-title">
                <?php the_title(); ?>
            </h1>
        </header>

        <div class="entry-content">
            <?php
            the_content();

            wp_link_pages( array(
                'before'      => '<div style="clear: both;"></div><div class="pagination clearfix">' . __( 'Pages:', 'extrachill' ),
                'after'       => '</div>',
                'link_before' => '<span>',
                'link_after'  => '</span>',
            ) );
            ?>
        </div>

        <div class="entry-footer">
            <?php
            // Edit button always shown - option removed
            edit_post_link( __( 'Edit', 'extrachill' ), '<span class="edit-link">', '</span>' );
            ?>
        </div>

        <?php do_action( 'extrachill_after_post_content' ); ?>
    </article>

<?php endwhile; ?>

</div><!-- #primary -->

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>