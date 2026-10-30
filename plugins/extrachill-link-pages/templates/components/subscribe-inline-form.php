<?php
/**
 * Link Page inline subscribe form.
 *
 * Expects $heading, $description, $url, $link_page_id.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

$ec_form_id = 'extrch-subscribe-form-link-page-' . absint( $link_page_id );
?>
<section class="extrch-subscribe-inline-form-container extrch-link-page-subscribe-inline-form-container" aria-labelledby="<?php echo esc_attr( $ec_form_id ); ?>-heading">
	<h3 id="<?php echo esc_attr( $ec_form_id ); ?>-heading" class="extrch-subscribe-header"><?php echo esc_html( $heading ); ?></h3>
	<p id="<?php echo esc_attr( $ec_form_id ); ?>-description"><?php echo esc_html( $description ); ?></p>
	<form id="<?php echo esc_attr( $ec_form_id ); ?>" class="extrch-subscribe-form" data-subscribe-api-url="<?php echo esc_url( $url ); ?>" aria-describedby="<?php echo esc_attr( $ec_form_id ); ?>-description">
		<div class="form-group">
			<label class="screen-reader-text" for="<?php echo esc_attr( $ec_form_id ); ?>-email">Email Address</label>
			<input type="email" name="subscriber_email" id="<?php echo esc_attr( $ec_form_id ); ?>-email" placeholder="Your email address" required autocomplete="email">
		</div>
		<button type="submit" class="button-1 button-medium">Subscribe</button>
		<div class="extrch-form-message" role="status" aria-live="polite"></div>
	</form>
</section>
