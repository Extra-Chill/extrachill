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
		private array $data;
		public function __construct( string $code, string $message = '', array $data = array() ) {
			unset( $message );
			$this->code = $code;
			$this->data = $data;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_data(): array {
			return $this->data;
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
		if ( ! empty( $GLOBALS['ec_artist_test']['term_query_db_error'] ) || ! empty( $GLOBALS['ec_artist_test']['term_query_db_error_blogs'][ get_current_blog_id() ] ) ) {
			$GLOBALS['wpdb']->last_error = 'simulated empty term query database failure';
			return array();
		}
		if ( isset( $args['meta_key'] ) && ! empty( $GLOBALS['ec_artist_test']['mapping_claims_db_error'] ) ) {
			$GLOBALS['wpdb']->last_error = 'simulated empty mapping claims database failure';
			return array();
		}
		$terms = array_values( $GLOBALS['ec_artist_test']['terms'][ get_current_blog_id() ] ?? array() );
		$terms = array_values( array_filter( $terms, static fn( $term ) => $term->taxonomy === $args['taxonomy'] ) );
		if ( ! empty( $args['include'] ) ) {
			$include = array_map( 'intval', (array) $args['include'] );
			$terms   = array_values( array_filter( $terms, static fn( $term ) => in_array( (int) $term->term_id, $include, true ) ) );
		}
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

/** Minimal advisory-lock database double for plain identity tests. */
final class EventsByArtistWpdb {
	public $last_error = '';
	public $release_result = 1;
	public $after_mapping_lock;
	public $locks = array();

	public function flush(): void {
		$this->last_error = '';
	}

	public function prepare( $query, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			static function ( $match ) use ( &$args, &$i ) {
				$value = $args[ $i++ ];
				return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function get_var( $query ) {
		$this->last_error = '';
		if ( false !== strpos( $query, 'GET_LOCK' ) ) {
			$this->locks[] = 'acquire';
			if ( is_callable( $this->after_mapping_lock ) ) {
				$callback = $this->after_mapping_lock;
				$this->after_mapping_lock = null;
				$callback();
			}
			return 1;
		}
		$this->locks[] = 'release';
		return $this->release_result;
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-by-artist.php';

final class EventsByArtistAbilityTest extends TestCase {
	private int $starting_blog_id = 4;
	private $original_ability_resolver;
	private bool $registered_test_category = false;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new EventsByArtistWpdb();
		$this->original_ability_resolver     = $GLOBALS['ec_test_ability_resolver'] ?? null;
		$GLOBALS['ec_test_ability_resolver'] = static fn() => $GLOBALS['ec_artist_test']['ability'] ?? null;
		$GLOBALS['ec_artist_test']           = array(
			'blog_id' => 4,
			'stack'   => array(),
			'terms'   => array( 1 => array(), 7 => array() ),
			'meta'    => array( 1 => array(), 7 => array() ),
			'options' => array( 1 => array(), 7 => array() ),
			'events'  => array( 44 => array( 'artists' => array() ) ),
			'fail_updates' => array(),
			'registered'   => array(),
		);
		if ( ! class_exists( 'WP_UnitTestCase' ) ) {
			$GLOBALS['wpdb'] = new EventsByArtistWpdb();
		}
		$this->starting_blog_id = get_current_blog_id();
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
		if ( class_exists( 'WP_UnitTestCase' ) ) {
			$this->resetManagedFixture();
			if ( ! wp_has_ability_category( 'extrachill-events' ) ) {
				WP_Ability_Categories_Registry::get_instance()->register(
					'extrachill-events',
					array(
						'label'       => 'Extra Chill Events',
						'description' => 'Extra Chill Events abilities.',
					)
				);
			}
			if ( wp_has_ability( 'data-machine-events/events-by-term' ) ) {
				wp_unregister_ability( 'data-machine-events/events-by-term' );
			}
			if ( ! wp_has_ability_category( 'extrachill-events-tests' ) ) {
				$this->registered_test_category = true;
				WP_Ability_Categories_Registry::get_instance()->register(
					'extrachill-events-tests',
					array(
						'label'       => 'Extra Chill Events tests',
						'description' => 'Managed test abilities.',
					)
				);
			}
			WP_Abilities_Registry::get_instance()->register(
				'data-machine-events/events-by-term',
				array(
					'label'               => 'Events by term test',
					'description'         => 'Captures delegated artist input.',
					'category'            => 'extrachill-events-tests',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'taxonomy' => array( 'type' => 'string' ),
							'term_id'  => array( 'type' => 'integer' ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'taxonomy'  => array( 'type' => 'string' ),
							'term_id'   => array( 'type' => 'integer' ),
							'term_slug' => array( 'type' => 'string' ),
							'found'     => array( 'type' => 'boolean' ),
							'upcoming'  => array( 'type' => 'array' ),
							'past'      => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => static function ( array $input ): array {
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
					},
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	protected function tearDown(): void {
		if ( function_exists( 'wp_unregister_ability' ) && wp_has_ability( 'data-machine-events/events-by-term' ) ) {
			wp_unregister_ability( 'data-machine-events/events-by-term' );
		}
		if ( $this->registered_test_category && wp_has_ability_category( 'extrachill-events-tests' ) ) {
			wp_unregister_ability_category( 'extrachill-events-tests' );
		}
		if ( null === $this->original_ability_resolver ) {
			unset( $GLOBALS['ec_test_ability_resolver'] );
		} else {
			$GLOBALS['ec_test_ability_resolver'] = $this->original_ability_resolver;
		}
		unset( $GLOBALS['ec_artist_test'] );
		parent::tearDown();
	}

	private function resetManagedFixture(): void {
		global $wpdb;
		foreach ( array( 1, 7 ) as $blog_id ) {
			switch_to_blog( $blog_id );
			register_taxonomy( 'artist', 'post' );
			$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id BETWEEN 101 AND 599" );
			$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_id BETWEEN 101 AND 599" );
			$wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id BETWEEN 101 AND 599" );
			foreach ( array_merge( range( 101, 105 ), range( 501, 599 ) ) as $term_id ) {
				wp_cache_delete( $term_id, 'term_meta' );
				clean_term_cache( $term_id, 'artist' );
			}
			restore_current_blog();
		}
	}

	private function addTerm( int $blog_id, int $term_id, string $slug, string $taxonomy = 'artist' ): void {
		$GLOBALS['ec_artist_test']['terms'][ $blog_id ][ $term_id ] = (object) array(
			'term_id'  => $term_id,
			'slug'     => $slug,
			'taxonomy' => $taxonomy,
		);
		if ( class_exists( 'WP_UnitTestCase' ) ) {
			global $wpdb;
			switch_to_blog( $blog_id );
			register_taxonomy( $taxonomy, 'post' );
			$wpdb->replace(
				$wpdb->terms,
				array(
					'term_id'    => $term_id,
					'name'       => $slug,
					'slug'       => $slug,
					'term_group' => 0,
				)
			);
			$wpdb->replace(
				$wpdb->term_taxonomy,
				array(
					'term_taxonomy_id' => $term_id,
					'term_id'          => $term_id,
					'taxonomy'         => $taxonomy,
					'description'      => '',
					'parent'           => 0,
					'count'            => 0,
				)
			);
			clean_term_cache( $term_id, $taxonomy );
			restore_current_blog();
		}
	}

	private function bindProfile( int $canonical_id, int $profile_id = 10 ): void {
		$GLOBALS['ec_artist_test']['meta'][1][ $canonical_id ]['_artist_profile_id'] = $profile_id;
		$this->setMeta( $canonical_id, '_artist_profile_id', $profile_id );
	}

	private function setMeta( int $canonical_id, string $key, int $value ): void {
		$GLOBALS['ec_artist_test']['meta'][1][ $canonical_id ][ $key ] = $value;
		if ( class_exists( 'WP_UnitTestCase' ) ) {
			global $wpdb;
			switch_to_blog( 1 );
			$wpdb->delete(
				$wpdb->termmeta,
				array(
					'term_id'  => $canonical_id,
					'meta_key' => $key,
				)
			);
			$wpdb->insert(
				$wpdb->termmeta,
				array(
					'term_id'    => $canonical_id,
					'meta_key'   => $key,
					'meta_value' => $value,
				)
			);
			wp_cache_delete( $canonical_id, 'term_meta' );
			restore_current_blog();
		}
	}

	private function getMeta( int $canonical_id, string $key ) {
		if ( class_exists( 'WP_UnitTestCase' ) ) {
			switch_to_blog( 1 );
			$value = get_term_meta( $canonical_id, $key, true );
			restore_current_blog();
			return $value;
		}
		return $GLOBALS['ec_artist_test']['meta'][1][ $canonical_id ][ $key ] ?? null;
	}

	public function test_canonical_mapping_survives_renamed_slugs(): void {
		$this->addTerm( 1, 101, 'renamed-main' );
		$this->addTerm( 7, 501, 'renamed-events' );
		$this->setMeta( 101, EXTRACHILL_EVENTS_ARTIST_TERM_META, 501 );

		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );

		$this->assertSame( 501, $result['term_id'] );
		$this->assertArrayNotHasKey( 'term_slug', $GLOBALS['ec_artist_test']['delegated'] );
		$this->assertSame( $this->starting_blog_id, get_current_blog_id() );
	}

	public function test_missing_mapping_fails_closed_when_no_slug_matches(): void {
		$this->addTerm( 1, 101, 'main-only' );

		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );

		$this->assertSame( 'artist_mapping_missing', $result->get_error_code() );
		$this->assertSame( $this->starting_blog_id, get_current_blog_id() );
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
		$this->setMeta( 102, EXTRACHILL_EVENTS_ARTIST_TERM_META, 501 );

		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );

		$this->assertSame( 'artist_mapping_missing', $result->get_error_code() );
		$this->assertSame( $this->starting_blog_id, get_current_blog_id() );
	}

	public function test_deleted_or_wrong_taxonomy_local_terms_are_stale(): void {
		$this->addTerm( 1, 101, 'band' );
		$this->setMeta( 101, EXTRACHILL_EVENTS_ARTIST_TERM_META, 501 );
		$this->assertSame( 'stale_artist_mapping', extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) )->get_error_code() );

		$this->addTerm( 7, 501, 'band', 'festival' );
		$this->assertSame( 'stale_artist_mapping', extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) )->get_error_code() );
		$this->assertSame( $this->starting_blog_id, get_current_blog_id() );
	}

	public function test_deleted_or_wrong_taxonomy_canonical_terms_are_rejected(): void {
		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );
		$this->assertSame( 'invalid_canonical_artist', $result->get_error_code() );

		$this->addTerm( 1, 101, 'band', 'festival' );
		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );
		$this->assertSame( 'invalid_canonical_artist', $result->get_error_code() );
		$this->assertSame( $this->starting_blog_id, get_current_blog_id() );
	}

	public function test_duplicate_local_claims_are_rejected(): void {
		$this->addTerm( 1, 101, 'one' );
		$this->addTerm( 1, 102, 'two' );
		$this->addTerm( 7, 501, 'local' );
		$this->setMeta( 101, EXTRACHILL_EVENTS_ARTIST_TERM_META, 501 );
		$this->setMeta( 102, EXTRACHILL_EVENTS_ARTIST_TERM_META, 501 );

		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );

		$this->assertSame( 'duplicate_artist_mapping', $result->get_error_code() );
		$this->assertSame( $this->starting_blog_id, get_current_blog_id() );
	}

	public function test_empty_reverse_claim_result_with_database_error_fails_closed(): void {
		$this->addTerm( 1, 101, 'one' );
		$this->addTerm( 7, 501, 'local' );
		$this->setMeta( 101, EXTRACHILL_EVENTS_ARTIST_TERM_META, 501 );
		$GLOBALS['ec_artist_test']['mapping_claims_db_error'] = true;

		$result = extrachill_events_ability_events_by_artist( array( 'artist_term_id' => 101 ) );

		$this->assertSame( 'artist_mapping_claims_query_failed', $result->get_error_code() );
		$this->assertSame( $this->starting_blog_id, get_current_blog_id() );
	}

	public function test_canonical_term_database_error_is_propagated(): void {
		$this->addTerm( 1, 101, 'one' );
		$this->addTerm( 7, 501, 'local' );
		$this->setMeta( 101, EXTRACHILL_EVENTS_ARTIST_TERM_META, 501 );
		$GLOBALS['ec_artist_test']['term_query_db_error_blogs'][1] = true;

		$result = extrachill_events_resolve_artist_term( 101 );

		$this->assertSame( 'artist_term_query_failed', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame( $this->starting_blog_id, get_current_blog_id() );
	}

	public function test_local_term_database_error_is_propagated(): void {
		$this->addTerm( 1, 101, 'one' );
		$this->addTerm( 7, 501, 'local' );
		$this->setMeta( 101, EXTRACHILL_EVENTS_ARTIST_TERM_META, 501 );
		$GLOBALS['ec_artist_test']['term_query_db_error_blogs'][7] = true;

		$result = extrachill_events_resolve_artist_term( 101 );

		$this->assertSame( 'artist_term_query_failed', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame( $this->starting_blog_id, get_current_blog_id() );
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
		$this->setMeta( 103, EXTRACHILL_EVENTS_ARTIST_TERM_META, 999 );
		$this->setMeta( 104, EXTRACHILL_EVENTS_ARTIST_TERM_META, 504 );
		$this->setMeta( 105, EXTRACHILL_EVENTS_ARTIST_TERM_META, 504 );
		$events_before = $GLOBALS['ec_artist_test']['events'];

		$report = extrachill_events_backfill_artist_identity();

		$this->assertSame( 501, (int) $this->getMeta( 101, EXTRACHILL_EVENTS_ARTIST_TERM_META ) );
		$this->assertCount( 1, $report['mapped'] );
		$this->assertCount( 1, $report['missing'] );
		$this->assertCount( 1, $report['stale'] );
		$this->assertCount( 1, $report['collisions'] );
		$this->assertCount( 1, $report['unmatched_local'] );
		$this->assertSame( $events_before, $GLOBALS['ec_artist_test']['events'] );
		$this->assertSame( $this->starting_blog_id, get_current_blog_id() );
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
		$this->setMeta( 102, EXTRACHILL_EVENTS_ARTIST_TERM_META, 501 );

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
		$fail_update = static function ( $check, $object_id ) {
			return 101 === (int) $object_id ? false : $check;
		};
		if ( class_exists( 'WP_UnitTestCase' ) ) {
			add_filter( 'update_term_metadata', $fail_update, 10, 2 );
		}

		$report = extrachill_events_backfill_artist_identity();
		if ( class_exists( 'WP_UnitTestCase' ) ) {
			remove_filter( 'update_term_metadata', $fail_update, 10 );
		}

		$this->assertFalse( $report['complete'] );
		$this->assertCount( 0, $report['mapped'] );
		$this->assertCount( 1, $report['write_failures'] );
		$this->assertArrayNotHasKey( EXTRACHILL_EVENTS_ARTIST_TERM_META, $GLOBALS['ec_artist_test']['meta'][1][101] );
	}

	public function test_backfill_rechecks_reverse_claims_after_mapping_lock(): void {
		$this->addTerm( 1, 101, 'candidate' );
		$this->addTerm( 1, 102, 'racer' );
		$this->bindProfile( 101, 1101 );
		$this->addTerm( 7, 501, 'candidate' );
		$GLOBALS['wpdb']->after_mapping_lock = static function (): void {
			$GLOBALS['ec_artist_test']['meta'][1][102][ EXTRACHILL_EVENTS_ARTIST_TERM_META ] = 501;
		};

		$report = extrachill_events_backfill_artist_identity();

		$this->assertCount( 0, $report['mapped'] );
		$this->assertCount( 1, $report['collisions'] );
		$this->assertSame( array( 102, 101 ), $report['collisions'][0]['artist_term_ids'] );
		$this->assertArrayNotHasKey( EXTRACHILL_EVENTS_ARTIST_TERM_META, $GLOBALS['ec_artist_test']['meta'][1][101] );
		$this->assertSame( array( 'acquire', 'release' ), $GLOBALS['wpdb']->locks );
	}

	public function test_backfill_reports_mapping_lock_release_failure_and_retains_lock(): void {
		$this->addTerm( 1, 101, 'candidate' );
		$this->bindProfile( 101, 1101 );
		$this->addTerm( 7, 501, 'candidate' );
		$GLOBALS['wpdb']->release_result = 0;

		$report = extrachill_events_backfill_artist_identity();

		$this->assertFalse( $report['complete'] );
		$this->assertCount( 0, $report['mapped'] );
		$this->assertCount( 1, $report['write_failures'] );
		$this->assertSame( 501, (int) $GLOBALS['ec_artist_test']['meta'][1][101][ EXTRACHILL_EVENTS_ARTIST_TERM_META ] );
		$this->assertSame( 'events_artist_mapping_lock_busy', \ExtraChillEvents\Core\ArtistMappingLock::acquire( 501 )->get_error_code() );
		$GLOBALS['wpdb']->release_result = 1;
		$this->assertTrue( \ExtraChillEvents\Core\ArtistMappingLock::release( 'ec_events_artist_mapping_501' ) );
	}

	public function test_input_schema_requires_only_a_canonical_id(): void {
		if ( function_exists( 'wp_unregister_ability' ) && wp_has_ability( 'extrachill-events/events-by-artist' ) ) {
			wp_unregister_ability( 'extrachill-events/events-by-artist' );
		}
		if ( class_exists( 'WP_UnitTestCase' ) ) {
			WP_Abilities_Registry::get_instance()->register(
				'extrachill-events/events-by-artist',
				array(
					'label'               => 'Events By Canonical Artist',
					'description'         => 'Managed schema fixture.',
					'category'            => 'extrachill-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'artist_term_id' ),
						'properties' => array( 'artist_term_id' => array( 'type' => 'integer' ) ),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => 'extrachill_events_ability_events_by_artist',
					'permission_callback' => '__return_true',
				)
			);
		} else {
			extrachill_events_register_events_by_artist_ability();
		}

		$schema = class_exists( 'WP_UnitTestCase' )
			? wp_get_ability( 'extrachill-events/events-by-artist' )->get_input_schema()
			: $GLOBALS['ec_artist_test']['registered']['extrachill-events/events-by-artist']['input_schema'];
		$this->assertSame( array( 'artist_term_id' ), $schema['required'] );
		$this->assertArrayNotHasKey( 'term_slug', $schema['properties'] );
	}
}
