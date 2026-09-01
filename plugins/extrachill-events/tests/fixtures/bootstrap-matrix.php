<?php
/**
 * Isolated runtime fixture for the feature-provider bootstrap matrix.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Purpose-built WordPress runtime stubs must share one isolated fixture.

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );
	$GLOBALS['bootstrap_matrix_hooks'] = array();
	$GLOBALS['bootstrap_matrix_blog']  = 'unrelated' === ( $argv[1] ?? '' ) ? 2 : 7;
	$GLOBALS['bootstrap_matrix_fail']  = 'provider-throwing' === ( $argv[1] ?? '' );
	$GLOBALS['bootstrap_matrix_provider_failures'] = array();
	$GLOBALS['bootstrap_matrix_translation_calls'] = array();

	function __( $text, $domain = 'default' ) {
		if ( 'extrachill-events' === $domain ) {
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 );
			$GLOBALS['bootstrap_matrix_translation_calls'][] = array(
				'file' => $trace[1]['file'] ?? '',
				'line' => $trace[1]['line'] ?? 0,
			);
		}
		return $text;
	}

	function wp_register_ability() {}

	function get_option( $option, $default = false ) {
		$values = array(
			'extrachill_events_booking_schema_version'        => '16',
			'extrachill_events_vendor_request_schema_version' => '1',
		);
		return $values[ $option ] ?? $default;
	}

	function plugin_dir_path( $file ) {
		return trailingslashit( dirname( $file ) );
	}

	function plugin_dir_url( $file ) {
		return 'https://example.org/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}

	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}

	function trailingslashit( $value ) {
		return rtrim( $value, '/\\' ) . '/';
	}

	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		if ( $GLOBALS['bootstrap_matrix_fail'] && 'datamachine_tasks' === $hook ) {
			$GLOBALS['bootstrap_matrix_fail'] = false;
			throw new RuntimeException( 'Injected ingestion provider failure.' );
		}
		$GLOBALS['bootstrap_matrix_hooks'][] = array( $hook, $callback, $priority, $accepted_args );
		return true;
	}

	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}

	function apply_filters( $hook, $value ) {
		return $value;
	}

	function do_action( $hook, ...$args ) {
		if ( 'extrachill_events_provider_failed' === $hook ) {
			$GLOBALS['bootstrap_matrix_provider_failures'][] = $args[0] ?? '';
		}
	}

	function register_activation_hook() {}
	function register_deactivation_hook() {}
	function is_admin() {
		return false;
	}

	function get_current_blog_id() {
		return $GLOBALS['bootstrap_matrix_blog'];
	}

	function ec_get_blog_id( $site ) {
		return 'events' === $site ? 7 : null;
	}

	if ( 'cli' === ( $argv[1] ?? '' ) ) {
		define( 'WP_CLI', true );
		class WP_CLI {
			public static $commands = array();

			public static function add_command( $name, $class ) {
				self::$commands[ $name ] = $class;
			}
		}
	}

	require dirname( __DIR__, 2 ) . '/extrachill-events.php';

	foreach ( $GLOBALS['bootstrap_matrix_hooks'] as $registered_hook ) {
		if ( 'plugins_loaded' === $registered_hook[0] && array( \ExtraChillEvents\Providers\AbilitiesProvider::class, 'initialize' ) === $registered_hook[1] ) {
			call_user_func( $registered_hook[1] );
		}
	}

	$hooks_before_repeat = count( $GLOBALS['bootstrap_matrix_hooks'] );
	\ExtraChillEvents\Providers\CliProvider::register();
	\ExtraChillEvents\Providers\IngestionProvider::register();
	\ExtraChillEvents\Providers\AdministrationProvider::register();
	\ExtraChillEvents\Providers\LifecycleProvider::register();
	\ExtraChillEvents\Providers\CoreRuntimeProvider::register();
	\ExtraChillEvents\Providers\ArtistUrlImportProvider::register();
	\ExtraChillEvents\Providers\VenueBookingProvider::register();
	\ExtraChillEvents\Providers\PublicExperienceProvider::register();
	\ExtraChillEvents\Providers\AbilitiesProvider::register();
	\ExtraChillEvents\Providers\DataMachineEventsProvider::register();

	echo json_encode(
		array(
			'owner_site'               => ec_is_events_site(),
			'public_registered'        => function_exists( 'ec_events_render_homepage' ),
			'optional_loader_present'  => function_exists( 'extrachill_events_init_data_machine_integration' ),
			'optional_dependency_seen' => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ),
			'ingestion_hooked'         => in_array( 'datamachine_tasks', array_column( $GLOBALS['bootstrap_matrix_hooks'], 0 ), true ),
			'event_source_registered'  => function_exists( 'ExtraChillEvents\\Api\\register_event_source_routes' ),
			'booking_registered'       => class_exists( 'ExtraChillEvents\\Core\\BookingRepository', false ),
			'optional_scheduler_seen'  => function_exists( 'as_schedule_single_action' ),
			'optional_users_seen'      => function_exists( 'ec_users_notify_with_receipts' ),
			'cli_commands'             => class_exists( 'WP_CLI', false ) ? count( WP_CLI::$commands ) : 0,
			'cli_command_names'        => class_exists( 'WP_CLI', false ) ? array_keys( WP_CLI::$commands ) : array(),
			'provider_failures'        => $GLOBALS['bootstrap_matrix_provider_failures'],
			'translation_calls'        => $GLOBALS['bootstrap_matrix_translation_calls'],
			'ability_lifecycle_hooked' => in_array( array( 'plugins_loaded', array( \ExtraChillEvents\Providers\AbilitiesProvider::class, 'initialize' ), 25, 1 ), $GLOBALS['bootstrap_matrix_hooks'], true ),
			'public_contract_hooks'    => array_values(
				array_intersect(
					array( 'extrachill_homepage_content', 'extrachill_template_archive', 'template_redirect', 'admin_post_ec_accept_venue_invitation', 'admin_post_nopriv_ec_accept_venue_invitation', 'ec_points_sources' ),
					array_column( $GLOBALS['bootstrap_matrix_hooks'], 0 )
				)
			),
			'hooks_before_repeat'      => $hooks_before_repeat,
			'hooks_after_repeat'       => count( $GLOBALS['bootstrap_matrix_hooks'] ),
		)
	);
}
