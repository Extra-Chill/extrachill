<?php
/**
 * Festival Wire Tip Form
 * Minimalist tip submission form with Cloudflare Turnstile protection.
 * To be included in archive and single Festival Wire templates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get Turnstile site key from options
$turnstile_site_key = get_option( 'ec_turnstile_site_key' );
?>

<form id="festival-wire-tip-form" class="festival-wire-tip-form" method="post" action="" autocomplete="off">
	<label for="festival-wire-tip-content" class="screen-reader-text">Your Festival Tip</label>
	<textarea id="festival-wire-tip-content" name="content" rows="4" placeholder="Drop us a tip..." required style="resize:vertical;width:100%;"></textarea>
	<?php if ( $turnstile_site_key ) : ?>
		<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_site_key ); ?>"></div>
	<?php endif; ?>
	<input type="hidden" name="action" value="festival_wire_tip_submission">
	<?php wp_nonce_field( 'festival_wire_tip_nonce', 'festival_wire_tip_nonce_field' ); ?>
	<button type="submit" class="festival-wire-tip-submit">Submit</button>
	<div class="festival-wire-tip-message" style="margin-top:1em;"></div>
</form>
