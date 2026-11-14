<?php
/**
 * Buyer Terms of Service consent on the Checkout block.
 *
 * Uses WooCommerce's own `woocommerce/checkout-terms-block` in its
 * required-checkbox mode. The block's attributes are enforced two ways:
 *
 * - At render, through core's `render_block_data` filter. WooCommerce turns
 *   inner checkout block attributes into `data-*` attributes that the checkout
 *   frontend reads, so the checkbox is required even if an editor toggles it off.
 * - In storage, through a one-time provisioning pass over the checkout page
 *   (re-run whenever the Terms version changes), so the editor shows the same
 *   required checkbox and a missing terms block is put back.
 *
 * Accepted orders record the timestamp and Terms version as order meta.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_SHOP_CHECKOUT_TERMS_BLOCK = 'woocommerce/checkout-terms-block';

add_filter( 'render_block_data', 'extrachill_shop_require_checkout_terms_block' );
add_action( 'init', 'extrachill_shop_maybe_provision_checkout_terms', 20 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'extrachill_shop_record_checkout_terms_acceptance' );

/**
 * Checkout consent text linking the network Terms of Service and Privacy Policy.
 *
 * @return string HTML.
 */
function extrachill_shop_checkout_terms_text(): string {
	return sprintf(
		/* translators: 1: Terms of Service URL, 2: Privacy Policy URL. */
		__( 'I have read and agree to the <a href="%1$s" target="_blank" rel="noopener noreferrer">Terms of Service</a> and <a href="%2$s" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.', 'extrachill-shop' ),
		esc_url( extrachill_shop_legal_document( 'terms', 'url' ) ),
		esc_url( extrachill_shop_legal_document( 'privacy', 'url' ) )
	);
}

/**
 * Attributes the checkout terms block must carry.
 *
 * @return array{checkbox: bool, text: string}
 */
function extrachill_shop_checkout_terms_block_attrs(): array {
	return array(
		'checkbox' => true,
		'text'     => extrachill_shop_checkout_terms_text(),
	);
}

/**
 * Force the required checkbox and consent text onto the checkout terms block.
 *
 * @param array $parsed_block Parsed block.
 * @return array
 */
function extrachill_shop_require_checkout_terms_block( $parsed_block ) {
	if ( ! is_array( $parsed_block ) || EXTRACHILL_SHOP_CHECKOUT_TERMS_BLOCK !== ( $parsed_block['blockName'] ?? '' ) ) {
		return $parsed_block;
	}
	$attrs                 = is_array( $parsed_block['attrs'] ?? null ) ? $parsed_block['attrs'] : array();
	$parsed_block['attrs'] = array_merge( $attrs, extrachill_shop_checkout_terms_block_attrs() );
	return $parsed_block;
}

/**
 * Serialize block attributes for a block comment delimiter.
 *
 * Mirrors core's serialize_block_attributes() when it is unavailable.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function extrachill_shop_serialize_block_attributes( array $attrs ): string {
	if ( function_exists( 'serialize_block_attributes' ) ) {
		return serialize_block_attributes( $attrs );
	}
	$json = (string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Core fallback.
	$json = preg_replace( '/--/', '\\u002d\\u002d', $json );
	$json = preg_replace( '/</', '\\u003c', $json );
	$json = preg_replace( '/>/', '\\u003e', $json );
	$json = preg_replace( '/&/', '\\u0026', $json );
	return preg_replace( '/\\\\"/', '\\u0022', $json );
}

/**
 * Ensure checkout page markup contains a required, linked terms block.
 *
 * Updates an existing terms block's delimiter in place, keeping any other
 * attributes. A missing terms block is inserted before the checkout actions
 * block (or at the end of the checkout fields block).
 *
 * @param string $content Checkout page post content.
 * @return string Updated content; unchanged when it holds no Checkout block.
 */
function extrachill_shop_ensure_checkout_terms_markup( string $content ): string {
	if ( ! str_contains( $content, '<!-- wp:woocommerce/checkout ' ) && ! str_contains( $content, '<!-- wp:woocommerce/checkout -->' ) ) {
		return $content;
	}

	$pattern = '#<!--\s+wp:woocommerce/checkout-terms-block(\s+\{.*?\})?\s+(/)?-->#s';
	if ( preg_match( $pattern, $content ) ) {
		return (string) preg_replace_callback(
			$pattern,
			static function ( $matches ) {
				$existing = isset( $matches[1] ) && '' !== trim( $matches[1] ) ? json_decode( trim( $matches[1] ), true ) : array();
				$attrs    = array_merge( is_array( $existing ) ? $existing : array(), extrachill_shop_checkout_terms_block_attrs() );
				$closer   = empty( $matches[2] ) ? '-->' : '/-->';
				return '<!-- wp:' . EXTRACHILL_SHOP_CHECKOUT_TERMS_BLOCK . ' ' . extrachill_shop_serialize_block_attributes( $attrs ) . ' ' . $closer;
			},
			$content,
			1
		);
	}

	$block = '<!-- wp:' . EXTRACHILL_SHOP_CHECKOUT_TERMS_BLOCK . ' ' . extrachill_shop_serialize_block_attributes( extrachill_shop_checkout_terms_block_attrs() ) . " -->\n"
		. "<div class=\"wp-block-woocommerce-checkout-terms-block\"></div>\n"
		. '<!-- /wp:' . EXTRACHILL_SHOP_CHECKOUT_TERMS_BLOCK . " -->\n\n";

	foreach ( array( '<!-- wp:woocommerce/checkout-actions-block', '<!-- /wp:woocommerce/checkout-fields-block -->' ) as $anchor ) {
		$position = strpos( $content, $anchor );
		if ( false !== $position ) {
			return substr_replace( $content, $block, $position, 0 );
		}
	}
	return $content;
}

/**
 * Provision the checkout page's terms block once per Terms version and text.
 *
 * @return void
 */
function extrachill_shop_maybe_provision_checkout_terms() {
	if ( ! function_exists( 'wc_get_page_id' ) ) {
		return;
	}

	$signature = extrachill_shop_legal_document( 'terms', 'version' ) . ':' . md5( extrachill_shop_checkout_terms_text() );
	if ( get_option( 'extrachill_shop_checkout_terms_provisioned' ) === $signature ) {
		return;
	}

	$page_id = (int) wc_get_page_id( 'checkout' );
	$page    = $page_id > 0 ? get_post( $page_id ) : null;
	if ( ! $page ) {
		return;
	}

	$updated = extrachill_shop_ensure_checkout_terms_markup( (string) $page->post_content );
	if ( $updated !== $page->post_content ) {
		$result = wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => wp_slash( $updated ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return;
		}
	}

	update_option( 'extrachill_shop_checkout_terms_provisioned', $signature );
}

/**
 * Record the buyer's Terms acceptance on a Store API checkout order.
 *
 * The Checkout block refuses to submit until the required terms checkbox is
 * ticked, so a processed Store API checkout carries the buyer's consent.
 *
 * @param WC_Order $order Order being processed.
 * @return void
 */
function extrachill_shop_record_checkout_terms_acceptance( $order ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
		return;
	}
	$order->update_meta_data( '_extrachill_terms_accepted_at', gmdate( 'Y-m-d\TH:i:s\Z' ) );
	$order->update_meta_data( '_extrachill_terms_version', extrachill_shop_legal_document( 'terms', 'version' ) );
	$order->save();
}
