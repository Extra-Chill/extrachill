<?php
/**
 * QR generator page ad eligibility.
 *
 * The public generator page is a free, ad-supported tool: logged-out visitors
 * see ads; signed-in members do not. Lifetime members are excluded upstream.
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opt a page containing the QR generator block into ads for logged-out visitors.
 *
 * @param bool $allows  Current opt-in.
 * @param int  $post_id Queried page ID.
 * @return bool
 */
function extrachill_content_blocks_qr_page_allows_ads( $allows, $post_id ) {
	if ( $allows || is_user_logged_in() ) {
		return (bool) $allows;
	}
	$post = get_post( (int) $post_id );
	return $post instanceof WP_Post && has_block( 'extrachill/qr-code-generator', $post );
}
add_filter( 'extrachill_page_allows_ads', 'extrachill_content_blocks_qr_page_allows_ads', 10, 2 );
