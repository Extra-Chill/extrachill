<?php
/**
 * WordPress team capability bridge regression tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\TeamCapabilities;
use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueMembershipRepository;
use ExtraChillEvents\Core\VenueSchema;
use ExtraChillEvents\Core\PromoterAuthoritySchema;

require_once __DIR__ . '/VenueMembershipAuthorizationTest.php';
require_once __DIR__ . '/PromoterAuthorityTest.php';
require_once __DIR__ . '/../../extrachill-users/inc/teams/TeamMembershipRepository.php';
require_once __DIR__ . '/../inc/Core/NetworkTeamMembersMigration.php';
require_once dirname( __DIR__ ) . '/inc/Core/TeamCapabilities.php';
require_once dirname( __DIR__ ) . '/inc/Core/PromoterAuthoritySchema.php';
require_once dirname( __DIR__ ) . '/inc/Core/PromoterAuthorityRepository.php';

/** Exercises the public WordPress boundary, not just the policy wrapper. */
final class TeamAuthorizationTest extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['promoter_link_page_fixture']['principal_user_id'], $GLOBALS['promoter_link_page_fixture']['principal_capabilities'] );
		$GLOBALS['wpdb']                  = new VenueMembershipWpdb();
		$GLOBALS['venue_membership_test'] = array(
			'current_blog_id'   => 7,
			'term_meta'         => array(),
			'term_history'      => array(),
			'options'           => array(
				VenueSchema::VERSION_OPTION             => VenueSchema::SCHEMA_VERSION,
				PromoterAuthoritySchema::VERSION_OPTION => PromoterAuthoritySchema::SCHEMA_VERSION,
			),
			'terms'             => array(
				55 => (object) array(
					'term_id'  => 55,
					'taxonomy' => 'venue',
				),
			),
			'users'             => array(
				1 => new WP_User( 1 ),
				2 => new WP_User( 2 ),
				3 => new WP_User( 3 ),
				4 => new WP_User( 4 ),
			),
			'administrators'    => array( 1 => true ),
			'team_access'       => array(
				2 => true,
				3 => true,
				4 => true,
			),
			'feature_available' => true,
		);
		TeamCapabilities::register();
		foreach ( array(
			2 => true,
			3 => false,
		) as $user => $owner ) {
			$result = ( new VenueMembershipRepository() )->create(
				array(
					'venue_term_id'      => 55,
					'user_id'            => $user,
					'is_owner'           => $owner,
					'status'             => 'active',
					'created_by_user_id' => 1,
				)
			);
			$this->assertIsArray( $result );
		}
	}

	protected function tearDown(): void {
		remove_filter( 'map_meta_cap', array( TeamCapabilities::class, 'map_meta_cap' ), 10 );
		unset( $GLOBALS['wpdb'], $GLOBALS['venue_membership_test'], $GLOBALS['promoter_link_page_fixture']['principal_user_id'], $GLOBALS['promoter_link_page_fixture']['principal_capabilities'] );
	}

	public function test_owner_member_outsider_and_admin(): void {
		foreach ( array(
			2 => true,
			3 => true,
			4 => false,
		) as $user => $allowed ) {
			$this->assertSame( $allowed, user_can( $user, 'ec_access_venue', 55 ) );
			$this->assertSame( 2 === $user, user_can( $user, 'ec_manage_venue_finances', 55 ) );
			$this->assertSame( 2 === $user, user_can( $user, 'ec_manage_venue_members', 55 ) );
			$this->assertFalse( user_can( $user, 'ec_access_promoter', 55 ) );
			$this->assertFalse( user_can( $user, 'ec_manage_promoter_members', 55 ) );
		}
		$this->assertTrue( user_can( 1, 'ec_manage_venue_members', 55 ) );
		$GLOBALS['venue_membership_test']['team_access'][2] = false;
		$this->assertFalse( user_can( 2, 'ec_access_venue', 55 ) );
		$GLOBALS['venue_membership_test']['team_access'][2]    = true;
		$GLOBALS['venue_membership_test']['feature_available'] = false;
		$this->assertFalse( user_can( 2, 'ec_access_venue', 55 ) );
	}

	/** Venue owner actions enforce the same ceiling for ordinary and locked reads. */
	public function test_venue_owner_actions_enforce_principal_ceiling(): void {
		$authorization = new VenueAuthorization();
		$locked        = array_values( $GLOBALS['wpdb']->rows['wp_7_ec_venue_members'] );
		$GLOBALS['promoter_link_page_fixture']['principal_user_id'] = 2;
		foreach ( array( array(), array( VenueAuthorization::ACCESS_CAPABILITY ) ) as $ceiling ) {
			$GLOBALS['promoter_link_page_fixture']['principal_capabilities'] = $ceiling;
			foreach ( VenueAuthorization::actions() as $action ) {
				$results = array(
					$authorization->authorize( 2, 55, $action ),
					$authorization->authorize_locked( 2, 55, $action, $locked ),
				);
				foreach ( $results as $result ) {
					if ( $ceiling ) {
						$this->assertTrue( $result );
					} else {
						$this->assertInstanceOf( WP_Error::class, $result );
						$this->assertSame( 'venue_action_forbidden', $result->get_error_code() );
						$this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
					}
				}
			}
		}
	}

	/** An administrator override requires manage_options in the active ceiling. */
	public function test_venue_admin_override_enforces_principal_ceiling(): void {
		$authorization = new VenueAuthorization();
		$GLOBALS['promoter_link_page_fixture']['principal_user_id']      = 1;
		$GLOBALS['promoter_link_page_fixture']['principal_capabilities'] = array( VenueAuthorization::ACCESS_CAPABILITY );
		$this->assertSame( 'venue_action_forbidden', $authorization->authorize( 1, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS )->get_error_code() );
		$GLOBALS['promoter_link_page_fixture']['principal_capabilities'] = array( 'manage_options' );
		$this->assertTrue( $authorization->authorize( 1, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
	}

	/** Web requests retain domain authority without adding an owner capability gate. */
	public function test_web_venue_authority_is_unchanged(): void {
		$authorization = new VenueAuthorization();
		foreach ( VenueAuthorization::actions() as $action ) {
			$this->assertTrue( $authorization->authorize( 2, 55, $action ) );
			$this->assertSame( VenueAuthorization::ACTION_ACCESS_VENUE === $action, $authorization->can( 3, 55, $action ) );
			$this->assertSame( VenueAuthorization::ACTION_MANAGE_MEMBERS === $action, $authorization->can( 1, 55, $action ) );
		}
		$GLOBALS['venue_membership_test']['team_access'][2]    = false;
		$GLOBALS['venue_membership_test']['feature_available'] = false;
		$this->assertTrue( $authorization->authorize( 2, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
		$this->assertFalse( $authorization->can( 2, 55, VenueAuthorization::ACTION_MANAGE_FINANCES ) );
		$this->assertFalse( $authorization->can( 3, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) );
		$this->assertTrue( $authorization->authorize( 1, 55, VenueAuthorization::ACTION_MANAGE_MEMBERS ) );
	}

	public function test_promoter_capabilities_do_not_inherit_venue_membership(): void {
		$GLOBALS['wpdb'] = new PromoterAuthorityWpdb();
		$GLOBALS['venue_membership_test']['terms'][55]->taxonomy = 'promoter';
		$repository = new \ExtraChillEvents\Core\PromoterAuthorityRepository();
		$this->assertIsArray( $repository->verify( 55, null, 1 ) );
		foreach ( array(
			2 => true,
			3 => false,
		) as $user => $owner ) {
			$this->assertIsArray( $repository->create_membership( 55, $user, $owner, 1 ) );
		}
		$this->assertTrue( user_can( 2, 'ec_access_promoter', 55 ) );
		$this->assertTrue( user_can( 3, 'ec_access_promoter', 55 ) );
		$this->assertFalse( user_can( 4, 'ec_access_promoter', 55 ) );
		$this->assertTrue( user_can( 2, 'ec_manage_promoter_members', 55 ) );
		$this->assertFalse( user_can( 3, 'ec_manage_promoter_members', 55 ) );
		$this->assertTrue( user_can( 1, 'ec_manage_promoter_members', 55 ) );
		$this->assertFalse( user_can( 2, 'ec_access_venue', 55 ) );
	}

	public function test_invalid_terms_and_unrelated_capabilities(): void {
		foreach ( array( 'ec_access_venue', 'ec_manage_venue_members', 'ec_manage_venue_finances', 'ec_access_promoter', 'ec_manage_promoter_members' ) as $cap ) {
			$this->assertFalse( user_can( 2, $cap ) );
			$this->assertFalse( user_can( 2, $cap, -1 ) );
			$this->assertFalse( user_can( 2, $cap, 999 ) );
		}
		$this->assertSame( array( 'custom_primitive' ), apply_filters( 'map_meta_cap', array( 'custom_primitive' ), 'unrelated', 2, array() ) );
	}
}
