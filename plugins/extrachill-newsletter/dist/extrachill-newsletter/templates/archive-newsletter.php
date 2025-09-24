<?php
/**
 * Newsletter Archive Template
 *
 * Template for displaying newsletter archive pages.
 * This template is provided by the ExtraChill Newsletter plugin
 * and overrides the theme's template hierarchy.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

get_header(); ?>

<div id="mediavine-settings" data-blocklist-all="1"></div>

<?php do_action( 'extrachill_before_body_content' ); ?>

<section id="primary" class="newsletter-archive">
    <?php if ( have_posts() ) : ?>

        <header class="page-header">
            <h1 class="page-title"><span><?php _e('Newsletters', 'extrachill-newsletter'); ?></span></h1>
            <p><?php _e('Our newsletter goes out regularly with updates from our editor, music news, and featured Extra Chill content. This page contains our newsletter archive, with links to all past newsletters, and the date that they were sent. Subscribe to get a copy of these in your inbox as we send them out.', 'extrachill-newsletter'); ?></p>
        </header><!-- .page-header -->

        <div class="newsletter-subscribe-form">
            <h2><?php _e('Subscribe to Our Newsletter', 'extrachill-newsletter'); ?></h2>
            <form id="newsletterArchiveForm" class="newsletter-form">
                <label for="newsletter_archive_email"><?php _e('Email:', 'extrachill-newsletter'); ?></label><br>
                <input type="email" id="newsletter_archive_email" name="email" required>
                <input type="hidden" name="action" value="submit_newsletter_form">
                <?php wp_nonce_field( 'newsletter_nonce', 'newsletter_nonce_field' ); ?>
                <button type="submit" class="submit-button"><?php _e('Subscribe', 'extrachill-newsletter'); ?></button>
            </form>
            <p><?php _e('Explore past Extra Chill newsletters below.', 'extrachill-newsletter'); ?></p>
        </div>

        <?php
        // Optional Term and Author Description
        if ( !is_paged() && empty($_GET['tag']) ) {
            $term_description = term_description();
            if ( ! empty( $term_description ) ) {
                printf( '<div class="taxonomy-description">%s</div>', $term_description );
            }

            if ( is_author() ) {
                $author_bio = get_the_author_meta('description');
                if ( !empty($author_bio) ) {
                    echo '<div class="author-bio">' . wpautop($author_bio) . '</div>';
                }
            }
        }
        ?>

        <div class="article-container newsletter-list">
            <?php global $post_i; $post_i = 1; ?>
            <?php while ( have_posts() ) : the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class('newsletter-card'); ?>>
                    <?php if ( has_post_thumbnail() ) { ?>
                        <div class="featured-image">
                            <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                                <?php the_post_thumbnail( 'large' ); ?>
                            </a>
                        </div>
                    <?php } ?>

                    <header class="entry-header">
                        <?php if ( is_single() ) : ?>
                            <h1 class="entry-title"><?php the_title(); ?></h1>
                        <?php else : ?>
                            <h2 class="entry-title">
                                <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                                    <?php the_title(); ?>
                                </a>
                            </h2>
                        <?php endif; ?>
                        <span class="below-entry-meta">
                            <?php printf(__('Sent on %s', 'extrachill-newsletter'), get_the_date()); ?>
                        </span>
                    </header>

                    <?php if ( has_excerpt() ) : ?>
                        <div class="entry-excerpt">
                            <?php the_excerpt(); ?>
                        </div>
                    <?php endif; ?>

                </article>
            <?php endwhile; ?>
        </div>

        <?php
        // Pagination
        the_posts_pagination(array(
            'mid_size' => 2,
            'prev_text' => __('&laquo; Previous', 'extrachill-newsletter'),
            'next_text' => __('Next &raquo;', 'extrachill-newsletter'),
        ));
        ?>

    <?php else : ?>
        <div class="no-newsletters-found">
            <h2><?php _e('No newsletters found', 'extrachill-newsletter'); ?></h2>
            <p><?php _e('We haven\'t published any newsletters yet. Subscribe to be notified when we do!', 'extrachill-newsletter'); ?></p>

            <!-- Show subscription form even when no newsletters exist -->
            <div class="newsletter-subscribe-form">
                <h3><?php _e('Subscribe to Our Newsletter', 'extrachill-newsletter'); ?></h3>
                <form id="newsletterEmptyForm" class="newsletter-form">
                    <label for="newsletter_empty_email"><?php _e('Email:', 'extrachill-newsletter'); ?></label><br>
                    <input type="email" id="newsletter_empty_email" name="email" required>
                    <input type="hidden" name="action" value="submit_newsletter_form">
                    <?php wp_nonce_field( 'newsletter_nonce', 'newsletter_nonce_field' ); ?>
                    <button type="submit" class="submit-button"><?php _e('Subscribe', 'extrachill-newsletter'); ?></button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</section><!-- #primary -->

<?php get_sidebar(); ?>

<?php do_action( 'extrachill_after_body_content' ); ?>

<?php get_footer(); ?>