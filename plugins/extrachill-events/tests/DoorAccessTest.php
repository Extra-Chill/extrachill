<?php
/**
 * Door access grant tests (extrachill#1244).
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone harness adds stubs and replaces the database with a test double.

use ExtraChillEvents\Abilities\DoorAccessAbilities;
use ExtraChillEvents\Core\DoorAccessService;
use ExtraChillEvents\Core\PromoterAuthorityRepository;
use ExtraChillEvents\Core\PromoterAuthoritySchema;
use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueMembershipRepository;

require_once __DIR__ . '/VenueMembershipAuthorizationTest.php';
require_once __DIR__ . '/PromoterAuthorityTest.php';
require_once dirname( __DIR__ ) . '/inc/Core/PromoterAuthoritySchema.php';
require_once dirname( __DIR__ ) . '/inc/Core/PromoterAuthorityRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/DoorAccessService.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/DoorAccessAbilities.php';

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return $GLOBALS['event_management_test']['posts'][ $post_id ] ?? null;
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy ) {
		return $GLOBALS['event_management_test']['terms'][ $post_id ][ $taxonomy ] ?? array();
	}
}
if ( ! function_exists( 'get_users' ) ) {
	/** Applies the same-domain search and registration window the service asks for. */
	function get_users( $args = array() ) {
		$GLOBALS['door_access_test']['get_users_args'][] = $args;
		$domain = substr( (string) $args['search'], 2 );
		$after  = strtotime( $args['date_query'][0]['after'] . ' UTC' );
		$found  = array();
		foreach ( $GLOBALS['venue_membership_test']['users'] as $user ) {
			$registered = $GLOBALS['door_access_test']['registered'][ $user->ID ] ?? '2020-01-01 00:00:00';
			if ( substr( strtolower( $user->user_email ), -strlen( '@' . $domain ) ) === '@' . $domain && strtotime( $registered . ' UTC' ) >= $after ) {
				$found[] = (object) array(
					'ID'              => $user->ID,
					'user_login'      => $user->user_login,
					'user_email'      => $user->user_email,
					'user_registered' => $registered,
				);
			}
		}
		return $found;
	}
}

/** Exercises who may grant door access and what a grant may change. */
final class DoorAccessTest extends \PHPUnit\Framework\TestCase {
	private const VENUE = 55;
	private const EVENT = 900;

	protected function setUp(): void {
		unset( $GLOBALS['promoter_link_page_fixture']['principal_user_id'], $GLOBALS['promoter_link_page_fixture']['principal_capabilities'] );
		$GLOBALS['wpdb']                  = new VenueMembershipWpdb();
		$GLOBALS['venue_membership_test'] = array(
			'current_blog_id'   => 7,
			'current_user_id'   => 0,
			'term_meta'         => array(),
			'term_history'      => array(),
			'fired_actions'     => array(),
			'options'           => array(
				PromoterAuthoritySchema::VERSION_OPTION => PromoterAuthoritySchema::SCHEMA_VERSION,
			),
			'terms'             => array(
				self::VENUE => (object) array(
					'term_id'  => self::VENUE,
					'taxonomy' => 'venue',
				),
			),
			'users'             => array(
				1 => new WP_User( 1, 'admin@example.com' ),
				2 => new WP_User( 2, 'owner@example.com' ),
				3 => new WP_User( 3, 'member@example.com' ),
				4 => new WP_User( 4, 'outsider@example.com' ),
				5 => new WP_User( 5, 'staff@extrachill.test' ),
				6 => new WP_User( 6, 'eargle@example.com' ),
				7 => new WP_User( 7, 'existing@example.com' ),
			),
			'administrators'    => array( 1 => true ),
			// Only the Extra Chill team role carries access_events_admin.
			'team_access'       => array( 5 => true ),
			'feature_available' => true,
		);
		$GLOBALS['door_access_test']      = array(
			'resolver_calls' => array(),
			'registered'     => array( 6 => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ),
		);
		$event                            = (object) array(
			'ID'        => self::EVENT,
			'post_type' => 'data_machine_events',
		);
		$GLOBALS['event_management_test'] = array(
			'posts' => array( self::EVENT => $event ),
			'terms' => array( self::EVENT => array( 'venue' => array( $GLOBALS['venue_membership_test']['terms'][ self::VENUE ] ) ) ),
		);
		$GLOBALS['ec_artist_test']        = array(
			'blog_id' => 7,
			'posts'   => array( 7 => array( self::EVENT => $event ) ),
		);
		foreach ( array(
			2 => true,
			3 => false,
		) as $user => $owner ) {
			$this->assertIsArray(
				( new VenueMembershipRepository() )->create(
					array(
						'venue_term_id'      => self::VENUE,
						'user_id'            => $user,
						'is_owner'           => $owner,
						'status'             => 'active',
						'created_by_user_id' => 1,
					)
				)
			);
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['venue_membership_test'], $GLOBALS['door_access_test'], $GLOBALS['event_management_test'], $GLOBALS['ec_artist_test'], $GLOBALS['promoter_link_page_fixture']['principal_user_id'], $GLOBALS['promoter_link_page_fixture']['principal_capabilities'] );
	}

