<?php
/**
 * Who gets a venue's or promoter's commerce emails.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Test doubles and WordPress stubs.

namespace ExtraChillEvents\Core {
	if ( ! class_exists( VenueMembershipRepository::class ) ) {
		class VenueMembershipRepository {
			public function list_for_venue( $id, $filters ) {
				return array_slice( $GLOBALS['csn_members'], $filters['offset'], $filters['limit'] );
			}
		}
		class VenueAuthorization {
			const ACTION_MANAGE_FINANCES = 'manage_finances';
			public function __construct( $repository = null ) {}
			public function can( $user, $venue, $action ) {
				return 1 === $user && 9 === $venue && self::ACTION_MANAGE_FINANCES === $action;
			}
		}
		class PromoterAuthoritySchema {
			public static function is_ready() { return true; }
		}
		class PromoterAuthorityRepository {
			public function get_organization( $id ) { return array( 'status' => $GLOBALS['csn_org_status'] ); }
			public function list_memberships( $id ) { return $GLOBALS['csn_members']; }
		}
	}
}

namespace {
	foreach ( array(
		'ec_get_blog_id'       => static fn( $site ) => 'events' === $site ? 7 : 3,
		'get_current_blog_id'  => static fn() => 3,
		'switch_to_blog'       => static function ( $id ) { $GLOBALS['csn_blogs'][] = $id; },
		'restore_current_blog' => static function () { array_pop( $GLOBALS['csn_blogs'] ); },
		'get_term'             => static fn( $id, $type ) => (object) array( 'taxonomy' => $type, 'name' => ucfirst( $type ) ),
		'get_userdata'         => static fn( $id ) => (object) array( 'user_email' => "user$id@example.com" ),
	) as $name => $impl ) {
		if ( ! function_exists( $name ) ) {
			$GLOBALS['csn_impl'][ $name ] = $impl;
			eval( "function {$name}( ...\$a ) { return ( \$GLOBALS['csn_impl']['{$name}'] )( ...\$a ); }" );
		}
	}

	require_once dirname( __DIR__, 3 ) . '/inc/Abilities/CommerceSellerNotifications.php';

	use ExtraChillEvents\Abilities\CommerceSellerNotifications;

	final class CommerceSellerNotificationsTest extends \PHPUnit\Framework\TestCase {
		protected function setUp(): void {
			$GLOBALS['csn_blogs']      = array();
			$GLOBALS['csn_org_status'] = 'active';
			$GLOBALS['csn_members']    = array(
				array( 'user_id' => 1, 'status' => 'active', 'is_owner' => true ),
				array( 'user_id' => 2, 'status' => 'active', 'is_owner' => true ),
				array( 'user_id' => 3, 'status' => 'revoked', 'is_owner' => true ),
				array( 'user_id' => 6, 'status' => 'active', 'is_owner' => false ),
			);
		}

		public function test_venue_only_reaches_owners_with_finance_authority(): void {
			$data = CommerceSellerNotifications::get_data( 'venue', 9 );
			$this->assertSame( array( 'name' => 'Venue', 'recipients' => array( 'user1@example.com' ) ), $data );
			$this->assertSame( array(), $GLOBALS['csn_blogs'], 'The blog switch is restored.' );
		}

		public function test_promoter_reaches_active_owners(): void {
			$this->assertSame( array( 'user1@example.com', 'user2@example.com' ), CommerceSellerNotifications::get_data( 'promoter', 9 )['recipients'] );
		}

		public function test_inactive_promoter_and_unknown_types_reach_nobody(): void {
			$GLOBALS['csn_org_status'] = 'revoked';
			$this->assertSame( array(), CommerceSellerNotifications::get_data( 'promoter', 9 )['recipients'] );
			$this->assertSame( array(), CommerceSellerNotifications::get_data( 'artist', 9 )['recipients'] );
		}
	}
}
