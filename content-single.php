<?php
/**
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */

?>
<?php if ( is_singular('post') ) : ?>
    <?php 
    $show_breadcrumbs = false;
    if (get_theme_mod('colormag_single_post_title_position', 'below') == 'above') {
        $show_breadcrumbs = true;
    }
    if (get_theme_mod('colormag_single_post_title_position', 'below') == 'below') {
        $show_breadcrumbs = true;
    }
    if ($show_breadcrumbs) {
        display_post_breadcrumbs();
    }
    ?>
    <?php
    $locations = get_the_terms( get_the_ID(), 'location' );
    $location_classes = '';
    if ( ! is_wp_error( $locations ) && ! empty( $locations ) ) {
        foreach ( $locations as $location ) {
            $location_classes .= ' location-' . sanitize_html_class( $location->slug );
        }
    }
    ?>
    <div class="single-post-card<?php echo esc_attr( $location_classes ); ?>">
<?php endif; ?>
<article id="post-<?php the_ID(); ?>">
    <?php do_action('extrachill_before_post_content'); ?>

    <?php if (get_theme_mod('colormag_single_post_title_position', 'below') == 'above') { ?>
        <div class="single-title-above">
            <?php if (is_singular('post')) : ?>
                <?php /* Breadcrumb moved above card */ ?>
            <?php endif; ?>

            <header class="entry-header">
                <h1 class="entry-title">
                    <?php the_title(); ?>
                </h1>
            </header>

            <?php extrachill_entry_meta(); ?>
        </div>
    <?php } ?>

    <?php
    $image_popup_id = get_post_thumbnail_id();
    $image_popup_url = wp_get_attachment_url($image_popup_id);
    ?>

    <?php if (get_post_format()) {
        get_template_part('inc/post-formats');
    } ?>

    <?php if (get_theme_mod('colormag_single_post_title_position', 'below') == 'below') { ?>
        <?php if (is_singular('post')) : ?>
            <?php /* Breadcrumb moved above card */ ?>
            <header class="entry-header" id="postvote">
                <div class="taxonomy-badges">
                    <?php
                    $categories = get_the_category();
                    if ( ! empty( $categories ) ) {
                        foreach ( $categories as $cat ) {
                            $cat_slug = sanitize_html_class( $cat->slug );
                            echo '<a href="' . esc_url( get_category_link( $cat->term_id ) ) . '" class="taxonomy-badge category-badge category-' . $cat_slug . '-badge">' . esc_html( $cat->name ) . '</a>';
                        }
                    }
                    $locations = get_the_terms( get_the_ID(), 'location' );
                    if ( ! is_wp_error( $locations ) && ! empty( $locations ) ) {
                        foreach ( $locations as $location ) {
                            echo '<a href="' . esc_url( get_term_link( $location ) ) . '" class="taxonomy-badge location-badge">' . esc_html( $location->name ) . '</a>';
                        }
                    }
                    ?>
                </div>
                <div class="upvote">
                    <span class="upvote-icon" data-post-id="<?php the_ID(); ?>" data-nonce="<?php echo wp_create_nonce('upvote_nonce'); ?>" data-community-user-id="">
                        <svg>
                            <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg?v1.3#circle-up-regular"></use>
                        </svg>
                    </span>
                    <span class="upvote-count"><?php echo get_upvote_count(get_the_ID()); ?></span>
                </div>
                <h1 class="entry-title">
                    <?php the_title(); ?>
                </h1>
            </header>
            <?php extrachill_entry_meta(); ?>
        <?php endif; ?>
    <?php } ?>

    <div class="entry-content">
        <?php
        the_content();

        wp_link_pages(array(
            'before'      => '<div style="clear: both;"></div><div class="pagination clearfix">' . __('Pages:', 'colormag-pro'),
            'after'       => '</div>',
            'link_before' => '<span>',
            'link_after'  => '</span>',
        ));
        ?>
    </div>

    <?php do_action('extrachill_after_post_content'); ?>
</article>
<?php if ( is_singular('post') ) : ?>
</div>
<?php endif; ?>
