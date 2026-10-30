<?php
/**
 * Shippo Data Machine auth provider.
 *
 * Holds the encrypted platform-wide Shippo API token. Discoverable via
 * `wp datamachine auth status` and managed via
 * `wp datamachine auth config ec_shippo --api_key=...`.
 *
 * @package ExtraChillNetwork\Commerce\Auth
 * @since 1.23.0
 */

namespace ExtraChillNetwork\Commerce\Auth;

defined( 'ABSPATH' ) || exit;

final class ShippoAuthProvider extends CommerceAuthProvider {

	public const PROVIDER_SLUG = 'ec_shippo';

	public function __construct() {
		parent::__construct( self::PROVIDER_SLUG );
	}

	public static function slug(): string {
		return self::PROVIDER_SLUG;
	}

	public function get_config_fields(): array {
		return array(
			'api_key' => array(
				'label'       => __( 'API Key', 'extrachill-network' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Shippo live API token (shippo_live_...). Confidential.', 'extrachill-network' ),
			),
		);
	}

	public static function encrypted_fields(): array {
		return array( 'api_key' );
	}

	public function is_authenticated(): bool {
		return '' !== $this->get_api_key();
	}

	public function get_api_key(): string {
		return $this->config_string( 'api_key' );
	}
}
