<?php
/**
 * Events-owned (venue and promoter) Link Pages integration bootstrap.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Defers Events adapters until the Link Pages runtime reports itself valid and ready.
 *
 * TODO: Add the native plugin dependency after Link Pages PR #4 is merged,
 * released, and installed across the deployment stack.
 */
final class LinkPagesProvider {

	private const PLUGIN = 'extrachill-link-pages/extrachill-link-pages.php';

	/**
	 * Whether the deferred hook registered in this request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Attach the deferred bootstrap once. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'plugins_loaded', array( self::class, 'initialize' ), 30 );
	}

	/**
	 * Events owner kinds, keyed by standalone provider name.
	 *
	 * Class names stay strings so a failed runtime preflight never loads the
	 * venue adapter.
	 */
	private const OWNERS = array(
		'events-venues'    => array( '\\ExtraChillEvents\\Core\\VenueLinkPages', 'venue' ),
		'events-promoters' => array( '\\ExtraChillEvents\\Core\\PromoterLinkPages', 'promoter' ),
	);

	/** Preflight every owner registry before mutating any, then register both kinds. */
	public static function initialize() {
		add_filter( 'extrachill_events_promoter_authority_precommit', array( self::class, 'promoter_authority_precommit' ), 20, 3 );
		$valid = self::validate_runtime();
		if ( is_wp_error( $valid ) ) {
			self::record_error( $valid );
			return $valid;
		}

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueLinkPages.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/PromoterLinkPages.php';
		$registries = array(
			'ec_can_register_link_page_owner_compatibility_provider' => array( 'ec_register_link_page_owner_compatibility_provider', 'compatibility_provider' ),
			'ec_can_register_link_page_operation_provider' => array( 'ec_register_link_page_operation_provider', 'operation_provider' ),
			'ec_can_register_link_page_public_projection_provider' => array( 'ec_register_link_page_public_projection_provider', 'public_projection_provider' ),
		);
		foreach ( array( 0, 1 ) as $commit ) {
			foreach ( self::OWNERS as $name => $owner ) {
				foreach ( $registries as $preflight => $registry ) {
					$result = call_user_func( $commit ? $registry[0] : $preflight, $name, array( $owner[0], $registry[1] ) );
					if ( is_wp_error( $result ) ) {
						self::record_error( $result );
						return $result;
					}
				}
			}
		}

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/TeamTermLinkPageAbilities.php';
		foreach ( self::OWNERS as $owner ) {
			call_user_func( array( $owner[0], 'register_hooks' ) );
			new \ExtraChillEvents\Abilities\TeamTermLinkPageAbilities( $owner[1] );
		}
		self::clear_logged_error();
		return true;
	}

