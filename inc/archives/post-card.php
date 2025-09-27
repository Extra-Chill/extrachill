<?php
/**
 * Archive Post Card Template
 *
 * Displays post cards for archives, search results, and post listings.
 * Clean template with no context conditionals - purely for card display.
 *
 * @package ExtraChill
 * @since 1.0
 */

$featured_image_size = 'medium_large';
?>

<div class="archive-card">
    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
        <?php
        extrachill_display_taxonomy_badges( get_the_ID(), array(
            'wrapper_style' => 'position: relative; z-index: 2;'
        ) );
        ?>

        <?php if ( has_post_thumbnail() ) { ?>
            <div class="featured-image">
                <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                    <?php the_post_thumbnail( 'medium_large' ); ?>
                </a>
            </div>
        <?php } ?>

        <div class="archive-post">
            <header class="entry-header">
                <h2 class="entry-title" style="margin: 0; position: relative;">
                    <a href="<?php the_permalink(); ?>" class="card-link-target" title="<?php the_title_attribute(); ?>"><?php the_title(); ?></a>
                </h2>
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
                            <?php
                            echo extrachill_get_product_price_html( get_the_ID() );
                            ?>
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
                    <?php
                    extrachill_render_add_to_cart_button();
                    ?>
                </div>
            <?php else : ?>
                <div class="archive-excerpt">
                    <?php
                    $content = get_the_excerpt();
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
</div>