<?php
/**
 * The template used for displaying post content in archives
 *
 * @package ThemeGrill
 * @subpackage ColorMag
 * @since ColorMag 1.0
 */

$featured_image_size = 'medium_large'; // Using WordPress' medium_large size for better image quality.

$locations = get_the_terms( get_the_ID(), 'location' );
$location_classes = '';
if ( ! is_wp_error( $locations ) && ! empty( $locations ) ) {
    foreach ( $locations as $location ) {
        $location_classes .= ' location-' . sanitize_html_class( $location->slug );
    }
}
?>

<?php if ( is_archive() || is_search() ) : ?>
<div class="archive-card<?php echo esc_attr( $location_classes ); ?>">
<?php endif; ?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <?php if ( is_archive() || is_search() ) : ?>
    <div class="taxonomy-badges" style="position: relative; z-index: 2;">
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
    <?php endif; ?>
    <?php if ( ! has_post_format( array( 'gallery', 'video' ) ) ) : ?>
        <?php if ( has_post_thumbnail() ) { ?>
            <div class="featured-image">
                <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                    <?php the_post_thumbnail( 'medium_large' ); ?>
                </a>
            </div>

            <?php if ( ( get_theme_mod( 'colormag_featured_image_caption_show', 0 ) == 1 ) && ( get_post( get_post_thumbnail_id() ) -> post_excerpt ) ) { ?>
                <span class="featured-image-caption">
                    <?php echo wp_kses_post( get_post( get_post_thumbnail_id() ) -> post_excerpt ); ?>
                </span>
            <?php } ?>
        <?php } ?>
    <?php endif; ?>

    <div class="archive-post">
        <header class="entry-header" id="wild-vote">

            <div class="title-upvote-row">
                <?php if ( get_post_type() === 'post' ) : ?>
                    <div class="upvote">
                        <?php if ( isset( $post->_is_forum_post ) && $post->_is_forum_post ) : ?>
                            <span class="upvote-icon" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo wp_create_nonce('upvote_nonce'); ?>" data-community-user-id="">
                                <svg>
                                    <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg?v1.3#circle-up-regular"></use>
                                </svg>
                            </span>
                            <span class="upvote-count">
                                <?php 
                                $forum_upvotes = isset( $post->_upvotes ) ? intval( $post->_upvotes ) : 0; 
                                echo $forum_upvotes + 1; 
                                ?>
                            </span>
                        <?php else : ?>
                            <span class="upvote-icon" data-post-id="<?php the_ID(); ?>" data-nonce="<?php echo wp_create_nonce('upvote_nonce'); ?>" data-community-user-id="">
                                <svg>
                                    <use href="/wp-content/themes/colormag-pro/fonts/fontawesome.svg?v1.3#circle-up-regular"></use>
                                </svg>
                            </span>
                            <span class="upvote-count"><?php echo get_upvote_count( get_the_ID() ); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <h2 class="entry-title" style="margin: 0; position: relative;">
                    <a href="<?php the_permalink(); ?>" class="card-link-target" title="<?php the_title_attribute(); ?>"><?php the_title(); ?></a>
                </h2>
            </div>
        </header>

        <?php if ( get_post_type() === 'product' ) : ?>
            <div class="product-details">
                <span class="woocommerce-category-price"> 
                    <div class="product-category">
                        <?php
                        $product_cats = wp_get_post_terms( get_the_ID(), 'product_cat' );
                        if ( ! empty( $product_cats ) && ! is_wp_error( $product_cats ) ) {
                            foreach ( $product_cats as $cat ) {
                                echo '<a class="product-category-link" href="' . esc_url( get_term_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a>';
                            }
                        }
                        ?>
                    </div>
                    <div class="product-price">
                        <?php echo wc_get_product( get_the_ID() )->get_price_html(); ?>
                    </div>
                </span>
            </div>
        <?php endif; ?>

        <?php extrachill_entry_meta(); ?>

        <?php if ( isset( $post->_is_forum_post ) && $post->_is_forum_post ) : ?>
            <div class="archive-excerpt">
                <?php
                $excerpt = $post->post_excerpt;
                $search_term = get_query_var( 's' );
                echo wp_kses_post( highlight_search_term( $excerpt, $search_term ) );
                ?>
            </div>
            <span>
                <a href="<?php echo esc_url( get_permalink( $post ) ); ?>" class="location-link" id="forum-post" target="_blank" rel="noopener noreferrer">View in Community</a>
            </span>
        <?php elseif ( get_post_type() === 'product' ) : ?>
            <div class="archive-excerpt">
                <?php
                $excerpt = get_the_excerpt();
                $search_term = get_query_var( 's' );
                $highlighted_excerpt = highlight_search_term( $excerpt, $search_term );
                echo wp_kses_post( $highlighted_excerpt . '...' );
                ?>
            </div>
            <div class="woocommerce add-to-cart-button">
                <?php woocommerce_template_loop_add_to_cart(); ?>
            </div>
        <?php else : ?>
            <div class="archive-excerpt">
    <?php
    $content = get_the_content(); // Use full post content
    $content = preg_replace('/<figcaption\b[^>]*>(.*?)<\/figcaption>/i', '', $content); // Remove <figcaption> elements
    $search_term = get_query_var('s');

    // Use the contextual excerpt for regular posts on search pages
    if (is_search() && $search_term) {
        echo wp_kses_post(ec_get_contextual_excerpt(wp_strip_all_tags($content), $search_term, 30));
    } else {
        echo wp_kses_post(wp_trim_words($content, 30, '...'));
    }
    ?>
</div>

            <span>
        <a href="<?php the_permalink(); ?>" class="read-more-button" target="_self" rel="noopener noreferrer">View Full Post</a>
    </span>
        <?php endif; ?>
    </div>

    <?php do_action( 'extrachill_after_post_content' ); ?>
</article>

<?php if ( is_archive() || is_search() ) : ?>
</div>
<?php endif; ?>
