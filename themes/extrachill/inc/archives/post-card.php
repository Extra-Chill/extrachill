<?php
/**
 * Post Card Template
 *
 * Archive post card with support for multisite search and forum posts.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

$featured_image_size = 'medium_large';
?>

<div class="archive-card">
    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
        <?php do_action( 'extrachill_archive_above_tax_badges' ); ?>
        <?php extrachill_display_taxonomy_badges( get_the_ID() ); ?>

        <?php
        if ( isset( $post->_thumbnail ) && ! empty( $post->_thumbnail['thumbnail_url'] ) ) {
            $thumbnail = $post->_thumbnail;
            ?>
            <div class="featured-image">
                <a href="<?php echo isset($post->permalink) ? esc_url($post->permalink) : the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                    <img src="<?php echo esc_url( $thumbnail['thumbnail_url'] ); ?>"
                         <?php if ( ! empty( $thumbnail['thumbnail_width'] ) && ! empty( $thumbnail['thumbnail_height'] ) ) : ?>
                         width="<?php echo esc_attr( $thumbnail['thumbnail_width'] ); ?>"
                         height="<?php echo esc_attr( $thumbnail['thumbnail_height'] ); ?>"
                         <?php endif; ?>
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
            <header>
                <h2>
                    <a href="<?php echo isset($post->permalink) ? esc_url($post->permalink) : the_permalink(); ?>" class="card-link-target" title="<?php the_title_attribute(); ?>"><?php the_title(); ?></a>
                </h2>
            </header>

            <?php extrachill_entry_meta(); ?>

            <?php if ( isset( $post->_is_forum_post ) && $post->_is_forum_post ) : ?>
                <span>
                    <a href="<?php echo isset($post->permalink) ? esc_url($post->permalink) : esc_url(get_permalink($post)); ?>" class="button-1 button-small" id="forum-post" target="_blank" rel="noopener noreferrer">View in Community</a>
                </span>
            <?php else : ?>
                <span>
                    <a href="<?php echo isset($post->permalink) ? esc_url($post->permalink) : the_permalink(); ?>" class="button-1 button-medium" target="_self" rel="noopener noreferrer">View Full Post</a>
                </span>
            <?php endif; ?>
        </div>

        <?php do_action( 'extrachill_after_post_content' ); ?>
    </article>
</div>