<?php
/**
 * Base auth provider for Extra Chill commerce credentials.
 *
 * Stripe (secret/publishable/connect client id/webhook secret) and Shippo (API
 * key) credentials are held in Data Machine's encrypted auth envelope so they
 * live at rest with the same AES-256-GCM protection used by every other Data
 * Machine handler, and so they surface in `wp datamachine auth ...`.
 *
 * These providers live at the NETWORK layer (extrachill-network) because they
 * must be loaded in BOTH contexts that touch commerce credentials:
 *   - network-admin, where the Network Admin > Payments save handler writes
 *     them (extrachill-network is network-active, so its PHP loads here), and
 *   - blog 3 (the shop), where checkout / webhook / shipping reads them.
 * A blog-scoped plugin (extrachill-shop, Requires Plugins: woocommerce) cannot
 * load in network-admin, so a provider that lived there was unreachable from
 * the save handler and the save silently no-op'd. See #92.
 *
 * Storage layout (`get_site_option( 'datamachine_auth_data' )`):
 *
 *   [
 *       'ec_stripe' => [
 *           'config' => [
 *               'secret_key'        => 'dm:enc:v1:...:...:...',
 *               'publishable_key'   => 'pk_live_...',   // plaintext by design
 *               'connect_client_id' => 'dm:enc:v1:...:...:...',
 *               'webhook_secret'    => 'dm:enc:v1:...:...:...',
 *           ],
 *       ],
 *       'ec_shippo' => [
 *           'config' => [ 'api_key' => 'dm:enc:v1:...:...:...' ],
 *       ],
 *   ]
 *
 * The sensitive fields are added to the encrypted-at-rest list via the
 * `datamachine_auth_encrypted_fields` filter (see register_with_datamachine()).
 * Encryption/decryption is handled by `BaseAuthProvider::encrypt_fields()` /
 * `decrypt_fields()` using AES-256-GCM keyed from `wp_salt( 'auth' )`.
 *
 * @package ExtraChillNetwork\Commerce\Auth
 * @since 1.23.0
 */

namespace ExtraChillNetwork\Commerce\Auth;

use DataMachine\Core\OAuth\BaseAuthProvider;

defined( 'ABSPATH' ) || exit;

abstract class CommerceAuthProvider extends BaseAuthProvider {

	/**
	 * Provider slug for this concrete provider.
	 *
	 * @return string
	 */
	abstract public static function slug(): string;

	/**
	 * Field names (from get_config_fields()) that must be encrypted at rest.
	 *
	 * @return array<string>
	 */
	abstract public static function encrypted_fields(): array;

	/**
	 * Read a single config value as a trimmed string.
	 *
	 * Decryption happens inside BaseAuthProvider::get_config().
	 *
	 * @param string $field Field name.
	 * @return string Decrypted value, or empty string when unset.
	 */
	protected function config_string( string $field ): string {
		$config = $this->get_config();
		return isset( $config[ $field ] ) ? trim( (string) $config[ $field ] ) : '';
	}

	/**
	 * Write the provider's credentials through the encrypted store.
	 *
	 * Sensitive fields are encrypted by BaseAuthProvider::save_config() before
	 * storage. Call statically on a concrete subclass, e.g.
	 * `StripeAuthProvider::save( $creds )`.
	 *
	 * @param array $data Credential map keyed by config field name.
	 * @return bool True on successful write.
	 */
	public static function save( array $data ): bool {
		// Late static binding resolves to the concrete subclass
		// (e.g. StripeAuthProvider::save() -> new StripeAuthProvider()).
		$instance = new static();
		return $instance->save_config( $data );
	}

	/**
	 * Register every commerce auth provider with Data Machine and mark each
	 * provider's sensitive fields as encrypted-at-rest.
	 *
	 * Idempotent — safe to call multiple times.
	 */
	public static function register_with_datamachine(): void {
		$providers = array(
			StripeAuthProvider::class,
			ShippoAuthProvider::class,
		);

		add_filter(
			'datamachine_auth_providers',
			static function ( $providers_list ) use ( $providers ) {
				if ( ! is_array( $providers_list ) ) {
					$providers_list = array();
				}
				foreach ( $providers as $provider_class ) {
					$slug = $provider_class::slug();
					if ( ! isset( $providers_list[ $slug ] ) ) {
						$providers_list[ $slug ] = new $provider_class();
					}
				}
				return $providers_list;
			}
		);

		add_filter(
			'datamachine_auth_encrypted_fields',
			static function ( $fields, string $provider_slug ) use ( $providers ) {
				if ( ! is_array( $fields ) ) {
					$fields = array();
				}
				foreach ( $providers as $provider_class ) {
					if ( $provider_class::slug() === $provider_slug ) {
						$fields = array_merge( $fields, $provider_class::encrypted_fields() );
						break;
					}
				}
				return $fields;
			},
			10,
			2
		);
	}
}
