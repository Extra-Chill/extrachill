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
			<h3><?php _e('Stay Connected with Extra Chill', 'extrachill-newsletter'); ?></h3>
			<p class="newsletter-content-description">
				<?php _e('Get stories, reflections, and music industry insights delivered to your inbox. Stay connected with the latest from the underground.', 'extrachill-newsletter'); ?>
			</p>
		</div>

		<form data-newsletter-form data-newsletter-context="content" class="newsletter-form newsletter-section-form">
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
				<button type="submit"><?php _e('Subscribe', 'extrachill-newsletter'); ?></button>
			</div>
			<p data-newsletter-feedback class="newsletter-feedback" style="display:none;" aria-live="polite"></p>
		</form>

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