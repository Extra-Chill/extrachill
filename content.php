<?php
/**
 * The template used for displaying page content in page.php
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 1.0
 */

$featured_image_size = 'medium_large'; // Using WordPress' medium_large size for better image quality.
$class_name_layout_two = '';

// Determine which layout is active and adjust styling accordingly.
if ( get_theme_mod( 'colormag_archive_search_layout', 'double_column_layout' ) == 'single_column_layout' ) {
    $class_name_layout_two = 'archive-layout-two';
} elseif ( get_theme_mod( 'colormag_archive_search_layout', 'double_column_layout' ) == 'full_width_layout' ) {
    $class_name_layout_two = 'archive-layout-full-width';
} elseif ( get_theme_mod( 'colormag_archive_search_layout', 'double_column_layout' ) == 'grid_layout' ) {
    $class_name_layout_two = 'archive-layout-grid';
}
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( array( $class_name_layout_two ) ); ?><?php echo colormag_schema_markup( 'entry' ); ?>> 
    <?php if ( ! has_post_format( array( 'gallery', 'video' ) ) ) : ?>
    <?php if ( has_post_thumbnail() ) { ?>
        <div class="featured-image">
            <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>"><?php the_post_thumbnail( $featured_image_size ); ?></a>
        </div>

        <?php if ( ( get_theme_mod( 'colormag_featured_image_caption_show', 0 ) == 1 ) && ( get_post( get_post_thumbnail_id() ) -> post_excerpt ) ) { ?>
            <span class="featured-image-caption">
                <?php echo wp_kses_post( get_post( get_post_thumbnail_id() ) -> post_excerpt ); ?>
            </span>
        <?php } ?>
    <?php } ?>
    <?php endif; ?>

    <?php if ( get_post_format() ) {
        get_template_part( 'inc/post-formats' );
    } ?>
    <div class="archive-post">
        <header class="entry-header" id="wild-vote">
            <div class="upvote">
                <span class="upvote-icon" data-post-id="<?php the_ID(); ?>" data-nonce="<?php echo wp_create_nonce('upvote_nonce'); ?>" data-community-user-id="">
                    <svg>
                        <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg?v1.3#circle-up-regular"></use>
                    </svg>
                </span>
                <span class="upvote-count"><?php echo get_upvote_count(get_the_ID()); ?></span> | 
            </div>

            <h2 class="entry-title"<?php echo colormag_schema_markup( 'entry_title' ); ?>>
                <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>"><?php the_title(); ?></a>
            </h2>
        </header>
        <?php colormag_entry_meta(); ?>

        <div class="post-location-meta">
            <!-- Display categories -->
            <span class="post-categories">
                <?php the_category(' '); ?>
            </span>

            <!-- Display location -->
            <span class="post-location">
                <?php
                // Get the first location term associated with the post
                $locations = get_the_terms( get_the_ID(), 'location' );
                if ( ! is_wp_error( $locations ) && ! empty( $locations ) ) {
                    foreach ( $locations as $location ) {
                        echo '<a href="' . esc_url( get_term_link( $location ) ) . '">' . esc_html( $location->name ) . '</a>';
                        break; // Only show the first location
                    }
                }
                ?>
            </span>
        </div>
    </div>
    <?php do_action( 'colormag_after_post_content' ); ?>
</article>
