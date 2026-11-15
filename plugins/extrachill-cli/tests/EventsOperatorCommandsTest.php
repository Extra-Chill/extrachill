<?php
/**
 * Contract tests for the extrachill-events operator commands that moved into
 * extrachill-cli (Extra-Chill/extrachill#1426): registry names, owner-site
 * guards, flag synopsis, and adapter-to-service wiring.
 *
 * Run standalone: php tests/EventsOperatorCommandsTest.php
 */

// phpcs:disable Universal.Namespaces,Universal.Files.SeparateFunctionsFromOO,Generic.Files.OneObjectStructurePerFile,Generic.Classes.DuplicateClassName.Found,Generic.CodeAnalysis.UnusedFunctionParameter,WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.WP.AlternativeFunctions -- Standalone test doubles for WP-CLI and WordPress, scoped per namespace like the other extrachill-cli contract tests.

namespace {
	// The doubles would collide with a real WordPress bootstrap; declarations
	// stay inside the conditional block so they are not hoisted.
	$events_operator_wp_bootstrapped = function_exists( 'wp_get_ability' );

	if ( ! $events_operator_wp_bootstrapped ) {
		define( 'ABSPATH', __DIR__ . '/' );

		class EventsOperatorTestError extends \RuntimeException {}

		class WP_CLI {
			public static $messages  = array();
			public static $formatted = array();
			public static function error( $message ) { throw new EventsOperatorTestError( $message ); }
			public static function log( $message ) { self::$messages[] = $message; }
			public static function success( $message ) { self::$messages[] = 'Success: ' . $message; }
			public static function warning( $message ) { self::$messages[] = 'Warning: ' . $message; }
		}

		class WP_Error {
			private $message;
			public function __construct( $code, $message ) { $this->message = $message; }
			public function get_error_message() { return $this->message; }
		}

		$GLOBALS['eoc_blog_id'] = 7;
		$GLOBALS['eoc_calls']   = array();

		function is_wp_error( $value ) { return $value instanceof WP_Error; }
		function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
		function ec_get_blog_id( $key ) { return 'events' === $key ? 7 : 0; }
		function ec_get_site_url( $key ) { return 'https://' . $key . '.extrachill.com'; }
		function get_current_blog_id() { return $GLOBALS['eoc_blog_id']; }
		function taxonomy_exists( $taxonomy ) { return 'location' === $taxonomy; }
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
		function format_items( $format, $items, $fields ) {
			\WP_CLI::$formatted[] = compact( 'format', 'items', 'fields' );
		}
	}
}