	/**
	 * Withdraw a promoter's page inside the revocation transaction.
	 *
	 * Registered before runtime validation: when the promoter adapter could
	 * not load, revocation fails closed so a public page never outlives its
	 * organization's authority.
	 *
	 * @param mixed  $allowed          Prior precommit decision.
	 * @param int    $promoter_term_id Promoter term ID.
	 * @param string $change           Authority change.
	 */
	public static function promoter_authority_precommit( $allowed, $promoter_term_id, $change ) {
		if ( true !== $allowed || 'organization_revoked' !== $change ) {
			return $allowed;
		}
		if ( ! class_exists( '\\ExtraChillEvents\\Core\\PromoterLinkPages', false ) ) {
			return new \WP_Error( 'promoter_link_page_runtime_unavailable', __( 'Promoter authority cannot be revoked while its public projection runtime is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return \ExtraChillEvents\Core\PromoterLinkPages::authority_precommit( $allowed, $promoter_term_id, $change );
	}

	/**
	 * Validate configured activation, then trust the runtime's own validation.
	 *
	 * The runtime checks its function contract, storage contract and API
	 * version in `ec_validate_link_pages_runtime()`. Events ships from the same
	 * monorepo commit, so it does not keep its own copy of those signatures.
	 *
	 * Runs on `plugins_loaded` (before `init`), so every branch here must return
	 * an UNTRANSLATED error message. Translating on `plugins_loaded` trips core's
	 * `_load_textdomain_just_in_time()` "too early" notice on every request. The
	 * message is translated lazily at render time in `error_notice()`, which only
	 * runs on `admin_notices`/`network_admin_notices` (safely after `init`).
	 */
	public static function validate_runtime() {
		$active         = (array) get_option( 'active_plugins', array() );
		$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
		if ( ! in_array( self::PLUGIN, $active, true ) && ! isset( $network_active[ self::PLUGIN ] ) ) {
			return new \WP_Error( 'venue_link_pages_runtime_not_configured', 'Extra Chill Link Pages must be active before venue Link Pages can load.' );
		}
		if ( ! function_exists( 'ec_validate_link_pages_runtime' ) || ! class_exists( '\\ExtraChill\\LinkPages\\Owned_Link_Pages' ) ) {
			return new \WP_Error( 'venue_link_pages_runtime_incomplete', 'The configured Extra Chill Link Pages runtime is incomplete.' );
		}
		$valid = ec_validate_link_pages_runtime();
		if ( is_wp_error( $valid ) ) {
			return new \WP_Error( 'venue_link_pages_runtime_incompatible', $valid->get_error_message(), array( 'cause' => $valid->get_error_code() ) );
		}
		if ( ! function_exists( 'ec_link_pages_runtime_ready' ) || true !== ec_link_pages_runtime_ready() ) {
			return new \WP_Error( 'venue_link_pages_runtime_incomplete', 'The configured Extra Chill Link Pages runtime is not ready.' );
		}
		if ( ! function_exists( 'ec_get_link_page_storage_blog_id' ) || ! ec_get_link_page_storage_blog_id() ) {
			return new \WP_Error( 'venue_link_pages_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
		}
		return true;
	}

	/** Site option recording the last error code that was written to the log. */
	private const LOGGED_ERROR_OPTION = 'extrachill_events_venue_link_pages_logged_error';

	/**
	 * Make integration failure visible without partially registering adapters.
	 *
	 * The admin notice (added below) already provides ongoing operator
	 * visibility on every admin page load, so `error_log()` only needs to fire
	 * once per distinct error state, not once per request forever. Without this
	 * guard, a permanently-unsatisfied optional dependency (the common case:
	 * the sibling plugin is not installed at all) writes an identical line to
	 * `debug.log` on every single request indefinitely.
	 *
	 * @param \WP_Error $error Untranslated integration failure to record.
	 */
	private static function record_error( \WP_Error $error ): void {
		$GLOBALS['extrachill_events_venue_link_pages_error'] = $error;
		$code = $error->get_error_code();
		if ( get_option( self::LOGGED_ERROR_OPTION, '' ) !== $code ) {
			update_option( self::LOGGED_ERROR_OPTION, $code, false );
			error_log( 'Extra Chill Events Link Pages: ' . $error->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A partial cross-plugin runtime must be operator-visible, logged once per distinct error state.
		}
		add_action( 'admin_notices', array( self::class, 'error_notice' ) );
		add_action( 'network_admin_notices', array( self::class, 'error_notice' ) );
		do_action( 'extrachill_events_venue_link_pages_error', $error );
	}

	/**
	 * Forget the last logged error so a later recurrence is logged again.
	 *
	 * Without this, the log-once guard would suppress a genuine regression:
	 * once the runtime recovers, the stale error code stays recorded, and an
	 * identical failure later would be silently swallowed instead of surfacing
	 * in `debug.log`. Clearing on success bounds the guard to a single
	 * contiguous failure state rather than the lifetime of the install.
	 */
	private static function clear_logged_error(): void {
		if ( '' !== (string) get_option( self::LOGGED_ERROR_OPTION, '' ) ) {
			delete_option( self::LOGGED_ERROR_OPTION );
		}
	}

	/**
	 * Render the stored integration error.
	 *
	 * Runs on `admin_notices`/`network_admin_notices`, both of which fire after
	 * `init`, so translating here is safe and does not trigger core's
	 * "translation loaded too early" notice.
	 */
	public static function error_notice(): void {
		$error = $GLOBALS['extrachill_events_venue_link_pages_error'] ?? null;
		if ( is_wp_error( $error ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( self::translate_error_message( $error ) ) );
		}
	}

	/**
	 * Translate a runtime validation error message for display, safe only after `init`.
	 *
	 * @param \WP_Error $error Untranslated integration failure to render.
	 * @return string Translated message, or the stored message when the code is unmapped.
	 */
	public static function translate_error_message( \WP_Error $error ): string {
		$translations = array(
			'venue_link_pages_runtime_not_configured' => __( 'Extra Chill Link Pages must be active before venue Link Pages can load.', 'extrachill-events' ),
			'venue_link_pages_runtime_incomplete'     => __( 'The configured Extra Chill Link Pages runtime is incomplete.', 'extrachill-events' ),
			'venue_link_pages_runtime_incompatible'   => __( 'The configured Extra Chill Link Pages runtime has an incompatible signature.', 'extrachill-events' ),
			'venue_link_pages_storage_unavailable'    => __( 'The canonical Link Page storage blog is unavailable.', 'extrachill-events' ),
		);
		return $translations[ $error->get_error_code() ] ?? $error->get_error_message();
	}
}
