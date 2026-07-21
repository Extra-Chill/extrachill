<?php
/**
 * Ability-level tests for ordered activation-funnel progression.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-activation-funnel.php';

/**
 * Protect identity, ordering, response math, and read bounds.
 */
final class ActivationFunnelTest extends TestCase {
	/**
	 * Anonymous activity stitches to the one observed authenticated user, and
	 * the public response uses ordered populations for every rate and drop.
	 */
	public function test_ability_stitches_identity_and_returns_ordered_drop_math(): void {
		$rows = array(
			$this->row( 1, 'artist_signup_started', 100, 0, 'visitor-a' ),
			$this->row( 2, 'artist_profile_created', 101, 0, 'visitor-a', 10 ),
			$this->row( 3, 'artist_profile_first_publish', 102, 10 ),
			$this->row( 4, 'artist_signup_started', 103, 20 ),
			$this->row( 5, 'artist_signup_started', 104, 30 ),
			$this->row( 6, 'artist_profile_created', 105, 30 ),
		);
		$db   = $this->install_database( array( $rows, $rows ) );

		$response = extrachill_analytics_ability_get_activation_funnel(
			array(
				'days'    => 28,
				'blog_id' => 4,
			)
		);

		$this->assertSame( array( 3, 2, 1 ), array_column( $response['steps'], 'people' ) );
		$this->assertSame( 1.0, $response['steps'][0]['conversion_from_prev'] );
		$this->assertSame( 0.6667, $response['steps'][1]['conversion_from_prev'] );
		$this->assertSame( 0.5, $response['steps'][2]['conversion_from_prev'] );
		$this->assertSame( 0.3333, $response['steps'][2]['conversion_from_top'] );
		$this->assertSame( 0.3333, $response['overall_conversion'] );
		$this->assertSame(
			array(
				'from_step' => 'artist_profile_created',
				'to_step'   => 'artist_profile_first_publish',
				'dropped'   => 1,
				'drop_rate' => 0.5,
			),
			$response['biggest_abandon_step']
		);
		$this->assertCount( 2, $db->prepared_queries );
		$this->assertStringContainsString( 'created_at <= %s', $db->prepared_queries[0]['query'] );
		$this->assertStringContainsString( 'created_at >= %s', $db->prepared_queries[0]['query'] );
		$this->assertStringContainsString( 'blog_id = %d', $db->prepared_queries[0]['query'] );
	}

	/**
	 * Downstream-only and premature events do not advance, while later ordered
	 * repeats can complete the path without inflating its populations.
	 */
	public function test_ability_enforces_order_and_deduplicates_repeats(): void {
		$rows = array(
			$this->row( 1, 'artist_profile_created', 100, 40 ),
			$this->row( 2, 'artist_signup_started', 101, 40 ),
			$this->row( 3, 'artist_profile_first_publish', 102, 40 ),
			$this->row( 4, 'artist_signup_started', 103, 40 ),
			$this->row( 5, 'artist_profile_created', 104, 40 ),
			$this->row( 6, 'artist_profile_created', 105, 40 ),
			$this->row( 7, 'artist_profile_first_publish', 106, 40 ),
			$this->row( 8, 'artist_profile_first_publish', 107, 50 ),
		);
		$this->install_database( array( $rows, $rows ) );

		$response = extrachill_analytics_ability_get_activation_funnel( array( 'days' => 28 ) );

		$this->assertSame( array( 1, 1, 1 ), array_column( $response['steps'], 'people' ) );
		$this->assertSame( 1.0, $response['overall_conversion'] );
		$this->assertSame( 0, $response['biggest_abandon_step']['dropped'] );
		$this->assertSame( 0.0, $response['biggest_abandon_step']['drop_rate'] );
	}

	/**
	 * Manual approval and onboarding grant remain readable as distinct paths.
	 */
	public function test_ability_reports_distinct_access_paths_without_changing_activation_steps(): void {
		$rows = array(
			$this->row( 1, 'artist_access_requested', 100, 80 ),
			$this->row( 2, 'artist_access_approved', 101, 80 ),
			$this->row( 3, 'artist_access_granted', 102, 90 ),
			$this->row( 4, 'artist_access_granted', 103, 90 ),
			$this->row( 5, 'artist_signup_started', 104, 90 ),
		);
		$this->install_database( array( $rows, $rows ) );

		$response = extrachill_analytics_ability_get_activation_funnel( array( 'days' => 28 ) );

		$this->assertSame(
			array( 'artist_access_requested', 'artist_access_approved', 'artist_access_granted' ),
			array_column( $response['access_paths'], 'event_type' )
		);
		$this->assertSame( array( 1, 1, 1 ), array_column( $response['access_paths'], 'people' ) );
		$this->assertSame( array( 1, 1, 2 ), array_column( $response['access_paths'], 'events' ) );
		$this->assertSame( array( 1, 0, 0 ), array_column( $response['steps'], 'people' ) );
	}

