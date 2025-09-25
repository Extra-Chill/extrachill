<?php
/**
 * Newsletter Footer Form Template
 *
 * Template for the newsletter subscription form that appears above the footer
 * via the extrachill_above_footer hook. Provides a final subscription opportunity
 * before users leave the site.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="newsletter-footer-section">
	<div class="newsletter-footer-wrapper">
		<div class="newsletter-footer-content">
			<div class="newsletter-footer-header">
				<h3><?php _e('Stay Connected with Extra Chill', 'extrachill-newsletter'); ?></h3>
				<p class="newsletter-footer-description">
					<?php _e('Get the inside scoop on the music industry with stories and insights from the underground.', 'extrachill-newsletter'); ?>
				</p>
			</div>

			<form id="footerNewsletterForm" class="newsletter-form newsletter-footer-form">
				<div class="newsletter-form-group">
					<label for="newsletter-email-footer" class="sr-only">
						<?php _e('Email address for newsletter', 'extrachill-newsletter'); ?>
					</label>
					<input
						type="email"
						id="newsletter-email-footer"
						name="email"
						required
						placeholder="<?php esc_attr_e('Your email for music industry insights', 'extrachill-newsletter'); ?>"
						aria-label="<?php esc_attr_e('Email address', 'extrachill-newsletter'); ?>"
					>
					<input type="hidden" name="action" value="submit_newsletter_footer_form">
					<?php wp_nonce_field('newsletter_footer_nonce', 'nonce'); ?>
					<button type="submit"><?php _e('Get the Letter', 'extrachill-newsletter'); ?></button>
				</div>
			</form>

			<p class="newsletter-feedback" style="display:none;" aria-live="polite"></p>

			<div class="newsletter-footer-links">
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
</div>