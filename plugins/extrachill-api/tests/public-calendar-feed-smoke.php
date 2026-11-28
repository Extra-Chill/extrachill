<?php
/**
 * Standalone smoke test for the public calendar route and its Events ability.
 * Run with php plugins/extrachill-api/tests/public-calendar-feed-smoke.php.
 *
 * @package ExtraChillAPI
 */

// phpcs:disable -- Standalone fixture with WordPress and Data Machine Events test doubles.

namespace DataMachineEvents\Api\Controllers {
	class Calendar {
		public function serialize_contract_occurrence( array $occurrence ): array {
			return $occurrence;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'EXTRACHILL_API_TICKET_REDIRECT_ABILITY', 'data-machine-events/resolve-ticket-destination' );

	class WP_REST_Server {
		public const READABLE = 'GET';
	}

	class WP_REST_Request {
		public function __construct( private array $query ) {}

		public function get_param( string $name ): mixed {
			return $this->query[ $name ] ?? null;
		}
	}

	class WP_Ability {
		public function __construct( private Closure|string $callback ) {}

		public function execute( array $input ): array {
			return ( $this->callback )( $input );
		}

		public function get_meta_item( string $key ): mixed {
			return 'show_in_rest' === $key ? false : null;
		}
	}

	function add_action() {}
	function __( $text ) { return $text; }
	function is_wp_error() { return false; }
	function rest_ensure_response( $data ) { return $data; }
	function sanitize_text_field( $text ) { return trim( strip_tags( $text ) ); }
	function get_permalink( $id ) { return 'https://events.example/event/' . $id . '/'; }
	function wp_get_abilities() {
		return array_map( static fn( $definition ) => new WP_Ability( $definition['execute_callback'] ), $GLOBALS['calendar_abilities'] );
	}

	function register_rest_route( $namespace, $route, $definition ) {
		$GLOBALS['calendar_route'] = $definition;
	}

	function wp_register_ability( $name, $definition ) {
		$GLOBALS['calendar_abilities'][ $name ] = $definition;
	}

	function wp_get_ability( $name ) {
		return wp_get_abilities()[ $name ] ?? null;
	}

	function get_term_by( $field, $value, $taxonomy ) {
		$terms = array(
			'event_type' => array( 'open-mic' => 42 ),
			'location'   => array( 'charleston' => 7 ),
		);
		$id = 'slug' === $field ? ( $terms[ $taxonomy ][ $value ] ?? null ) : null;
		return $id ? (object) array( 'term_id' => $id ) : false;
	}

	function expect_same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message );
		}
	}

	require_once dirname( __DIR__ ) . '/inc/routes/events/calendar.php';
	require_once dirname( __DIR__, 2 ) . '/extrachill-events/inc/abilities/events-calendar.php';

	extrachill_api_register_events_calendar_route();
	extrachill_events_register_calendar_ability();
	$route = $GLOBALS['calendar_route'];
	$slug  = $route['args']['event_type'];
	$input = $GLOBALS['calendar_abilities']['extrachill/events-calendar']['input_schema'];
	expect_same( 'string', $slug['type'], 'The route must accept a string event_type.' );
	expect_same( false, $slug['required'], 'The route filter must be optional.' );
	expect_same( 'string', $input['properties']['event_type']['type'], 'The ability must declare event_type.' );

	$source     = array( 'url' => 'https://venue.example/calendar/jam/', 'name' => 'Example Venue' );
	$occurrence = array(
		'event'      => array(
			'id'         => 501,
			'title'      => 'Open Jam',
			'date'       => array( 'start_date' => '2026-12-12', 'start_time' => '19:00:00', 'end_date' => '' ),
			'venue'      => null,
			'organizer'  => null,
			'performer'  => null,
			'taxonomies' => array(),
			'status'     => 'EventScheduled',
			'ticket'     => array( 'url' => 'https://venue.example/box-office/jam/' ),
			'source'     => $source,
		),
		'occurrence' => array( 'display_context' => array(), 'display' => array() ),
	);
	$legacy = $occurrence;
	unset( $legacy['event']['source'] );

	wp_register_ability(
		'data-machine-events/get-calendar-page',
		array(
			'execute_callback' => static function ( array $input ) use ( $occurrence, $legacy ): array {
				$GLOBALS['calendar_delegate_input'] = $input;
				return array(
					'paged_date_groups' => array(
						array( 'date' => '2026-12-12', 'events' => array( $occurrence, $legacy ) ),
					),
				);
			},
		)
	);

	$response = $route['callback']( new WP_REST_Request( array( 'event_type' => 'open-mic', 'location' => 'charleston' ) ) );
	expect_same( array( 'location' => array( 7 ), 'event_type' => array( 42 ) ), $GLOBALS['calendar_delegate_input']['tax_filter'], 'The route must delegate event_type as a term ID alongside other taxonomy filters.' );
	$events = $response['dates'][0]['events'];
	expect_same( $source, $events[0]['source'], 'The original listing must pass through the route unchanged.' );
	expect_same( true, array_key_exists( 'source', $events[1] ), 'Older upstream responses must still include the source key.' );
	expect_same( null, $events[1]['source'], 'A missing upstream source must be null.' );
	expect_same( $occurrence['event']['ticket']['url'], $events[0]['ticket_url'], 'The source field must not replace the ticket URL.' );

	foreach ( array( array(), array( 'event_type' => 'not-a-term' ) ) as $query ) {
		$route['callback']( new WP_REST_Request( $query ) );
		expect_same( false, array_key_exists( 'tax_filter', $GLOBALS['calendar_delegate_input'] ), 'An omitted or unknown slug must not add a filter.' );
	}

	wp_register_ability(
		EXTRACHILL_API_TICKET_REDIRECT_ABILITY,
		array( 'execute_callback' => static fn( $input ) => array( 'is_affiliate' => 501 === $input['event_id'] ) )
	);
	$response = $route['callback']( new WP_REST_Request( array( 'event_type' => 'open-mic' ) ) );
	$event    = $response['dates'][0]['events'][0];
	expect_same( $source, $event['source'], 'Affiliate ticket gating must leave original listing attribution intact.' );
	expect_same( null, $event['ticket_url'], 'An affiliate ticket URL must still be withheld.' );
	expect_same( 501, $event['ticket_ref'], 'An affiliate event must keep its ticket reference.' );

	echo "Public calendar feed smoke test passed.\n";
}
