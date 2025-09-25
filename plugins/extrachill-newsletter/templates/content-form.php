<?php
/**
 * Newsletter Content Form Template
 *
 * Template for the newsletter subscription form that appears after post content
 * via the extrachill_after_post_content hook. Provides contextual subscription
 * opportunity after readers consume valuable content.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only display on single posts
if ( ! is_single() ) {
	return;
}
?>

<div class="newsletter-content-section">
	<div class="newsletter-content-wrapper">
		<div class="newsletter-content-header">
			<h3><?php _e('Join the Extra Chill Community', 'extrachill-newsletter'); ?></h3>
			<p class="newsletter-content-description">
				<?php _e('Get stories, reflections, and music industry insights delivered to your inbox. Join our community of music lovers and industry insiders.', 'extrachill-newsletter'); ?>
			</p>
		</div>

		<form id="contentNewsletterForm" class="newsletter-form newsletter-content-form">
			<div class="newsletter-form-group">
				<label for="newsletter-email-content" class="sr-only">
					<?php _e('Email address for newsletter', 'extrachill-newsletter'); ?>
				</label>
				<input
					type="email"
					id="newsletter-email-content"
					name="email"
					required
					placeholder="<?php esc_attr_e('Enter your email address', 'extrachill-newsletter'); ?>"
					aria-label="<?php esc_attr_e('Email address', 'extrachill-newsletter'); ?>"
				>
				<input type="hidden" name="action" value="submit_newsletter_content_form">
				<?php wp_nonce_field('newsletter_content_nonce', 'nonce'); ?>
				<button type="submit"><?php _e('Subscribe', 'extrachill-newsletter'); ?></button>
			</div>
		</form>

		<p class="newsletter-feedback" style="display:none;" aria-live="polite"></p>

		<div class="newsletter-content-links">
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
</div>