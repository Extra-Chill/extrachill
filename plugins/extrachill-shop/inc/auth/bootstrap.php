<?php
/**
 * Commerce auth providers bootstrap.
 *
 * Registers the Stripe + Shippo auth providers with Data Machine and migrates
 * any legacy plaintext site_options into the encrypted store.
 *
 * The provider classes extend Data Machine's
 * `DataMachine\Core\OAuth\BaseAuthProvider`, which is supplied by Data
 * Machine's PSR-4 autoloader. That autoloader is not guaranteed to be
 * registered at the moment this file loads, so the provider class files are
 * deferred behind a `class_exists` guard on `plugins_loaded` priority 30
 * (mirroring extrachill-users concert-import bootstrap.php). When Data Machine
 * is absent the commerce auth providers are simply not registered and the read
 * path degrades to "not configured".
 *
 * @package ExtraChill\Shop\Auth
 * @since 0.9.0
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

		\ExtraChill\Shop\Auth\CommerceAuthProvider::register_with_datamachine();
		extrachill_shop_migrate_plaintext_commerce_credentials();
	},
	30
);

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
function extrachill_shop_migrate_plaintext_commerce_credentials(): void {
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
		\ExtraChill\Shop\Auth\StripeAuthProvider::save( $stripe_data );

		foreach ( array_keys( $stripe_data ) as $field ) {
			$option_name = array_search( $field, $stripe_legacy, true );
			if ( false !== $option_name ) {
				delete_site_option( $option_name );
			}
		}
	}

	$shippo_value = get_site_option( 'extrachill_shippo_api_key', '' );
	if ( '' !== $shippo_value && null !== $shippo_value ) {
		\ExtraChill\Shop\Auth\ShippoAuthProvider::save(
			array( 'api_key' => (string) $shippo_value )
		);
		delete_site_option( 'extrachill_shippo_api_key' );
	}
}