namespace {
	if ( ! $events_operator_wp_bootstrapped ) {
		$root     = dirname( __DIR__ );
		$commands = array(
			'extrachill events ramp'                      => 'Events\\EventSourceRampCommand',
			'extrachill venues qualify-stats'             => 'Events\\QualifyStatsCommand',
			'extrachill venues requalify-pending'         => 'Events\\RequalifyPendingCommand',
			'extrachill venues requalify-flow'            => 'Events\\RequalifyFlowCommand',
			'extrachill venues unqualifiable-flows'       => 'Events\\UnqualifiableFlowsCommand',
			'extrachill events flows repair-locations'    => 'Events\\RepairFlowLocationsCommand',
			'extrachill events locations audit-integrity' => 'Events\\AuditLocationIntegrityCommand',
			'extrachill events locations reconcile-roots' => 'Events\\ReconcileRootLocationsCommand',
			'extrachill events sync-genres'               => 'Events\\SyncGenresCommand',
			'extrachill venues classify-tier'             => 'Events\\ClassifyVenueTierCommand',
			'extrachill artists classify-genre'           => 'Artists\\ClassifyGenreCommand',
		);

		require_once $root . '/inc/OwnerSiteAbility.php';
		require_once $root . '/inc/CommandRegistry.php';
		foreach ( $commands as $relative ) {
			require_once $root . '/inc/Commands/' . str_replace( '\\', '/', $relative ) . '.php';
		}

		$failures     = 0;
		$assert       = static function ( $condition, $message ) use ( &$failures ) {
			if ( ! $condition ) {
				++$failures;
				fwrite( STDERR, "FAIL: {$message}\n" );
			}
		};
		$expect_error = static function ( callable $run ) {
			try {
				$run();
			} catch ( EventsOperatorTestError $e ) {
				return $e->getMessage();
			}
			return null;
		};
		$invoke       = static function ( $relative, array $args = array(), array $assoc_args = array() ) {
			$class = '\\ExtraChill\\CLI\\Commands\\' . $relative;
			( new $class() )( $args, $assoc_args );
		};
		// Arguments each adapter needs to reach its owner-site guard.
		$guard_args  = array( 'Events\\RequalifyFlowCommand' => array( '42' ) );
		$guard_assoc = array(
			'Events\\EventSourceRampCommand' => array(
				'source-class'      => 'dice',
				'current-max-items' => '1',
				'phase'             => 'preflight',
				'evidence'          => '-',
				'scope'             => 'flow',
				'scope-id'          => '42',
			),
		);

		$message = $expect_error( static fn() => $invoke( 'Events\\EventSourceRampCommand' ) );
		$assert( '--source-class is required.' === $message, 'ramp validates required flags before the owner-site guard' );

		// Registry: identical command names, now owned by extrachill-cli.
		$registry_map = \ExtraChill\CLI\CommandRegistry::map();
		foreach ( $commands as $command => $relative ) {
			$class = 'ExtraChill\\CLI\\Commands\\' . $relative;
			$assert( ( $registry_map[ $command ] ?? null ) === $class, "registry maps {$command}" );
			$assert( class_exists( $class ), "{$class} loads" );

			$doc = (string) ( new \ReflectionMethod( $class, '__invoke' ) )->getDocComment();
			$assert( false !== strpos( $doc, '@when after_wp_load' ), "{$command} runs after WordPress loads" );
			$assert( false !== strpos( $doc, '## EXAMPLES' ), "{$command} keeps its help text" );
		}

		// Owner site: running off the events blog points at --url instead of executing.
		$GLOBALS['eoc_blog_id'] = 1;
		foreach ( $commands as $command => $relative ) {
			$message = $expect_error( static fn() => $invoke( $relative, $guard_args[ $relative ] ?? array(), $guard_assoc[ $relative ] ?? array() ) );
			$assert( false !== strpos( (string) $message, '--url=https://events.extrachill.com' ), "{$command} requires the events site" );
		}
		$GLOBALS['eoc_blog_id'] = 7;

		// Owner plugin inactive on the events site: name the missing service.
		foreach ( $commands as $command => $relative ) {
			$message = $expect_error( static fn() => $invoke( $relative, $guard_args[ $relative ] ?? array(), $guard_assoc[ $relative ] ?? array() ) );
			$assert(
				false !== strpos( (string) $message, 'is not available on Events. Ensure its owner plugin is active for https://events.extrachill.com.' ),
				"{$command} reports the inactive events plugin"
			);
		}

		// classify-genre: WP-CLI only accepts --no-ai when [--ai] is declared,
		// and the flag must be read through the negation-aware accessor.
		$genre_doc = (string) ( new \ReflectionMethod( \ExtraChill\CLI\Commands\Artists\ClassifyGenreCommand::class, '__invoke' ) )->getDocComment();
		$assert( false !== strpos( $genre_doc, '[--ai]' ), 'classify-genre declares [--ai] so --no-ai is accepted' );
		$assert( 1 === preg_match( '/\[--no-ai\].*?zero AI\s+\*?\s*cost/s', $genre_doc ), 'classify-genre documents --no-ai as zero AI cost' );
		$genre_source = (string) file_get_contents( $root . '/inc/Commands/Artists/ClassifyGenreCommand.php' );
		$assert( false !== strpos( $genre_source, "get_flag_value( \$assoc_args, 'ai', true )" ), 'classify-genre reads --ai through get_flag_value' );
		$assert( false === strpos( $genre_source, "\$assoc_args['no-ai']" ), "classify-genre never reads a literal 'no-ai' key" );

		// Adapter wiring against service doubles.
		require_once __DIR__ . '/EventsOperatorServicesFixture.php';

		$message = $expect_error( static fn() => $invoke( 'Events\\UnqualifiableFlowsCommand', array(), array( 'apply-repair' => true ) ) );
		$assert( '--apply-repair requires --yes and cannot be combined with --dry-run.' === $message, 'unqualifiable-flows rejects unconfirmed --apply-repair' );
		$assert( array() === $GLOBALS['eoc_calls'], 'unqualifiable-flows validates before reading flows' );

		WP_CLI::$messages = array();
		$invoke( 'Events\\UnqualifiableFlowsCommand', array(), array() );
		$assert( array( array( 'zero_yield_candidates', 14, false, 200 ) ) === $GLOBALS['eoc_calls'], 'unqualifiable-flows forwards default --min-runs and --limit' );
		$assert( array( 'No universal_web_scraper flows found.' ) === WP_CLI::$messages, 'unqualifiable-flows reports missing scraper flows' );

		$message = $expect_error( static fn() => $invoke( 'Events\\RequalifyFlowCommand', array(), array() ) );
		$assert( 'Provide a flow id or "all". Example: wp extrachill venues requalify-flow 42' === $message, 'requalify-flow requires a target' );

		$message = $expect_error( static fn() => $invoke( 'Events\\RequalifyFlowCommand', array( '42' ), array() ) );
		$assert( 'Flow 42 not found or has no universal_web_scraper step.' === $message, 'requalify-flow reports a missing flow' );

		$GLOBALS['eoc_calls']                           = array();
		\ExtraChillEvents\Core\VenueRequalifier::$flows = array( array( 'flow_id' => 42 ) );
		WP_CLI::$messages                               = array();
		WP_CLI::$formatted                              = array();
		$invoke( 'Events\\RequalifyFlowCommand', array( 'all' ), array( 'dry-run' => true ) );
		$assert( array( 'target_flows', 'all', 100 ) === $GLOBALS['eoc_calls'][0], 'requalify-flow forwards the default --limit' );
		$assert( array( 'requalify_flows', 1, true, false ) === $GLOBALS['eoc_calls'][1], 'requalify-flow forwards --dry-run and --auto-pause' );
		$assert( 'Re-qualifying 1 flow(s) (dry run).' === WP_CLI::$messages[0], 'requalify-flow announces the run' );
		$assert( array( 'flow_id', 'flow_name', 'new_verdict', 'event_count', 'action' ) === WP_CLI::$formatted[0]['fields'], 'requalify-flow keeps its table columns' );
		$assert( 'Warning: 1 flow(s) recommend pausing. Re-run with --auto-pause to apply.' === end( WP_CLI::$messages ), 'requalify-flow surfaces pause recommendations' );

		$GLOBALS['eoc_calls']                                 = array();
		\ExtraChillEvents\Core\RootLocationReconciler::$ready = new WP_Error( 'denied', 'Apply requires an authorized WordPress administrator context.' );
		$message = $expect_error( static fn() => $invoke( 'Events\\ReconcileRootLocationsCommand', array(), array( 'apply' => true ) ) );
		$assert( 'Apply requires an authorized WordPress administrator context.' === $message, 'reconcile-roots fails apply readiness first' );
		$assert( array() === $GLOBALS['eoc_calls'], 'reconcile-roots does not inspect candidates when apply is unauthorized' );

		WP_CLI::$messages = array();
		$invoke( 'Events\\ReconcileRootLocationsCommand', array(), array() );
		$assert( array( array( 'reconcile', false ) ) === $GLOBALS['eoc_calls'], 'reconcile-roots dry-runs by default' );
		$assert( 0 === strpos( (string) end( WP_CLI::$messages ), 'Dry run:' ), 'reconcile-roots prints the dry-run notice' );

		if ( $failures ) {
			fwrite( STDERR, "{$failures} failure(s)\n" );
			exit( 1 );
		}

		echo "EventsOperatorCommandsTest: OK\n";
	}
}
