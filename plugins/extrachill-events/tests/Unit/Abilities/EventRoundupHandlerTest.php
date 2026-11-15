<?php
/**
 * Event roundup pipeline handler delegation tests.
 *
 * @package ExtraChillEvents\Tests
 */

declare( strict_types=1 );

use ExtraChillEvents\Handlers\EventRoundup\EventRoundupHandler;

/**
 * The event_roundup fetch handler is a thin adapter over
 * EventRoundupAbilities: handler config becomes ability input, and the
 * packet + engine data shape stays stable for existing flows (#1429).
 *
 * Runs in a separate process: the Data Machine and WP shims below must not
 * leak into other plain-unit tests that branch on class/function existence.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class EventRoundupHandlerTest extends BookingTestCase {

	/** Install Data Machine, events-query, and term shims, then load the handler. */
	private function install_shims(): void {
		// phpcs:disable Squiz.PHP.Eval.Discouraged -- Test-only shims for classes owned by other plugins.
		eval( 'namespace DataMachine\Core; class ExecutionContext { public function log( $level, $message, $extra = array() ) { $GLOBALS["roundup_handler_test"]["logs"][] = array( $level, $message ); } public function getPipelineId() { return 13; } public function getJobId() { return "99"; } public function getFileContext() { return array( "pipeline_id" => 13, "flow_id" => 54 ); } public function storeEngineData( array $data ) { $GLOBALS["roundup_handler_test"]["engine"] = array_merge( $GLOBALS["roundup_handler_test"]["engine"], $data ); } }' );
		eval( 'namespace DataMachine\Core\Steps\Fetch\Handlers; abstract class FetchHandler { public function __construct( string $type ) {} abstract protected function executeFetch( array $config, \DataMachine\Core\ExecutionContext $context ): array; }' );
		eval( 'namespace DataMachine\Core\Steps; trait HandlerRegistrationTrait { protected static function registerHandler( ...$args ) {} }' );
		eval( 'namespace ExtraChillEvents\Templates; class EventRoundupTemplate { public static $last_slides = array(); }' );
		eval( 'class WP_Term { public $term_id; public $name; public function __construct( $id, $name ) { $this->term_id = $id; $this->name = $name; } }' );
		eval( 'function get_object_taxonomies( $type ) { return array( "location" ); }' );
		eval( 'function wp_timezone() { return new DateTimeZone( "UTC" ); }' );
		eval( 'function get_term( $id, $taxonomy ) { return 1618 === (int) $id ? new WP_Term( 1618, "Charleston" ) : null; }' );
		eval( 'function data_machine_events_query_events( $input ) { $GLOBALS["roundup_handler_test"]["query"] = $input; return array( "posts" => $GLOBALS["roundup_handler_test"]["posts"] ); }' );
		eval( 'function data_machine_events_parse_event_data( $post ) { return array( "venue" => "Music Farm &amp; Co", "startTime" => "20:00:00" ); }' );
		eval( 'function data_machine_events_group_by_date( $events, $past, $start, $end ) { return empty( $events ) ? array() : array( $start => array( "date_obj" => new DateTime( $start ), "events" => $events ) ); }' );
		// phpcs:enable Squiz.PHP.Eval.Discouraged

		$GLOBALS['roundup_handler_test'] = array(
			'posts'         => array( (object) array( 'post_title' => 'Tom &amp; Jerry' ) ),
			'render'        => null,
			'engine'        => array(),
			'logs'          => array(),
			// Built dynamically so static analysis does not resolve the real Data Machine class.
			'context_class' => implode( '\\', array( 'DataMachine', 'Core', 'ExecutionContext' ) ),
		);

		$GLOBALS['ec_test_ability_resolver'] = static function ( $name ) {
			if ( 'datamachine/render-image-template' !== $name ) {
				return null;
			}
			return new class() {
				/**
				 * Record the render request and return two slides.
				 *
				 * @param array $input Render input.
				 * @return array
				 */
				public function execute( array $input ): array {
					$GLOBALS['roundup_handler_test']['render'] = $input;
					return array(
						'success'    => true,
						'file_paths' => array( '/tmp/slide-1.png', '/tmp/slide-2.png' ),
					);
				}
			};
		};

		require_once dirname( __DIR__, 3 ) . '/inc/handlers/event-roundup/EventRoundupHandler.php';
	}

	/**
	 * Run the protected fetch with the given config against a fake context.
	 *
	 * The fake ExecutionContext records engine data and logs in
	 * $GLOBALS['roundup_handler_test'].
	 *
	 * @param array $config Handler config.
	 * @return array
	 */
	private function fetch( array $config ): array {
		$context_class = (string) $GLOBALS['roundup_handler_test']['context_class'];
		$method        = new ReflectionMethod( EventRoundupHandler::class, 'executeFetch' );
		return (array) $method->invoke( new EventRoundupHandler(), $config, new $context_class() );
	}

	/**
	 * Engine data stored by the last fetch.
	 *
	 * @return array
	 */
	private function engine(): array {
		return (array) $GLOBALS['roundup_handler_test']['engine'];
	}

	/** Flow 54 config drives the query + render abilities and keeps the packet shape. */
	public function test_fetch_delegates_to_abilities_and_keeps_packet_shape(): void {
		$this->install_shims();

		$packet = $this->fetch(
			array(
				'week_start_day'   => 'friday',
				'week_end_day'     => 'sunday',
				'location_term_id' => 1618,
				'title'            => 'Live Music in Charleston, SC This Week',
			)
		);

		$query = $GLOBALS['roundup_handler_test']['query'];
		$this->assertSame( array( 'location' => array( 1618 ) ), $query['tax_filters'] );
		$this->assertSame( 'Friday', ( new DateTime( $query['date_start'] ) )->format( 'l' ) );
		$this->assertSame( 'Sunday', ( new DateTime( $query['date_end'] ) )->format( 'l' ) );

		$render = $GLOBALS['roundup_handler_test']['render'];
		$this->assertSame( 'event_roundup', $render['template_id'] );
		$this->assertSame( 'Live Music in Charleston, SC This Week', $render['data']['title'] );
		$this->assertSame(
			array(
				'pipeline_id' => 13,
				'flow_id'     => 54,
			),
			$render['context']
		);

		$this->assertSame( array( 'title', 'content', 'metadata' ), array_keys( $packet ) );
		$this->assertStringStartsWith( 'Charleston Events: ', $packet['title'] );
		$this->assertSame(
			array(
				'source_type' => 'event_roundup',
				'location'    => 'Charleston',
				'date_start'  => $query['date_start'],
				'date_end'    => $query['date_end'],
				'event_count' => 1,
				'slide_count' => 2,
			),
			$packet['metadata']
		);

		// Ability behaviour wins: entities are decoded in the summary.
		$this->assertStringContainsString( '- Tom & Jerry @ Music Farm & Co (8:00 PM)', $packet['content'] );

		$engine = $this->engine();
		$this->assertSame(
			array( 'image_file_paths', 'event_summary', 'location_name', 'date_range', 'date_start', 'date_end', 'total_events', 'total_slides', 'roundup_context' ),
			array_keys( $engine )
		);
		$this->assertSame( array( '/tmp/slide-1.png', '/tmp/slide-2.png' ), $engine['image_file_paths'] );
		$this->assertSame( $packet['content'], $engine['event_summary'] );
		$this->assertSame( 2, $engine['total_slides'] );
		$this->assertSame( 3, $engine['roundup_context']['day_count'] );
	}

	/** Missing weekday config fails before any query. */
	public function test_missing_weekdays_returns_no_packet(): void {
		$this->install_shims();

		$this->assertSame( array(), $this->fetch( array( 'week_start_day' => 'friday' ) ) );
		$this->assertArrayNotHasKey( 'query', $GLOBALS['roundup_handler_test'] );
		$this->assertSame( array(), $this->engine() );
	}

	/** An empty window returns no packet and renders nothing. */
	public function test_no_events_skips_render(): void {
		$this->install_shims();
		$GLOBALS['roundup_handler_test']['posts'] = array();

		$packet = $this->fetch(
			array(
				'week_start_day'   => 'friday',
				'week_end_day'     => 'sunday',
				'location_term_id' => '',
			)
		);

		$this->assertSame( array(), $packet );
		$this->assertArrayNotHasKey( 'tax_filters', $GLOBALS['roundup_handler_test']['query'] );
		$this->assertNull( $GLOBALS['roundup_handler_test']['render'] );
		$this->assertSame( array(), $this->engine() );
	}
}
