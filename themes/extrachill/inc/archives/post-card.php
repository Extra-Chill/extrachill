<?php
/**
 * Post Card Template
 *
 * Technical Implementation:
 * - Multisite search support: Detects cross-site thumbnail data via $post->_thumbnail
 * - Forum posts: Conditional formatting for forum posts via $post->_is_forum_post flag
 * - Dynamic permalinks: Uses $post->permalink when available for cross-site links
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

        <?php
        if ( isset( $post->_thumbnail ) && ! empty( $post->_thumbnail['thumbnail_url'] ) ) {
            $thumbnail = $post->_thumbnail;
            ?>
            <div class="featured-image">
                <a href="<?php echo isset($post->permalink) ? esc_url($post->permalink) : the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                    <img src="<?php echo esc_url( $thumbnail['thumbnail_url'] ); ?>"
                         <?php if ( ! empty( $thumbnail['thumbnail_srcset'] ) ) : ?>
                         srcset="<?php echo esc_attr( $thumbnail['thumbnail_srcset'] ); ?>"
                         <?php endif; ?>
                         <?php if ( ! empty( $thumbnail['thumbnail_sizes'] ) ) : ?>
                         sizes="<?php echo esc_attr( $thumbnail['thumbnail_sizes'] ); ?>"
                         <?php endif; ?>
                         alt="<?php echo esc_attr( ! empty( $thumbnail['thumbnail_alt'] ) ? $thumbnail['thumbnail_alt'] : get_the_title() ); ?>"
                         loading="lazy">
                </a>
            </div>
        <?php } elseif ( has_post_thumbnail() ) { ?>
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
                    <a href="<?php echo isset($post->permalink) ? esc_url($post->permalink) : esc_url(get_permalink($post)); ?>" class="button-1 button-small" id="forum-post" target="_blank" rel="noopener noreferrer">View in Community</a>
                </span>
            <?php else : ?>
                <div class="archive-excerpt">
                    <?php echo wp_kses_post(wp_trim_words(get_the_excerpt(), 30, '...')); ?>
                </div>

                <span>
                    <a href="<?php echo isset($post->permalink) ? esc_url($post->permalink) : the_permalink(); ?>" class="button-1 button-medium" target="_self" rel="noopener noreferrer">View Full Post</a>
                </span>
            <?php endif; ?>
        </div>

        <?php do_action( 'extrachill_after_post_content' ); ?>
    </article>
</div>