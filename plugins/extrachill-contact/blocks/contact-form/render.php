<?php
/**
 * Contact Form Block - Frontend Render
 *
 * Outputs the React mount point and injects configuration
 * for the contact form component.
 */

defined( 'ABSPATH' ) || exit;

$turnstile_site_key = function_exists( 'ec_get_turnstile_site_key' )
	? ec_get_turnstile_site_key()
	: '';

$config = array(
	'endpoint'         => rest_url( 'extrachill/v1/contact/submit' ),
	'restNonce'        => wp_create_nonce( 'wp_rest' ),
	'turnstileSiteKey' => $turnstile_site_key,
	'subjects'         => array(
		'General Inquiry',
		'Partnership/Collaboration',
		'Shop/Store Support',
		'Technical Issue',
		'Other',
	),
	'newsletterNotice' => "By submitting this form, you'll receive our newsletter with music news, festival coverage, and platform updates.",
	'successMessage'   => "Your message has been sent successfully. We'll get back to you soon.",
	'successAction'    => array(
		'url'   => home_url( '/blog/' ),
		'label' => 'Check out the blog while you wait',
	),
);

if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
	ec_enqueue_turnstile_script();
}

wp_enqueue_script( 'wp-element' );
?>
<div <?php echo get_block_wrapper_attributes(); ?>>
	<div id="ec-contact-form"></div>
	<script>
		window.ecContactConfig = <?php echo wp_json_encode( $config ); ?>;
	</script>
</div>
