<?php
/**
 * Managed venue voices REST contract coverage.
 *
 * @package ExtraChillEvents\Tests\Unit\Abilities
 */

use ExtraChillEvents\Abilities\ManagedVenueVoicesAbilities;
use ExtraChillEvents\Core\BookingSchema;

require_once dirname( __DIR__, 3 ) . '/inc/Core/BookingSchema.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/VenueAuthorization.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/VenueMembershipRepository.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/ManagedVenueVoicesAbilities.php';

/** Proves zero-input GET execution through the WordPress Abilities route. */
final class ManagedVenueVoicesRestTest extends WP_UnitTestCase {

	private int $member_user_id;
	private int $venue_term_id;
	private string $venue_url;

	/** Create one active canonical venue membership on the Events site. */
	public function set_up(): void {
		parent::set_up();

		$this->member_user_id = self::factory()->user->create();
		switch_to_blog( 7 );
		try {
			if ( ! taxonomy_exists( 'venue' ) ) {
				register_taxonomy( 'venue', 'data_machine_events', array( 'public' => true ) );
			}
			$this->venue_term_id = self::factory()->term->create(
				array(
					'taxonomy'    => 'venue',
					'name'        => 'Route Contract Hall',
					'slug'        => 'route-contract-hall',
					'description' => '<strong>Public venue description.</strong>',
				)
			);
			$this->venue_url = get_term_link( $this->venue_term_id, 'venue' );

			global $wpdb;
			$created = gmdate( 'Y-m-d H:i:s' );
			$this->assertSame(
				1,
				$wpdb->insert(
					BookingSchema::memberships_table(),
					array(
						'venue_term_id'      => $this->venue_term_id,
						'user_id'            => $this->member_user_id,
						'is_owner'           => 0,
						'status'             => 'active',
						'version'            => 1,
						'created_by_user_id' => $this->member_user_id,
						'created_at'         => $created,
						'updated_at'         => $created,
						'revoked_at'         => null,
					)
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Disposable integration fixture.
		} finally {
			restore_current_blog();
		}

		if ( ! wp_get_ability( 'extrachill/get-managed-venue-voices' ) ) {
			$abilities = new ManagedVenueVoicesAbilities();
			$abilities->register();
		}
	}

	/** Verify omitted GET input defaults to an empty object across the REST boundary. */
	public function test_zero_input_get_denies_anonymous_and_returns_authenticated_voice(): void {
		$route = '/wp-abilities/v1/abilities/extrachill/get-managed-venue-voices/run';

		wp_set_current_user( 0 );
		$anonymous = rest_do_request( new WP_REST_Request( 'GET', $route ) );
		$this->assertSame( 401, $anonymous->get_status() );
		$this->assertSame( 'managed_venue_voice_authentication_required', $anonymous->get_data()['code'] );

		wp_set_current_user( $this->member_user_id );
		$authenticated = rest_do_request( new WP_REST_Request( 'GET', $route ) );
		$this->assertSame( 200, $authenticated->get_status() );
		$this->assertSame(
			array(
				'voices' => array(
					array(
						'reference'   => 'venue:' . $this->venue_term_id,
						'term_id'     => $this->venue_term_id,
						'name'        => 'Route Contract Hall',
						'slug'        => 'route-contract-hall',
						'url'         => $this->venue_url,
						'description' => 'Public venue description.',
					),
				),
			),
			$authenticated->get_data()
		);
		$this->assertSame( 1, get_current_blog_id() );
	}
}
