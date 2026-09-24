<?php
/**
 * Event management authorization: door list, perk config, and redemption
 * all share this one "can this user manage this event?" decision.
 *
 * Pure-PHP unit test in the same shape as PromoterAuthorityTest.php in this
 * same directory: a bespoke fake $wpdb seeded directly, real domain classes
 * loaded and exercised unmodified. This proves the COMPOSITION in
 * extrachill_events_user_can_manage_event() — that it correctly reuses
 * WordPress's own edit_post capability plus
 * PromoterAuthorityRepository::get_active_membership() and
 * VenueMembershipRepository::get_active() — not the repositories'
 * internal correctness, which is already covered by
 * tests/PromoterAuthorityTest.php and tests/VenueMembershipAuthorizationTest.php.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims, the fake $wpdb double, and the test case class, matching PromoterAuthorityTest.php's shape.

use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\PromoterAuthorityRepository;
use ExtraChillEvents\Core\PromoterAuthoritySchema;
use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueMembershipRepository;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/' );
}
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
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default_value = false ) {
		return $GLOBALS['event_management_test']['options'][ $key ] ?? $default_value; }
}
if ( ! function_exists( 'user_can' ) ) {
	/**
	 * @param int    $user_id     Candidate user ID.
	 * @param string $capability  Capability, including meta capabilities
	 *                            like 'edit_post'.
	 * @param mixed  ...$args     For meta capabilities, the object ID (e.g.
	 *                            the post ID for 'edit_post').
	 */
	function user_can( $user_id, $capability, ...$args ) {
		if ( 'edit_post' === $capability ) {
			$post_id = $args[0] ?? 0;
			return in_array( (int) $post_id, $GLOBALS['event_management_test']['editable_posts'][ $user_id ] ?? array(), true );
		}
		return in_array( $capability, $GLOBALS['event_management_test']['caps'][ $user_id ] ?? array(), true );
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return $GLOBALS['event_management_test']['posts'][ $post_id ] ?? null; }
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy ) {
		return $GLOBALS['event_management_test']['terms'][ $post_id ][ $taxonomy ] ?? array(); }
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return 7; }
}

