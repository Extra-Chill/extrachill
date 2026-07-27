<?php
/**
 * Public venue booking inquiry render tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\VenueBookingConfig;

final class VenueBookingInquiryRenderTest extends WP_UnitTestCase {
	private int $venue_id;

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
		$term           = self::factory()->term->create_and_get( array( 'taxonomy' => 'venue', 'name' => 'Test Room', 'description' => 'Independent room in Charleston.' ) );
		$this->venue_id = (int) $term->term_id;
		$config         = ( new VenueBookingConfig() )->defaults();
		$config['enabled']             = true;
		$config['revision']            = 4;
		$config['public_requirements'] = array( 'Include recent draw and routing.' );
		$config['spaces']              = array( array( 'key' => 'main-room', 'name' => 'Main Room', 'is_default' => true ) );
		$config['intake']['fields']    = array( array( 'key' => 'draw', 'label' => 'Recent draw', 'type' => 'number', 'required' => true, 'options' => array() ) );
		$config['ticket_provider_reference'] = 'private-provider-account';
		$config['correspondence']['booking_address'] = 'private-booking@example.com';
		update_term_meta( $this->venue_id, VenueBookingConfig::META_KEY, $config );
	}

	public function test_render_contains_only_redacted_public_projection(): void {
		$output = $this->render( array( 'venueId' => $this->venue_id ) );

		$this->assertStringContainsString( 'ec-venue-booking-inquiry', $output );
		$this->assertStringContainsString( 'Test Room', $output );
		$this->assertStringContainsString( 'Include recent draw and routing.', $output );
		$this->assertStringContainsString( '"revision":4', $output );
		$this->assertStringNotContainsString( 'private-provider-account', $output );
		$this->assertStringNotContainsString( 'private-booking@example.com', $output );
		$this->assertStringNotContainsString( 'attachment', strtolower( $output ) );
	}

	public function test_disabled_config_fails_closed_without_form_markup(): void {
		$config            = get_term_meta( $this->venue_id, VenueBookingConfig::META_KEY, true );
		$config['enabled'] = false;
		update_term_meta( $this->venue_id, VenueBookingConfig::META_KEY, $config );

		$this->assertSame( '', $this->render( array( 'venueId' => $this->venue_id ) ) );
	}

	private function render( array $attributes ): string {
		ob_start();
		include dirname( __DIR__, 2 ) . '/blocks/venue-booking-inquiry/render.php';
		return (string) ob_get_clean();
	}
}
