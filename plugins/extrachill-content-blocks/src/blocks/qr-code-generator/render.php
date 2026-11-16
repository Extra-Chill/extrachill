<?php
/**
 * Public QR generator for musicians, bands and venues (React mount).
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ec_qr_link_pages = function_exists( 'ec_get_site_url' ) ? (string) ec_get_site_url( 'link_pages' ) : '';
$ec_qr_join_url   = $ec_qr_link_pages ? trailingslashit( $ec_qr_link_pages ) . 'join' : '';
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress-generated wrapper. ?>>
	<p><?php esc_html_e( 'Free QR codes for your merch table, gig flyers, the tip jar and the screen between sets. They never expire, need no signup and run through no redirects.', 'extrachill-content-blocks' ); ?></p>
	<div data-qr-code-generator data-join-url="<?php echo esc_url( $ec_qr_join_url ); ?>">
		<noscript><?php esc_html_e( 'Enable JavaScript to generate a QR code.', 'extrachill-content-blocks' ); ?></noscript>
	</div>
</div>
