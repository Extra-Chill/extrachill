<?php
/**
 * Event roundup tax filter resolution tests.
 *
 * @package ExtraChillEvents\Tests
 */

declare( strict_types=1 );

use ExtraChillEvents\Abilities\EventRoundupAbilities;

/**
 * The roundup passes caller tax filters straight to the events query, so
 * resolution must reject unknown taxonomies and terms instead of silently
 * widening or emptying the result.
 *
 * Runs in a separate process: the WP term shims below must not leak into
 * other plain-unit tests that branch on function_exists().
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class EventRoundupTaxFiltersTest extends BookingTestCase {

	/** Install term shims backed by a small in-memory taxonomy registry. */
	private function install_term_shims(): void {
		$GLOBALS['roundup_tax_terms'] = array(
			'event_type' => array(
				'concert' => 11,
				'dj-set'  => 12,
				'comedy'  => 13,
			),
			'location'   => array( 'charleston' => 1618 ),
		);

		if ( ! class_exists( 'WP_Term' ) ) {
			eval( 'final class WP_Term { public $term_id; public $slug; public function __construct( $id, $slug ) { $this->term_id = $id; $this->slug = $slug; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim.
		}
		if ( ! function_exists( 'get_object_taxonomies' ) ) {
			eval( 'function get_object_taxonomies( $type ) { return array_keys( $GLOBALS["roundup_tax_terms"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim.
		}
		if ( ! function_exists( 'get_term_by' ) ) {
			eval( 'function get_term_by( $field, $value, $taxonomy ) { $id = $GLOBALS["roundup_tax_terms"][ $taxonomy ][ $value ] ?? 0; return $id ? new WP_Term( $id, $value ) : false; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim.
		}
		if ( ! function_exists( 'get_term' ) ) {
			eval( 'function get_term( $id, $taxonomy ) { $slug = array_search( (int) $id, $GLOBALS["roundup_tax_terms"][ $taxonomy ] ?? array(), true ); return false !== $slug ? new WP_Term( (int) $id, $slug ) : null; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim.
		}
		if ( ! function_exists( 'sanitize_key' ) ) {
			eval( 'function sanitize_key( $key ) { return preg_replace( "/[^a-z0-9_\-]/", "", strtolower( (string) $key ) ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim.
		}

		require_once dirname( __DIR__, 3 ) . '/inc/Abilities/EventRoundupAbilities.php';
	}

	public function test_resolves_slugs_ids_and_comma_lists(): void {
		$this->install_term_shims();

		$this->assertSame( array(), EventRoundupAbilities::resolveTaxFilters( array() ) );
		$this->assertSame(
			array(
				'event_type' => array( 11, 12 ),
				'location'   => array( 1618 ),
			),
			EventRoundupAbilities::resolveTaxFilters(
				array(
					'event_type' => array( 'concert', '12', 'concert' ),
					'location'   => 'charleston',
				)
			)
		);
		$this->assertSame(
			array( 'event_type' => array( 11, 13 ) ),
			EventRoundupAbilities::resolveTaxFilters( array( 'event_type' => 'concert, comedy,' ) )
		);
	}

	public function test_rejects_unknown_taxonomy_and_term(): void {
		$this->install_term_shims();

		$taxonomy = EventRoundupAbilities::resolveTaxFilters( array( 'post_tag' => array( 'x' ) ) );
		$this->assertInstanceOf( WP_Error::class, $taxonomy );
		$this->assertSame( 'unknown_taxonomy', $taxonomy->get_error_code() );

		$term = EventRoundupAbilities::resolveTaxFilters( array( 'event_type' => array( 'concert', 'polka' ) ) );
		$this->assertInstanceOf( WP_Error::class, $term );
		$this->assertSame( 'unknown_term', $term->get_error_code() );
	}
}
