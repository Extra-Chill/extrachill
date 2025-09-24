<?php
/**
 * Newsletter Content Template
 *
 * Template for displaying newsletter post content.
 * Used in both archive and single newsletter displays.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Initialize variables with defaults
$reading_time = '';
$featured_image_size = 'full';
$class_name_layout = '';
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( array( $class_name_layout, 'newsletter-content' ) ); ?>>

    <?php
    /**
     * Newsletter featured image display
     * Only show if not in gallery or video post format
     */
    if ( ! has_post_format( array( 'gallery', 'video' ) ) ) : ?>
        <?php if ( has_post_thumbnail() ) { ?>
            <div class="featured-image">
                <?php if ( is_singular( 'newsletter' ) ) : ?>
                    <?php the_post_thumbnail( $featured_image_size ); ?>
                <?php else : ?>
                    <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                        <?php the_post_thumbnail( 'large' ); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php } ?>
    <?php endif; ?>

    <?php
    // Handle post formats if they exist
    if ( get_post_format() ) {
        $post_format_template = locate_template( 'inc/post-formats.php' );
        if ( $post_format_template ) {
            include $post_format_template;
        }
    }
    ?>

    <div class="newsletter-content-wrapper">
        <?php if ( ! is_singular( 'newsletter' ) ) : ?>
            <div class="breadcrumbs">
                <a href="<?php echo esc_url( get_post_type_archive_link('newsletter') ); ?>">
                    <?php _e('Newsletters', 'extrachill-newsletter'); ?>
                </a>
            </div>
        <?php endif; ?>

        <header class="entry-header">
            <?php if ( is_singular( 'newsletter' ) ) : ?>
                <div class="breadcrumbs">
                    <a href="<?php echo esc_url( get_post_type_archive_link('newsletter') ); ?>">
                        <?php _e('Newsletters', 'extrachill-newsletter'); ?>
                    </a>
                </div>
                <h1 class="entry-title"><?php the_title(); ?></h1>
            <?php else : ?>
                <h2 class="entry-title">
                    <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                        <?php the_title(); ?>
                    </a>
                </h2>
            <?php endif; ?>

            <div class="entry-meta">
                <span class="below-entry-meta newsletter-date">
                    <?php printf(__('Sent on %s', 'extrachill-newsletter'), get_the_date()); ?>
                </span>

                <?php if ( is_singular( 'newsletter' ) ) : ?>
                    <span class="newsletter-author">
                        <?php printf(__('by %s', 'extrachill-newsletter'), get_the_author()); ?>
                    </span>
                <?php endif; ?>
            </div>
        </header>

        <?php if ( is_singular( 'newsletter' ) ) : ?>
            <div class="entry-content">
                <?php
                the_content();

                wp_link_pages( array(
                    'before' => '<div class="page-links">' . __( 'Pages:', 'extrachill-newsletter' ),
                    'after'  => '</div>',
                ) );
                ?>
            </div><!-- .entry-content -->

            <footer class="entry-footer">
                <div class="newsletter-actions">
                    <div class="newsletter-subscribe-cta">
                        <p>
                            <?php _e('Enjoyed this newsletter?', 'extrachill-newsletter'); ?>
                            <a href="<?php echo esc_url( get_post_type_archive_link('newsletter') ); ?>#newsletter-subscribe">
                                <?php _e('Subscribe for future issues', 'extrachill-newsletter'); ?>
                            </a>
                        </p>
                    </div>

                    <?php
                    // Show newsletter sharing options if available
                    if ( function_exists( 'extrachill_entry_meta' ) ) {
                        extrachill_entry_meta();
                    }
                    ?>

                    <div class="newsletter-archive-link">
                        <a href="<?php echo esc_url( get_post_type_archive_link('newsletter') ); ?>">
                            <?php _e('← Back to Newsletter Archive', 'extrachill-newsletter'); ?>
                        </a>
                    </div>
                </div>

                <?php
                edit_post_link(
                    __( 'Edit Newsletter', 'extrachill-newsletter' ),
                    '<span class="edit-link">',
                    '</span>'
                );
                ?>
            </footer><!-- .entry-footer -->
        <?php else : ?>
            <!-- Archive view: Show excerpt and read more link -->
            <?php if ( has_excerpt() ) : ?>
                <div class="entry-excerpt">
                    <?php the_excerpt(); ?>
                </div>
            <?php endif; ?>

            <div class="newsletter-archive-actions">
                <a href="<?php the_permalink(); ?>" class="read-newsletter-link">
                    <?php _e('Read Full Newsletter →', 'extrachill-newsletter'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div><!-- .newsletter-content-wrapper -->
</article>

<?php
// Hook for additional content after newsletter post
do_action( 'extrachill_after_newsletter_content', get_the_ID() );
?>