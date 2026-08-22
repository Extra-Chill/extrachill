<?php
/**
 * Public venue booking inquiry render tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\VenueBookingConfig;

require_once dirname( __DIR__ ) . '/Support/archive-template-stubs.php';

/** Proves the Events-owned booking block across canonical network contexts. */
final class VenueBookingInquiryRenderTest extends WP_UnitTestCase {
	private const EVENTS_BLOG_ID = 7;
	private const MAIN_BLOG_ID   = 1;
	private const STUDIO_BLOG_ID = 12;

	/**
	 * Canonical Events venue fixture ID.
	 *
	 * @var int
	 */
	private int $venue_id;

	/** Create one enabled canonical venue booking projection. */
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
		add_action( 'extrachill_archive_below_description', 'ec_events_render_venue_archive_workspace_action', 8 );
		switch_to_blog( self::EVENTS_BLOG_ID );
		$term                       = self::factory()->term->create_and_get(
			array(
				'taxonomy'    => 'venue',
				'name'        => 'Test Room',
				'description' => 'Independent room in Charleston.',
			)
		);
		$this->venue_id             = (int) $term->term_id;
		$config                     = ( new VenueBookingConfig() )->defaults();
		$config['enabled']          = true;
		$config['revision']         = 4;
		$config['spaces']           = array(
			array(
				'key'        => 'main-room',
				'name'       => 'Main Room',
				'is_default' => true,
			),
		);
		$config['intake']['fields'] = array(
			array(
				'key'      => 'draw',
				'label'    => 'Recent draw',
				'type'     => 'number',
				'required' => true,
				'options'  => array(),
			),
			array(
				'key'      => 'press_links',
				'label'    => 'Press links',
				'type'     => 'url_list',
				'required' => false,
				'options'  => array(),
			),
		);
		$config['intake']['presentation']['contact_phone_label'] = 'Phone (Emergency use only)';
		$config['ticket_provider_reference']                     = 'private-provider-account';
		$config['correspondence']['booking_address']             = 'private-booking@example.com';
		update_term_meta( $this->venue_id, VenueBookingConfig::META_KEY, $config );
		update_term_meta( $this->venue_id, '_venue_address', '42 Test Street' );
		update_term_meta( $this->venue_id, '_venue_city', 'Charleston' );
		update_term_meta( $this->venue_id, '_venue_state', 'SC' );
		update_term_meta( $this->venue_id, '_venue_zip', '29403' );
		restore_current_blog();
	}

	/** Confirm that only the established public projection reaches markup. */
	public function test_render_contains_only_redacted_public_projection(): void {
		$output = $this->render_on( self::EVENTS_BLOG_ID, array( 'venueId' => $this->venue_id ) );

		$this->assertStringContainsString( 'ec-venue-booking-inquiry', $output );
		$this->assertMatchesRegularExpression( '/<section[^>]+aria-labelledby="ec-booking-[^"]+-heading"/', $output );
		$this->assertStringContainsString( '"headingLevel":2', $output );
		$this->assertStringContainsString( 'Test Room', $output );
		$this->assertStringNotContainsString( '42 Test Street', $output );
		$this->assertStringNotContainsString( 'Charleston, SC, 29403', $output );
		$this->assertStringNotContainsString( 'Include recent draw and routing.', $output );
		$this->assertStringNotContainsString( 'Booking guide', $output );
		$this->assertStringContainsString( '"revision":4', $output );
		$this->assertStringContainsString( 'Phone (Emergency use only)', $output );
		$this->assertStringNotContainsString( '"appearance"', $output );
		$this->assertStringNotContainsString( '--ec-booking-', $output );
		$this->assertStringNotContainsString( ':root', $output );
		$this->assertStringNotContainsString( 'manage-link-page', $output );
		$this->assertStringNotContainsString( '"hasPage"', $output );
		$this->assertStringNotContainsString( 'private-provider-account', $output );
		$this->assertStringNotContainsString( 'private-booking@example.com', $output );
		$this->assertStringContainsString( '"attachments":{"version":1,"enabled":false,"ready":false', $output );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	/** Multiple blocks expose distinct region labels and caller-selected levels. */
	public function test_render_uses_unique_heading_ids_and_caller_heading_level(): void {
		$first  = $this->render_on( self::MAIN_BLOG_ID, array( 'venueId' => $this->venue_id ) );
		$second = $this->render_on(
			self::MAIN_BLOG_ID,
			array(
				'venueId'      => $this->venue_id,
				'headingLevel' => 1,
			)
		);

		preg_match( '/aria-labelledby="([^"]+)"/', $first, $first_label );
		preg_match( '/aria-labelledby="([^"]+)"/', $second, $second_label );
		$this->assertNotEmpty( $first_label[1] ?? '' );
		$this->assertNotEmpty( $second_label[1] ?? '' );
		$this->assertNotSame( $first_label[1], $second_label[1] );
		$this->assertStringContainsString( '"headingLevel":1', $second );
	}

	/** Disabled canonical configuration must fail closed on another site. */
	public function test_disabled_config_fails_closed_without_form_markup(): void {
		switch_to_blog( self::EVENTS_BLOG_ID );
		$config            = get_term_meta( $this->venue_id, VenueBookingConfig::META_KEY, true );
		$config['enabled'] = false;
		update_term_meta( $this->venue_id, VenueBookingConfig::META_KEY, $config );
		restore_current_blog();

		$this->assertSame( '', $this->render_on( self::MAIN_BLOG_ID, array( 'venueId' => $this->venue_id ) ) );
	}

	/** Render the same canonical venue through Events, main, and Studio. */
	public function test_events_main_and_studio_render_from_canonical_events_data_without_context_leakage(): void {
		foreach ( array( self::MAIN_BLOG_ID, self::STUDIO_BLOG_ID ) as $blog_id ) {
			$output           = $this->render_on( $blog_id, array( 'venueId' => $this->venue_id ) );
			$endpoint         = get_rest_url( $blog_id, 'extrachill/v1/venues/' . $this->venue_id . '/booking-inquiries' );
			$encoded_endpoint = trim( wp_json_encode( $endpoint ), '"' );

			$this->assertStringContainsString( 'Test Room', $output, 'Canonical venue data should render on blog ' . $blog_id );
			$this->assertStringContainsString( $encoded_endpoint, $output, 'The caller-site route-affinity endpoint should render on blog ' . $blog_id );
			foreach ( array( 'status', 'correction', 'withdrawal', 'receipt-recovery' ) as $action ) {
				$follow_through = get_rest_url( $blog_id, 'extrachill/v1/venues/' . $this->venue_id . '/booking-inquiries/follow-through/' . $action );
				$this->assertStringContainsString( trim( wp_json_encode( $follow_through ), '"' ), $output );
			}
			$this->assertStringNotContainsString( 'capability=', $output );
		}
	}

	/** Missing configuration must fail closed and leave the caller context intact. */
	public function test_missing_venue_fails_closed_on_network_site_without_context_leakage(): void {
		$this->assertSame( '', $this->render_on( self::STUDIO_BLOG_ID, array( 'venueId' => 999999 ) ) );
		$this->assertSame( '', $this->render_on( self::MAIN_BLOG_ID, array() ) );
	}

	/** Preserve automatic venue resolution on canonical Events archives. */
	public function test_events_archive_still_resolves_venue_automatically(): void {
		global $wp_query, $wp_the_query;

		switch_to_blog( self::EVENTS_BLOG_ID );
		$previous_query      = $wp_query;
		$previous_main_query = $wp_the_query;

		try {
			$this->go_to( get_term_link( $this->venue_id, 'venue' ) );
			$output = $this->render( array() );
			$this->assertStringContainsString( 'Test Room', $output );
		} finally {
			$wp_query     = $previous_query;
			$wp_the_query = $previous_main_query;
			restore_current_blog();
		}
	}

	/** Enabled venue archives expose one canonical booking link near the heading. */
	public function test_enabled_venue_archive_renders_booking_cta_before_calendar(): void {
		global $wp_query, $wp_the_query;

		switch_to_blog( self::EVENTS_BLOG_ID );
		$previous_query      = $wp_query;
		$previous_main_query = $wp_the_query;

		try {
			$archive_url = get_term_link( $this->venue_id, 'venue' );
			$this->go_to( $archive_url );
			$this->setExpectedDeprecated( 'Theme without header.php' );
			$this->setExpectedDeprecated( 'Theme without footer.php' );
			$this->setExpectedIncorrectUsage( 'WP_Styles::add' );
			$output            = $this->render_archive();
			$heading_position  = strpos( $output, 'class="page-title"' );
			$cta_position      = strpos( $output, 'Submit a booking inquiry' );
			$operator_position = strpos( $output, 'data-venue-workspace-action' );
			$calendar_position = strpos( $output, 'data-machine-events-calendar' );

			$this->assertNotFalse( $heading_position );
			$this->assertNotFalse( $cta_position );
			$this->assertNotFalse( $operator_position );
			$this->assertNotFalse( $calendar_position );
			$this->assertStringContainsString( 'href="' . esc_url( $archive_url . '#booking-inquiry' ) . '"', $output );
			$this->assertLessThan( $cta_position, $heading_position );
			$this->assertLessThan( $operator_position, $cta_position );
			$this->assertLessThan( $calendar_position, $operator_position );
			$this->assertSame( 1, substr_count( $output, 'Submit a booking inquiry' ) );
		} finally {
			$wp_query     = $previous_query;
			$wp_the_query = $previous_main_query;
			restore_current_blog();
		}
	}

	/** Disabled venue archives do not expose a booking CTA or destination. */
	public function test_disabled_venue_archive_hides_booking_cta(): void {
		global $wp_query, $wp_the_query;

		switch_to_blog( self::EVENTS_BLOG_ID );
		$config            = get_term_meta( $this->venue_id, VenueBookingConfig::META_KEY, true );
		$config['enabled'] = false;
		update_term_meta( $this->venue_id, VenueBookingConfig::META_KEY, $config );
		$previous_query      = $wp_query;
		$previous_main_query = $wp_the_query;

		try {
			$this->go_to( get_term_link( $this->venue_id, 'venue' ) );
			$output = $this->render_archive();
			$this->assertStringNotContainsString( 'Submit a booking inquiry', $output );
			$this->assertStringContainsString( 'data-venue-workspace-action', $output );
		} finally {
			$wp_query     = $previous_query;
			$wp_the_query = $previous_main_query;
			restore_current_blog();
		}
	}

	/** Register the existing dynamic block assets without the Events runtime. */
	public function test_network_entrypoint_registers_existing_assets_on_all_intended_sites(): void {
		require_once dirname( __DIR__, 2 ) . '/extrachill-events-network-blocks.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$registry = WP_Block_Type_Registry::get_instance();
		$plugins  = get_plugins( '/extrachill-events' );
		$this->assertArrayHasKey( 'extrachill-events-network-blocks.php', $plugins );
		$this->assertTrue( $plugins['extrachill-events-network-blocks.php']['Network'] );
		$this->assertSame( EXTRACHILL_EVENTS_VERSION, $plugins['extrachill-events-network-blocks.php']['Version'] );

		foreach ( array( self::MAIN_BLOG_ID, self::STUDIO_BLOG_ID ) as $blog_id ) {
			if ( $registry->is_registered( 'extrachill/venue-booking-inquiry' ) ) {
				$registry->unregister( 'extrachill/venue-booking-inquiry' );
			}
			switch_to_blog( $blog_id );
			try {
				extrachill_events_register_network_blocks();
				$block = $registry->get_registered( 'extrachill/venue-booking-inquiry' );
				$this->assertInstanceOf( WP_Block_Type::class, $block );
				$this->assertIsCallable( $block->render_callback );
				$this->assertNotEmpty( $block->style_handles );
				$this->assertNotEmpty( $block->view_script_handles );
			} finally {
				restore_current_blog();
			}
		}

		foreach ( array( 'extrachill/venue-booking-inquiry', 'extrachill/event-submission', 'extrachill/concert-stats', 'extrachill/venue-settings' ) as $block_name ) {
			if ( $registry->is_registered( $block_name ) ) {
				$registry->unregister( $block_name );
			}
		}
		switch_to_blog( self::EVENTS_BLOG_ID );
		try {
			extrachill_events_register_network_blocks();
			$this->assertFalse( $registry->is_registered( 'extrachill/venue-booking-inquiry' ) );
			extrachill_events_register_blocks();
			$this->assertTrue( $registry->is_registered( 'extrachill/venue-booking-inquiry' ) );
		} finally {
			restore_current_blog();
		}
	}

	/** Enforce network-active dependencies through WordPress's activation path. */
	public function test_network_entrypoint_activation_uses_actual_network_plugin_state(): void {
		$plugin_file = dirname( __DIR__, 2 ) . '/extrachill-events-network-blocks.php';
		require_once $plugin_file;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		// WP_UnitTestCase resets hooks between tests after require_once has loaded the entrypoint.
		register_activation_hook( $plugin_file, 'extrachill_events_activate_network_blocks' );

		$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
		$site_plugins    = get_option( 'active_plugins', array() );
		$plugin          = 'extrachill-events/extrachill-events-network-blocks.php';
		$required        = array(
			'extrachill-network/extrachill-network.php' => time(),
			'extrachill-api/extrachill-api.php'         => time(),
		);
		try {
			update_site_option( 'active_sitewide_plugins', $required );
			$this->assertNull( activate_plugin( $plugin, '', true, false ) );
			$this->assertTrue( is_plugin_active_for_network( $plugin ) );
			deactivate_plugins( $plugin, true, true );

			update_site_option( 'active_sitewide_plugins', array() );
			update_option( 'active_plugins', array_keys( $required ) );
			$die_handler = static function () {
				return static function ( $message ): void {
					throw new RuntimeException( wp_strip_all_tags( $message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test catches this exception; it is never rendered.
				};
			};
			add_filter( 'wp_die_handler', $die_handler );
			$buffer_level = ob_get_level();
			try {
				activate_plugin( $plugin, '', true, false );
				$this->fail( 'Site-only dependencies must not permit network activation.' );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'Network-activate these required plugins first', $error->getMessage() );
			} finally {
				remove_filter( 'wp_die_handler', $die_handler );
				while ( ob_get_level() > $buffer_level ) {
					ob_end_clean();
				}
			}
			$this->assertFalse( is_plugin_active_for_network( $plugin ) );
		} finally {
			update_site_option( 'active_sitewide_plugins', $network_plugins );
			update_option( 'active_plugins', $site_plugins );
		}
	}

	/**
	 * Render from one caller blog and restore the test's original context.
	 *
	 * @param int   $blog_id    Caller blog ID.
	 * @param array $attributes Block attributes.
	 */
	private function render_on( int $blog_id, array $attributes ): string {
		switch_to_blog( $blog_id );
		try {
			return $this->render( $attributes );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Include the dynamic template and assert its internal switch is balanced.
	 *
	 * @param array $attributes Block attributes.
	 */
	private function render( array $attributes ): string {
		$blog_id      = get_current_blog_id();
		$switch_depth = count( $GLOBALS['_wp_switched_stack'] ?? array() );
		ob_start();
		include dirname( __DIR__, 2 ) . '/blocks/venue-booking-inquiry/render.php';
		$output = (string) ob_get_clean();

		$this->assertSame( $blog_id, get_current_blog_id() );
		$this->assertCount( $switch_depth, $GLOBALS['_wp_switched_stack'] ?? array() );
		return $output;
	}

	/** Render the canonical archive template for hierarchy assertions. */
	private function render_archive(): string {
		ob_start();
		include dirname( __DIR__, 2 ) . '/inc/templates/archive.php';
		return (string) ob_get_clean();
	}
}
