<?php
/**
 * Events plugin lifecycle and schema registration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Owns plugin lifecycle hooks without coupling them to optional integrations. */
final class LifecycleProvider {

	/**
	 * Whether lifecycle hooks have registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Register lifecycle, localization, and schema hooks once. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_filter( 'ec_feature_ceilings', array( self::class, 'register_feature_ceilings' ) );
		add_action( 'init', array( self::class, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( self::class, 'maybe_install_schema' ), 20 );
		register_activation_hook( EXTRACHILL_EVENTS_PLUGIN_FILE, array( self::class, 'activate' ) );
		register_deactivation_hook( EXTRACHILL_EVENTS_PLUGIN_FILE, array( self::class, 'deactivate' ) );
	}

	/**
	 * Keep venue booking tools on the established internal-team rollout rung.
	 *
	 * @param array $ceilings Existing feature ceilings.
	 * @return array
	 */
	public static function register_feature_ceilings( array $ceilings ): array {
		if ( class_exists( '\\ExtraChillEvents\\Core\\VenueAuthorization' ) ) {
			$ceilings[ \ExtraChillEvents\Core\VenueAuthorization::FEATURE ] = 'team';
		}
		return $ceilings;
	}

	/** Load translations from the established plugin language directory. */
	public static function load_textdomain(): void {
		$plugin_basename = (string) constant( 'EXTRACHILL_EVENTS_PLUGIN_BASENAME' );
		load_plugin_textdomain( 'extrachill-events', false, dirname( $plugin_basename ) . '/languages' );
	}

	/** Install every Events-owned schema during activation. */
	public static function activate(): void {
		\ExtraChillEvents\Core\QualifyVerdictsTable::create_table();
		\ExtraChillEvents\Core\ArtistUrlSubmissionsTable::create_table();
		if ( class_exists( '\\ExtraChillEvents\\Core\\PromoterAuthoritySchema' ) ) {
			\ExtraChillEvents\Core\PromoterAuthoritySchema::install();
		}
		if ( class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
			\ExtraChillEvents\Core\RsvpPassesTable::create_table();
		}
		flush_rewrite_rules();
	}

	/** Flush routes when the plugin is deactivated. */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Idempotently install schemas after providers have loaded their classes.
	 *
	 * Runs on every request, so the steady state is a lock-free comparison of
	 * stored schema versions. When any schema is stale (the first requests
	 * after a deploy), installs are serialized behind a network-wide MySQL
	 * advisory lock: the request that wins re-checks the stored versions with
	 * fresh option caches and installs; requests that lose skip, and a later
	 * request retries if the winner did not finish.
	 */
	public static function maybe_install_schema(): void {
		if ( self::schemas_current() ) {
			return;
		}

		$lock_name = self::schema_lock_name();
		if ( ! self::acquire_schema_lock( $lock_name ) ) {
			return;
		}

		try {
			// Double-checked: another request may have installed while this one
			// waited on the lock, and its version writes are not visible
			// through this request's option cache.
			self::refresh_schema_version_caches();
			if ( self::schemas_current() ) {
				return;
			}
			self::install_stale_schemas();
		} finally {
			self::release_schema_lock( $lock_name );
		}
	}

	/** Run each version-gated installer; each skips itself when current. */
	private static function install_stale_schemas(): void {
		if ( class_exists( '\\ExtraChillEvents\\Core\\QualifyVerdictsTable' ) ) {
			\ExtraChillEvents\Core\QualifyVerdictsTable::maybe_install();
		}
		if ( class_exists( '\\ExtraChillEvents\\Core\\ArtistUrlSubmissionsTable' ) ) {
			\ExtraChillEvents\Core\ArtistUrlSubmissionsTable::maybe_install();
		}
		if ( class_exists( '\\ExtraChillEvents\\Core\\PromoterAuthoritySchema' ) ) {
			\ExtraChillEvents\Core\PromoterAuthoritySchema::maybe_install();
		}
		if ( class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
			\ExtraChillEvents\Core\RsvpPassesTable::maybe_install();
		}
	}

