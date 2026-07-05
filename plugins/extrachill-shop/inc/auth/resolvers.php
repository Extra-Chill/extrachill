<?php
/**
 * Procedural credential resolvers for commerce providers.
 *
 * The rest of the shop (stripe-connect.php, webhooks.php, shipping-settings.php)
 * reads credentials through `apply_filters( 'extrachill_stripe_<key>' )` /
 * `apply_filters( 'extrachill_shippo_api_key' )`, passing these resolvers as the
 * default value. The actual decrypted values are now provided by the
 * NETWORK-LAYER providers in extrachill-multisite (registered on
 * `extrachill_stripe_<key>` / `extrachill_shippo_api_key`), which load in both
 * network-admin (write path) and blog 3 (read path). See extrachill-multisite#92.
 *
 * These resolvers intentionally return an empty string — they are the neutral
 * local fallback. The shop must NOT reach into the network-layer provider
 * namespace (layer purity); it consumes the filter contract only. When the
 * network-layer filter callbacks are registered (multisite is network-active),
 * the call-site `apply_filters()` resolves to the decrypted value.
 *
 * @package ExtraChill\Shop\Auth
 * @since 0.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the Stripe secret key.
 *
 * Returns the empty local fallback; the decrypted value is supplied by the
 * network-layer provider via the `extrachill_stripe_secret_key` filter.
 *
 * @return string
 */
function extrachill_shop_resolve_stripe_secret_key(): string {
	return '';
}

/**
 * Resolve the Stripe publishable key.
 *
 * Returns the empty local fallback; the value is supplied by the network-layer
 * provider via the `extrachill_stripe_publishable_key` filter.
 *
 * @return string
 */
function extrachill_shop_resolve_stripe_publishable_key(): string {
	return '';
}

/**
 * Resolve the Stripe Connect client ID.
 *
 * Returns the empty local fallback; the value is supplied by the network-layer
 * provider via the `extrachill_stripe_connect_client_id` filter.
 *
 * @return string
 */
function extrachill_shop_resolve_stripe_connect_client_id(): string {
	return '';
}

/**
 * Resolve the Stripe webhook signing secret.
 *
 * Returns the empty local fallback; the decrypted value is supplied by the
 * network-layer provider via the `extrachill_stripe_webhook_secret` filter.
 *
 * @return string
 */
function extrachill_shop_resolve_stripe_webhook_secret(): string {
	return '';
}

/**
 * Resolve the Shippo API key.
 *
 * Returns the empty local fallback; the decrypted value is supplied by the
 * network-layer provider via the `extrachill_shippo_api_key` filter.
 *
 * @return string
 */
function extrachill_shop_resolve_shippo_api_key(): string {
	return '';
}
