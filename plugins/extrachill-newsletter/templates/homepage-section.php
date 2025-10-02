<?php
/**
 * Homepage Newsletter Section Template
 *
 * Template for the newsletter signup section displayed on the homepage.
 * This template is provided by the ExtraChill Newsletter plugin.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Don't display if not on front page
if ( ! is_front_page() ) {
    return;
}
?>

<div class="home-newsletter-signup newsletter-homepage-section">
    <h2 class="home-newsletter-header"><?php _e('A Note from the Editor', 'extrachill-newsletter'); ?></h2>
    <p class="home-newsletter-subhead">
        <?php _e('Stories, reflections, and music industry insights from the underground.', 'extrachill-newsletter'); ?>
    </p>

    <form id="homepageNewsletterForm" class="newsletter-form newsletter-horizontal-form">
        <label for="newsletter-email-home" class="sr-only">
            <?php _e('Email address for newsletter', 'extrachill-newsletter'); ?>
        </label>
        <input
            type="email"
            id="newsletter-email-home"
            name="email"
            required
            placeholder="<?php esc_attr_e('Your email for the inside scoop...', 'extrachill-newsletter'); ?>"
            aria-label="<?php esc_attr_e('Email address', 'extrachill-newsletter'); ?>"
        >
        <input type="hidden" name="action" value="subscribe_to_sendy_home">
        <?php wp_nonce_field('subscribe_to_sendy_home_nonce', 'subscribe_to_sendy_home_nonce_field'); ?>
        <button type="submit"><?php _e('Get the Letter', 'extrachill-newsletter'); ?></button>
    </form>

    <p class="newsletter-feedback" style="display:none;" aria-live="polite"></p>

    <div class="newsletter-homepage-links">
        <?php $archive_url = get_post_type_archive_link('newsletter'); ?>
        <?php if ( $archive_url ) : ?>
            <p class="past-newsletters-link">
                <a href="<?php echo esc_url( $archive_url ); ?>">
                    <?php _e('Browse past newsletters', 'extrachill-newsletter'); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
</div>
