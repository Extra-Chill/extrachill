<?php
/**
 * Post Card Template
 *
 * Displays post card with featured image, title, meta, and excerpt.
 * Supports forum posts from multisite search results.
 *
 * @package ExtraChill
 * @since 69.58
 */

$featured_image_size = 'medium_large';
?>

<div class="archive-card">
    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
        <?php do_action( 'extrachill_archive_above_tax_badges' ); ?>
        <?php
        extrachill_display_taxonomy_badges( get_the_ID(), array(
            'wrapper_style' => 'position: relative; z-index: 2;'
        ) );
        ?>

        <?php if ( has_post_thumbnail() ) { ?>
            <div class="featured-image">
                <a href="<?php echo isset($post->permalink) ? esc_url($post->permalink) : the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                    <?php the_post_thumbnail( 'medium_large' ); ?>
                </a>
            </div>
        <?php } ?>

        <div class="archive-post">
            <header class="entry-header">
                <h2 class="entry-title" style="margin: 0; position: relative;">
                    <a href="<?php echo isset($post->permalink) ? esc_url($post->permalink) : the_permalink(); ?>" class="card-link-target" title="<?php the_title_attribute(); ?>"><?php the_title(); ?></a>
                </h2>
            </header>

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
                    <a href="<?php echo isset($post->permalink) ? esc_url($post->permalink) : esc_url(get_permalink($post)); ?>" class="location-link" id="forum-post" target="_blank" rel="noopener noreferrer">View in Community</a>
                </span>
            <?php else : ?>
                <div class="archive-excerpt">
                    <?php echo wp_kses_post(wp_trim_words(get_the_excerpt(), 30, '...')); ?>
                </div>

                <span>
                    <a href="<?php echo isset($post->permalink) ? esc_url($post->permalink) : the_permalink(); ?>" class="read-more-button" target="_self" rel="noopener noreferrer">View Full Post</a>
                </span>
            <?php endif; ?>
        </div>

        <?php do_action( 'extrachill_after_post_content' ); ?>
    </article>
</div>