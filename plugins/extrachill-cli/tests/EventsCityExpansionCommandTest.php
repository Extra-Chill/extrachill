<?php
/**
 * Contract tests for `wp extrachill events add-city`,
 * `wp extrachill venues expand` and `wp extrachill venues expand-report`.
 *
 * Run standalone: php tests/EventsCityExpansionCommandTest.php
 */

namespace DataMachine\Cli {
	if ( ! class_exists( 'DataMachine\\Cli\\AgentResolver' ) ) {
		class AgentResolver {
			public static function resolveEffectiveContext( array $assoc_args ): array {
				return array( 'agent_slug' => $assoc_args['agent'] ?? 'default-agent' );
			}
		}
	}
}

namespace {
	// The doubles would collide with a real WordPress bootstrap; declarations
	// stay inside the conditional block so they are not hoisted.
	$events_city_expansion_wp_bootstrapped = function_exists( 'wp_get_ability' );

	if ( ! $events_city_expansion_wp_bootstrapped ) {
		define( 'ABSPATH', __DIR__ . '/' );

		class EventsCityExpansionTestError extends \RuntimeException {}

		class WP_CLI {
			public static $messages = array();
			public static function error( $message ) { throw new EventsCityExpansionTestError( $message ); }
			public static function log( $message ) { self::$messages[] = $message; }
			public static function success( $message ) { self::$messages[] = 'Success: ' . $message; }
			public static function warning( $message ) { self::$messages[] = 'Warning: ' . $message; }
		}

		class WP_Error {
			private $message;
			public function __construct( $code, $message ) { $this->message = $message; }
			public function get_error_message() { return $this->message; }
		}

		class EventsCityExpansionTestAbility {
			public $inputs = array();
			public $result = array();
			public function execute( $input ) { $this->inputs[] = $input; return $this->result; }
		}

		$GLOBALS['ece_abilities'] = array();
		$GLOBALS['ece_blog_id']   = 7;

		function wp_get_ability( $slug ) { return $GLOBALS['ece_abilities'][ $slug ] ?? null; }
		function is_wp_error( $value ) { return $value instanceof WP_Error; }
		function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
		function ec_get_blog_id( $key ) { return 'events' === $key ? 7 : 0; }
		function ec_get_site_url( $key ) { return 'https://' . $key . '.extrachill.com'; }
		function get_current_blog_id() { return $GLOBALS['ece_blog_id']; }

		require_once dirname( __DIR__ ) . '/inc/OwnerSiteAbility.php';
		require_once dirname( __DIR__ ) . '/inc/Commands/Events/AddCityCommand.php';
		require_once dirname( __DIR__ ) . '/inc/Commands/Events/VenueExpandCommand.php';
		require_once dirname( __DIR__ ) . '/inc/Commands/Events/VenueExpansionReportCommand.php';
		require_once dirname( __DIR__ ) . '/inc/CommandRegistry.php';

		$failures = 0;
		$assert   = static function ( $condition, $message ) use ( &$failures ) {
			if ( ! $condition ) {
				++$failures;
				fwrite( STDERR, "FAIL: {$message}\n" );
			}
		};
		$expect_error = static function ( callable $run ) {
			try {
				$run();
			} catch ( EventsCityExpansionTestError $e ) {
				return $e->getMessage();
			}
			return null;
		};

		$add_city = new EventsCityExpansionTestAbility();
		$expand   = new EventsCityExpansionTestAbility();
		$report   = new EventsCityExpansionTestAbility();

		$GLOBALS['ece_abilities'] = array(
			'extrachill/add-city'                => $add_city,
			'extrachill/expand-venues'           => $expand,
			'extrachill/venue-expansion-report'  => $report,
		);

		// Registry: the old extrachill-events roots move under extrachill events|venues.
		$map = \ExtraChill\CLI\CommandRegistry::map();
		$assert( \ExtraChill\CLI\Commands\Events\AddCityCommand::class === ( $map['extrachill events add-city'] ?? null ), 'registry maps events add-city' );
		$assert( \ExtraChill\CLI\Commands\Events\VenueExpandCommand::class === ( $map['extrachill venues expand'] ?? null ), 'registry maps venues expand' );
		$assert( \ExtraChill\CLI\Commands\Events\VenueExpansionReportCommand::class === ( $map['extrachill venues expand-report'] ?? null ), 'registry maps venues expand-report' );
		foreach ( array_keys( $map ) as $command ) {
			$assert( 0 === strpos( $command, 'extrachill ' ), "{$command} lives under wp extrachill" );
		}

		// add-city: flags map onto the ability input with the old defaults.
		$add_city->result = array(
			'message'     => 'Added Nashville.',
			'coordinates' => '36.1,-86.7',
			'pipeline_id' => 12,
			'flows'       => array(
				array( 'name' => 'Ticketmaster', 'status' => 'created', 'flow_id' => 5 ),
			),
		);
		( new \ExtraChill\CLI\Commands\Events\AddCityCommand() )( array( 'Nashville, TN' ), array( 'skip-dice' => true ) );
		$assert(
			array(
				'city'      => 'Nashville, TN',
				'radius'    => '50',
				'interval'  => 'daily',
				'skip_dice' => true,
				'force'     => false,
				'dry_run'   => false,
			) === $add_city->inputs[0],
			'add-city forwards defaults and flags'
		);
		$assert( in_array( 'Success: Added Nashville.', WP_CLI::$messages, true ), 'add-city reports success' );

		// add-city: ability error arrays and WP_Errors become CLI errors.
		$add_city->result = array( 'error' => 'Could not geocode.' );
		$assert( 'Could not geocode.' === $expect_error( static fn() => ( new \ExtraChill\CLI\Commands\Events\AddCityCommand() )( array( 'Nowhere' ), array() ) ), 'add-city surfaces error results' );
		$add_city->result = new WP_Error( 'missing_city', 'City name is required.' );
		$assert( 'City name is required.' === $expect_error( static fn() => ( new \ExtraChill\CLI\Commands\Events\AddCityCommand() )( array( 'X' ), array() ) ), 'add-city surfaces WP_Error' );
		$assert( null !== $expect_error( static fn() => ( new \ExtraChill\CLI\Commands\Events\AddCityCommand() )( array( '' ), array() ) ), 'add-city requires a city' );

		// Owner site: running off the events blog points at --url instead of executing.
		$GLOBALS['ece_blog_id'] = 1;
		$calls                  = count( $add_city->inputs );
		$message                = (string) $expect_error( static fn() => ( new \ExtraChill\CLI\Commands\Events\AddCityCommand() )( array( 'Austin, TX' ), array() ) );
		$assert( false !== strpos( $message, '--url=https://events.extrachill.com' ), 'add-city requires the events site' );
		$assert( count( $add_city->inputs ) === $calls, 'add-city does not execute off the owner site' );
		$GLOBALS['ece_blog_id'] = 7;

		// expand: exactly one of --city / --cities-file.
		$assert( 'Provide exactly one of --city or --cities-file.' === $expect_error( static fn() => ( new \ExtraChill\CLI\Commands\Events\VenueExpandCommand() )( array(), array() ) ), 'expand requires a city source' );
		$assert( 'Provide exactly one of --city or --cities-file.' === $expect_error( static fn() => ( new \ExtraChill\CLI\Commands\Events\VenueExpandCommand() )( array(), array( 'city' => 'A', 'cities-file' => 'b' ) ) ), 'expand rejects both city sources' );

		// expand: cities file parsing skips comments, blanks and duplicates.
		$file = tempnam( sys_get_temp_dir(), 'ec-cities-' );
		file_put_contents( $file, "# rollout\n\n Charleston, SC \nAustin, TX\nCharleston, SC\n  # later\n" );
		$assert( array( 'Charleston, SC', 'Austin, TX' ) === \ExtraChill\CLI\Commands\Events\VenueExpandCommand::parse_cities_file( $file ), 'cities file parser ignores comments, blanks and duplicates' );
		$assert( array() === \ExtraChill\CLI\Commands\Events\VenueExpandCommand::parse_cities_file( $file . '.missing' ), 'unreadable cities file yields no cities' );

		// expand: dry-run plan forwards derived budgets and warns.
		$expand->result     = array( 'plan' => true );
		WP_CLI::$messages   = array();
		( new \ExtraChill\CLI\Commands\Events\VenueExpandCommand() )( array(), array( 'cities-file' => $file, 'max-venues-per-city' => '5' ) );
		unlink( $file );
		$input = $expand->inputs[0];
		$assert( array( 'Charleston, SC', 'Austin, TX' ) === $input['cities'], 'expand forwards parsed cities' );
		$assert( 2 === $input['discovery_budget'] && 10 === $input['qualification_budget'], 'expand derives budgets from cities and venue cap' );
		$assert( true === $input['skip_existing'] && false === $input['apply'], 'expand defaults to skip-existing dry run' );
		$assert( ! isset( $input['agent_slug'] ), 'dry run resolves no agent' );
		$assert( in_array( 'Warning: Plan only. Pass --apply to schedule this bounded batch.', WP_CLI::$messages, true ), 'expand warns on plan only' );

		// expand: --no-skip-existing reaches the ability as false.
		$assert( false === \ExtraChill\CLI\Commands\Events\VenueExpandCommand::build_expand_input( array( 'A' ), array( 'skip-existing' => false ) )['skip_existing'], 'no-skip-existing is forwarded' );

		// expand --apply: the Data Machine agent context is forwarded.
		( new \ExtraChill\CLI\Commands\Events\VenueExpandCommand() )( array(), array( 'city' => 'Austin, TX', 'apply' => true, 'agent' => 'scout' ) );
		$applied = end( $expand->inputs );
		$assert( true === $applied['apply'] && 'scout' === ( $applied['agent_slug'] ?? null ), 'expand --apply forwards the resolved agent' );

		// expand-report: job selectors are cast and forwarded.
		$report->result = array( 'cities' => array() );
		( new \ExtraChill\CLI\Commands\Events\VenueExpansionReportCommand() )( array(), array( 'batch-job-id' => '9', 'job-ids' => '3,4' ) );
		$assert( array( 'batch_job_id' => 9, 'job_ids' => array( 3, 4 ) ) === $report->inputs[0], 'expand-report forwards job selectors' );

		if ( $failures ) {
			fwrite( STDERR, "{$failures} failure(s)\n" );
			exit( 1 );
		}

		echo "EventsCityExpansionCommandTest: OK\n";
	}
}