	/**
	 * Expected version for every schema installed through maybe_install_schema().
	 *
	 * Keyed by version option name. Integrations register their own entries
	 * via the `extrachill_events_schema_versions` filter so a stale
	 * integration schema also opens the install path.
	 *
	 * @return array<string, array{version: string|null, network: bool}>
	 */
	private static function schema_versions(): array {
		$schemas = array(
			'QualifyVerdictsTable'      => array( 'SCHEMA_VERSION_OPTION', false ),
			'ArtistUrlSubmissionsTable' => array( 'VERSION_OPTION', true ),
			'PromoterAuthoritySchema'   => array( 'VERSION_OPTION', false ),
			'RsvpPassesTable'           => array( 'VERSION_OPTION', false ),
		);

		$versions = array();
		foreach ( $schemas as $short_name => $definition ) {
			$class = '\\ExtraChillEvents\\Core\\' . $short_name;
			if ( ! class_exists( $class ) ) {
				continue;
			}
			list( $option_constant, $network ) = $definition;
			$option_key                        = $class . '::' . $option_constant;
			$version_key                       = $class . '::SCHEMA_VERSION';
			if ( ! defined( $option_key ) ) {
				// Unknown version contract: always offer it the install path.
				$versions[ 'unknown:' . $short_name ] = array(
					'version' => null,
					'network' => false,
				);
				continue;
			}
			$versions[ (string) constant( $option_key ) ] = array(
				'version' => defined( $version_key ) ? (string) constant( $version_key ) : null,
				'network' => $network,
			);
		}

		$filtered = apply_filters( 'extrachill_events_schema_versions', $versions );
		return is_array( $filtered ) ? $filtered : $versions;
	}

	/** Whether every registered schema's stored version matches its code version. */
	private static function schemas_current(): bool {
		foreach ( self::schema_versions() as $option => $schema ) {
			$expected = $schema['version'] ?? null;
			if ( null === $expected ) {
				return false;
			}
			$stored = empty( $schema['network'] ) ? get_option( (string) $option, '' ) : get_site_option( (string) $option, '' );
			if ( (string) $expected !== (string) $stored ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Drop request-local option caches for every schema version marker.
	 *
	 * Without this, the post-lock re-check (and each installer's own version
	 * gate) would read the values cached before the lock was acquired and
	 * re-run dbDelta after a concurrent request already finished.
	 */
	private static function refresh_schema_version_caches(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$network_id = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;
		wp_cache_delete( $network_id . ':notoptions', 'site-options' );
		foreach ( array_keys( self::schema_versions() ) as $option ) {
			wp_cache_delete( (string) $option, 'options' );
			wp_cache_delete( $network_id . ':' . $option, 'site-options' );
		}
	}

	/**
	 * Advisory lock name shared by every site in this network.
	 *
	 * Network-wide because ArtistUrlSubmissionsTable lives on the base prefix.
	 * MySQL caps lock names at 64 characters; this is 57.
	 */
	private static function schema_lock_name(): string {
		global $wpdb;
		$database = defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '';
		$prefix   = isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : '';
		return 'ec_events_schema_' . substr( hash( 'sha256', $database . "\0" . $prefix ), 0, 40 );
	}

	/**
	 * Try to take the install lock without waiting.
	 *
	 * GET_LOCK is released automatically if the connection drops, so a fatal
	 * mid-install cannot leave the lock stuck.
	 *
	 * @param string $lock_name Advisory lock name.
	 */
	private static function acquire_schema_lock( string $lock_name ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes concurrent schema installers across the network.
		return '1' === (string) $acquired;
	}

	/**
	 * Release the install lock taken by acquire_schema_lock().
	 *
	 * @param string $lock_name Advisory lock name.
	 */
	private static function release_schema_lock( string $lock_name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the exact installer lock.
	}
}
