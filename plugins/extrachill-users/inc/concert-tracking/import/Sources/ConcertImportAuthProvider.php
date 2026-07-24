<?php
/**
 * Base auth provider for concert-import sources.
 *
 * Each concert-import source (setlist.fm, phish.net, …) stores a single
 * platform-wide API key. The key is held in Data Machine's encrypted auth
 * envelope so credentials live at rest with the same AES-256-GCM protection
 * used by every other Data Machine handler, and so they show up in the
 * `wp datamachine auth ...` CLI surface.
 *
 * Each concrete subclass owns a unique provider slug (e.g.
 * `ec_concert_import_setlist_fm`). One key per slug. No `account`/`username`
 * fan-out — single-key sources don't need it.
 *
 * Storage layout (`get_site_option( 'datamachine_auth_data' )`):
 *
 *   [
 *       'ec_concert_import_setlist_fm' => [
 *           'config' => [ 'api_key' => 'dm:enc:v1:...:...:...' ],
 *       ],
 *       'ec_concert_import_phish_net' => [
 *           'config' => [ 'api_key' => 'dm:enc:v1:...:...:...' ],
 *       ],
 *   ]
 *
 * `api_key` is added to the encrypted-fields list via the
 * `datamachine_auth_encrypted_fields` filter (see register_encryption()).
 *
 * @package ExtraChill\Users\Concert_Import
 * @since 0.14.0
 */

namespace ExtraChill\Users\Concert_Import\Sources;

use DataMachine\Core\OAuth\BaseAuthProvider;

defined( 'ABSPATH' ) || exit;

abstract class ConcertImportAuthProvider extends BaseAuthProvider {

	/**
	 * Configurable field name used for credential entry + retrieval.
	 *
	 * Kept as `api_key` (not `password`) because the CLI / admin UI surfaces
	 * the field label directly — `api_key` reads correctly for this kind of
	 * platform-wide credential.
	 */
	public const CREDENTIAL_FIELD = 'api_key';

	/**
	 * Configuration fields surfaced via `wp datamachine auth config <slug> ...`.
	 *
	 * Single field. No account/username — these sources have one platform-wide
	 * key, not per-account credentials.
	 */
	public function get_config_fields(): array {
		return array(
			self::CREDENTIAL_FIELD => array(
				'label'       => __( 'API Key', 'extrachill-users' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Platform-wide API key for this concert import source.', 'extrachill-users' ),
			),
		);
	}

	/**
	 * Whether a usable credential is stored.
	 *
	 * Stored at the site-level config slot so the credential is shared across
	 * the multisite network. Concert tracking is network-scoped; the credential
	 * is too.
	 */
	public function is_authenticated(): bool {
		return '' !== $this->get_api_key();
	}

	/**
	 * Return the decrypted API key, or empty string when none is configured.
	 *
	 * Reads the site-level config slot. Decryption happens inside
	 * BaseAuthProvider::get_config().
	 */
	public function get_api_key(): string {
		$config = $this->get_config();
		$value  = isset( $config[ self::CREDENTIAL_FIELD ] ) ? (string) $config[ self::CREDENTIAL_FIELD ] : '';
		return trim( $value );
	}

	/**
	 * Register all concert-import auth providers with the Data Machine registry
	 * and mark `api_key` as encrypted-at-rest for each one.
	 *
	 * Idempotent — safe to call multiple times.
	 */
	public static function register_with_datamachine(): void {
		add_filter(
			'datamachine_auth_providers',
			static function ( $providers ) {
				if ( ! is_array( $providers ) ) {
					$providers = array();
				}
				$slug               = SetlistFmAuthProvider::PROVIDER_SLUG;
				$providers[ $slug ] = $providers[ $slug ] ?? new SetlistFmAuthProvider();

				$slug               = PhishNetAuthProvider::PROVIDER_SLUG;
				$providers[ $slug ] = $providers[ $slug ] ?? new PhishNetAuthProvider();

				return $providers;
			}
		);

		add_filter(
			'datamachine_auth_encrypted_fields',
			static function ( array $fields, string $provider_slug ): array {
				if ( in_array(
					$provider_slug,
					array( SetlistFmAuthProvider::PROVIDER_SLUG, PhishNetAuthProvider::PROVIDER_SLUG ),
					true
				) ) {
					$fields[] = self::CREDENTIAL_FIELD;
				}

				return $fields;
			},
			10,
			2
		);
	}
}
