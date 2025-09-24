<?php
/**
 * Single Newsletter Template
 *
 * Template for displaying single newsletter posts.
 * This template is provided by the ExtraChill Newsletter plugin
 * and overrides the theme's template hierarchy.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

get_header(); ?>

<div id="mediavine-settings" data-blocklist-all="1"></div>

<?php do_action( 'extrachill_before_body_content' ); ?>

<section id="primary" class="content-area newsletter-single">
<main id="main" class="site-main">
        <?php
        while ( have_posts() ) :
            the_post();

            // Load newsletter content template
            if ( locate_newsletter_template( 'content-newsletter.php' ) ) {
                include locate_newsletter_template( 'content-newsletter.php' );
            } else {
                // Fallback content display
                ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class('newsletter-single-post'); ?>>
                    <?php if ( has_post_thumbnail() ) { ?>
                        <div class="featured-image">
                            <?php the_post_thumbnail( 'full' ); ?>
                        </div>
                    <?php } ?>

                    <header class="entry-header">
                        <div class="breadcrumbs">
                            <a href="<?php echo esc_url( get_post_type_archive_link('newsletter') ); ?>">
                                <?php _e('Newsletters', 'extrachill-newsletter'); ?>
                            </a>
                        </div>
                        <h1 class="entry-title"><?php the_title(); ?></h1>
                        <span class="below-entry-meta">
                            <?php printf(__('Sent on %s', 'extrachill-newsletter'), get_the_date()); ?>
                        </span>
                    </header>

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
                        <div class="newsletter-meta">
                            <p class="newsletter-subscribe-cta">
                                <?php _e('Enjoyed this newsletter?', 'extrachill-newsletter'); ?>
                                <a href="<?php echo esc_url( get_post_type_archive_link('newsletter') ); ?>">
                                    <?php _e('Subscribe to get future issues', 'extrachill-newsletter'); ?>
                                </a>
                            </p>
                        </div>

                        <?php
                        // Show edit link for authorized users
                        edit_post_link(
                            __( 'Edit Newsletter', 'extrachill-newsletter' ),
                            '<span class="edit-link">',
                            '</span>'
                        );
                        ?>
                    </footer><!-- .entry-footer -->
                </article>

                <?php
                // Navigation to other newsletters
                $prev_post = get_previous_post(false, '', 'newsletter');
                $next_post = get_next_post(false, '', 'newsletter');

                if ($prev_post || $next_post) :
                ?>
                <nav class="newsletter-navigation" role="navigation" aria-labelledby="newsletter-navigation-heading">
                    <h2 id="newsletter-navigation-heading" class="screen-reader-text">
                        <?php _e('Newsletter navigation', 'extrachill-newsletter'); ?>
                    </h2>
                    <div class="nav-links">
                        <?php if ($prev_post) : ?>
                            <div class="nav-previous">
                                <a href="<?php echo get_permalink($prev_post->ID); ?>" rel="prev">
                                    <span class="meta-nav" aria-hidden="true"><?php _e('Previous Newsletter', 'extrachill-newsletter'); ?></span>
                                    <span class="screen-reader-text"><?php _e('Previous newsletter:', 'extrachill-newsletter'); ?></span>
                                    <span class="post-title"><?php echo get_the_title($prev_post->ID); ?></span>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if ($next_post) : ?>
                            <div class="nav-next">
                                <a href="<?php echo get_permalink($next_post->ID); ?>" rel="next">
                                    <span class="meta-nav" aria-hidden="true"><?php _e('Next Newsletter', 'extrachill-newsletter'); ?></span>
                                    <span class="screen-reader-text"><?php _e('Next newsletter:', 'extrachill-newsletter'); ?></span>
                                    <span class="post-title"><?php echo get_the_title($next_post->ID); ?></span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </nav>
                <?php endif;
            }

            // If comments are open or there is at least one comment, load up the comment template.
            if ( comments_open() || get_comments_number() ) :
                comments_template();
            endif;

        endwhile; // End of the loop.
        ?>
</main>
</section><!-- #primary -->

<?php
get_sidebar();

do_action( 'extrachill_after_body_content' );

get_footer();
?>