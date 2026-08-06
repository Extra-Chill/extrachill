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
		$GLOBALS['bootstrap_matrix_hooks'][] = array( $hook, $callback, $priority, $accepted_args );
		return true;
	}

	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
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

	$hooks_before_repeat = count( $GLOBALS['bootstrap_matrix_hooks'] );
	\ExtraChillEvents\Providers\CliProvider::register();
	\ExtraChillEvents\Providers\IngestionProvider::register();
	\ExtraChillEvents\Providers\ArtistUrlImportProvider::register();
	\ExtraChillEvents\Providers\VenueBookingProvider::register();
	\ExtraChillEvents\Providers\PublicExperienceProvider::register();
	\ExtraChillEvents\Providers\DataMachineEventsProvider::register();

	echo json_encode(
		array(
			'owner_site'               => ec_is_events_site(),
			'public_registered'        => function_exists( 'ec_events_render_homepage' ),
			'optional_loader_present'  => function_exists( 'extrachill_events_init_data_machine_integration' ),
			'optional_dependency_seen' => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ),
			'ingestion_hooked'         => in_array( 'datamachine_tasks', array_column( $GLOBALS['bootstrap_matrix_hooks'], 0 ), true ),
			'artist_url_registered'    => function_exists( 'ExtraChillEvents\\Api\\register_artist_url_routes' ),
			'booking_registered'       => class_exists( 'ExtraChillEvents\\Core\\BookingRepository', false ),
			'optional_scheduler_seen'  => function_exists( 'as_schedule_single_action' ),
			'optional_users_seen'      => function_exists( 'ec_users_notify_with_receipts' ),
			'cli_commands'             => class_exists( 'WP_CLI', false ) ? count( WP_CLI::$commands ) : 0,
			'cli_command_names'        => class_exists( 'WP_CLI', false ) ? array_keys( WP_CLI::$commands ) : array(),
			'hooks_before_repeat'      => $hooks_before_repeat,
			'hooks_after_repeat'       => count( $GLOBALS['bootstrap_matrix_hooks'] ),
		)
	);
}
