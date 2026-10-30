<?php
/**
 * Link Page subscribe modal.
 *
 * Expects $heading, $description, $url, $link_page_id.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="extrch-subscribe-modal" class="extrch-subscribe-modal extrch-modal extrch-modal-hidden" role="dialog" aria-modal="true" aria-labelledby="extrch-subscribe-modal-title">
	<div class="extrch-subscribe-modal-overlay extrch-modal-overlay"></div>
	<div class="extrch-subscribe-modal-content extrch-modal-content">
		<button class="extrch-subscribe-modal-close extrch-modal-close" aria-label="Close subscribe dialog">&times;</button>
		<div class="extrch-subscribe-modal-header">
			<h3 id="extrch-subscribe-modal-title" class="extrch-subscribe-header"><?php echo esc_html( $heading ); ?></h3>
			<p><?php echo esc_html( $description ); ?></p>
		</div>
		<form id="extrch-subscribe-form-modal" class="extrch-subscribe-form" data-subscribe-api-url="<?php echo esc_url( $url ); ?>">
			<div class="form-group">
				<input type="email" name="subscriber_email" id="subscriber_email_modal" placeholder="Your email address" required aria-label="Email Address" autocomplete="email">
			</div>
			<button type="submit" class="button-1 button-medium">Subscribe</button>
			<div class="extrch-form-message" aria-live="polite"></div>
		</form>
	</div>
</div>