	/** Fake of extrachill_users_ability_resolve_account_by_email(). */
	private function service(): DoorAccessService {
		$resolver = static function ( array $input ) {
			$GLOBALS['door_access_test']['resolver_calls'][] = $input;
			$existing                                        = get_user_by( 'email', $input['email'] );
			if ( $existing ) {
				return array(
					'user_id'         => $existing->ID,
					'created'         => false,
					'unclaimed'       => false,
					'claim_url'       => '',
					'claim_throttled' => false,
				);
			}
			$id = max( array_keys( $GLOBALS['venue_membership_test']['users'] ) ) + 1;
			$GLOBALS['venue_membership_test']['users'][ $id ] = new WP_User( $id, $input['email'] );
			return array(
				'user_id'         => $id,
				'created'         => true,
				'unclaimed'       => true,
				'claim_url'       => 'https://community.extrachill.test/claim/' . $id,
				'claim_throttled' => false,
			);
		};
		return new DoorAccessService( null, null, $resolver );
	}

	private function membership( int $user_id ) {
		return ( new VenueMembershipRepository() )->get( self::VENUE, $user_id );
	}

	public function test_team_user_grants_new_account_by_event(): void {
		$result = $this->service()->grant(
			5,
			array(
				'email'    => 'door@example.com',
				'event_id' => self::EVENT,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['created_account'] );
		$this->assertSame( 'door@example.com', $result['email'] );
		$this->assertSame( 'user' . $result['user_id'], $result['user_login'] );
		$this->assertSame( 'https://community.extrachill.test/claim/' . $result['user_id'], $result['claim_url'] );
		$this->assertSame( 'venue', $result['team_type'] );
		$this->assertSame( self::VENUE, $result['team_term_id'] );
		$this->assertSame( array(), $result['near_matches'] );
		$this->assertSame(
			array(
				'status'             => 'active',
				'is_owner'           => false,
				'created'            => true,
				'version'            => 1,
				'created_by_user_id' => 5,
			),
			$result['membership']
		);
		$stored = $this->membership( $result['user_id'] );
		$this->assertFalse( $stored['is_owner'] );
		$this->assertSame( 5, $stored['created_by_user_id'] );
		$this->assertSame( DoorAccessService::REGISTRATION_SOURCE, $GLOBALS['door_access_test']['resolver_calls'][0]['registration_source'] );
	}

	public function test_venue_non_owner_member_may_grant(): void {
		$result = $this->service()->grant(
			3,
			array(
				'email'         => 'existing@example.com',
				'venue_term_id' => self::VENUE,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['created_account'] );
		$this->assertNull( $result['claim_url'] );
		$this->assertSame( 7, $result['user_id'] );
		$this->assertSame( 3, $this->membership( 7 )['created_by_user_id'] );
	}

	public function test_site_admin_may_grant(): void {
		$this->assertTrue( $this->service()->can_grant( 1, array( 'venue_term_id' => self::VENUE ) ) );
	}

	public function test_random_user_is_denied_and_nothing_is_created(): void {
		$result = $this->service()->grant(
			4,
			array(
				'email'    => 'door@example.com',
				'event_id' => self::EVENT,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'door_access_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['door_access_test']['resolver_calls'] );
		$this->assertFalse( get_user_by( 'email', 'door@example.com' ) );
	}

	public function test_never_grants_owner_even_when_asked(): void {
		$result = $this->service()->grant(
			1,
			array(
				'email'         => 'existing@example.com',
				'venue_term_id' => self::VENUE,
				'is_owner'      => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['membership']['is_owner'] );
		$this->assertFalse( $this->membership( 7 )['is_owner'] );
	}

	public function test_regrant_is_idempotent_and_never_changes_ownership(): void {
		$service = $this->service();
		$input   = array(
			'email'         => 'existing@example.com',
			'venue_term_id' => self::VENUE,
		);
		$first   = $service->grant( 5, $input );
		$second  = $service->grant( 5, $input );
		$this->assertTrue( $first['membership']['created'] );
		$this->assertFalse( $second['membership']['created'] );
		$this->assertSame( $first['membership']['version'], $second['membership']['version'] );

		// The owner stays an owner when someone re-grants them door access.
		$owner = $service->grant(
			3,
			array(
				'email'         => 'owner@example.com',
				'venue_term_id' => self::VENUE,
			)
		);
		$this->assertFalse( $owner['membership']['created'] );
		$this->assertTrue( $owner['membership']['is_owner'] );
		$this->assertTrue( $this->membership( 2 )['is_owner'] );
	}

	public function test_revoked_member_is_not_silently_restored(): void {
		$members = new \ExtraChill\Users\Teams\TeamMembershipRepository();
		$members->insert( 'venue', $members->new_row( 'venue', self::VENUE, 7, false, 2, 'revoked' ) );

		$result = $this->service()->grant(
			5,
			array(
				'email'         => 'existing@example.com',
				'venue_term_id' => self::VENUE,
			)
		);

		$this->assertSame( 'door_access_membership_revoked', $result->get_error_code() );
		$this->assertSame( 'revoked', $this->membership( 7 )['status'] );
	}

	public function test_near_match_is_hinted_but_never_used(): void {
		// The real person registered as eargle@ two days ago; the host typed eagle@.
		$GLOBALS['venue_membership_test']['users'][8] = new WP_User( 8, 'eagles@example.com' );
		$GLOBALS['door_access_test']['registered'][8] = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
		$GLOBALS['venue_membership_test']['users'][9] = new WP_User( 9, 'eargle@elsewhere.test' );
		$GLOBALS['door_access_test']['registered'][9] = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$result = $this->service()->grant(
			5,
			array(
				'email'    => 'eagle@example.com',
				'event_id' => self::EVENT,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['created_account'] );
		$this->assertSame( 'eagle@example.com', $result['email'] );
		$this->assertCount( 1, $result['near_matches'] );
		$this->assertSame( 6, $result['near_matches'][0]['user_id'] );
		$this->assertSame( 'eargle@example.com', $result['near_matches'][0]['email'] );
		$this->assertSame( 1, $result['near_matches'][0]['distance'] );
		$this->assertNull( $this->membership( 6 ) );

		// An exact account produces no hints.
		$exact = $this->service()->grant(
			5,
			array(
				'email'    => 'eargle@example.com',
				'event_id' => self::EVENT,
			)
		);
		$this->assertSame( array(), $exact['near_matches'] );
	}

	public function test_user_login_attaches_existing_account(): void {
		$result = $this->service()->grant(
			5,
			array(
				'email'      => 'eagle@example.com',
				'event_id'   => self::EVENT,
				'user_login' => 'user6',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 6, $result['user_id'] );
		$this->assertSame( 'eargle@example.com', $result['email'] );
		$this->assertFalse( $result['created_account'] );
		$this->assertFalse( get_user_by( 'email', 'eagle@example.com' ) );
		$this->assertSame( 'active', $this->membership( 6 )['status'] );

		$missing = $this->service()->grant(
			5,
			array(
				'email'      => 'eagle@example.com',
				'event_id'   => self::EVENT,
				'user_login' => 'nobody',
			)
		);
		$this->assertSame( 'door_access_user_not_found', $missing->get_error_code() );
	}

	public function test_admin_and_staff_under_restricted_principal_are_denied(): void {
		$GLOBALS['promoter_link_page_fixture']['principal_capabilities'] = array( 'read' );
		foreach ( array( 1, 5, 3 ) as $user ) {
			$GLOBALS['promoter_link_page_fixture']['principal_user_id'] = $user;
			$this->assertSame( 'door_access_forbidden', $this->service()->can_grant( $user, array( 'venue_term_id' => self::VENUE ) )->get_error_code() );
		}

		// A principal acting for someone else never inherits the admin's authority.
		$GLOBALS['promoter_link_page_fixture']['principal_capabilities'] = null;
		$GLOBALS['promoter_link_page_fixture']['principal_user_id']      = 4;
		$this->assertSame( 'door_access_forbidden', $this->service()->can_grant( 1, array( 'venue_term_id' => self::VENUE ) )->get_error_code() );

		$GLOBALS['promoter_link_page_fixture']['principal_user_id']      = 1;
		$GLOBALS['promoter_link_page_fixture']['principal_capabilities'] = array( 'manage_options' );
		$this->assertTrue( $this->service()->can_grant( 1, array( 'venue_term_id' => self::VENUE ) ) );
	}

	public function test_target_validation(): void {
		$service = $this->service();
		$this->assertSame( 'door_access_target_required', $service->can_grant( 5, array() )->get_error_code() );
		$this->assertSame(
			'door_access_target_required',
			$service->can_grant(
				5,
				array(
					'event_id'      => self::EVENT,
					'venue_term_id' => self::VENUE,
				)
			)->get_error_code()
		);
		$this->assertSame( 'event_not_found', $service->can_grant( 5, array( 'event_id' => 901 ) )->get_error_code() );
		$this->assertSame(
			'door_access_event_has_no_team',
			$service->can_grant(
				5,
				array(
					'event_id'  => self::EVENT,
					'team_type' => 'promoter',
				)
			)->get_error_code()
		);
		$this->assertSame(
			'door_access_team_type_mismatch',
			$service->can_grant(
				5,
				array(
					'venue_term_id' => self::VENUE,
					'team_type'     => 'promoter',
				)
			)->get_error_code()
		);
	}

	public function test_promoter_team_by_event_hint(): void {
		$GLOBALS['wpdb']                               = new PromoterAuthorityWpdb();
		$GLOBALS['venue_membership_test']['terms'][77] = (object) array(
			'term_id'  => 77,
			'taxonomy' => 'promoter',
		);
		$GLOBALS['event_management_test']['terms'][ self::EVENT ]['promoter'] = array( $GLOBALS['venue_membership_test']['terms'][77] );
		$repository = new PromoterAuthorityRepository();
		$service    = $this->service();
		$input      = array(
			'email'     => 'existing@example.com',
			'event_id'  => self::EVENT,
			'team_type' => 'promoter',
		);
		$this->assertSame( 'door_access_promoter_unverified', $service->grant( 5, $input )->get_error_code() );

		$this->assertIsArray( $repository->verify( 77, null, 1 ) );
		$this->assertIsArray( $repository->create_membership( 77, 2, true, 1 ) );
		$this->assertIsArray( $repository->create_membership( 77, 3, false, 1 ) );

		$this->assertSame( 'door_access_forbidden', $service->grant( 4, $input )->get_error_code() );
		$result = $service->grant( 3, $input );
		$this->assertIsArray( $result );
		$this->assertSame( 'promoter', $result['team_type'] );
		$this->assertSame( 77, $result['team_term_id'] );
		$membership = $repository->get_active_membership( 77, 7 );
		$this->assertFalse( $membership['is_owner'] );
		$this->assertSame( 3, $membership['created_by_user_id'] );
	}

	public function test_ability_is_exposed_and_authorizes_the_current_user(): void {
		$abilities = new DoorAccessAbilities( $this->service() );
		$abilities->register();
		$definition = $GLOBALS['venue_membership_test']['abilities']['extrachill/grant-door-access'];

		$this->assertTrue( $definition['meta']['show_in_rest'] );
		$this->assertSame( array( 'email' ), $definition['input_schema']['required'] );
		$this->assertArrayNotHasKey( 'is_owner', $definition['input_schema']['properties'] );

		$GLOBALS['venue_membership_test']['current_user_id'] = 4;
		$this->assertInstanceOf( WP_Error::class, call_user_func( $definition['permission_callback'], array( 'venue_term_id' => self::VENUE ) ) );
		$GLOBALS['venue_membership_test']['current_user_id'] = 3;
		$this->assertTrue( call_user_func( $definition['permission_callback'], array( 'venue_term_id' => self::VENUE ) ) );
		$result = call_user_func(
			$definition['execute_callback'],
			array(
				'email'         => 'existing@example.com',
				'venue_term_id' => self::VENUE,
			)
		);
		$this->assertSame( 3, $result['membership']['created_by_user_id'] );
	}
}
