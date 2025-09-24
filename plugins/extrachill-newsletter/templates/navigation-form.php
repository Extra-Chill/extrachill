<?php
/**
 * Newsletter Navigation Form Template
 *
 * Template for the newsletter subscription form that appears
 * in the navigation menu via the extrachill_navigation_before_social_links hook.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<li class="menu-newsletter">
	<form class="newsletter-form newsletter-nav-form" id="navigationNewsletterForm">
		<label for="newsletter-email-nav" class="sr-only"><?php _e('Get our Newsletter', 'extrachill-newsletter'); ?></label>
		<input type="email" id="newsletter-email-nav" name="email" placeholder="<?php esc_attr_e('Enter your email', 'extrachill-newsletter'); ?>" required>
		<input type="hidden" name="action" value="subscribe_to_sendy">
		<?php wp_nonce_field('newsletter_nonce', 'subscribe_nonce'); ?>
		<button type="submit"><?php _e('Subscribe', 'extrachill-newsletter'); ?></button>
		<p><a href="/newsletters"><?php _e('See past newsletters', 'extrachill-newsletter'); ?></a></p>
	</form>
	<p class="newsletter-feedback" style="display:none;" aria-live="polite"></p>
</li>

