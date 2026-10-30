<?php
/**
 * Stripe Data Machine auth provider.
 *
 * Holds the encrypted platform-wide Stripe Connect credentials. Discoverable
 * via `wp datamachine auth status` and managed via
 * `wp datamachine auth config ec_stripe --secret_key=...`.
 *
 * `publishable_key` is intentionally NOT encrypted — Stripe publishable keys
 * are non-secret by design (they are exposed in frontend checkout HTML).
 *
 * @package ExtraChillNetwork\Commerce\Auth
 * @since 1.23.0
 */

namespace ExtraChillNetwork\Commerce\Auth;

defined( 'ABSPATH' ) || exit;

final class StripeAuthProvider extends CommerceAuthProvider {

	public const PROVIDER_SLUG = 'ec_stripe';

	public function __construct() {
		parent::__construct( self::PROVIDER_SLUG );
	}

	public static function slug(): string {
		return self::PROVIDER_SLUG;
	}

	public function get_config_fields(): array {
		return array(
			'secret_key'        => array(
				'label'       => __( 'Secret Key', 'extrachill-network' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Stripe secret API key (sk_live_... / sk_test_...). Confidential.', 'extrachill-network' ),
			),
			'publishable_key'   => array(
				'label'       => __( 'Publishable Key', 'extrachill-network' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Stripe publishable key (pk_live_... / pk_test_...). Non-secret by Stripe design.', 'extrachill-network' ),
			),
			'connect_client_id' => array(
				'label'       => __( 'Connect Client ID', 'extrachill-network' ),
				'type'        => 'password',
				'required'    => false,
				'description' => __( 'Stripe Connect client ID (ca_...).', 'extrachill-network' ),
			),
			'webhook_secret'    => array(
				'label'       => __( 'Webhook Signing Secret', 'extrachill-network' ),
				'type'        => 'password',
				'required'    => false,
				'description' => __( 'Signing secret for the Stripe webhook endpoint (whsec_...).', 'extrachill-network' ),
			),
		);
	}

	public static function encrypted_fields(): array {
		// publishable_key intentionally omitted — public by Stripe design.
		return array( 'secret_key', 'connect_client_id', 'webhook_secret' );
	}

	public function is_authenticated(): bool {
		$config = $this->get_config();
		return ! empty( $config['secret_key'] ) && ! empty( $config['publishable_key'] );
	}

	public function get_secret_key(): string {
		return $this->config_string( 'secret_key' );
	}

	public function get_publishable_key(): string {
		return $this->config_string( 'publishable_key' );
	}

	public function get_connect_client_id(): string {
		return $this->config_string( 'connect_client_id' );
	}

	public function get_webhook_secret(): string {
		return $this->config_string( 'webhook_secret' );
	}
}
