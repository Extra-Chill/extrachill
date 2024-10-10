<?php
/**
 * The template used for displaying post content in newsletters archive
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 1.0
 */

// Initialize the variables with an empty string as a default value.
$reading_time = '';

$featured_image_size = 'colormag-featured-image';
$class_name_layout_two = '';
if ( get_theme_mod( 'colormag_archive_search_layout', 'double_column_layout' ) == 'single_column_layout' ) {
    $featured_image_size = 'colormag-featured-post-medium';
    $class_name_layout_two = 'archive-layout-two';
} elseif ( get_theme_mod( 'colormag_archive_search_layout', 'double_column_layout' ) == 'full_width_layout' ) {
    $class_name_layout_two = 'archive-layout-full-width';
} elseif ( get_theme_mod( 'colormag_archive_search_layout', 'double_column_layout' ) == 'grid_layout' ) {
    $featured_image_size = 'colormag-featured-post-medium';
    $class_name_layout_two = 'archive-layout-grid';
}
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( array( $class_name_layout_two ) ); ?>>
    <!-- Removed schema markup function call -->
    <?php if ( ! has_post_format( array( 'gallery', 'video' ) ) ) : ?>
        <?php if ( has_post_thumbnail() ) { ?>
            <div class="featured-image">
                <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                    <?php the_post_thumbnail( $featured_image_size ); ?>
                </a>
            </div>
            <?php if ( get_theme_mod( 'colormag_featured_image_caption_show', 0 ) == 1 && get_post( get_post_thumbnail_id() )->post_excerpt ) { ?>
                <span class="featured-image-caption">
                    <?php echo wp_kses_post( get_post( get_post_thumbnail_id() )->post_excerpt ); ?>
                </span>
            <?php } ?>
        <?php } ?>
    <?php endif; ?>

    <?php if ( get_post_format() ) {
        get_template_part( 'inc/post-formats' );
    } ?>
    <div class="newsletter-archive">
        <header class="entry-header">
            <?php if ( is_single() ) : ?>
                <div class="breadcrumbs">
                    <a href="<?php echo esc_url( home_url( '/newsletters' ) ); ?>">Newsletters</a>
                </div>
            <?php endif; ?>
            <h2 class="entry-title">
                <?php the_title(); ?>
            </h2><br>
            <span class="below-entry-meta">Sent on <?php echo get_the_date(); ?></span>
        </header>
        
        <?php if ( is_single() ) : ?>
            <div class="entry-content">
                <?php
                the_content();
                wp_link_pages( array(
                    'before' => '<div class="page-links">' . __( 'Pages:', 'colormag' ),
                    'after'  => '</div>',
                ) );
                ?>
            </div><!-- .entry-content -->
        <?php endif; ?>

        <footer class="entry-footer">
            <?php colormag_entry_meta(); ?>
            <?php edit_post_link( __( 'Edit', 'colormag' ), '<span class="edit-link">', '</span>' ); ?>
        </footer><!-- .entry-footer -->
    </div>
</article>

<?php do_action( 'colormag_after_post_content' ); ?>


<?php do_action( 'colormag_after_post_content' ); ?>
