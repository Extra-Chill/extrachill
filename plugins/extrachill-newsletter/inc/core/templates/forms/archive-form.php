<?php
/**
 * Newsletter Archive Subscription Form Template
 *
 * Displays subscription form on newsletter archive pages.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="newsletter-subscription-form">
	<h2 class="newsletter-form-title"><?php _e( 'Subscribe to Our Newsletter', 'extrachill-newsletter' ); ?></h2>
	<p class="newsletter-form-description"><?php _e( 'Get independent music journalism with personality delivered to your inbox.', 'extrachill-newsletter' ); ?></p>
	<form data-newsletter-form data-newsletter-context="archive" class="newsletter-form newsletter-section-form">
		<input type="email" name="email" placeholder="<?php esc_attr_e( 'Enter your email', 'extrachill-newsletter' ); ?>" required>
		<button type="submit" class="submit-button"><?php _e( 'Subscribe', 'extrachill-newsletter' ); ?></button>
		<p data-newsletter-feedback class="newsletter-feedback" style="display:none;" aria-live="polite"></p>
	</form>
</div>
