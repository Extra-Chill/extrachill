<?php
/**
 * Public QR generator React mount.
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress-generated wrapper. ?> data-qr-code-generator>
	<p><?php esc_html_e( 'Free forever. Static QR codes never expire, with no signup and no redirects.', 'extrachill-content-blocks' ); ?></p>
	<noscript><?php esc_html_e( 'Enable JavaScript to generate a QR code.', 'extrachill-content-blocks' ); ?></noscript>
</div>
