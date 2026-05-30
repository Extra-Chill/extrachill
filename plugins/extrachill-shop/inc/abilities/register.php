<?php
declare(strict_types=1);
/**
 * Abilities Registration
 *
 * Registers the extrachill-shop ability category and loads all ability files.
 * Each file registers its own abilities on the wp_abilities_api_init hook.
 *
 * @package ExtraChillShop
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_shop_register_ability_category' );

/**
 * Register shop ability category.
 */
function extrachill_shop_register_ability_category(): void {
	wp_register_ability_category(
		'extrachill-shop',
		array(
			'label'       => __( 'Extra Chill Shop', 'extrachill-shop' ),
			'description' => __( 'Shop operations: orders, products, shipping, Stripe Connect, and taxonomy counts.', 'extrachill-shop' ),
		)
	);
}

// Shared product write helpers (variation setup, attribute taxonomy, status
// validation, image ordering, ownership checks). Loaded before the product
// abilities that call into them.
require_once __DIR__ . '/product-write-helpers.php';

// Load ability files — each self-registers on wp_abilities_api_init.
require_once __DIR__ . '/shop-list-orders.php';
require_once __DIR__ . '/shop-refund-order.php';
require_once __DIR__ . '/shop-update-order-status.php';
require_once __DIR__ . '/shop-list-products.php';
require_once __DIR__ . '/shop-get-product.php';
require_once __DIR__ . '/shop-upload-product-image.php';
require_once __DIR__ . '/shop-get-shipping-address.php';
require_once __DIR__ . '/shop-update-shipping-address.php';
require_once __DIR__ . '/shop-list-shipping-labels.php';
require_once __DIR__ . '/shop-get-shipping-label.php';
require_once __DIR__ . '/shop-stripe-dashboard-link.php';
require_once __DIR__ . '/shop-stripe-onboarding-link.php';
require_once __DIR__ . '/shop-stripe-status.php';
require_once __DIR__ . '/shop-taxonomy-counts.php';
require_once __DIR__ . '/shop-create-product.php';
require_once __DIR__ . '/shop-update-product.php';
require_once __DIR__ . '/shop-delete-product.php';
require_once __DIR__ . '/shop-delete-product-image.php';
require_once __DIR__ . '/shop-create-shipping-label.php';
