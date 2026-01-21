<?php
/**
 * Product Schema for WooCommerce Products
 *
 * Outputs Product schema on single WooCommerce product pages.
 * Only active on shop.extrachill.com (Blog ID 3).
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'extrachill_seo_schema_graph',
	function ( $graph ) {
		// Only run on shop site.
		$shop_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'shop' ) : 3;
		if ( (int) get_current_blog_id() !== (int) $shop_blog_id ) {
			return $graph;
		}

		// Only run on single products.
		if ( ! is_singular( 'product' ) || ! function_exists( 'wc_get_product' ) ) {
			return $graph;
		}

		$product = wc_get_product( get_the_ID() );
		if ( ! $product ) {
			return $graph;
		}

		$permalink = get_permalink();

		$schema = [
			'@type' => 'Product',
			'@id'   => $permalink . '#product',
			'name'  => $product->get_name(),
			'url'   => $permalink,
		];

		// Add description.
		$description = $product->get_short_description();
		if ( empty( $description ) ) {
			$description = wp_trim_words( wp_strip_all_tags( $product->get_description() ), 30, '...' );
		}
		if ( ! empty( $description ) ) {
			$schema['description'] = wp_strip_all_tags( $description );
		}

		// Add image.
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$image = wp_get_attachment_image_src( $image_id, 'full' );
			if ( $image ) {
				$schema['image'] = [
					'@type'  => 'ImageObject',
					'url'    => $image[0],
					'width'  => $image[1],
					'height' => $image[2],
				];
			}
		}

		// Add SKU.
		$sku = $product->get_sku();
		if ( ! empty( $sku ) ) {
			$schema['sku'] = $sku;
		}

		// Add offers.
		$price = $product->get_price();
		if ( '' !== $price ) {
			$offer = [
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => get_woocommerce_currency(),
				'url'           => $permalink,
			];

			// Add availability.
			if ( $product->is_in_stock() ) {
				$offer['availability'] = 'https://schema.org/InStock';
			} else {
				$offer['availability'] = 'https://schema.org/OutOfStock';
			}

			// Add price valid until (optional, helps with rich results).
			$offer['priceValidUntil'] = gmdate( 'Y-m-d', strtotime( '+1 year' ) );

			$schema['offers'] = $offer;
		}

		// Add brand from artist taxonomy if available.
		$artist_terms = get_the_terms( $product->get_id(), 'artist' );
		if ( $artist_terms && ! is_wp_error( $artist_terms ) ) {
			$schema['brand'] = [
				'@type' => 'Brand',
				'name'  => $artist_terms[0]->name,
			];
		}

		$graph[] = $schema;

		return $graph;
	}
);
