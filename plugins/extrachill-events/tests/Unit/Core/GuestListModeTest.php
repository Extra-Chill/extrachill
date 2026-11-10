<?php
/**
 * Guest list access modes and set-event-perk field handling (extrachill#1246).
 *
 * Covers: the legacy migration mapping (password → password, otherwise
 * going), that it is read-time and idempotent; extrachill_events_apply_perk_access()
 * mode/password rules; set-event-perk treating empty or omitted fields as
 * unchanged, with explicit clear_password and clear_text; the /guest-list
 * view each viewer gets; and CSV/TXT name parsing.
 *
 * Shims share global keys with sibling files in the rsvp-perks-unit suite
 * (`event_management_test` posts, `event_perk_test` meta), so load order
 * doesn't matter.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims with the test case class.

use ExtraChillEvents\Abilities\EventPerkAbilities;

if ( ! class_exists( 'WP_Post' ) ) {
	final class WP_Post {
		public $ID          = 0;
		public $post_title  = '';
		public $post_type   = 'post';
		public $post_status = 'publish';

		public function __construct( $post = null ) {
			foreach ( (array) $post as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return $GLOBALS['event_management_test']['posts'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $path, $output = null, $post_type = 'page' ) {
		unset( $path, $output, $post_type );
		return null;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		return $GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key ) {
		unset( $GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_hash_password' ) ) {
	function wp_hash_password( $password ) {
		return password_hash( (string) $password, PASSWORD_BCRYPT, array( 'cost' => 4 ) );
	}
}

if ( ! function_exists( 'wp_check_password' ) ) {
	function wp_check_password( $password, $hash, $user_id = '' ) {
		unset( $user_id );
		return password_verify( (string) $password, (string) $hash );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub.
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	function wp_attachment_is_image( $post_id ) {
		return in_array( (int) $post_id, $GLOBALS['event_perk_test']['images'] ?? array(), true );
	}
}

if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( $attachment_id, $size = 'thumbnail' ) {
		return 'https://events.example.test/uploads/pass-' . (int) $attachment_id . '-' . $size . '.png';
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post = null ) {
		return (int) ( $GLOBALS['event_perk_test']['thumbnails'][ (int) $post ] ?? 0 );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['guest_list_mode_test']['user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, $capability, ...$args ) {
		if ( 'edit_post' === $capability ) {
			$post_id = $args[0] ?? 0;
			return in_array( (int) $post_id, $GLOBALS['event_management_test']['editable_posts'][ $user_id ] ?? array(), true );
		}
		return in_array( $capability, $GLOBALS['event_management_test']['caps'][ $user_id ] ?? array(), true );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/event-management-authority.php';
require_once dirname( __DIR__, 3 ) . '/inc/admin/event-perks.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/TicketAbilities.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/EventPerkAbilities.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/guest-list-bulk-add.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/guest-list-door.php';

final class GuestListModeTest extends PHPUnit\Framework\TestCase {

	private const EVENT_ID = 731001;

	private EventPerkAbilities $abilities;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ] = new WP_Post(
			array(
				'ID'          => self::EVENT_ID,
				'post_title'  => 'Mode Show',
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ]        = array();
		$GLOBALS['guest_list_mode_test']                             = array( 'user_id' => 0 );
		// User 38 hosts this show (edit_post); 99 is a logged-in guest.
		$GLOBALS['event_management_test']['editable_posts'][38] = array( self::EVENT_ID );
		$this->abilities                                        = new EventPerkAbilities();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['event_management_test']['posts'][ self::EVENT_ID ], $GLOBALS['event_management_test']['editable_posts'][38], $GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ], $GLOBALS['guest_list_mode_test'] );
		parent::tearDown();
	}

	private function meta( string $key ) {
		return $GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ][ $key ] ?? null;
	}

	private function set( array $input ) {
		return $this->abilities->set_event_perk( array( 'event' => (string) self::EVENT_ID ) + $input );
	}

	// Migration mapping.

	public function test_legacy_password_list_reads_as_password_mode(): void {
		extrachill_events_set_perk_password( self::EVENT_ID, 'spooky' );

		$this->assertSame( 'password', extrachill_events_get_perk_mode( self::EVENT_ID ) );
		$this->assertNull( $this->meta( EXTRACHILL_EVENTS_PERK_MODE_META ), 'The mapping is derived on read; nothing is written.' );
	}

	public function test_legacy_open_perk_reads_as_going_mode(): void {
		$this->assertSame( 'going', extrachill_events_get_perk_mode( self::EVENT_ID ) );
		$this->assertFalse( extrachill_events_guest_list_is_gated( self::EVENT_ID ) );
	}

	public function test_migration_is_idempotent_and_a_stored_mode_wins(): void {
		extrachill_events_set_perk_password( self::EVENT_ID, 'spooky' );
		$first  = extrachill_events_get_perk_mode( self::EVENT_ID );
		$second = extrachill_events_get_perk_mode( self::EVENT_ID );
		$this->assertSame( $first, $second );

		// A save with nothing access-related changed stores the same mode.
		$this->assertSame( 'password', extrachill_events_apply_perk_access( self::EVENT_ID, null, null, false ) );
		$this->assertSame( 'password', extrachill_events_get_perk_mode( self::EVENT_ID ) );

		extrachill_events_set_perk_mode( self::EVENT_ID, 'host_list' );
		$this->assertSame( 'host_list', extrachill_events_get_perk_mode( self::EVENT_ID ), 'A stored mode beats the derived one.' );
	}

	public function test_an_unknown_stored_mode_falls_back_to_the_mapping(): void {
		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ][ EXTRACHILL_EVENTS_PERK_MODE_META ] = 'bogus';

		$this->assertSame( 'going', extrachill_events_get_perk_mode( self::EVENT_ID ) );
		$this->assertFalse( extrachill_events_set_perk_mode( self::EVENT_ID, 'bogus' ) );
	}

	// apply_perk_access rules.

	public function test_host_list_needs_no_password_and_refuses_claims(): void {
		$this->assertSame( 'host_list', extrachill_events_apply_perk_access( self::EVENT_ID, 'host_list', null, false ) );

		$this->assertTrue( extrachill_events_guest_list_is_gated( self::EVENT_ID ) );
		$this->assertFalse( extrachill_events_guest_list_accepts_claims( self::EVENT_ID ) );
		$this->assertSame( 'guest_list_host_managed', extrachill_events_guest_list_claim_error( self::EVENT_ID, '' )->get_error_code() );
	}

	public function test_password_mode_without_a_password_is_refused_before_writing(): void {
		$result = extrachill_events_apply_perk_access( self::EVENT_ID, 'password', null, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'perk_password_required', $result->get_error_code() );
		$this->assertNull( $this->meta( EXTRACHILL_EVENTS_PERK_MODE_META ) );
	}

	public function test_setting_a_password_on_an_open_list_makes_it_a_password_list(): void {
		$this->assertSame( 'password', extrachill_events_apply_perk_access( self::EVENT_ID, null, 'spooky', false ) );
		$this->assertTrue( extrachill_events_guest_list_accepts_claims( self::EVENT_ID ) );
		$this->assertNull( extrachill_events_guest_list_claim_error( self::EVENT_ID, 'spooky' ) );
		$this->assertNotNull( extrachill_events_guest_list_claim_error( self::EVENT_ID, 'nope' ) );
	}

	public function test_clearing_the_password_reopens_a_password_list_but_keeps_a_host_list(): void {
		extrachill_events_apply_perk_access( self::EVENT_ID, null, 'spooky', false );
		$this->assertSame( 'going', extrachill_events_apply_perk_access( self::EVENT_ID, null, null, true ) );

		extrachill_events_apply_perk_access( self::EVENT_ID, 'host_list', 'spooky', false );
		$this->assertSame( 'host_list', extrachill_events_apply_perk_access( self::EVENT_ID, null, null, true ) );
		$this->assertFalse( extrachill_events_perk_password_required( self::EVENT_ID ) );
	}

	public function test_an_invalid_mode_is_refused(): void {
		$this->assertSame( 'invalid_perk_mode', extrachill_events_apply_perk_access( self::EVENT_ID, 'vip', null, false )->get_error_code() );
	}

	// set-event-perk field handling.

	public function test_set_event_perk_outputs_the_mode(): void {
		$result = $this->set(
			array(
				'enabled' => true,
				'text'    => 'Extra Chill guest list',
				'mode'    => 'host_list',
			)
		);

		$this->assertSame( 'host_list', $result['event']['mode'] );
		$this->assertTrue( $result['event']['enabled'] );
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/Abilities/EventPerkAbilities.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- get-event-perk needs the pass table; check its output source-level.
		$this->assertSame( 2, substr_count( $source, "'mode'               => extrachill_events_get_perk_mode( \$post->ID )," ), 'set- and get-event-perk both output the mode.' );
	}

	public function test_empty_password_keeps_the_text_and_the_password(): void {
		$this->set(
			array(
				'enabled'  => true,
				'text'     => 'Extra Chill guest list',
				'password' => 'spooky',
			)
		);
		$hash = $this->meta( EXTRACHILL_EVENTS_PERK_PASSWORD_META );

		$result = $this->set( array( 'password' => '' ) );

		$this->assertSame( 'Extra Chill guest list', $result['event']['text'] );
		$this->assertTrue( $result['event']['enabled'], 'Omitting enabled keeps the perk on.' );
		$this->assertTrue( $result['event']['password_required'] );
		$this->assertSame( $hash, $this->meta( EXTRACHILL_EVENTS_PERK_PASSWORD_META ) );
		$this->assertSame( 'password', $result['event']['mode'] );
	}

	public function test_empty_text_keeps_the_text_and_clear_text_removes_it(): void {
		$this->set(
			array(
				'enabled' => true,
				'text'    => 'Extra Chill guest list',
			)
		);

		$this->assertSame( 'Extra Chill guest list', $this->set( array( 'text' => '' ) )['event']['text'] );
		$this->assertSame( '', $this->set( array( 'clear_text' => true ) )['event']['text'] );
	}

	public function test_clear_password_removes_only_the_password(): void {
		$this->set(
			array(
				'enabled'  => true,
				'text'     => 'Extra Chill guest list',
				'password' => 'spooky',
			)
		);

		$result = $this->set( array( 'clear_password' => true ) );

		$this->assertFalse( $result['event']['password_required'] );
		$this->assertNull( $this->meta( EXTRACHILL_EVENTS_PERK_PASSWORD_META ) );
		$this->assertSame( 'Extra Chill guest list', $result['event']['text'] );
		$this->assertSame( 'going', $result['event']['mode'] );
	}

	public function test_password_mode_without_a_password_writes_nothing(): void {
		$result = $this->set(
			array(
				'enabled' => true,
				'mode'    => 'password',
			)
		);

		$this->assertSame( 'perk_password_required', $result->get_error_code() );
		$this->assertNull( $this->meta( EXTRACHILL_EVENTS_PERK_ENABLED_META ) );
		$this->assertNull( $this->meta( '_extrachill_ticket_offer' ) );
	}

	public function test_set_event_perk_schema_exposes_mode_and_clear_password(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/Abilities/EventPerkAbilities.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level read.
		foreach ( array( "'mode'", "'clear_password'", "'clear_text'" ) as $field ) {
			$this->assertStringContainsString( $field, $source );
		}
	}

	// /guest-list views.

	public function test_a_host_gets_the_door_view_in_every_mode(): void {
		foreach ( array( 'host_list', 'going' ) as $mode ) {
			extrachill_events_set_perk_mode( self::EVENT_ID, $mode );
			$this->assertSame( 'door', extrachill_events_guest_list_page_view( self::EVENT_ID, 38 ) );
		}
	}

	public function test_guests_get_the_view_for_the_mode(): void {
		$this->assertSame( 'pass_only', extrachill_events_guest_list_page_view( self::EVENT_ID, 0 ), 'No perk (a ticket-only page): pass links only.' );
		update_post_meta( self::EVENT_ID, EXTRACHILL_EVENTS_PERK_ENABLED_META, true );

		extrachill_events_apply_perk_access( self::EVENT_ID, 'host_list', null, false );
		$this->assertSame( 'host_managed', extrachill_events_guest_list_page_view( self::EVENT_ID, 0 ) );
		$this->assertSame( 'host_managed', extrachill_events_guest_list_page_view( self::EVENT_ID, 99 ), 'A logged-in non-host is still a guest.' );

		extrachill_events_apply_perk_access( self::EVENT_ID, 'password', 'spooky', false );
		$this->assertSame( 'claim', extrachill_events_guest_list_page_view( self::EVENT_ID, 0 ) );

		extrachill_events_apply_perk_access( self::EVENT_ID, 'going', null, true );
		$this->assertSame( 'going', extrachill_events_guest_list_page_view( self::EVENT_ID, 0 ) );
	}

	public function test_every_enabled_perk_has_a_guest_list_page(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/core/guest-list-page.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level read; the file's URL helpers collide with sibling stubs.

		$this->assertStringContainsString( '( $has_tickets || extrachill_events_perk_enabled( $event_id ) )', $source, 'Pass links open in every mode, not only password mode.' );
		$this->assertStringNotContainsString( 'perk_password_required', $source );
	}

	public function test_guest_template_has_no_password_prompt_outside_password_mode(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/templates/guest-list.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level read.

		$door     = strpos( $source, "'door' === \$view" );
		$managed  = strpos( $source, "'host_managed' === \$view" );
		$going    = strpos( $source, "'going' === \$view" );
		$password = strpos( $source, 'name="password"' );
		$this->assertNotFalse( $door );
		$this->assertNotFalse( $managed );
		$this->assertNotFalse( $going );
		$this->assertGreaterThan( $going, $password, 'The password field only renders in the claim (password mode) branch.' );
		$this->assertStringContainsString( 'managed by the host', $source );
		$this->assertStringContainsString( 'extrachill_events_render_guest_list_door', $source );
	}

	public function test_door_view_lists_checked_in_and_active_passes_only(): void {
		$rows = extrachill_events_guest_list_door_rows(
			array(
				array(
					'kind'         => 'attendee',
					'user_id'      => 7,
					'display_name' => 'Amy',
					'pass_status'  => 'redeemed',
				),
				array(
					'kind'         => 'attendee',
					'user_id'      => 8,
					'display_name' => 'Going Only',
					'pass_status'  => '',
				),
				array(
					'kind'   => 'guest',
					'label'  => 'Jake',
					'code'   => 'AAAA',
					'status' => 'active',
				),
				array(
					'kind'   => 'guest',
					'label'  => 'Gone',
					'code'   => 'BBBB',
					'status' => 'revoked',
				),
			)
		);

		$this->assertSame( array( 'Amy', 'Jake' ), array_column( $rows, 'name' ) );
		$this->assertSame( array( true, false ), array_column( $rows, 'checked_in' ) );
	}

	// Upload parsing.

	public function test_text_parsing_skips_blank_lines_and_trims(): void {
		$this->assertSame(
			array( 'Jane Doe', 'Smith, John', 'Al +1' ),
			extrachill_events_parse_guest_list_names( "\xEF\xBB\xBF  Jane Doe \r\n\r\n   \nSmith, John\n\tAl  +1\n" )
		);
	}

	public function test_csv_parsing_takes_the_first_cell_and_skips_a_header(): void {
		$csv = "Name,Email\n\"Doe, Jane\",jane@example.com\nJake,\n,orphan@example.com\n\n";

		$this->assertSame( array( 'Doe, Jane', 'Jake' ), extrachill_events_parse_guest_list_names( $csv, 'csv' ) );
	}

	public function test_csv_without_a_header_keeps_the_first_row(): void {
		$this->assertSame( array( 'Jane', 'Jake' ), extrachill_events_parse_guest_list_names( "Jane\nJake", 'csv' ) );
	}

	public function test_name_keys_ignore_case_and_spacing(): void {
		$this->assertSame( extrachill_events_guest_list_name_key( 'Jane  Doe' ), extrachill_events_guest_list_name_key( ' jane doe ' ) );
	}
}