/** Fake $wpdb answering the exact single-row lookups the two repositories issue. */
final class EventManagementTestWpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $rows       = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			static function ( $matched ) use ( &$args, &$i ) {
				$value = $args[ $i++ ];
				return '%d' === $matched[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function get_row( $query, $output = null ) {
		unset( $output );
		$this->last_error = '';
		if ( ! preg_match( '/FROM (\S+) WHERE (\w+) = (\d+) AND (\w+) = (\d+)/', $query, $match ) ) {
			return null;
		}
		foreach ( $this->rows[ $match[1] ] ?? array() as $row ) {
			if ( (int) $row[ $match[2] ] === (int) $match[3] && (int) $row[ $match[4] ] === (int) $match[5] ) {
				return $row;
			}
		}
		return null;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/PromoterAuthoritySchema.php';
require_once dirname( __DIR__ ) . '/inc/Core/PromoterAuthorityRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/BookingSchema.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueAuthorization.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueMembershipRepository.php';
require_once dirname( __DIR__ ) . '/inc/core/event-management-authority.php';

final class EventManagementAuthorityTest extends TestCase {

	private const EVENT_ID    = 486727;
	private const PROMOTER_ID = 501;
	private const VENUE_ID    = 601;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb']                  = new EventManagementTestWpdb();
		$GLOBALS['event_management_test'] = array(
			'options'        => array(
				PromoterAuthoritySchema::VERSION_OPTION => PromoterAuthoritySchema::SCHEMA_VERSION,
				BookingSchema::VERSION_OPTION           => BookingSchema::SCHEMA_VERSION,
			),
			'caps'           => array(),
			'editable_posts' => array(),
			'posts'          => array(
				self::EVENT_ID => (object) array(
					'ID'        => self::EVENT_ID,
					'post_type' => 'data_machine_events',
				),
			),
			'terms'          => array(
				self::EVENT_ID => array(
					'promoter' => array( (object) array( 'term_id' => self::PROMOTER_ID ) ),
					'venue'    => array( (object) array( 'term_id' => self::VENUE_ID ) ),
				),
			),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['event_management_test'] );
		parent::tearDown();
	}

	private function seed_promoter_membership( int $user_id, string $status = 'active' ): void {
		$GLOBALS['wpdb']->rows[ PromoterAuthoritySchema::memberships_table() ][] = array(
			'id'                 => 1,
			'promoter_term_id'   => self::PROMOTER_ID,
			'user_id'            => $user_id,
			'is_owner'           => 0,
			'status'             => $status,
			'version'            => 1,
			'created_by_user_id' => 1,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
			'revoked_by_user_id' => null,
			'revoked_at'         => null,
		);
	}

	private function seed_venue_membership( int $user_id, string $status = 'active' ): void {
		$GLOBALS['wpdb']->rows[ BookingSchema::memberships_table() ][] = array(
			'id'                 => 1,
			'venue_term_id'      => self::VENUE_ID,
			'user_id'            => $user_id,
			'is_owner'           => 0,
			'status'             => $status,
			'created_by_user_id' => 1,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
			'version'            => 1,
		);
	}

	public function test_network_admin_is_always_authorized(): void {
		$GLOBALS['event_management_test']['caps'][42] = array( 'manage_network_options' );

		$this->assertTrue( extrachill_events_user_can_manage_event( 42, self::EVENT_ID ) );
	}

	public function test_a_stranger_with_no_membership_is_denied(): void {
		$this->assertFalse( extrachill_events_user_can_manage_event( 999, self::EVENT_ID ) );
	}

	public function test_an_active_promoter_member_is_authorized(): void {
		$this->seed_promoter_membership( 7 );

		$this->assertTrue( extrachill_events_user_can_manage_event( 7, self::EVENT_ID ) );
	}

	public function test_a_revoked_promoter_member_is_denied(): void {
		$this->seed_promoter_membership( 7, 'revoked' );

		$this->assertFalse( extrachill_events_user_can_manage_event( 7, self::EVENT_ID ) );
	}

	public function test_an_active_venue_member_is_authorized(): void {
		$this->seed_venue_membership( 9 );

		$this->assertTrue( extrachill_events_user_can_manage_event( 9, self::EVENT_ID ) );
	}

	/**
	 * The generalization added for #879: a plain WordPress edit_post
	 * capability on the event post is now sufficient, so a team member
	 * with no promoter/venue membership — but who could already edit this
	 * event in wp-admin — is not locked out of perk config / door list /
	 * redemption either. Previously only EventPerkAbilities checked this,
	 * duplicated and narrower (edit_post only, no promoter/venue path).
	 */
	public function test_edit_post_capability_alone_is_sufficient(): void {
		$GLOBALS['event_management_test']['editable_posts'][15] = array( self::EVENT_ID );

		$this->assertTrue( extrachill_events_user_can_manage_event( 15, self::EVENT_ID ) );
	}

	public function test_edit_post_on_a_different_post_does_not_grant_this_event(): void {
		$GLOBALS['event_management_test']['editable_posts'][15] = array( 999999 );

		$this->assertFalse( extrachill_events_user_can_manage_event( 15, self::EVENT_ID ) );
	}

	/**
	 * Pins the privacy boundary the review flagged: consolidating onto this
	 * one shared check WIDENS door-list access (which shows private
	 * attendees) to anyone with edit_post on the event — acceptable on
	 * this install because events are authored by a shared "Extra Chill
	 * Staff" account and only two admins/editors hold edit_others_posts;
	 * author/contributor/extra_chill_team can only edit_post on posts they
	 * personally authored. A contributor who did NOT author this event
	 * therefore has no edit_post capability on it, and — with no
	 * promoter/venue membership either — must still be denied.
	 */
	public function test_a_contributor_who_did_not_author_the_event_cannot_see_its_door_list(): void {
		// The contributor authored some OTHER post, never this event — the
		// same shape WordPress's own map_meta_cap would produce.
		$GLOBALS['event_management_test']['editable_posts'][30] = array( 111111 );

		$this->assertFalse( extrachill_events_user_can_manage_event( 30, self::EVENT_ID ) );
	}

	public function test_a_nonexistent_event_is_denied(): void {
		$this->assertFalse( extrachill_events_user_can_manage_event( 42, 999999 ) );
	}

	/**
	 * extrachill_events_user_can_manage_event_door_list() is kept as a thin
	 * back-compat alias for the function's original, narrower name.
	 */
	public function test_the_door_list_named_alias_delegates_to_the_general_check(): void {
		$this->seed_venue_membership( 9 );

		$this->assertTrue( extrachill_events_user_can_manage_event_door_list( 9, self::EVENT_ID ) );
		$this->assertFalse( extrachill_events_user_can_manage_event_door_list( 999, self::EVENT_ID ) );
	}

	public function test_filter_grants_when_the_events_authority_check_passes(): void {
		$this->seed_venue_membership( 9 );

		$allowed = extrachill_events_authorize_full_event_attendance( false, 9, self::EVENT_ID, 7 );

		$this->assertTrue( $allowed );
	}

	public function test_filter_does_not_reason_about_a_different_blog(): void {
		$this->seed_venue_membership( 9 );

		// get_current_blog_id() is stubbed to 7; a different target blog_id
		// must not be answered by this site's promoter/venue terms.
		$allowed = extrachill_events_authorize_full_event_attendance( false, 9, self::EVENT_ID, 3 );

		$this->assertFalse( $allowed );
	}

	public function test_filter_never_revokes_an_existing_grant(): void {
		$allowed = extrachill_events_authorize_full_event_attendance( true, 999, self::EVENT_ID, 7 );

		$this->assertTrue( $allowed );
	}
}
