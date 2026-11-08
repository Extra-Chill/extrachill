<?php
/**
 * "Shop Merch" link on venue and promoter calendar pages.
 *
 * Shown only when the venue or promoter has published products in the Shop,
 * so the link never leads to an empty storefront.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storefront URL for a venue or promoter that sells merch, or ''.
 *
 * @param string $type    Seller type: venue or promoter.
 * @param int    $term_id Seller term ID.
 * @return string
 */
function extrachill_events_seller_storefront_url( string $type, int $term_id ): string {
	if ( ! function_exists( 'ec_shop_seller_has_products' ) || ! function_exists( 'ec_get_shop_storefront_url' ) ) {
		return '';
	}
	$seller = array(
		'type' => $type,
		'id'   => $term_id,
	);
	return ec_shop_seller_has_products( $seller ) ? (string) ec_get_shop_storefront_url( $seller ) : '';
}

/**
 * Render the "Shop Merch" link for a venue or promoter archive.
 *
 * @param WP_Term $term Venue or promoter term.
 */
function extrachill_events_render_seller_shop_link( $term ): void {
	if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, array( 'venue', 'promoter' ), true ) ) {
		return;
	}
	$url = extrachill_events_seller_storefront_url( $term->taxonomy, (int) $term->term_id );
	if ( '' === $url ) {
		return;
	}
	?>
	<p class="seller-shop-link"><a class="button-2 button-medium" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Shop Merch', 'extrachill-events' ); ?></a></p>
	<?php
}
add_action( 'extrachill_events_venue_archive_actions', 'extrachill_events_render_seller_shop_link' );
add_action( 'extrachill_events_promoter_archive_actions', 'extrachill_events_render_seller_shop_link' );
