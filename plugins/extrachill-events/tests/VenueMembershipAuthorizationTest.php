<?php
/**
 * Venue membership and authorization tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\VenueMembershipAbilities;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueMembershipRepository;
use ExtraChillEvents\Core\VenueMembershipService;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code, $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code; }
		public function get_error_message() {
			return $this->message; }
		public function get_error_data() {
			return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error; }
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		$term = $GLOBALS['venue_membership_test']['terms'][ $term_id ] ?? null;
		return $term && ( '' === $taxonomy || $taxonomy === $term->taxonomy ) ? $term : null;
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return $GLOBALS['venue_membership_test']['users'][ $user_id ] ?? false;
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, $capability ) {
		if ( 'manage_options' === $capability ) {
			return ! empty( $GLOBALS['venue_membership_test']['administrators'][ $user_id ] );
		}
		return VenueAuthorization::ACCESS_CAPABILITY === $capability && ! empty( $GLOBALS['venue_membership_test']['team_access'][ $user_id ] );
	}
}
if ( ! function_exists( 'ec_feature_available' ) ) {
	function ec_feature_available( $feature, $user_id = null ) {
		unset( $user_id );
		return VenueAuthorization::FEATURE === $feature && ! empty( $GLOBALS['venue_membership_test']['feature_available'] );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return $GLOBALS['venue_membership_test']['current_user_id']; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback ) {
		$GLOBALS['venue_membership_test']['actions'][ $hook ][] = $callback; }
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $definition ) {
		$GLOBALS['venue_membership_test']['abilities'][ $name ] = $definition; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['venue_membership_test']['options'][ $key ] ?? $default; }
}

/** Minimal membership wpdb fake with transaction and optimistic update support. */
final class VenueMembershipWpdb {
	public $prefix      = 'wp_7_';
	public $insert_id   = 0;
	public $last_error  = '';
	public $rows        = array();
	public $race_insert = false;
	public $after_start = null;
	public $before_list = null;
	private $snapshot   = null;

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			static function ( $match ) use ( &$args, &$i ) {
				$value = $args[ $i++ ];
				return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function insert( $table, $row ) {
		$this->last_error = '';
		foreach ( $this->rows[ $table ] ?? array() as $existing ) {
			if ( (int) $existing['venue_term_id'] === (int) $row['venue_term_id'] && (int) $existing['user_id'] === (int) $row['user_id'] ) {
				$this->last_error = 'duplicate venue user';
				return false;
			}
		}
		if ( $this->race_insert ) {
			$this->race_insert = false;
			$this->store( $table, array_merge( $row, array( 'is_owner' => 0 ) ) );
			$this->snapshot   = $this->rows;
			$this->last_error = 'simulated concurrent duplicate';
			return false;
		}
		$this->store( $table, $row );
		return 1;
	}

	public function get_row( $query, $output = null ) {
		unset( $output );
		$this->last_error = '';
		if ( ! preg_match( '/venue_term_id = (\d+) AND user_id = (\d+)/', $query, $match ) ) {
			return null;
		}
		foreach ( $this->rows[ $this->prefix . 'ec_venue_members' ] ?? array() as $row ) {
			if ( (int) $row['venue_term_id'] === (int) $match[1] && (int) $row['user_id'] === (int) $match[2] ) {
				return $row;
			}
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		unset( $output );
		$this->last_error = '';
		if ( false !== strpos( $query, 'SELECT member.*' ) && is_callable( $this->before_list ) ) {
			$callback          = $this->before_list;
			$this->before_list = null;
			$callback( $this );
		}
		$rows = array_values( $this->rows[ $this->prefix . 'ec_venue_members' ] ?? array() );
		if ( preg_match( '/AS actor .*actor.user_id = (\d+)/', $query, $actor ) ) {
			$authorized = false;
			preg_match( '/member\.venue_term_id = (\d+)/', $query, $requested_venue );
			foreach ( $rows as $row ) {
				if ( (int) $row['user_id'] === (int) $actor[1] && (int) $row['venue_term_id'] === (int) ( $requested_venue[1] ?? 0 ) && ! empty( $row['is_owner'] ) && VenueAuthorization::STATUS_ACTIVE === $row['status'] ) {
					$authorized = true;
					break;
				}
			}
			if ( ! $authorized ) {
				return array();
			}
		}
		if ( preg_match( '/venue_term_id = (\d+)/', $query, $match ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $match ) {
						return (int) $row['venue_term_id'] === (int) $match[1];
					}
				)
			);
		}
		foreach ( array( 'status' ) as $field ) {
			if ( preg_match( "/member\.{$field} = '([^']+)'/", $query, $match ) ) {
				$rows = array_values(
					array_filter(
						$rows,
						static function ( $row ) use ( $field, $match ) {
							return $row[ $field ] === stripslashes( $match[1] );
						}
					)
				);
			}
		}
		if ( preg_match( '/member\.is_owner = ([01])/', $query, $owner_match ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $owner_match ) {
						return (int) $row['is_owner'] === (int) $owner_match[1];
					}
				)
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $a['id'] <=> $b['id'];
			}
		);
		if ( preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $query, $page ) ) {
			$rows = array_slice( $rows, (int) $page[2], (int) $page[1] );
		}
		return $rows;
	}

	public function query( $query ) {
		$this->last_error = '';
		if ( 'START TRANSACTION' === $query ) {
			$this->snapshot = $this->rows;
			if ( is_callable( $this->after_start ) ) {
				$callback          = $this->after_start;
				$this->after_start = null;
				$callback( $this );
				$this->snapshot = $this->rows;
			}
			return 1;
		}
		if ( 'ROLLBACK' === $query ) {
			$this->rows     = $this->snapshot;
			$this->snapshot = null;
			return 1;
		}
		if ( 'COMMIT' === $query ) {
			$this->snapshot = null;
			return 1;
		}
		if ( ! preg_match( '/WHERE venue_term_id = (\d+) AND user_id = (\d+) AND version = (\d+)/', $query, $match ) ) {
			return false;
		}
		$table = $this->prefix . 'ec_venue_members';
		foreach ( $this->rows[ $table ] ?? array() as $id => $row ) {
			if ( (int) $row['venue_term_id'] !== (int) $match[1] || (int) $row['user_id'] !== (int) $match[2] || (int) $row['version'] !== (int) $match[3] ) {
				continue;
			}
			$set = substr( $query, strpos( $query, ' SET ' ) + 5, strpos( $query, ' WHERE ' ) - strpos( $query, ' SET ' ) - 5 );
			preg_match_all( "/([a-z_]+) = (version \\+ 1|[01]|'(?:\\\\.|[^'])*')(?=, [a-z_]+ = |$)/", $set, $assignments, PREG_SET_ORDER );
			foreach ( $assignments as $assignment ) {
				if ( 'version + 1' === $assignment[2] ) {
					++$this->rows[ $table ][ $id ]['version'];
				} elseif ( "'" === $assignment[2][0] ) {
					$this->rows[ $table ][ $id ][ $assignment[1] ] = stripslashes( substr( $assignment[2], 1, -1 ) );
				} else {
					$this->rows[ $table ][ $id ][ $assignment[1] ] = (int) $assignment[2];
				}
			}
			return 1;
		}
		return 0;
	}

	private function store( $table, $row ): void {
		$this->insert_id                          = count( $this->rows[ $table ] ?? array() ) + 1;
		$row['id']                                = $this->insert_id;
		$this->rows[ $table ][ $this->insert_id ] = $row;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/BookingSchema.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueAuthorization.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueMembershipRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueMembershipService.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/VenueMembershipAbilities.php';

final class VenueMembershipAuthorizationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['venue_membership_test'] = array(
			'terms'             => array(
				55 => (object) array(
					'term_id'  => 55,
					'taxonomy' => 'venue',
				),
				56 => (object) array(
					'term_id'  => 56,
					'taxonomy' => 'venue',
				),
				57 => (object) array(
					'term_id'  => 57,
					'taxonomy' => 'artist',
				),
			),
			'users'             => array(
				1 => (object) array( 'ID' => 1 ),
				2 => (object) array( 'ID' => 2 ),
				3 => (object) array( 'ID' => 3 ),
				4 => (object) array( 'ID' => 4 ),
				5 => (object) array( 'ID' => 5 ),
				6 => (object) array( 'ID' => 6 ),
				7 => (object) array( 'ID' => 7 ),
			),
			'administrators'    => array( 1 => true ),
			'team_access'       => array(
				2 => true,
				3 => true,
				4 => true,
				5 => true,
				6 => true,
				7 => true,
			),
			'feature_available' => true,
			'current_user_id'   => 1,
			'actions'           => array(),
			'abilities'         => array(),
			'options'           => array( BookingSchema::VERSION_OPTION => BookingSchema::SCHEMA_VERSION ),
		);
		$GLOBALS['wpdb']                  = new VenueMembershipWpdb();
	}

	private function create_member( int $venue, int $user, bool $is_owner, string $status = VenueAuthorization::STATUS_ACTIVE, int $creator = 1 ) {
		return ( new VenueMembershipRepository() )->create(
			array(
				'venue_term_id'      => $venue,
				'user_id'            => $user,
				'is_owner'           => $is_owner,
				'status'             => $status,
				'created_by_user_id' => $creator,
			)
		);
	}

	public function test_capability_scope_and_inactive_statuses_fail_closed(): void {
		$authorization = new VenueAuthorization();
		$this->create_member( 55, 3, true );
		$this->create_member( 55, 2, false );
		$this->create_member( 56, 4, true, VenueAuthorization::STATUS_INVITED );
		$this->create_member( 56, 5, true, VenueAuthorization::STATUS_REVOKED );
		$this->create_member( 56, 6, true );

		$this->assertTrue( $authorization->can( 2, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) );
		$this->assertFalse( $authorization->can( 2, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
		$this->assertTrue( $authorization->can( 3, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
		$this->assertFalse( $authorization->can( 4, 56, VenueAuthorization::ACTION_ACCESS_VENUE ) );
		$this->assertFalse( $authorization->can( 5, 56, VenueAuthorization::ACTION_ACCESS_VENUE ) );

		unset( $GLOBALS['venue_membership_test']['team_access'][6] );
		$this->assertFalse( $authorization->can( 6, 56, VenueAuthorization::ACTION_ACCESS_VENUE ) );
		$GLOBALS['venue_membership_test']['feature_available'] = false;
		$this->assertFalse( $authorization->can( 3, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) );
	}

	public function test_administrator_override_validates_venue_and_cross_venue_access_is_denied(): void {
		$authorization = new VenueAuthorization();
		$this->assertTrue( $authorization->can( 1, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
		$this->assertFalse( $authorization->can( 1, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) );
		$this->assertSame( 'invalid_venue_membership_venue', $authorization->authorize( 1, 999, VenueAuthorization::ACTION_MANAGE_MEMBERS )->get_error_code() );
		$this->assertSame( 'invalid_venue_membership_venue', $authorization->authorize( 1, 57, VenueAuthorization::ACTION_MANAGE_MEMBERS )->get_error_code() );

		$this->create_member( 55, 2, true );
		$this->assertTrue( $authorization->can( 2, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
		$this->assertFalse( $authorization->can( 2, 56, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
	}

	public function test_admin_bootstraps_owner_and_only_owner_can_manage_exact_venue(): void {
		$service = new VenueMembershipService();
		$this->assertSame( 'venue_membership_owner_required', $service->create( 1, 55, 3, false )->get_error_code() );
		$owner = $service->create( 1, 55, 2, true );
		$this->assertTrue( $owner['is_owner'] );
		$this->assertSame( 1, $owner['created_by_user_id'] );

		$member = $service->create( 2, 55, 3, false );
		$this->assertFalse( $member['is_owner'] );
		$this->assertSame( 'venue_action_forbidden', $service->create( 3, 55, 4, false )->get_error_code() );
		$this->assertSame( 'venue_action_forbidden', $service->create( 2, 56, 4, false )->get_error_code() );
		$this->assertSame( 'venue_action_forbidden', $service->create( 4, 56, 4, true )->get_error_code() );
	}

	public function test_unique_relationship_and_concurrent_create_conflicts_are_explicit(): void {
		$repository = new VenueMembershipRepository();
		$first      = $this->create_member( 55, 2, true );
		$duplicate  = $this->create_member( 55, 2, false );
		$this->assertSame( 'venue_membership_exists', $duplicate->get_error_code() );
		$this->assertSame( $first['version'], $duplicate->get_error_data()['current_version'] );

		$same_user_other_venue = $this->create_member( 56, 2, true );
		$this->assertSame( 56, $same_user_other_venue['venue_term_id'] );

		$GLOBALS['wpdb']->race_insert = true;
		$race                         = $repository->create(
			array(
				'venue_term_id'      => 55,
				'user_id'            => 3,
				'is_owner'           => false,
				'created_by_user_id' => 1,
			)
		);
		$this->assertSame( 'venue_membership_exists', $race->get_error_code() );
		$this->assertFalse( $repository->get( 55, 3 )['is_owner'] );
	}

	public function test_optimistic_updates_and_last_owner_invariant(): void {
		$repository = new VenueMembershipRepository();
		$owner      = $this->create_member( 55, 2, true );
		$this->assertSame( 'venue_membership_last_owner', $repository->update_owner( 55, 2, false, $owner['version'], 1 )->get_error_code() );
		$this->assertSame( 'venue_membership_last_owner', $repository->revoke( 55, 2, $owner['version'], 1 )->get_error_code() );

		$second = $this->create_member( 55, 3, true );
		$member = $repository->update_owner( 55, 2, false, $owner['version'], 1 );
		$this->assertFalse( $member['is_owner'] );
		$this->assertSame( 2, $member['version'] );
		$this->assertSame( 'venue_membership_version_conflict', $repository->update_owner( 55, 2, true, 1, 1 )->get_error_code() );

		$this->create_member( 55, 4, true );
		$revoked = $repository->revoke( 55, 3, $second['version'], 1 );
		$this->assertSame( VenueAuthorization::STATUS_REVOKED, $revoked['status'] );
		$this->assertNotNull( $revoked['revoked_at'] );
		$this->assertFalse( ( new VenueAuthorization( $repository ) )->can( 3, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) );
	}

	public function test_actor_authority_is_rechecked_under_the_venue_lock(): void {
		$service = new VenueMembershipService();
		$owner   = $service->create( 1, 55, 2, true );
		$this->assertSame( VenueAuthorization::STATUS_ACTIVE, $owner['status'] );

		$GLOBALS['wpdb']->after_start = static function ( VenueMembershipWpdb $wpdb ): void {
			foreach ( $wpdb->rows['wp_7_ec_venue_members'] as &$row ) {
				if ( 2 === (int) $row['user_id'] ) {
					$row['status'] = VenueAuthorization::STATUS_REVOKED;
				}
			}
			unset( $row );
		};

		$result = $service->create( 2, 55, 3, false );
		$this->assertSame( 'venue_action_forbidden', $result->get_error_code() );
		$this->assertSame( VenueAuthorization::STATUS_REVOKED, ( new VenueMembershipRepository() )->get( 55, 2 )['status'] );
		$this->assertNull( ( new VenueMembershipRepository() )->get( 55, 3 ) );
	}

	public function test_authorization_fails_closed_until_schema_is_ready(): void {
		unset( $GLOBALS['venue_membership_test']['options'][ BookingSchema::VERSION_OPTION ] );
		$result = ( new VenueAuthorization() )->authorize( 1, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS );
		$this->assertSame( 'venue_membership_schema_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_member_listing_rechecks_actor_in_the_same_query(): void {
		$service = new VenueMembershipService();
		$service->create( 1, 55, 2, true );
		$service->create( 2, 55, 3, false );

		$GLOBALS['wpdb']->before_list = static function ( VenueMembershipWpdb $wpdb ): void {
			foreach ( $wpdb->rows['wp_7_ec_venue_members'] as &$row ) {
				if ( 2 === (int) $row['user_id'] ) {
					$row['status'] = VenueAuthorization::STATUS_REVOKED;
				}
			}
			unset( $row );
		};

		$this->assertSame( array(), $service->list( 2, 55 ) );
	}

	public function test_list_filters_and_corrupt_rows_fail_closed(): void {
		$repository = new VenueMembershipRepository();
		$this->create_member( 55, 2, true );
		$this->create_member( 55, 3, false );
		$this->create_member( 55, 4, false, VenueAuthorization::STATUS_INVITED );
		$this->create_member( 56, 5, true );

		$this->assertCount( 3, $repository->list_for_venue( 55 ) );
		$this->assertCount( 2, $repository->list_for_venue( 55, array( 'status' => VenueAuthorization::STATUS_ACTIVE ) ) );
		$this->assertSame( 2, $repository->list_for_venue( 55, array( 'is_owner' => true ) )[0]['user_id'] );
		$this->assertCount( 2, $repository->list_for_venue( 55, array( 'is_owner' => false ) ) );

		$row             = $repository->get( 55, 2 );
		$row['is_owner'] = 2;
		$this->assertSame( 'venue_membership_corrupt_owner', $repository->hydrate( $row )->get_error_code() );
		$row['is_owner'] = 1;
		$row['status']   = 'paused';
		$this->assertSame( 'venue_membership_corrupt_status', $repository->hydrate( $row )->get_error_code() );
	}

	public function test_abilities_share_authorization_and_execution_rechecks_it(): void {
		$this->create_member( 55, 2, true );
		$GLOBALS['venue_membership_test']['current_user_id'] = 2;
		$abilities = new VenueMembershipAbilities();
		$abilities->register();

		$this->assertSame(
			array(
				'extrachill/create-venue-membership',
				'extrachill/update-venue-membership',
				'extrachill/revoke-venue-membership',
				'extrachill/list-venue-memberships',
			),
			array_keys( $GLOBALS['venue_membership_test']['abilities'] )
		);
		foreach ( $GLOBALS['venue_membership_test']['abilities'] as $definition ) {
			$this->assertSame( 'extrachill-events', $definition['category'] );
			$this->assertTrue( $definition['meta']['show_in_rest'] );
			$this->assertTrue( call_user_func( $definition['permission_callback'], array( 'venue_term_id' => 55 ) ) );
			$this->assertSame( 'venue_action_forbidden', call_user_func( $definition['permission_callback'], array( 'venue_term_id' => 56 ) )->get_error_code() );
		}
		$this->assertStringNotContainsString( '"role"', json_encode( $GLOBALS['venue_membership_test']['abilities'] ) );
		$this->assertTrue( $GLOBALS['venue_membership_test']['abilities']['extrachill/update-venue-membership']['meta']['annotations']['destructive'] );

		$created = call_user_func(
			$GLOBALS['venue_membership_test']['abilities']['extrachill/create-venue-membership']['execute_callback'],
			array(
				'venue_term_id' => 55,
				'user_id'       => 3,
				'is_owner'      => false,
			)
		);
		$this->assertSame( 3, $created['user_id'] );

		$GLOBALS['venue_membership_test']['current_user_id'] = 3;
		$denied = call_user_func(
			$GLOBALS['venue_membership_test']['abilities']['extrachill/create-venue-membership']['execute_callback'],
			array(
				'venue_term_id' => 55,
				'user_id'       => 4,
				'is_owner'      => false,
			)
		);
		$this->assertSame( 'venue_action_forbidden', $denied->get_error_code() );
	}
}
