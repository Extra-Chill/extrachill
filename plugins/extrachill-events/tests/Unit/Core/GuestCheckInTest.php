<?php
/**
 * One Guest Check-In button on the event page; host tools on /guest-list
 * (extrachill#1702).
 *
 * Covers: the event page carries none of the three manager panels (host
 * guest list panel, owner's lists, door list), pinned or not; managers get
 * exactly one red "Guest Check-In" button in the action row and everyone
 * else gets nothing; managers can open /guest-list with the guest list
 * perk off and no tickets while guests keep the existing rule; and the
 * host screen leads with the check-in list, then holds the settings and
 * owner's lists in the collapsible host tools.
 *
 * Shims share global keys with sibling files in the rsvp-perks-unit suite
 * (`event_management_test` posts/caps, `event_perk_test` meta, and the
 * current-user keys of OwnerGuestListTest and GuestListModeTest), so load
 * order doesn't matter.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims with the test case class.

if ( ! defined( 'EXTRACHILL_EVENTS_PLUGIN_DIR' ) ) {
	define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', dirname( __DIR__, 3 ) . '/' );
}
if ( ! defined( 'EXTRACHILL_EVENTS_PLUGIN_URL' ) ) {
	define( 'EXTRACHILL_EVENTS_PLUGIN_URL', 'https://events.example/wp-content/plugins/extrachill-events/' );
}
if ( ! defined( 'EXTRACHILL_EVENTS_VERSION' ) ) {
	define( 'EXTRACHILL_EVENTS_VERSION', 'tests' );
}

if ( ! function_exists( 'ec_is_events_site' ) ) {
	function ec_is_events_site() {
		return (bool) ( $GLOBALS['guest_check_in_test']['events_site'] ?? true );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		// Same key as OwnerGuestListTest's shim, which loads later in the suite.
		return (int) ( $GLOBALS['owner_list_test']['current_user'] ?? 0 );
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, $capability, ...$args ) {
		if ( 'edit_post' === $capability ) {
			return in_array( (int) ( $args[0] ?? 0 ), $GLOBALS['event_management_test']['editable_posts'][ $user_id ] ?? array(), true );
		}
		return in_array( $capability, $GLOBALS['event_management_test']['caps'][ $user_id ] ?? array(), true );
	}
}

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

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		return $GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default_value = false ) {
		return $GLOBALS['event_management_test']['options'][ $key ] ?? $default_value;
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		return 'Event ' . (int) $post;
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post = null ) {
		unset( $post );
		return 0;
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	function wp_attachment_is_image( $post_id ) {
		unset( $post_id );
		return false;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) {
		unset( $domain );
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		unset( $domain );
		echo htmlspecialchars( (string) $text, ENT_QUOTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = null ) {
		unset( $domain );
		echo htmlspecialchars( (string) $text, ENT_QUOTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://events.extrachill.com/wp-admin/' . $path;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'nonce-' . md5( (string) $action );
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true ) {
		unset( $referer );
		echo '<input type="hidden" name="' . htmlspecialchars( (string) $name, ENT_QUOTES ) . '" value="' . htmlspecialchars( wp_create_nonce( $action ), ENT_QUOTES ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( ...$args ) {
		unset( $args );
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( ...$args ) {
		unset( $args );
		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'extrachill_events_guest_list_url' ) ) {
	function extrachill_events_guest_list_url( int $event_id ): string {
		return 'https://events.extrachill.com/events/' . $event_id . '/guest-list';
	}
}

// guest-list-page.php also defines helpers sibling files shim, so mirror its
// guest rule here instead of loading it; test_guest_rule_is_unchanged()
// pins the real source.
if ( ! function_exists( 'extrachill_events_has_guest_list_page' ) ) {
	function extrachill_events_has_guest_list_page( int $event_id ): bool {
		$ticket_offer = get_post_meta( $event_id, '_extrachill_ticket_offer', true );
		$has_tickets  = is_array( $ticket_offer ) && ! empty( $ticket_offer['ticket_seller']['id'] );
		return $event_id > 0 && ( $has_tickets || extrachill_events_perk_enabled( $event_id ) );
	}
}

// The door rows and host panels have their own suites; here they are
// fixtures so the host screen's composition is what is under test.
if ( ! function_exists( 'extrachill_events_get_door_list_rows' ) ) {
	function extrachill_events_get_door_list_rows( int $event_id ): array {
		unset( $event_id );
		return array( 'door_rows' => $GLOBALS['guest_check_in_test']['door_rows'] ?? array() );
	}
}

if ( ! function_exists( 'extrachill_events_render_guest_list_management' ) ) {
	function extrachill_events_render_guest_list_management( int $event_id, bool $settings = false ): void {
		echo '<section id="ec-guest-list-management">settings:' . (int) $event_id . ':' . ( $settings ? 'form' : 'summary' ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test fixture.
	}
}

if ( ! function_exists( 'extrachill_events_render_owner_guest_lists' ) ) {
	function extrachill_events_render_owner_guest_lists( int $event_id ): void {
		echo '<section id="ec-owner-guest-list">owner-lists:' . (int) $event_id . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test fixture.
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/admin/event-perks.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/event-management-authority.php';
require_once dirname( __DIR__, 3 ) . '/inc/core/guest-list-door.php';

final class GuestCheckInTest extends PHPUnit\Framework\TestCase {

	private const EVENT_ID = 714188;
	private const HOST_ID  = 41;
	private const ADMIN_ID = 42;
	private const GUEST_ID = 43;

	/** @var array<string, mixed> */
	private array $saved_current_user = array();

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ]         = (object) array(
			'ID'          => self::EVENT_ID,
			'post_type'   => 'data_machine_events',
			'post_status' => 'publish',
		);
		$GLOBALS['event_management_test']['editable_posts'][ self::HOST_ID ] = array( self::EVENT_ID );
		$GLOBALS['event_management_test']['caps'][ self::ADMIN_ID ]          = array( 'manage_options' );
		// Guest list perk off and no tickets.
		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ] = array();
		$GLOBALS['guest_check_in_test']                       = array(
			'events_site' => true,
			'door_rows'   => array(),
		);
		$this->saved_current_user                             = array(
			'owner_list_test'      => $GLOBALS['owner_list_test'] ?? null,
			'guest_list_mode_test' => $GLOBALS['guest_list_mode_test'] ?? null,
		);
		$this->as_user( 0 );
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['event_management_test']['posts'][ self::EVENT_ID ],
			$GLOBALS['event_management_test']['editable_posts'][ self::HOST_ID ],
			$GLOBALS['event_management_test']['caps'][ self::ADMIN_ID ],
			$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ],
			$GLOBALS['guest_check_in_test']
		);
		foreach ( $this->saved_current_user as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $key ] );
			} else {
				$GLOBALS[ $key ] = $value;
			}
		}
		unset( $_GET['ec_host_tools'] );
		parent::tearDown();
	}

	/** Set the viewer for whichever get_current_user_id() shim loaded first. */
	private function as_user( int $user_id ): void {
		$GLOBALS['owner_list_test']['current_user'] = $user_id;
		$GLOBALS['guest_list_mode_test']['user_id'] = $user_id;
	}

	private function source( string $relative ): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/' . $relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level read.
	}

	private function button_html( int $event_id = self::EVENT_ID ): string {
		ob_start();
		extrachill_events_render_guest_check_in_button( $event_id, '', 'upcoming' );
		return (string) ob_get_clean();
	}

	private function host_screen_html(): string {
		ob_start();
		extrachill_events_render_guest_list_door( self::EVENT_ID );
		return (string) ob_get_clean();
	}

	private function enable_guest_list(): void {
		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ][ EXTRACHILL_EVENTS_PERK_ENABLED_META ] = '1';
	}

	// Event page.

	public function test_event_page_renders_no_manager_panels(): void {
		foreach ( array( 'inc/core/guest-list-management.php', 'inc/core/owner-guest-list-management.php', 'inc/core/rsvp-pass-integration.php', 'inc/core/guest-list-door.php' ) as $file ) {
			$source = $this->source( $file );
			$this->assertStringNotContainsString( "'the_content'", $source, "{$file} adds nothing to the event content." );
		}

		$management = $this->source( 'inc/core/guest-list-management.php' );
		foreach ( array( 'extrachill_events_append_guest_list_management', 'extrachill_events_should_pin_guest_list', 'extrachill_events_is_event_show_day' ) as $removed ) {
			$this->assertStringNotContainsString( $removed, $management, 'No show-day pinning either.' );
		}
		$this->assertStringNotContainsString( 'extrachill_events_append_owner_guest_lists', $this->source( 'inc/core/owner-guest-list-management.php' ) );
		$this->assertStringNotContainsString( 'extrachill_events_append_door_list', $this->source( 'inc/core/rsvp-pass-integration.php' ) );
		$this->assertFileDoesNotExist( EXTRACHILL_EVENTS_PLUGIN_DIR . 'templates/door-list.php' );

		// Nothing else in the plugin puts host tools into the event content.
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression(
				"/add_filter\\(\\s*'the_content',\\s*'[a-z_]*(guest_list|door_list|owner_guest)/",
				(string) file_get_contents( $file->getPathname() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source-level read.
				$file->getPathname() . ' adds a host panel to the event content.'
			);
		}
	}

	public function test_manager_gets_one_guest_check_in_button(): void {
		foreach ( array( self::HOST_ID, self::ADMIN_ID ) as $manager ) {
			$this->as_user( $manager );
			$html = $this->button_html();

			$this->assertSame( 1, substr_count( $html, '<a ' ), 'Exactly one control.' );
			$this->assertStringContainsString( 'class="button-danger button-medium"', $html );
			$this->assertStringContainsString( 'href="' . extrachill_events_guest_list_url( self::EVENT_ID ) . '"', $html );
			$this->assertStringContainsString( 'Guest Check-In', $html );
		}
	}

	public function test_button_shows_with_the_guest_list_off_and_on(): void {
		$this->as_user( self::HOST_ID );
		$this->assertNotSame( '', $this->button_html(), 'Perk off, no tickets: still the way in to turn it on.' );
		$this->enable_guest_list();
		$this->assertNotSame( '', $this->button_html() );
	}

	public function test_non_managers_see_no_button(): void {
		$this->enable_guest_list();

		$this->as_user( self::GUEST_ID );
		$this->assertSame( '', trim( $this->button_html() ), 'A logged-in non-manager sees nothing.' );

		$this->as_user( 0 );
		$this->assertSame( '', trim( $this->button_html() ), 'Logged out sees nothing.' );

		$this->as_user( self::HOST_ID );
		$GLOBALS['guest_check_in_test']['events_site'] = false;
		$this->assertSame( '', trim( $this->button_html() ), 'Only on the events site.' );
	}

	public function test_button_is_in_the_event_action_row(): void {
		$this->assertStringContainsString(
			"add_action( 'data_machine_events_action_buttons', 'extrachill_events_render_guest_check_in_button', 4, 3 );",
			$this->source( 'inc/core/guest-list-door.php' )
		);
	}

	// Who can open /guest-list.

	public function test_host_screen_is_reachable_for_a_manager_with_the_perk_off(): void {
		$this->assertFalse( extrachill_events_has_guest_list_page( self::EVENT_ID ), 'Perk off and no tickets: no guest page.' );

		$this->assertTrue( extrachill_events_user_can_reach_guest_list_page( self::EVENT_ID, self::HOST_ID ) );
		$this->assertTrue( extrachill_events_user_can_reach_guest_list_page( self::EVENT_ID, self::ADMIN_ID ) );
		$this->assertSame( 'door', extrachill_events_guest_list_page_view( self::EVENT_ID, self::HOST_ID ) );
	}

	public function test_non_manager_access_is_unchanged(): void {
		// Perk off, no tickets: 404 for guests, logged in or not.
		$this->assertFalse( extrachill_events_user_can_reach_guest_list_page( self::EVENT_ID, self::GUEST_ID ) );
		$this->assertFalse( extrachill_events_user_can_reach_guest_list_page( self::EVENT_ID, 0 ) );
		$this->assertFalse( extrachill_events_user_can_reach_guest_list_page( 0, self::HOST_ID ) );

		// Tickets only: pass links still open.
		$GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ]['_extrachill_ticket_offer'] = array( 'ticket_seller' => array( 'id' => 5 ) );
		$this->assertTrue( extrachill_events_user_can_reach_guest_list_page( self::EVENT_ID, 0 ) );
		$this->assertSame( 'pass_only', extrachill_events_guest_list_page_view( self::EVENT_ID, self::GUEST_ID ) );

		// Perk on: the page is open to everyone, as before.
		unset( $GLOBALS['event_perk_test']['meta'][ self::EVENT_ID ]['_extrachill_ticket_offer'] );
		$this->enable_guest_list();
		$this->assertTrue( extrachill_events_user_can_reach_guest_list_page( self::EVENT_ID, self::GUEST_ID ) );
		$this->assertTrue( extrachill_events_user_can_reach_guest_list_page( self::EVENT_ID, 0 ) );
	}

	public function test_guest_rule_is_unchanged_and_the_endpoint_uses_the_reach_check(): void {
		$page = $this->source( 'inc/core/guest-list-page.php' );

		$this->assertStringContainsString( '( $has_tickets || extrachill_events_perk_enabled( $event_id ) )', $page, 'Guests keep the existing rule.' );
		$this->assertSame( 2, substr_count( $page, 'extrachill_events_user_can_reach_guest_list_page( (int) get_queried_object_id(), (int) get_current_user_id() )' ), 'template_redirect and template_include both gate on it.' );
	}

	// The host screen.

	public function test_host_screen_has_check_in_first_then_settings_and_owner_lists(): void {
		$this->enable_guest_list();
		$this->as_user( self::HOST_ID );
		$GLOBALS['guest_check_in_test']['door_rows'] = array(
			array(
				'kind'         => 'attendee',
				'user_id'      => 7,
				'display_name' => 'Amy',
				'pass_status'  => 'active',
			),
			array(
				'kind'     => 'guest',
				'label'    => 'Ticket · Pat',
				'code'     => 'TICKETCODE',
				'status'   => 'active',
				'added_by' => '',
			),
			array(
				'kind'         => 'attendee',
				'user_id'      => 8,
				'display_name' => 'Going Only',
				'pass_status'  => '',
				'marked_at'    => 'Nov 5',
			),
		);

		$html = $this->host_screen_html();

		$check_in = strpos( $html, 'ec-door-list__redeem' );
		$details  = strpos( $html, '<details id="ec-host-tools"' );
		$settings = strpos( $html, 'settings:' . self::EVENT_ID . ':form' );
		$owners   = strpos( $html, 'owner-lists:' . self::EVENT_ID );
		$this->assertNotFalse( $check_in, 'Check-in list.' );
		$this->assertNotFalse( $details, 'Collapsible host tools.' );
		$this->assertNotFalse( $settings, 'Guest list settings form.' );
		$this->assertNotFalse( $owners, 'Owner\'s lists.' );
		$this->assertLessThan( $details, $check_in, 'Check-in comes first.' );
		$this->assertLessThan( $settings, $details );
		$this->assertLessThan( $owners, $settings );

		$this->assertStringContainsString( 'Ticket · Pat', $html, 'Ticket buyers are on the check-in list.' );
		$this->assertStringContainsString( 'data-pass-code="TICKETCODE"', $html );
		$this->assertStringContainsString( 'data-door-list-filter', $html, 'Find a name.' );
		$this->assertStringContainsString( 'ec_guest_list_bulk_add', $html );
		$this->assertStringContainsString( 'Going Only', substr( $html, $details ), 'Going without a pass is listed in the host tools, not the check-in list.' );
		$this->assertStringNotContainsString( 'Going Only', substr( $html, 0, $details ) );
		$this->assertDoesNotMatchRegularExpression( '/<details id="ec-host-tools"[^>]*\bopen\b/', $html, 'Host tools start closed once the list is on.' );
	}

	public function test_host_screen_enqueues_check_in_copy_qr_and_filter_scripts(): void {
		$door = $this->source( 'inc/core/guest-list-door.php' );
		$this->assertStringContainsString( "wp_enqueue_script( 'extrachill-events-rsvp-pass', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/rsvp-pass.js'", $door, 'Check in.' );
		$this->assertStringContainsString( "wp_enqueue_script( 'ec-guest-list-management', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/guest-list-management.js'", $door, 'Copy, confirm, and find a name.' );
		$this->assertStringContainsString( "'ecGuestListManagement'", $door );
		// rsvp-pass.js binds the first .ec-door-list; the host screen is it.
		$this->assertStringContainsString( 'class="ec-guest-list-door ec-door-list', $this->source( 'templates/guest-list-door.php' ) );
	}

	public function test_host_screen_with_the_perk_off_opens_host_tools(): void {
		$this->as_user( self::HOST_ID );

		$html = $this->host_screen_html();

		$this->assertMatchesRegularExpression( '/<details id="ec-host-tools"[^>]*\bopen\b/', $html, 'The host came to turn the list on.' );
		$this->assertStringContainsString( 'The guest list is off for this show.', $html );
		$this->assertStringContainsString( 'settings:' . self::EVENT_ID . ':form', $html );
		$this->assertStringNotContainsString( 'ec_guest_list_bulk_add', $html, 'Adding names waits until the list is on.' );
	}

	public function test_host_tools_reopen_after_a_host_tools_form(): void {
		$this->enable_guest_list();
		$this->as_user( self::HOST_ID );
		$_GET['ec_host_tools'] = '1';

		$this->assertMatchesRegularExpression( '/<details id="ec-host-tools"[^>]*\bopen\b/', $this->host_screen_html() );
	}

	public function test_non_managers_never_get_the_host_screen(): void {
		$this->enable_guest_list();

		$this->as_user( self::GUEST_ID );
		$this->assertSame( '', $this->host_screen_html() );
		$this->as_user( 0 );
		$this->assertSame( '', $this->host_screen_html() );
	}

	public function test_host_tools_forms_return_to_the_host_screen(): void {
		$management = $this->source( 'inc/core/guest-list-management.php' );
		$owner      = $this->source( 'inc/core/owner-guest-list-management.php' );

		$this->assertStringNotContainsString( 'get_permalink(', $management, 'No handler sends the host back to the event page.' );
		$this->assertStringNotContainsString( 'get_permalink(', $owner );
		$this->assertGreaterThanOrEqual( 7, substr_count( $management, 'extrachill_events_guest_list_host_tools_url(' ) );
		$this->assertStringContainsString( "extrachill_events_guest_list_host_tools_url( \$event_id, 'ec-owner-guest-list', \$args )", $owner );
		$this->assertStringContainsString( "array( 'ec_host_tools' => '1' ) + \$args", $this->source( 'inc/core/guest-list-page.php' ) );
	}
}
