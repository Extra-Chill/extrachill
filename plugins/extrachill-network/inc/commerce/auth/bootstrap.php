<?php
/**
 * Commerce auth providers bootstrap (network layer).
 *
 * Registers the Stripe + Shippo auth providers with Data Machine, exposes their
 * decrypted values through the read-filter contract, and migrates any legacy
 * plaintext site_options into the encrypted store.
 *
 * This bootstrap lives in extrachill-network (network-active) so the provider
 * classes load in BOTH contexts that touch commerce credentials:
 *   - network-admin, where the Network Admin > Payments save handler writes
 *     them (the class_exists() guard in the save handler is now TRUE), and
 *   - blog 3 (the shop), where checkout / webhook / shipping reads them.
 *
 * The read-filter contract (`extrachill_stripe_<key>` / `extrachill_shippo_api_key`)
 * is the single integration point the shop consumes. The shop's resolver
 * functions now return an empty default and rely on these callbacks to supply
 * the decrypted value. See #92.
 *
 * The provider classes extend Data Machine's
 * `DataMachine\Core\OAuth\BaseAuthProvider`, which is supplied by Data
 * Machine's PSR-4 autoloader. That autoloader is not guaranteed to be
 * registered at the moment this file loads, so the provider class files are
 * deferred behind a `class_exists` guard on `plugins_loaded` priority 30. When
 * Data Machine is absent the commerce auth providers are simply not registered
 * and the read path degrades to "not configured".
 *
 * @package ExtraChillNetwork\Commerce\Auth
 * @since 1.23.0
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\DataMachine\\Core\\OAuth\\BaseAuthProvider' ) ) {
			return;
		}

		require_once __DIR__ . '/CommerceAuthProvider.php';
		require_once __DIR__ . '/StripeAuthProvider.php';
		require_once __DIR__ . '/ShippoAuthProvider.php';

		\ExtraChillNetwork\Commerce\Auth\CommerceAuthProvider::register_with_datamachine();
		extrachill_network_register_commerce_read_filters();
		extrachill_network_migrate_plaintext_commerce_credentials();
	},
	30
);

/**
 * Register the read-filter contract for commerce credentials.
 *
 * The shop (and any other consumer) reads credentials via
 * `apply_filters( 'extrachill_stripe_<key>' )` / `apply_filters( 'extrachill_shippo_api_key' )`.
 * Each callback returns the decrypted value from the network-layer provider,
 * while respecting a value already supplied by a higher-priority callback (so
 * extrachill-dev and other overrides still win). Provider slugs are stable so
 * previously-stored encrypted values keep decrypting.
 */
function extrachill_network_register_commerce_read_filters(): void {
	add_filter(
		'extrachill_stripe_secret_key',
		static function ( $value ) {
			if ( '' !== $value ) {
				return $value;
			}
			return ( new \ExtraChillNetwork\Commerce\Auth\StripeAuthProvider() )->get_secret_key();
		}
	);

	add_filter(
		'extrachill_stripe_publishable_key',
		static function ( $value ) {
			if ( '' !== $value ) {
				return $value;
			}
			return ( new \ExtraChillNetwork\Commerce\Auth\StripeAuthProvider() )->get_publishable_key();
		}
	);

	add_filter(
		'extrachill_stripe_connect_client_id',
		static function ( $value ) {
			if ( '' !== $value ) {
				return $value;
			}
			return ( new \ExtraChillNetwork\Commerce\Auth\StripeAuthProvider() )->get_connect_client_id();
		}
	);

	add_filter(
		'extrachill_stripe_webhook_secret',
		static function ( $value ) {
			if ( '' !== $value ) {
				return $value;
			}
			return ( new \ExtraChillNetwork\Commerce\Auth\StripeAuthProvider() )->get_webhook_secret();
		}
	);

	add_filter(
		'extrachill_shippo_api_key',
		static function ( $value ) {
			if ( '' !== $value ) {
				return $value;
			}
			return ( new \ExtraChillNetwork\Commerce\Auth\ShippoAuthProvider() )->get_api_key();
		}
	);
}

/**
 * Migrate legacy plaintext commerce credentials into the encrypted store.
 *
 * Copies each non-empty plaintext site_option into the matching provider config
 * (which encrypts sensitive fields on save) and then deletes the plaintext
 * option. Naturally idempotent: once the plaintext options are gone there is
 * nothing to migrate, so this runs effectively once per install.
 *
 * Safe no-op when no plaintext values exist (the current production state).
 */
function extrachill_network_migrate_plaintext_commerce_credentials(): void {
	if ( ! function_exists( 'get_site_option' ) || ! function_exists( 'delete_site_option' ) ) {
		return;
	}

	// Map legacy site_option name => provider config field.
	$stripe_legacy = array(
		'extrachill_stripe_secret_key'        => 'secret_key',
		'extrachill_stripe_publishable_key'   => 'publishable_key',
		'extrachill_stripe_connect_client_id' => 'connect_client_id',
		'extrachill_stripe_webhook_secret'    => 'webhook_secret',
	);

	$stripe_data = array();
	foreach ( $stripe_legacy as $option_name => $field ) {
		$value = get_site_option( $option_name, '' );
		if ( '' !== $value && null !== $value ) {
			$stripe_data[ $field ] = (string) $value;
		}
	}

	if ( ! empty( $stripe_data ) ) {
		\ExtraChillNetwork\Commerce\Auth\StripeAuthProvider::save( $stripe_data );

		foreach ( array_keys( $stripe_data ) as $field ) {
			$option_name = array_search( $field, $stripe_legacy, true );
			if ( false !== $option_name ) {
				delete_site_option( $option_name );
			}
		}
	}

	$shippo_value = get_site_option( 'extrachill_shippo_api_key', '' );
	if ( '' !== $shippo_value && null !== $shippo_value ) {
		\ExtraChillNetwork\Commerce\Auth\ShippoAuthProvider::save(
			array( 'api_key' => (string) $shippo_value )
		);
		delete_site_option( 'extrachill_shippo_api_key' );
	}
}
