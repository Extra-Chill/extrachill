<?php
/**
 * Festival Wire Tip Form  
 * Minimalist tip submission form with Cloudflare Turnstile protection.
 * Conditional email requirement for non-community members.
 * To be included in archive and single Festival Wire templates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get Turnstile site key from options
$turnstile_site_key = get_option( 'ec_turnstile_site_key' );

// Check if user is a community member via WordPress native authentication
$is_community_member = is_user_logged_in();
?>

<form id="festival-wire-tip-form" class="festival-wire-tip-form" method="post" action="" autocomplete="off" data-community-member="<?php echo $is_community_member ? 'true' : 'false'; ?>">
	<label for="festival-wire-tip-content" class="screen-reader-text">Your Festival Tip</label>
	<textarea id="festival-wire-tip-content" name="content" rows="4" placeholder="Drop us a tip..." required style="resize:vertical;width:100%;" maxlength="1000"></textarea>
	<div class="character-count" style="font-size:0.8em;color:#666;margin-top:0.5em;">Characters: <span id="char-count">0</span>/1000</div>
	
	<?php if ( ! $is_community_member ) : ?>
	<div class="email-field-container" style="margin-top:1em;">
		<label for="festival-wire-tip-email" class="screen-reader-text">Your Email Address</label>
		<input type="email" id="festival-wire-tip-email" name="email" placeholder="Your email address" required style="width:100%;padding:0.5em;" />
		<div class="email-field-note" style="font-size:0.8em;color:#666;margin-top:0.5em;">
			<em>Email required for non-members. We'll add you to our festival updates list.</em>
		</div>
	</div>
	<?php else : ?>
	<div class="community-member-note" style="font-size:0.8em;color:#666;margin-top:0.5em;">
		<em>✓ Submitting as community member</em>
	</div>
	<?php endif; ?>
	
	<!-- Honeypot field for bot detection -->
	<div style="position:absolute;left:-5000px;" aria-hidden="true">
		<input type="text" name="website" tabindex="-1" autocomplete="off" />
	</div>
	<?php if ( $turnstile_site_key ) : ?>
		<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_site_key ); ?>"></div>
	<?php endif; ?>
	<input type="hidden" name="action" value="festival_wire_tip_submission">
	<?php wp_nonce_field( 'festival_wire_tip_nonce', 'festival_wire_tip_nonce_field' ); ?>
	<button type="submit" class="festival-wire-tip-submit">Submit</button>
	<div class="festival-wire-tip-message" style="margin-top:1em;"></div>
</form>
