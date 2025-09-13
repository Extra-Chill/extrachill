<?php
/**
 * The template used for displaying post content in newsletters archive
 *
 * @package ExtraChill
 * @since 1.0
 */

// Initialize the variables with an empty string as a default value.
$reading_time = '';

$featured_image_size = 'full';
$class_name_layout_two = '';
// Archive layout options removed - not used in current theme
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( array( $class_name_layout_two ) ); ?>>
    <!-- Removed schema markup function call -->
    <?php if ( ! has_post_format( array( 'gallery', 'video' ) ) ) : ?>
        <?php if ( has_post_thumbnail() ) { ?>
            <div class="featured-image">
                <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                    <?php the_post_thumbnail( 'large' ); ?>
                </a>
            </div>
            <?php /* Featured image caption functionality removed - not used */ ?>
        <?php } ?>
    <?php endif; ?>

    <?php if ( get_post_format() ) {
        get_template_part( 'inc/post-formats' );
    } ?>
                <div class="breadcrumbs">
                    <a href="<?php echo esc_url( home_url( '/newsletters' ) ); ?>">Newsletters</a>
                </div>
        <header class="entry-header">
            <?php if ( is_single() ) : ?>
            <?php endif; ?>
            <h1 class="entry-title">
                <?php the_title(); ?>
            </h1><br>
            <span class="below-entry-meta">Sent on <?php echo get_the_date(); ?></span>
        </header>
        
        <?php if ( is_single() ) : ?>
            <div class="entry-content">
                <?php
                the_content();
                wp_link_pages( array(
                    'before' => '<div class="page-links">' . __( 'Pages:', 'extrachill' ),
                    'after'  => '</div>',
                ) );
                ?>
            </div><!-- .entry-content -->
        <?php endif; ?>

        <footer class="entry-footer">
            <?php extrachill_entry_meta(); ?>
            <?php edit_post_link( __( 'Edit', 'extrachill' ), '<span class="edit-link">', '</span>' ); ?>
        </footer><!-- .entry-footer -->
</article>

<?php do_action( 'extrachill_after_post_content' ); ?>