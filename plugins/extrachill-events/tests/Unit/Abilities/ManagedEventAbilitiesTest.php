<?php
/**
 * Managed-event list and update abilities (extrachill#1301).
 *
 * Pure-PHP: real ManagedEventAbilities, ManagedEventScope, TeamAuthorization,
 * and the agent principal fixture; file-local WordPress doubles; membership
 * readers, the event lookup, and Data Machine Events' update path are
 * injected so each case states exactly who belongs to which team.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO,Universal.Namespaces,Generic.CodeAnalysis.UnusedFunctionParameter,Squiz.Commenting,WordPress.WP.GlobalVariablesOverride.Prohibited -- File-local WordPress doubles; the finder test swaps $wpdb and restores it.

namespace {
	foreach ( array(
		'get_current_user_id'                      => static fn() => $GLOBALS['me_user'],
		'user_can'                                 => static fn( $user_id, $cap, ...$args ) => in_array( $cap, $GLOBALS['me_caps'][ (int) $user_id ] ?? array(), true ),
		'get_post'                                 => static fn( $id ) => $GLOBALS['me_posts'][ (int) $id ] ?? null,
		'wp_get_post_terms'                        => static fn( $id, $tax ) => array_map( static fn( $term_id ) => (object) array( 'term_id' => $term_id ), $GLOBALS['me_post_terms'][ (int) $id ][ $tax ] ?? array() ),
		'get_term'                                 => static fn( $id, $tax ) => isset( $GLOBALS['me_terms'][ $tax ][ $id ] ) ? (object) array(
			'term_id' => $id,
			'name'    => $GLOBALS['me_terms'][ $tax ][ $id ],
		) : null,
		'get_permalink'                            => static fn( $id ) => 'https://events.example/events/' . $id . '/',
		'wp_register_ability'                      => static function ( $name, $args ) {
			$GLOBALS['me_abilities'][ $name ] = $args;
		},
		'extrachill_events_perk_enabled'           => static fn( $id ) => 102 === (int) $id,
		'extrachill_events_get_perk_mode'          => static fn( $id ) => 102 === (int) $id ? 'password' : 'going',
		'extrachill_events_perk_password_required' => static fn( $id ) => 102 === (int) $id,
	) as $name => $impl ) {
		if ( ! function_exists( $name ) ) {
			$GLOBALS['me_impl'][ $name ] = $impl;
			eval( "function {$name}( ...\$a ) { return ( \$GLOBALS['me_impl']['{$name}'] )( ...\$a ); }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Define only missing doubles.
		}
	}
}

namespace DataMachineEvents\Core {
	if ( ! class_exists( EventDatesTable::class, false ) ) {
		class EventDatesTable {
			public static function table_name(): string {
				return 'wp_7_datamachine_event_dates';
			}
			public static function get( int $post_id ): ?object {
				return $GLOBALS['me_dates'][ $post_id ] ?? null;
			}
		}
	}
	if ( ! class_exists( EventOwnership::class, false ) ) {
		class EventOwnership {
			public static function markHumanOwned( int $post_id, int $user_id ): void {
				$GLOBALS['me_owned'][] = array( $post_id, $user_id );
			}
		}
	}
}

namespace ExtraChillEvents\Tests\Unit\Abilities {
	use ExtraChillEvents\Abilities\ManagedEventAbilities;
	use ExtraChillEvents\Core\ManagedEventFinder;
	use ExtraChillEvents\Core\ManagedEventScope;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__, 2 ) . '/fixtures/promoter-link-pages-principal.php';
	require_once dirname( __DIR__, 3 ) . '/inc/Abilities/ManagedEventAbilities.php';

	/** Applies finder criteria to the fixture events, the way the SQL does. */
	final class FixtureEventFinder extends ManagedEventFinder {
		public $criteria = array();

		public function find( array $criteria ) {
			$this->criteria[] = $criteria;
			$ids              = array();
			foreach ( $GLOBALS['me_posts'] as $id => $post ) {
				if ( 'publish' !== $post->post_status ) {
					continue;
				}
				$venues    = $GLOBALS['me_post_terms'][ $id ]['venue'] ?? array();
				$promoters = $GLOBALS['me_post_terms'][ $id ]['promoter'] ?? array();
				if ( empty( $criteria['unrestricted'] ) && ! array_intersect( $venues, $criteria['venue_ids'] ) && ! array_intersect( $promoters, $criteria['promoter_ids'] ) ) {
					continue;
				}
				if ( $criteria['venue_term_id'] && ! in_array( $criteria['venue_term_id'], $venues, true ) ) {
					continue;
				}
				if ( $criteria['promoter_term_id'] && ! in_array( $criteria['promoter_term_id'], $promoters, true ) ) {
					continue;
				}
				$ids[] = $id;
			}
			return array(
				'ids'   => array_slice( $ids, $criteria['offset'], $criteria['limit'] ),
				'total' => count( $ids ),
			);
		}
	}

	/** Captures prepared finder SQL. */
	final class FinderWpdb {
		public $posts              = 'wp_7_posts';
		public $term_relationships = 'wp_7_term_relationships';
		public $term_taxonomy      = 'wp_7_term_taxonomy';
		public $last_error         = '';
		public $queries            = array();
		public $total              = 3;

		public function prepare( $query, $params ) {
			$this->queries[] = array( $query, $params );
			return $query;
		}
		public function get_var( $query ) {
			return $this->total;
		}
		public function get_col( $query ) {
			return array( '102', '101' );
		}
	}

	final class ManagedEventAbilitiesTest extends TestCase {
		private const ADMIN    = 1;
		private const VENUE_MG = 2;
		private const PROMOTER = 3;
		private const RANDOM   = 4;

		private const VENUE_A    = 601;
		private const VENUE_B    = 602;
		private const PROMOTER_X = 701;

		/** @var FixtureEventFinder */
		private $finder;

		/** @var ManagedEventAbilities */
		private $ability;

		/** @var array[] */
		private $updates = array();

		protected function setUp(): void {
			unset( $GLOBALS['promoter_link_page_fixture']['principal_user_id'], $GLOBALS['promoter_link_page_fixture']['principal_capabilities'] );
			$GLOBALS['me_user']       = self::VENUE_MG;
			$GLOBALS['me_owned']      = array();
			$GLOBALS['me_dates']      = array(
				102 => (object) array(
					'start_datetime' => '2026-12-04 20:00:00',
					'end_datetime'   => '2026-12-04 23:30:00',
				),
			);
			$GLOBALS['me_caps']       = array(
				self::ADMIN    => array( 'read', 'edit_others_posts', 'manage_options' ),
				self::VENUE_MG => array( 'read' ),
				self::PROMOTER => array( 'read' ),
				self::RANDOM   => array( 'read' ),
			);
			$GLOBALS['me_terms']      = array(
				'venue'    => array(
					self::VENUE_A => 'The A Room',
					self::VENUE_B => 'B &amp; Sons Hall',
				),
				'promoter' => array( self::PROMOTER_X => 'X Presents' ),
			);
			$GLOBALS['me_posts']      = array();
			$GLOBALS['me_post_terms'] = array();
			$this->event( 101, array( self::VENUE_A ), array() );
			$this->event( 102, array( self::VENUE_B ), array( self::PROMOTER_X ) );
			$this->event( 103, array( self::VENUE_B ), array() );
			$this->event( 104, array( self::VENUE_A ), array(), 'draft' );

			$memberships   = array(
				'venue'    => array( self::VENUE_MG => array( self::VENUE_A ) ),
				'promoter' => array( self::PROMOTER => array( self::PROMOTER_X ) ),
			);
			$scope         = new ManagedEventScope(
				static fn( int $user_id ) => $memberships['venue'][ $user_id ] ?? array(),
				static fn( int $user_id ) => $memberships['promoter'][ $user_id ] ?? array()
			);
			$this->finder  = new FixtureEventFinder();
			$this->updates = array();
			$updates       = &$this->updates;
			$this->ability = new ManagedEventAbilities(
				$scope,
				$this->finder,
				static function ( array $update ) use ( &$updates ) {
					$updates[] = $update;
					return array(
						'results' => array(
							array(
								'post_id'        => $update['event'],
								'status'         => 'updated',
								'updated_fields' => array_values( array_diff( array_keys( $update ), array( 'event' ) ) ),
								'warnings'       => array(),
							),
						),
					);
				},
				false
			);
		}

		protected function tearDown(): void {
			unset( $GLOBALS['promoter_link_page_fixture']['principal_user_id'], $GLOBALS['promoter_link_page_fixture']['principal_capabilities'] );
		}

		private function event( int $id, array $venues, array $promoters, string $status = 'publish' ): void {
			$GLOBALS['me_posts'][ $id ]      = (object) array(
				'ID'          => $id,
				'post_type'   => 'data_machine_events',
				'post_status' => $status,
				'post_title'  => 'Show ' . $id,
			);
			$GLOBALS['me_post_terms'][ $id ] = array(
				'venue'    => $venues,
				'promoter' => $promoters,
			);
		}

		private function list_ids( array $input = array() ): array {
			$result = $this->ability->list_events( $input );
			$this->assertIsArray( $result );
			return array_column( $result['events'], 'id' );
		}

		private function as_principal( int $user_id, ?array $ceiling ): void {
			$GLOBALS['promoter_link_page_fixture']['principal_user_id']      = $user_id;
			$GLOBALS['promoter_link_page_fixture']['principal_capabilities'] = $ceiling;
		}

		public function test_venue_member_lists_only_their_venues_published_events(): void {
			$this->assertTrue( $this->ability->can_list( array() ) );
			$result = $this->ability->list_events( array() );
			$this->assertSame( array( 101 ), array_column( $result['events'], 'id' ) );
			$this->assertSame(
				array(
					'all'       => false,
					'venues'    => array(
						array(
							'id'   => self::VENUE_A,
							'name' => 'The A Room',
						),
					),
					'promoters' => array(),
				),
				$result['managed']
			);
			$this->assertSame( array( self::VENUE_A ), $this->finder->criteria[0]['venue_ids'] );
			$this->assertFalse( $this->finder->criteria[0]['unrestricted'] );
			$this->assertSame( 'upcoming', $this->finder->criteria[0]['when'] );
		}

		public function test_promoter_member_lists_promoter_events_with_projection(): void {
			$GLOBALS['me_user'] = self::PROMOTER;
			$result             = $this->ability->list_events( array() );
			$this->assertSame(
				array(
					array(
						'id'         => 102,
						'title'      => 'Show 102',
						'start'      => '2026-12-04 20:00:00',
						'end'        => '2026-12-04 23:30:00',
						'venue'      => array(
							'id'   => self::VENUE_B,
							'name' => 'B & Sons Hall',
						),
						'promoters'  => array(
							array(
								'id'   => self::PROMOTER_X,
								'name' => 'X Presents',
							),
						),
						'permalink'  => 'https://events.example/events/102/',
						'guest_list' => array(
							'enabled'           => true,
							'mode'              => 'password',
							'password_required' => true,
						),
					),
				),
				$result['events']
			);
			$this->assertSame( 1, $result['total'] );
		}

		public function test_random_user_lists_nothing_and_cannot_update(): void {
			$GLOBALS['me_user'] = self::RANDOM;
			$this->assertSame( array(), $this->list_ids() );
			foreach ( array( 101, 102, 103 ) as $id ) {
				$this->assertSame( 'managed_event_forbidden', $this->ability->can_update( array( 'event' => $id ) )->get_error_code() );
			}
			$this->assertSame( 'managed_events_scope_forbidden', $this->ability->can_list( array( 'scope' => 'all' ) )->get_error_code() );
		}

		public function test_signed_out_is_denied(): void {
			$GLOBALS['me_user'] = 0;
			$error              = $this->ability->can_list( array() );
			$this->assertSame( 'managed_events_authentication_required', $error->get_error_code() );
			$this->assertSame( 401, $error->get_error_data()['status'] );
			$this->assertSame( 'managed_events_authentication_required', $this->ability->update_event( array( 'event' => 101 ) )->get_error_code() );
		}

		public function test_filters_and_paging_pass_through(): void {
			$GLOBALS['me_user'] = self::ADMIN;
			$this->assertSame( array( 102, 103 ), $this->list_ids( array(
				'scope'         => 'all',
				'venue_term_id' => self::VENUE_B,
			) ) );
			$this->assertSame( array( 102 ), $this->list_ids( array(
				'scope'            => 'all',
				'promoter_term_id' => self::PROMOTER_X,
			) ) );
			$page = $this->ability->list_events( array(
				'scope'     => 'all',
				'limit'     => 2,
				'page'      => 2,
				'when'      => 'past',
				'date_from' => '2026-01-01',
				'date_to'   => '2026-01-31',
			) );
			$this->assertSame( array( 103 ), array_column( $page['events'], 'id' ) );
			$this->assertSame( 3, $page['total'] );
			$last = end( $this->finder->criteria );
			$this->assertSame( array( 2, 2, 'past', '2026-01-01', '2026-01-31' ), array( $last['limit'], $last['offset'], $last['when'], $last['date_from'], $last['date_to'] ) );
			$this->assertSame( 'managed_events_invalid_date', $this->ability->list_events( array( 'date_from' => '2026-02-30' ) )->get_error_code() );
		}

		public function test_venue_member_filter_cannot_widen_scope(): void {
			$this->assertSame( array(), $this->list_ids( array( 'venue_term_id' => self::VENUE_B ) ) );
			$this->assertSame( 'managed_events_scope_forbidden', $this->ability->list_events( array( 'scope' => 'all' ) )->get_error_code() );
		}

		public function test_manager_updates_own_event_and_takes_ownership(): void {
			$this->assertTrue( $this->ability->can_update( array( 'event' => 101 ) ) );
			$result = $this->ability->update_event(
				array(
					'event'       => 101,
					'startTime'   => '9pm',
					'description' => 'Doors at 8.',
					'ticketUrl'   => 'https://tickets.example/101',
				)
			);
			$this->assertSame( true, $result['success'] );
			$this->assertSame( 'updated', $result['status'] );
			$this->assertSame(
				array(
					'event'       => 101,
					'startTime'   => '9pm',
					'description' => 'Doors at 8.',
					'ticketUrl'   => 'https://tickets.example/101',
				),
				$this->updates[0]
			);
			$this->assertSame( array( array( 101, self::VENUE_MG ) ), $GLOBALS['me_owned'] );

			$GLOBALS['me_user'] = self::PROMOTER;
			$this->assertSame( true, $this->ability->update_event( array(
				'event'       => 102,
				'eventStatus' => 'EventPostponed',
			) )['success'] );
		}

		public function test_manager_denied_on_other_venues_event(): void {
			$error = $this->ability->update_event( array(
				'event' => 103,
				'price' => 'Free',
			) );
			$this->assertSame( 'managed_event_forbidden', $error->get_error_code() );
			$this->assertSame( 403, $error->get_error_data()['status'] );
			$this->assertSame( array(), $this->updates );
			$this->assertSame( 'managed_event_not_found', $this->ability->can_update( array( 'event' => 999 ) )->get_error_code() );
		}

		public function test_manager_cannot_edit_unpublished_event(): void {
			$this->assertSame( 'managed_event_not_editable', $this->ability->update_event( array(
				'event' => 104,
				'price' => 'Free',
			) )->get_error_code() );
			$this->assertSame( array(), $this->updates );
		}

		public function test_cannot_reassign_venue_or_promoter_to_escape_scope(): void {
			foreach ( array(
				array( 'venue' => self::VENUE_B ),
				array( 'promoter' => self::PROMOTER_X ),
				array( 'organizer' => 'Someone Else' ),
				array( 'title' => 'Renamed' ),
				array( 'post_status' => 'publish' ),
				array(
					'events' => array(
						array(
							'event' => 103,
							'price' => 'Free',
						),
					),
				),
			) as $smuggled ) {
				$error = $this->ability->update_event( array(
					'event' => 101,
					'price' => 'Free',
				) + $smuggled );
				$this->assertSame( 'managed_event_field_not_allowed', $error->get_error_code() );
				$this->assertSame( array_keys( $smuggled ), $error->get_error_data()['fields'] );
			}
			$this->assertSame( array(), $this->updates );
			$this->assertSame( array( self::VENUE_A ), $GLOBALS['me_post_terms'][101]['venue'] );

			$schema = $this->registered( ManagedEventAbilities::UPDATE_ABILITY )['input_schema'];
			$this->assertFalse( $schema['additionalProperties'] );
			$this->assertSame( array_merge( array( 'event' ), ManagedEventAbilities::UPDATABLE_FIELDS ), array_keys( $schema['properties'] ) );
		}

		public function test_update_needs_a_field_and_surfaces_update_failures(): void {
			$this->assertSame( 'managed_event_no_fields', $this->ability->update_event( array( 'event' => 101 ) )->get_error_code() );

			$failing = new ManagedEventAbilities(
				new ManagedEventScope( static fn() => array( self::VENUE_A ), static fn() => array() ),
				$this->finder,
				static fn() => array(
					'results' => array(
						array(
							'status'       => 'failed',
							'error'        => 'Bad date.',
							'error_code'   => 'invalid_event_date',
							'error_status' => 400,
						),
					),
				),
				false
			);
			$error   = $failing->update_event( array(
				'event'     => 101,
				'startDate' => 'nope',
			) );
			$this->assertSame( 'invalid_event_date', $error->get_error_code() );
			$this->assertSame( 400, $error->get_error_data()['status'] );
			$this->assertSame( array(), $GLOBALS['me_owned'] );
		}

		public function test_update_degrades_when_data_machine_events_is_absent(): void {
			$this->assertFalse( class_exists( '\\DataMachineEvents\\Abilities\\EventUpdateAbilities' ) );
			$this->assertSame( 'managed_event_update_unavailable', ManagedEventAbilities::data_machine_update( array( 'event' => 101 ) )->get_error_code() );
		}

		public function test_admin_lists_all_and_updates_any_event_on_the_web(): void {
			$GLOBALS['me_user'] = self::ADMIN;
			$this->assertSame( array(), $this->list_ids() );
			$this->assertSame( array( 101, 102, 103 ), $this->list_ids( array( 'scope' => 'all' ) ) );
			$this->assertTrue( $this->ability->can_update( array( 'event' => 103 ) ) );
			$this->assertTrue( $this->ability->can_update( array( 'event' => 104 ) ) );
		}

		public function test_admin_under_restricted_principal_falls_back_to_team_scope(): void {
			$GLOBALS['me_user'] = self::ADMIN;
			$this->as_principal( self::ADMIN, array( 'read' ) );
			$this->assertSame( 'managed_events_scope_forbidden', $this->ability->can_list( array( 'scope' => 'all' ) )->get_error_code() );
			$this->assertSame( array(), $this->list_ids() );
			$this->assertSame( 'managed_event_forbidden', $this->ability->update_event( array(
				'event' => 103,
				'price' => 'Free',
			) )->get_error_code() );

			$this->as_principal( self::ADMIN, array( 'read', 'edit_others_posts' ) );
			$this->assertSame( array( 101, 102, 103 ), $this->list_ids( array( 'scope' => 'all' ) ) );
			$this->assertTrue( $this->ability->can_update( array( 'event' => 103 ) ) );
		}

		public function test_member_principal_needs_read_and_must_represent_the_user(): void {
			$this->as_principal( self::VENUE_MG, null );
			$this->assertSame( array( 101 ), $this->list_ids() );
			$this->assertTrue( $this->ability->can_update( array( 'event' => 101 ) ) );

			$this->as_principal( self::VENUE_MG, array() );
			$this->assertSame( 'managed_events_principal_forbidden', $this->ability->can_list( array() )->get_error_code() );
			$this->assertSame( 'managed_events_principal_forbidden', $this->ability->update_event( array(
				'event' => 101,
				'price' => 'Free',
			) )->get_error_code() );

			$this->as_principal( self::PROMOTER, array( 'read' ) );
			$this->assertSame( 'managed_events_principal_forbidden', $this->ability->can_list( array() )->get_error_code() );
			$this->assertSame( array(), $this->updates );
		}

		public function test_finder_scopes_sql_to_team_terms_with_placeholders(): void {
			$original        = $GLOBALS['wpdb'] ?? null;
			$GLOBALS['wpdb'] = new FinderWpdb();
			try {
				$finder = new ManagedEventFinder();
				$this->assertSame( array(
					'ids'   => array(),
					'total' => 0,
				), $finder->find( array(
					'venue_ids'    => array(),
					'promoter_ids' => array(),
				) ) );
				$this->assertSame( array(), $GLOBALS['wpdb']->queries );

				$found = $finder->find(
					array(
						'venue_ids'     => array( 601, 602 ),
						'promoter_ids'  => array( 701 ),
						'venue_term_id' => 602,
						'when'          => 'upcoming',
						'date_to'       => '2026-12-31',
						'now'           => '2026-11-11 12:00:00',
						'limit'         => 2,
						'offset'        => 0,
					)
				);
				$this->assertSame( array(
					'ids'   => array( 102, 101 ),
					'total' => 3,
				), $found );
				list( $count_sql, $params ) = $GLOBALS['wpdb']->queries[0];
				$this->assertStringContainsString( "FROM wp_7_posts p INNER JOIN wp_7_datamachine_event_dates ed ON ed.post_id = p.ID WHERE p.post_type = %s AND p.post_status = 'publish'", $count_sql );
				$this->assertStringContainsString( 'tt.taxonomy = %s AND tt.term_id IN ( %d, %d ) ) OR EXISTS', $count_sql );
				$this->assertStringContainsString( '( ed.start_datetime >= %s OR ed.end_datetime >= %s )', $count_sql );
				$this->assertSame( substr_count( $count_sql, '%' ), count( $params ) );
				$this->assertSame( array( 'data_machine_events', 'venue', 601, 602, 'promoter', 701, 'venue', 602, '2026-11-11 12:00:00', '2026-11-11 12:00:00', '2026-12-31 23:59:59' ), $params );
				list( $page_sql, $page_params ) = $GLOBALS['wpdb']->queries[1];
				$this->assertStringEndsWith( 'ORDER BY ed.start_datetime ASC, p.ID ASC LIMIT %d OFFSET %d', $page_sql );
				$this->assertSame( array( 2, 0 ), array_slice( $page_params, -2 ) );

				$GLOBALS['wpdb']->queries = array();
				$finder->find( array(
					'unrestricted' => true,
					'when'         => 'past',
					'now'          => '2026-11-11 12:00:00',
					'limit'        => 5,
					'offset'       => 0,
				) );
				$this->assertStringNotContainsString( 'term_id IN', $GLOBALS['wpdb']->queries[0][0] );
				$this->assertStringContainsString( 'ORDER BY ed.start_datetime DESC', $GLOBALS['wpdb']->queries[1][0] );
			} finally {
				$GLOBALS['wpdb'] = $original;
			}
		}

		public function test_both_abilities_are_rest_exposed_with_per_user_permission(): void {
			foreach ( array(
				ManagedEventAbilities::LIST_ABILITY   => array( 'can_list', 'list_events', true ),
				ManagedEventAbilities::UPDATE_ABILITY => array( 'can_update', 'update_event', false ),
			) as $name => $expected ) {
				$definition = $this->registered( $name );
				$this->assertTrue( $definition['meta']['show_in_rest'] );
				$this->assertSame( $expected[2], $definition['meta']['annotations']['readonly'] );
				$this->assertSame( array( $this->ability, $expected[0] ), $definition['permission_callback'] );
				$this->assertSame( array( $this->ability, $expected[1] ), $definition['execute_callback'] );
			}
		}

		private function registered( string $name ): array {
			$GLOBALS['me_abilities'] = array();
			$this->ability->register();
			return $GLOBALS['me_abilities'][ $name ];
		}
	}
}
