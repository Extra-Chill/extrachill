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
	<form id="newsletterArchiveForm" class="newsletter-form newsletter-section-form">
		<input type="email" id="newsletter_archive_email" name="email" placeholder="<?php esc_attr_e( 'Enter your email', 'extrachill-newsletter' ); ?>" required>
		<input type="hidden" name="action" value="submit_newsletter_form">
		<?php wp_nonce_field( 'newsletter_nonce', 'newsletter_nonce_field' ); ?>
		<button type="submit" class="submit-button"><?php _e( 'Subscribe', 'extrachill-newsletter' ); ?></button>
	</form>
</div>
