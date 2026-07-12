<?php
/**
 * Account market integration tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return (bool) ( $GLOBALS['test_is_user_logged_in'] ?? false );
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		return 'extrachill/get-user-settings' === $name ? ( $GLOBALS['test_account_market_ability'] ?? null ) : null;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error() {
		return false;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( (string) $value ) ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'is_tax' ) ) {
	function is_tax() {
		return (bool) ( $GLOBALS['test_is_tax'] ?? false );
	}
}

if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page() {
		return (bool) ( $GLOBALS['test_is_front_page'] ?? false );
	}
}

if ( ! function_exists( 'ec_is_events_site' ) ) {
	function ec_is_events_site() {
		return true;
	}
}

if ( ! function_exists( 'extrachill_events_is_near_me_page' ) ) {
	function extrachill_events_is_near_me_page() {
		return (bool) ( $GLOBALS['test_is_near_me_page'] ?? false );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/account-market.php';

/**
 * Verifies the account preference integration and its precedence gates.
 */
final class AccountMarketTest extends TestCase {
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolves_coordinates_from_user_ability(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'default_event_location' => array(
						'slug'        => 'Charleston SC',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};

		$this->assertSame(
			array(
				'lat'     => 32.7765,
				'lon'     => -79.9311,
				'slug'    => 'charleston-sc',
				'term_id' => 1618,
			),
			extrachill_events_get_account_market()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fails_open_when_ability_is_unavailable(): void {
		$GLOBALS['test_is_user_logged_in'] = true;

		$this->assertNull( extrachill_events_get_account_market() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_adds_taxonomy_default_without_mutating_request_globals(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_front_page']          = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'default_event_location' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};
		$_GET = array();
		$result = extrachill_events_calendar_account_market_defaults( array(), array( 'archive_term' => null ) );

		$this->assertSame( array( 'location' => array( 1618 ) ), $result['tax_filter'] );
		$this->assertSame( array(), $_GET );
	}

	public function test_explicit_geo_and_taxonomy_filters_win(): void {
		$_GET = array(
			'lat' => '40.7',
			'lng' => '-74.0',
		);
		$this->assertTrue( extrachill_events_has_explicit_market() );

		$_GET = array( 'tax_filter' => array( 'location' => array( 42 ) ) );
		$this->assertTrue( extrachill_events_has_explicit_market() );

		$explicit_geo = array(
			'lat' => '40.7',
			'lng' => '-74.0',
		);
		$this->assertSame(
			$explicit_geo,
			extrachill_events_calendar_account_market_defaults( $explicit_geo, array( 'archive_term' => null ) )
		);

		$explicit_taxonomy = array( 'tax_filter' => array( 'location' => array( 42 ) ) );
		$this->assertSame(
			$explicit_taxonomy,
			extrachill_events_calendar_account_market_defaults( $explicit_taxonomy, array( 'archive_term' => null ) )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_near_me_adds_geo_defaults_without_taxonomy_filter(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_near_me_page']        = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'default_event_location' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};

		$result = extrachill_events_calendar_account_market_defaults( array(), array( 'archive_term' => null ) );

		$this->assertSame( 32.7765, $result['lat'] );
		$this->assertSame( -79.9311, $result['lng'] );
		$this->assertArrayNotHasKey( 'tax_filter', $result );
	}

	public function test_anonymous_request_does_not_resolve_account_market(): void {
		$GLOBALS['test_is_user_logged_in'] = false;

		$this->assertNull( extrachill_events_get_account_market() );
	}
}
