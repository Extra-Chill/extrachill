<?php
/**
 * Commerce credentials (network layer).
 *
 * `ec_commerce_credential( $provider, $field )` is the one way to read a
 * platform commerce credential (Stripe, Shippo). It reads the provider's
 * config from Data Machine's encrypted auth envelope
 * (`datamachine_auth_data` site option, `ec_stripe` / `ec_shippo` slots) and
 * returns the decrypted, trimmed value.
 *
 * This lives in extrachill-network (network-active) so the provider classes
 * load in both contexts that touch commerce credentials:
 *   - network-admin, where Network Admin > Payments writes them, and
 *   - blog 3 (the shop), where checkout / webhooks / shipping read them.
 *
 * The provider classes extend Data Machine's
 * `DataMachine\Core\OAuth\BaseAuthProvider`. Data Machine's autoloader is not
 * guaranteed to be registered when this file loads, so the classes load on
 * `plugins_loaded` priority 30, or lazily on the first credential read. When
 * Data Machine is missing, every read returns '' AND logs a critical error:
 * a missing provider layer means checkout is down, never "not configured".
 *
 * @package ExtraChillNetwork\Commerce\Auth
 * @since 1.23.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', 'extrachill_network_load_commerce_auth_providers', 30 );

/**
 * Commerce provider key => provider class.
 *
 * Provider slugs (`ec_stripe`, `ec_shippo`) are stable so stored encrypted
 * values keep decrypting.
 *
 * @return array<string,string>
 */
function extrachill_network_commerce_auth_providers(): array {
	return array(
		'stripe' => '\\ExtraChillNetwork\\Commerce\\Auth\\StripeAuthProvider',
		'shippo' => '\\ExtraChillNetwork\\Commerce\\Auth\\ShippoAuthProvider',
	);
}

/**
 * Load the commerce auth provider classes and register them with Data Machine.
 *
 * Idempotent. Returns false when Data Machine's `BaseAuthProvider` is missing.
 *
 * @return bool Whether the provider layer is available.
 */
function extrachill_network_load_commerce_auth_providers(): bool {
	static $loaded = null;

	if ( null !== $loaded ) {
		return $loaded;
	}

	if ( ! class_exists( '\\DataMachine\\Core\\OAuth\\BaseAuthProvider' ) ) {
		// Not cached: a later call (after Data Machine's autoloader registers)
		// may still succeed.
		return false;
	}

	require_once __DIR__ . '/CommerceAuthProvider.php';
	require_once __DIR__ . '/StripeAuthProvider.php';
	require_once __DIR__ . '/ShippoAuthProvider.php';

	\ExtraChillNetwork\Commerce\Auth\CommerceAuthProvider::register_with_datamachine();

	$loaded = true;
	return true;
}

/**
 * Read a platform commerce credential.
 *
 * @param string $provider Provider key: 'stripe' or 'shippo'.
 * @param string $field    Config field, e.g. 'secret_key', 'publishable_key',
 *                         'webhook_secret', 'connect_webhook_secret' (Stripe)
 *                         or 'api_key' (Shippo).
 * @return string Decrypted, trimmed value; '' when unset or unreadable.
 */
function ec_commerce_credential( string $provider, string $field ): string {
	$providers = extrachill_network_commerce_auth_providers();

	if ( ! isset( $providers[ $provider ] ) ) {
		extrachill_network_log_commerce_credential_error(
			sprintf( 'unknown commerce credential provider "%s" (field "%s").', $provider, $field )
		);
		return '';
	}

	if ( ! extrachill_network_load_commerce_auth_providers() ) {
		extrachill_network_log_commerce_credential_error(
			sprintf(
				'cannot read commerce credential %s.%s: Data Machine BaseAuthProvider is not loaded. Stripe and Shippo are unavailable until Data Machine is active.',
				$provider,
				$field
			)
		);
		return '';
	}

	$class  = $providers[ $provider ];
	$config = ( new $class() )->get_config();

	return isset( $config[ $field ] ) && is_scalar( $config[ $field ] ) ? trim( (string) $config[ $field ] ) : '';
}

/**
 * Log a commerce credential failure, once per distinct message per request.
 *
 * @param string $message Failure description.
 */
function extrachill_network_log_commerce_credential_error( string $message ): void {
	static $logged = array();

	if ( isset( $logged[ $message ] ) ) {
		return;
	}
	$logged[ $message ] = true;

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a missing commerce provider layer takes checkout down; it must reach the server log.
	error_log( '[extrachill-network] CRITICAL commerce credentials: ' . $message );
}
