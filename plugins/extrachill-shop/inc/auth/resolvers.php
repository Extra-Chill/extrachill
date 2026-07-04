<?php
/**
 * Procedural credential resolvers for commerce providers.
 *
 * The rest of the shop (stripe-connect.php, webhooks.php, shipping-settings.php)
 * reads credentials through these helpers. Each resolver pulls the decrypted
 * value from the Data Machine auth store, or returns an empty string when Data
 * Machine (or the provider) is unavailable — preserving the "not configured"
 * behavior without a fatal.
 *
 * These functions are loaded eagerly so the call sites can invoke them at
 * runtime regardless of provider registration timing. They must NOT be called
 * before the provider classes are loaded unless guarded by class_exists (which
 * they are, internally).
 *
 * @package ExtraChill\Shop\Auth
 * @since 0.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the Stripe secret key from the encrypted auth store.
 *
 * @return string Decrypted secret key, or empty string when not configured / unavailable.
 */
function extrachill_shop_resolve_stripe_secret_key(): string {
	if ( ! class_exists( '\ExtraChill\Shop\Auth\StripeAuthProvider' ) ) {
		return '';
	}
	return ( new \ExtraChill\Shop\Auth\StripeAuthProvider() )->get_secret_key();
}

/**
 * Resolve the Stripe publishable key from the auth store.
 *
 * @return string Publishable key (stored as plaintext by design), or empty string.
 */
function extrachill_shop_resolve_stripe_publishable_key(): string {
	if ( ! class_exists( '\ExtraChill\Shop\Auth\StripeAuthProvider' ) ) {
		return '';
	}
	return ( new \ExtraChill\Shop\Auth\StripeAuthProvider() )->get_publishable_key();
}

/**
 * Resolve the Stripe Connect client ID from the encrypted auth store.
 *
 * @return string Decrypted Connect client ID, or empty string.
 */
function extrachill_shop_resolve_stripe_connect_client_id(): string {
	if ( ! class_exists( '\ExtraChill\Shop\Auth\StripeAuthProvider' ) ) {
		return '';
	}
	return ( new \ExtraChill\Shop\Auth\StripeAuthProvider() )->get_connect_client_id();
}

/**
 * Resolve the Stripe webhook signing secret from the encrypted auth store.
 *
 * @return string Decrypted webhook secret, or empty string.
 */
function extrachill_shop_resolve_stripe_webhook_secret(): string {
	if ( ! class_exists( '\ExtraChill\Shop\Auth\StripeAuthProvider' ) ) {
		return '';
	}
	return ( new \ExtraChill\Shop\Auth\StripeAuthProvider() )->get_webhook_secret();
}

/**
 * Resolve the Shippo API key from the encrypted auth store.
 *
 * @return string Decrypted Shippo API key, or empty string.
 */
function extrachill_shop_resolve_shippo_api_key(): string {
	if ( ! class_exists( '\ExtraChill\Shop\Auth\ShippoAuthProvider' ) ) {
		return '';
	}
	return ( new \ExtraChill\Shop\Auth\ShippoAuthProvider() )->get_api_key();
}
