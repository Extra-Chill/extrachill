<?php
/**
 * Canonical artist identity adapter tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Isolated WordPress test doubles.

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		public function __construct( string $code, string $message = '', array $data = array() ) {
			unset( $message, $data );
			$this->code = $code;
		}
		public function get_error_code(): string {
			return $this->code;
		}
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ) {
		return strtolower( trim( (string) $value ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return strtolower( trim( (string) $value ) );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}
if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( $site ) {
		return array( 'main' => 1, 'events' => 7 )[ $site ] ?? 0;
	}
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return $GLOBALS['ec_artist_test']['blog_id'];
	}
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ) {
		$GLOBALS['ec_artist_test']['stack'][] = $GLOBALS['ec_artist_test']['blog_id'];
		$GLOBALS['ec_artist_test']['blog_id'] = (int) $blog_id;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() {
		$GLOBALS['ec_artist_test']['blog_id'] = array_pop( $GLOBALS['ec_artist_test']['stack'] );
	}
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		$term = $GLOBALS['ec_artist_test']['terms'][ get_current_blog_id() ][ $term_id ] ?? null;
		return $term && ( '' === $taxonomy || $taxonomy === $term->taxonomy ) ? $term : null;
	}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy ) {
		foreach ( $GLOBALS['ec_artist_test']['terms'][ get_current_blog_id() ] ?? array() as $term ) {
			if ( $taxonomy === $term->taxonomy && $value === $term->{$field} ) {
				return $term;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key ) {
		return $GLOBALS['ec_artist_test']['meta'][ get_current_blog_id() ][ $term_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		if ( ! empty( $GLOBALS['ec_artist_test']['fail_updates'][ $term_id ] ) ) {
			return false;
		}
		$GLOBALS['ec_artist_test']['meta'][ get_current_blog_id() ][ $term_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) {
		$GLOBALS['ec_artist_test']['registered'][ $name ] = $args;
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args ) {
		$terms = array_values( $GLOBALS['ec_artist_test']['terms'][ get_current_blog_id() ] ?? array() );
		$terms = array_values( array_filter( $terms, static fn( $term ) => $term->taxonomy === $args['taxonomy'] ) );
		if ( isset( $args['meta_key'] ) ) {
			$terms = array_values(
				array_filter(
					$terms,
					static fn( $term ) => (int) get_term_meta( $term->term_id, $args['meta_key'], true ) === (int) $args['meta_value']
				)
			);
		}
		return 'ids' === ( $args['fields'] ?? '' ) ? array_map( static fn( $term ) => $term->term_id, $terms ) : $terms;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['ec_artist_test']['options'][ get_current_blog_id() ][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		$GLOBALS['ec_artist_test']['options'][ get_current_blog_id() ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability() {
		return $GLOBALS['ec_artist_test']['ability'];
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-by-artist.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class EventsByArtistAbilityTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id' => 4,
			'stack'   => array(),
			'terms'   => array( 1 => array(), 7 => array() ),
			'meta'    => array( 1 => array(), 7 => array() ),
			'options' => array( 1 => array(), 7 => array() ),
			'events'  => array( 44 => array( 'artists' => array() ) ),
			'fail_updates' => array(),
			'registered'   => array(),
		);
		$GLOBALS['ec_artist_test']['ability'] = new class() {
			public function execute( array $input ): array {
				$GLOBALS['ec_artist_test']['delegated'] = $input;
				$term = get_term( $input['term_id'], 'artist' );
				return array(
					'taxonomy'  => 'artist',
					'term_id'   => $input['term_id'],
					'term_slug' => $term ? $term->slug : '',
					'found'     => true,
					'upcoming'  => array(),
					'past'      => array(),
				);
			}
		};
	}

	private function addTerm( int $blog_id, int $term_id, string $slug, string $taxonomy = 'artist' ): void {
		$GLOBALS['ec_artist_test']['terms'][ $blog_id ][ $term_id ] = (object) array(
			'term_id'  => $term_id,
			'slug'     => $slug,
			'taxonomy' => $taxonomy,
		);
	}

	private function bindProfile( int $canonical_id, int $profile_id = 10 ): void {
		$GLOBALS['ec_artist_test']['meta'][1][ $canonical_id ]['_artist_profile_id'] = $profile_id;
	}

	public function test_canonical_mapping_survives_renamed_slugs(): void {
		$this->addTerm( 1, 101, 'renamed-main' );
		$this->addTerm( 7, 501, 'renamed-events' );
		$GLOBALS['ec_artist_test']['meta'][1][101][ EXTRACHILL_EVENTS_ARTIST_TERM_META ] = 501;

		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );

		$this->assertSame( 501, $result['term_id'] );
		$this->assertArrayNotHasKey( 'term_slug', $GLOBALS['ec_artist_test']['delegated'] );
		$this->assertSame( 4, get_current_blog_id() );
	}

	public function test_missing_mapping_fails_closed_when_no_slug_matches(): void {
		$this->addTerm( 1, 101, 'main-only' );

		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );

		$this->assertSame( 'artist_mapping_missing', $result->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
	}

	public function test_canonical_identity_cannot_be_overridden_by_legacy_slug(): void {
		$this->addTerm( 1, 101, 'canonical-band' );
		$this->addTerm( 7, 501, 'other-band' );

		$result = extrachill_events_ability_events_by_artist(
			array(
				'artist_term_id' => 101,
				'term_slug'      => 'other-band',
			)
		);

		$this->assertSame( 'artist_mapping_missing', $result->get_error_code() );
	}

	public function test_missing_mapping_does_not_fall_back_to_a_matching_slug(): void {
		$this->addTerm( 1, 101, 'shared-band' );
		$this->addTerm( 1, 102, 'other-name' );
		$this->addTerm( 7, 501, 'shared-band' );
		$GLOBALS['ec_artist_test']['meta'][1][102][ EXTRACHILL_EVENTS_ARTIST_TERM_META ] = 501;

		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );

		$this->assertSame( 'artist_mapping_missing', $result->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
	}

	public function test_deleted_or_wrong_taxonomy_local_terms_are_stale(): void {
		$this->addTerm( 1, 101, 'band' );
		$GLOBALS['ec_artist_test']['meta'][1][101][ EXTRACHILL_EVENTS_ARTIST_TERM_META ] = 501;
		$this->assertSame( 'stale_artist_mapping', extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) )->get_error_code() );

		$this->addTerm( 7, 501, 'band', 'festival' );
		$this->assertSame( 'stale_artist_mapping', extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) )->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
	}

	public function test_deleted_or_wrong_taxonomy_canonical_terms_are_rejected(): void {
		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );
		$this->assertSame( 'invalid_canonical_artist', $result->get_error_code() );

		$this->addTerm( 1, 101, 'band', 'festival' );
		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );
		$this->assertSame( 'invalid_canonical_artist', $result->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
	}

	public function test_duplicate_local_claims_are_rejected(): void {
		$this->addTerm( 1, 101, 'one' );
		$this->addTerm( 1, 102, 'two' );
		$this->addTerm( 7, 501, 'local' );
		$GLOBALS['ec_artist_test']['meta'][1][101][ EXTRACHILL_EVENTS_ARTIST_TERM_META ] = 501;
		$GLOBALS['ec_artist_test']['meta'][1][102][ EXTRACHILL_EVENTS_ARTIST_TERM_META ] = 501;

		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );

		$this->assertSame( 'duplicate_artist_mapping', $result->get_error_code() );
		$this->assertSame( 4, get_current_blog_id() );
	}

	public function test_slug_only_lookup_is_rejected(): void {
		$this->addTerm( 7, 501, 'legacy-band' );

		$result = extrachill_events_ability_events_by_artist( array( 'term_slug' => 'legacy-band' ) );

		$this->assertSame( 'missing_artist_identity', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'delegated', $GLOBALS['ec_artist_test'] );
	}

	public function test_backfill_maps_only_bound_exact_unclaimed_pairs_and_reports_failures(): void {
		$this->addTerm( 1, 101, 'mapped' );
		$this->addTerm( 1, 102, 'missing' );
		$this->addTerm( 1, 103, 'stale' );
		$this->addTerm( 1, 104, 'collision' );
		$this->addTerm( 1, 105, 'collision-two' );
		foreach ( range( 101, 105 ) as $canonical_id ) {
			$this->bindProfile( $canonical_id, $canonical_id + 1000 );
		}
		$this->addTerm( 7, 501, 'mapped' );
		$this->addTerm( 7, 504, 'collision' );
		$this->addTerm( 7, 599, 'local-only' );
		$GLOBALS['ec_artist_test']['meta'][1][103][ EXTRACHILL_EVENTS_ARTIST_TERM_META ] = 999;
		$GLOBALS['ec_artist_test']['meta'][1][104][ EXTRACHILL_EVENTS_ARTIST_TERM_META ] = 504;
		$GLOBALS['ec_artist_test']['meta'][1][105][ EXTRACHILL_EVENTS_ARTIST_TERM_META ] = 504;
		$events_before = $GLOBALS['ec_artist_test']['events'];

		$report = extrachill_events_backfill_artist_identity();

		$this->assertSame( 501, $GLOBALS['ec_artist_test']['meta'][1][101][ EXTRACHILL_EVENTS_ARTIST_TERM_META ] );
		$this->assertCount( 1, $report['mapped'] );
		$this->assertCount( 1, $report['missing'] );
		$this->assertCount( 1, $report['stale'] );
		$this->assertCount( 1, $report['collisions'] );
		$this->assertCount( 1, $report['unmatched_local'] );
		$this->assertSame( $events_before, $GLOBALS['ec_artist_test']['events'] );
		$this->assertSame( 4, get_current_blog_id() );
	}

	public function test_backfill_reports_ambiguous_slugs_without_mapping_them(): void {
		$this->addTerm( 1, 101, 'duplicate' );
		$this->addTerm( 1, 102, 'duplicate' );
		$this->bindProfile( 101, 1101 );
		$this->bindProfile( 102, 1102 );
		$this->addTerm( 7, 501, 'duplicate' );

		$report = extrachill_events_backfill_artist_identity();

		$this->assertCount( 0, $report['mapped'] );
		$this->assertCount( 3, $report['ambiguous'] );
		$this->assertArrayNotHasKey( EXTRACHILL_EVENTS_ARTIST_TERM_META, $GLOBALS['ec_artist_test']['meta'][1][101] );
		$this->assertArrayNotHasKey( EXTRACHILL_EVENTS_ARTIST_TERM_META, $GLOBALS['ec_artist_test']['meta'][1][102] );
		$this->assertSame( 4, get_current_blog_id() );
	}

	public function test_backfill_counts_unbound_artist_mappings_as_claims(): void {
		$this->addTerm( 1, 101, 'candidate' );
		$this->addTerm( 1, 102, 'unbound-owner' );
		$this->bindProfile( 101, 1101 );
		$this->addTerm( 7, 501, 'candidate' );
		$GLOBALS['ec_artist_test']['meta'][1][102][ EXTRACHILL_EVENTS_ARTIST_TERM_META ] = 501;

		$report = extrachill_events_backfill_artist_identity();

		$this->assertCount( 0, $report['mapped'] );
		$this->assertCount( 1, $report['collisions'] );
		$this->assertArrayNotHasKey( EXTRACHILL_EVENTS_ARTIST_TERM_META, $GLOBALS['ec_artist_test']['meta'][1][101] );
	}

	public function test_failed_mapping_write_is_reported_and_remains_retryable(): void {
		$this->addTerm( 1, 101, 'candidate' );
		$this->bindProfile( 101, 1101 );
		$this->addTerm( 7, 501, 'candidate' );
		$GLOBALS['ec_artist_test']['fail_updates'][101] = true;

		$report = extrachill_events_backfill_artist_identity();

		$this->assertFalse( $report['complete'] );
		$this->assertCount( 0, $report['mapped'] );
		$this->assertCount( 1, $report['write_failures'] );
		$this->assertArrayNotHasKey( EXTRACHILL_EVENTS_ARTIST_TERM_META, $GLOBALS['ec_artist_test']['meta'][1][101] );
	}

	public function test_input_schema_requires_only_a_canonical_id(): void {
		extrachill_events_register_events_by_artist_ability();

		$schema = $GLOBALS['ec_artist_test']['registered']['extrachill-events/events-by-artist']['input_schema'];
		$this->assertSame( array( 'artist_term_id' ), $schema['required'] );
		$this->assertArrayNotHasKey( 'term_slug', $schema['properties'] );
	}
}
