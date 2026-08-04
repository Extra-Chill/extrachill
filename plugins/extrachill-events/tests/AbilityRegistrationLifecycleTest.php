<?php
/**
 * Ability registration lifecycle regression coverage.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\PriorityVenueAbilities;
use ExtraChillEvents\Abilities\TicketSettlementAbilities;
use ExtraChillEvents\Abilities\BookingReportingAbilities;
use ExtraChillEvents\Abilities\BookingPrivacyAbilities;
use ExtraChillEvents\Abilities\VenueBookingAbilities;
use ExtraChillEvents\Abilities\VenueBookingCommunicationAbilities;
use ExtraChillEvents\Abilities\VenueBookingEventAbilities;
use ExtraChillEvents\Abilities\VenueBookingHoldAbilities;
use ExtraChillEvents\Abilities\VenueMembershipAbilities;

require_once __DIR__ . '/Support/BookingTestHarness.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueMembershipService.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/VenueMembershipAbilities.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/PriorityVenueAbilities.php';

/** Covers Events ability hooks when the registry initializes early on init. */
final class AbilityRegistrationLifecycleTest extends BookingTestCase {

	/** Prepare the minimal booking runtime. */
	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'abilities' => array(),
			'actions'   => array(),
			'blog_id'   => 7,
			'options'   => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated test database double.
	}

	/**
	 * Full-runtime registry resolution must retain every representative surface.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_full_runtime_early_registry_keeps_events_abilities_registered(): void {
		$bootstrap = file_get_contents( dirname( __DIR__ ) . '/extrachill-events.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

		$this->assertIsString( $bootstrap );
		$this->assertStringContainsString( "add_action( 'plugins_loaded', array( \$this, 'init_abilities' ), 25 );", $bootstrap );
		$this->assertStringNotContainsString( "add_action( 'init', array( \$this, 'init_abilities' ), 25 );", $bootstrap );
		$this->assertStringContainsString( 'BookingSchema::is_ready()', $bootstrap );
		$this->assertStringContainsString( 'LocalSupportSchema::is_ready()', $bootstrap );

		$booking_abilities = new VenueBookingAbilities();
		$admission_service = new ReflectionProperty( VenueBookingAbilities::class, 'inquiry_admission' );
		$admission_service->setAccessible( true );
		$this->assertNull( $admission_service->getValue( $booking_abilities ) );
		new VenueMembershipAbilities();
		new VenueBookingHoldAbilities();
		new VenueBookingEventAbilities();
		new VenueBookingCommunicationAbilities();
		new TicketSettlementAbilities();
		new BookingReportingAbilities();
		new BookingPrivacyAbilities();
		new PriorityVenueAbilities();

		new VenueBookingAbilities();
		new VenueMembershipAbilities();
		new VenueBookingHoldAbilities();
		new VenueBookingEventAbilities();
		new VenueBookingCommunicationAbilities();
		new TicketSettlementAbilities();
		new BookingReportingAbilities();
		new BookingPrivacyAbilities();
		new PriorityVenueAbilities();

		$this->assertCount( 9, $GLOBALS['ec_test_filters']['wp_abilities_api_init'][10] );

		define( 'WP_AGENT_RUNTIME', true );
		add_action(
			'init',
			static function (): void {
				do_action( 'wp_abilities_api_init' );
			},
			10
		);
		do_action( 'init' );

		foreach (
			array(
				'extrachill/assign-venue-booking',
				'extrachill/create-venue-membership',
				'extrachill/create-booking-hold',
				'extrachill/convert-booking-to-event',
				'extrachill/send-booking-message',
				'extrachill/finalize-booking-settlement',
				'extrachill/get-venue-booking-performance-report',
				'extrachill/operate-venue-booking-privacy',
				'extrachill/list-priority-venues',
			) as $ability_name
		) {
			$this->assertArrayHasKey( $ability_name, $GLOBALS['ec_artist_test']['abilities'] );
		}
	}
}
