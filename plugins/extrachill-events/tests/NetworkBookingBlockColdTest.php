<?php
/**
 * Cold network booking block tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;


/** Proves the network companion in a cold non-Events process. */
final class NetworkBookingBlockColdTest extends TestCase {
	/** Load only the network companion for this process. */
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/extrachill-events-network-blocks.php';
	}

	/** Reset the simulated network request and activation state. */
	protected function setUp(): void {
		$GLOBALS['ec_network_block_test']['blog_id']         = 1;
		$GLOBALS['ec_network_block_test']['stack']           = array();
		$GLOBALS['ec_network_block_test']['registered']      = array();
		$GLOBALS['ec_network_block_test']['nocache_headers'] = 0;
		$GLOBALS['ec_network_block_test']['network_plugins'] = array(
			'extrachill-network/extrachill-network.php',
			'extrachill-api/extrachill-api.php',
		);
	}

	/** Render without loading private booking-domain dependencies. */
	public function test_companion_only_render_has_bounded_dependencies_and_restores_main_context(): void {
		$this->assertFalse( class_exists( '\ExtraChillEvents\Core\BookingRepository', false ) );
		extrachill_events_register_network_blocks();
		$this->assertCount( 1, $GLOBALS['ec_network_block_test']['registered'] );
		$this->assertFileExists( $GLOBALS['ec_network_block_test']['registered'][0] . '/block.json' );
		$this->assertFileEquals( dirname( __DIR__ ) . '/blocks/venue-booking-inquiry/render.php', $GLOBALS['ec_network_block_test']['registered'][0] . '/render.php' );

		$attributes = array( 'venueId' => 1524 );
		ob_start();
		include dirname( __DIR__ ) . '/blocks/venue-booking-inquiry/render.php';
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Cold Room', $output );
		$this->assertStringContainsString( 'https:\/\/extrachill.com\/wp-json\/extrachill\/v1\/venues\/1524\/booking-inquiries', $output );
		$this->assertTrue( $GLOBALS['ec_network_block_test']['turnstile_enqueued'] );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
		$this->assertSame( 1, $GLOBALS['ec_network_block_test']['nocache_headers'] );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_network_block_test']['stack'] );
	}

	/** Require true network activation and network-active dependencies. */
	public function test_activation_rejects_site_only_or_missing_dependencies(): void {
		$this->assertSame( 'extrachill_events_activate_network_blocks', $GLOBALS['ec_network_block_test']['activation_callback'] );
		$this->assertNull( extrachill_events_network_blocks_activation_error( true ) );
		$this->assertSame( 'network_activation_required', extrachill_events_network_blocks_activation_error( false )->get_error_code() );

		$GLOBALS['ec_network_block_test']['network_plugins'] = array( 'extrachill-network/extrachill-network.php' );
		$error = extrachill_events_network_blocks_activation_error( true );
		$this->assertSame( 'network_dependency_required', $error->get_error_code() );
		$this->assertStringContainsString( 'Extra Chill API', $error->get_error_message() );
	}

	/** Skip Events when the full site plugin is loaded, but not other sites. */
	public function test_companion_respects_full_plugin_active_site(): void {
		if ( ! defined( 'EXTRACHILL_EVENTS_PLUGIN_FILE' ) ) {
			define( 'EXTRACHILL_EVENTS_PLUGIN_FILE', dirname( __DIR__ ) . '/extrachill-events.php' );
		}

		$GLOBALS['ec_network_block_test']['blog_id'] = 7;
		extrachill_events_register_network_blocks();
		$this->assertSame( array(), $GLOBALS['ec_network_block_test']['registered'] );

		$GLOBALS['ec_network_block_test']['blog_id'] = 1;
		extrachill_events_register_network_blocks();
		$this->assertCount( 1, $GLOBALS['ec_network_block_test']['registered'] );
	}
}
