<?php
/**
 * Tests for zero-configuration service assertion key provisioning.
 *
 * @package ExtraChillShop
 */

// phpcs:disable WordPress.Files.FileName
// phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/ticket-service-authority.php';
require_once dirname( __DIR__, 2 ) . '/extrachill-events/inc/core/priority-boost-service-authority.php';
require_once dirname( __DIR__, 2 ) . '/extrachill-events/inc/core/ticket-service-authority.php';

final class ServiceAssertionKeysTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['test_site_options'] = array();
		// Other suites register explicit key filters; this suite exercises the defaults.
		foreach ( array_keys( $GLOBALS['test_filters'] ) as $hook ) {
			if ( str_contains( $hook, 'service_assertion' ) ) {
				unset( $GLOBALS['test_filters'][ $hook ] );
			}
		}
	}

	public function test_no_override_constants_are_defined(): void {
		foreach ( array( 'EXTRACHILL_SHOP_PRIORITY_BOOST_SERVICE_ASSERTION_KEYS', 'EXTRACHILL_SHOP_TICKET_SERVICE_ASSERTION_KEYS', 'EXTRACHILL_EVENTS_PRIORITY_BOOST_SERVICE_ASSERTION_KEYS', 'EXTRACHILL_EVENTS_TICKET_SERVICE_ASSERTION_KEYS' ) as $constant ) {
			$this->assertFalse( defined( $constant ), $constant );
		}
	}

	public function test_priority_boost_grants_resolve_without_constants_and_match_across_sites(): void {
		$source = extrachill_shop_priority_boost_source_grant();
		$target = extrachill_events_priority_boost_target_grant();

		$this->assertNotNull( $source );
		$this->assertNotNull( $target );
		$this->assertArrayHasKey( $source['active_key_id'], $source['keys'] );
		$this->assertSame( $source['keys'], $target['keys'] );
		$this->assertGreaterThanOrEqual( 32, strlen( $source['keys'][ $source['active_key_id'] ] ) );
		$this->assertSame( 3, $source['source_site_id'] );
		$this->assertSame( 7, $target['target_site_id'] );
	}

	public function test_ticket_grants_resolve_without_constants_and_match_across_sites(): void {
		$sources = extrachill_shop_register_ticket_source_grants( array() );
		$targets = extrachill_events_ticket_target_grants();

		$this->assertCount( 5, $sources );
		$this->assertCount( 5, $targets );
		$this->assertSame( $sources[0]['keys'], $targets[0]['keys'] );
		$this->assertContains( '/wp-abilities/v1/abilities/extrachill/notify-commerce-seller/run', array_column( $sources, 'route' ) );
	}

	public function test_keyring_is_provisioned_once_and_reused(): void {
		$first  = extrachill_shop_priority_boost_source_grant();
		$stored = $GLOBALS['test_site_options'][ EC_CROSS_SITE_SERVICE_ASSERTION_KEYRING ];
		$second = extrachill_shop_priority_boost_source_grant();

		$this->assertSame( $first['keys'], $second['keys'] );
		$this->assertSame( $stored, $GLOBALS['test_site_options'][ EC_CROSS_SITE_SERVICE_ASSERTION_KEYRING ] );
		$this->assertSame( 64, strlen( $stored['keys'][ $stored['active_key_id'] ] ) );
	}

	public function test_each_service_gets_a_distinct_derived_key(): void {
		$boost  = extrachill_shop_priority_boost_source_grant();
		$ticket = extrachill_shop_register_ticket_source_grants( array() )[0];
		$root   = $GLOBALS['test_site_options'][ EC_CROSS_SITE_SERVICE_ASSERTION_KEYRING ];

		$this->assertSame( $boost['active_key_id'], $ticket['active_key_id'] );
		$this->assertNotSame( $boost['keys'], $ticket['keys'] );
		$this->assertNotContains( $root['keys'][ $root['active_key_id'] ], $boost['keys'] );
	}

	public function test_unusable_stored_keyring_is_replaced(): void {
		$GLOBALS['test_site_options'][ EC_CROSS_SITE_SERVICE_ASSERTION_KEYRING ] = array(
			'active_key_id' => 'short',
			'keys'          => array( 'short' => 'too-short' ),
		);

		$this->assertNotNull( extrachill_shop_priority_boost_source_grant() );
		$this->assertNotSame( 'short', $GLOBALS['test_site_options'][ EC_CROSS_SITE_SERVICE_ASSERTION_KEYRING ]['active_key_id'] );
	}

	public function test_rotation_keeps_old_key_accepted_at_target(): void {
		extrachill_shop_priority_boost_source_grant();
		$keyring                    = $GLOBALS['test_site_options'][ EC_CROSS_SITE_SERVICE_ASSERTION_KEYRING ];
		$old                        = $keyring['active_key_id'];
		$keyring['keys']['rotated'] = str_repeat( 'r', 64 );
		$keyring['active_key_id']   = 'rotated';
		$GLOBALS['test_site_options'][ EC_CROSS_SITE_SERVICE_ASSERTION_KEYRING ] = $keyring;

		$this->assertSame( 'rotated', extrachill_shop_priority_boost_source_grant()['active_key_id'] );
		$this->assertArrayHasKey( $old, extrachill_events_priority_boost_target_grant()['keys'] );
	}

	public function test_filter_override_still_wins(): void {
		add_filter(
			'extrachill_shop_priority_boost_service_assertion_keys',
			static function () {
				return array( 'manual' => str_repeat( 'm', 32 ) );
			}
		);
		add_filter(
			'extrachill_shop_priority_boost_service_assertion_active_key_id',
			static function () {
				return 'manual';
			}
		);

		$grant = extrachill_shop_priority_boost_source_grant();

		$this->assertSame( 'manual', $grant['active_key_id'] );
		$this->assertSame( array( 'manual' => str_repeat( 'm', 32 ) ), $grant['keys'] );
	}
}