	/**
	 * Equal timestamps follow row-ID order in the ability's persisted stream.
	 */
	public function test_ability_uses_event_id_for_equal_timestamp_ordering(): void {
		$rows = array(
			$this->row( 10, 'artist_signup_started', 100, 60 ),
			$this->row( 11, 'artist_profile_created', 100, 60 ),
			$this->row( 12, 'artist_profile_first_publish', 100, 60 ),
		);
		$db   = $this->install_database( array( $rows, $rows ) );

		$response = extrachill_analytics_ability_get_activation_funnel( array( 'days' => 28 ) );

		$this->assertSame( array( 1, 1, 1 ), array_column( $response['steps'], 'people' ) );
		$this->assertStringContainsString( 'ORDER BY created_at ASC, id ASC', $db->prepared_queries[0]['query'] );
	}

	/**
	 * All-time mode remains upper-bounded and keyset-paged on both identity and
	 * progression passes rather than materializing every matching event.
	 */
	public function test_all_time_mode_is_upper_bounded_and_paged(): void {
		$rows = array();
		for ( $id = 1; $id <= 501; ++$id ) {
			$rows[] = $this->row( $id, 'artist_signup_started', 100 + $id, 70 );
		}
		$first_page = array_slice( $rows, 0, 500 );
		$last_page  = array_slice( $rows, 500 );
		$db         = $this->install_database( array( $first_page, $last_page, $first_page, $last_page ) );

		$response = extrachill_analytics_ability_get_activation_funnel( array( 'days' => 0 ) );

		$this->assertSame( array( 1, 0, 0 ), array_column( $response['steps'], 'people' ) );
		$this->assertCount( 4, $db->prepared_queries );
		foreach ( $db->prepared_queries as $prepared ) {
			$this->assertStringContainsString( 'created_at <= %s', $prepared['query'] );
			$this->assertStringNotContainsString( 'created_at >= %s', $prepared['query'] );
			$this->assertStringContainsString( 'LIMIT %d', $prepared['query'] );
			$this->assertSame( 500, end( $prepared['args'] ) );
		}
		$this->assertStringContainsString( 'id > %d', $db->prepared_queries[1]['query'] );
		$this->assertStringContainsString( 'id > %d', $db->prepared_queries[3]['query'] );
	}

	/**
	 * Install a database fixture globally for the production callback.
	 *
	 * @param array<int,array<object>> $pages Ordered result pages.
	 * @return object Database fixture.
	 */
	private function install_database( $pages ) {
		$db              = new class( $pages ) {
			/**
			 * Prepared queries and arguments.
			 *
			 * @var array<int,array{query:string,args:array}>
			 */
			public $prepared_queries = array();

			/**
			 * Ordered pages returned by successive reads.
			 *
			 * @var array<int,array<object>>
			 */
			private $pages;

			/**
			 * Current page index.
			 *
			 * @var int
			 */
			private $page_index = 0;

			/**
			 * Set fixture pages.
			 *
			 * @param array<int,array<object>> $fixture_pages Ordered query result pages.
			 */
			public function __construct( $fixture_pages ) {
				$this->pages = $fixture_pages;
			}

			/**
			 * Capture a prepared query.
			 *
			 * @param string $query SQL query.
			 * @param mixed  ...$args Prepared values.
			 * @return string Unchanged SQL query.
			 */
			public function prepare( $query, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}

				$this->prepared_queries[] = array(
					'query' => $query,
					'args'  => $args,
				);

				return $query;
			}

			/**
			 * Return the next configured result page.
			 *
			 * @param string $query SQL query (unused by the fixture).
			 * @return array<object> Event rows.
			 */
			public function get_results( $query ) {
				unset( $query );
				$page = $this->pages[ $this->page_index ] ?? array();
				++$this->page_index;

				return $page;
			}
		};
		$GLOBALS['wpdb'] = $db;

		return $db;
	}

	/**
	 * Build one stored analytics event fixture.
	 *
	 * @param int    $id              Stored event ID.
	 * @param string $event_type      Event type.
	 * @param int    $timestamp       Relative fixture timestamp.
	 * @param int    $user_id         Stored authenticated user ID.
	 * @param string $visitor_id      Anonymous visitor ID.
	 * @param int    $payload_user_id User ID stored in event_data.
	 * @return object Event row.
	 */
	private function row( $id, $event_type, $timestamp, $user_id = 0, $visitor_id = '', $payload_user_id = 0 ) {
		return (object) array(
			'id'         => $id,
			'event_type' => $event_type,
			'event_data' => $payload_user_id > 0 ? sprintf( '{"user_id":%d}', $payload_user_id ) : '{}',
			'user_id'    => $user_id,
			'visitor_id' => $visitor_id,
			'created_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
		);
	}
}
