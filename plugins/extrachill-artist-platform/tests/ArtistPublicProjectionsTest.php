<?php

use PHPUnit\Framework\TestCase;

final class ArtistPublicProjectionsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 4,
			'blog_stack'      => array(),
			'blogs'           => array(
				1 => array( 'terms' => array(), 'term_meta' => array(), 'posts' => array(), 'post_meta' => array() ),
				4 => array( 'terms' => array(), 'term_meta' => array(), 'posts' => array(), 'post_meta' => array() ),
			),
		);
		extrachill_artist_platform_register_abilities();
	}

	private function addTerm( int $id, string $slug, int $profile_id = 0 ): void {
		$GLOBALS['ec_test']['blogs'][1]['terms'][ $id ] = (object) array(
			'term_id'  => $id,
			'taxonomy' => 'artist',
			'slug'     => $slug,
		);
		if ( $profile_id > 0 ) {
			$GLOBALS['ec_test']['blogs'][1]['term_meta'][ $id ]['_artist_profile_id'] = $profile_id;
		}
	}

	private function addProfile( int $id, string $slug, string $name, int $term_id, string $status = 'publish' ): void {
		$GLOBALS['ec_test']['blogs'][4]['posts'][ $id ] = (object) array(
			'ID'          => $id,
			'post_type'   => 'artist_profile',
			'post_status' => $status,
			'post_title'  => $name,
			'post_name'   => $slug,
		);
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][ $id ]['_artist_term_id'] = $term_id;
	}

	public function test_registration_exposes_an_exact_public_read_contract(): void {
		$ability = wp_get_ability( 'extrachill/artist-public-projections' );
		$input   = $ability->get_input_schema();
		$output  = $ability->get_output_schema();
		$item    = $output['properties']['items']['items'];

		$this->assertTrue( $ability->check_permissions( array( 'schema_version' => '1', 'slugs' => array( 'kid-lake' ) ) ) );
		$this->assertTrue( $ability->get_meta()['show_in_rest'] );
		$this->assertSame( array( 'readonly' => true, 'idempotent' => true, 'destructive' => false ), $ability->get_meta()['annotations'] );
		$this->assertSame( array( 'schema_version', 'slugs' ), $input['required'] );
		$this->assertFalse( $input['additionalProperties'] );
		$this->assertSame( array( '1' ), $input['properties']['schema_version']['enum'] );
		$this->assertSame( 1, $input['properties']['slugs']['minItems'] );
		$this->assertSame( 100, $input['properties']['slugs']['maxItems'] );
		$this->assertTrue( $input['properties']['slugs']['uniqueItems'] );
		$this->assertSame( 1, $input['properties']['slugs']['items']['minLength'] );
		$this->assertSame( 200, $input['properties']['slugs']['items']['maxLength'] );
		$this->assertSame( '^[a-z0-9]+(?:-[a-z0-9]+)*$', $input['properties']['slugs']['items']['pattern'] );
		$this->assertSame( array( 'schema_version', 'items' ), $output['required'] );
		$this->assertFalse( $output['additionalProperties'] );
		$this->assertSame( 100, $output['properties']['items']['maxItems'] );
		$this->assertSame( array( 'slug', 'status', 'name', 'url' ), $item['required'] );
		$this->assertFalse( $item['additionalProperties'] );
		$this->assertSame( array( 'resolved', 'not_found' ), $item['properties']['status']['enum'] );
	}

	public function test_schema_rejects_duplicate_bounds_and_noncanonical_slugs(): void {
		$slugs_schema = wp_get_ability( 'extrachill/artist-public-projections' )->get_input_schema()['properties']['slugs'];
		$valid_slug   = static function ( string $slug ) use ( $slugs_schema ): bool {
			$item = $slugs_schema['items'];
			return strlen( $slug ) >= $item['minLength']
				&& strlen( $slug ) <= $item['maxLength']
				&& 1 === preg_match( '/' . $item['pattern'] . '/', $slug );
		};

		$this->assertLessThan( $slugs_schema['minItems'], count( array() ) );
		$this->assertGreaterThan( $slugs_schema['maxItems'], count( range( 1, 101 ) ) );
		$this->assertNotSame( array( 'kid-lake', 'kid-lake' ), array_values( array_unique( array( 'kid-lake', 'kid-lake' ) ) ) );
		$this->assertTrue( $slugs_schema['uniqueItems'] );
		$this->assertTrue( $valid_slug( 'kid-lake' ) );
		$this->assertFalse( $valid_slug( '' ) );
		$this->assertFalse( $valid_slug( 'Kid-Lake' ) );
		$this->assertFalse( $valid_slug( '-kid-lake' ) );
		$this->assertFalse( $valid_slug( 'kid--lake' ) );
		$this->assertFalse( $valid_slug( str_repeat( 'a', 201 ) ) );
	}

	public function test_resolved_and_missing_artists_preserve_order_and_exact_shape(): void {
		$this->addTerm( 10, 'kid-lake', 20 );
		$this->addProfile( 20, 'kid-lake', 'Kid Lake', 10 );

		$result = extrachill_artist_platform_ability_artist_public_projections(
			array( 'schema_version' => '1', 'slugs' => array( 'missing-artist', 'kid-lake' ) )
		);

		$this->assertSame(
			array(
				'schema_version' => '1',
				'items'          => array(
					array( 'slug' => 'missing-artist', 'status' => 'not_found', 'name' => '', 'url' => '' ),
					array( 'slug' => 'kid-lake', 'status' => 'resolved', 'name' => 'Kid Lake', 'url' => 'https://artist.example/artists/kid-lake/' ),
				),
			),
			$result
		);
		$this->assertSame( 4, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_test']['blog_stack'] );
	}

	public function test_missing_and_stale_bindings_do_not_fall_back_by_slug(): void {
		$this->addTerm( 10, 'unbound-artist' );
		$this->addProfile( 20, 'unbound-artist', 'Unbound Artist', 10 );
		$this->addTerm( 11, 'stale-artist', 21 );
		$this->addProfile( 21, 'stale-artist', 'Stale Artist', 99 );

		$result = extrachill_artist_platform_ability_artist_public_projections(
			array( 'schema_version' => '1', 'slugs' => array( 'unbound-artist', 'stale-artist' ) )
		);

		$this->assertSame( array( 'not_found', 'not_found' ), array_column( $result['items'], 'status' ) );
		$this->assertSame( array( '', '' ), array_column( $result['items'], 'name' ) );
		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['ec_test']['blogs'][1]['term_meta'][10] ?? array() );
	}

	public function test_deleted_and_unpublished_profiles_are_not_found(): void {
		$this->addTerm( 10, 'deleted-artist', 20 );
		$this->addTerm( 11, 'draft-artist', 21 );
		$this->addProfile( 21, 'draft-artist', 'Draft Artist', 11, 'draft' );

		$result = extrachill_artist_platform_ability_artist_public_projections(
			array( 'schema_version' => '1', 'slugs' => array( 'deleted-artist', 'draft-artist' ) )
		);

		$this->assertSame( array( 'not_found', 'not_found' ), array_column( $result['items'], 'status' ) );
		$this->assertSame( array( '', '' ), array_column( $result['items'], 'url' ) );
	}

	public function test_owner_site_failure_remains_an_error(): void {
		$GLOBALS['ec_test']['artist_blog_unavailable'] = true;

		$result = extrachill_artist_platform_ability_artist_public_projections(
			array( 'schema_version' => '1', 'slugs' => array( 'kid-lake' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_projection_owner_unavailable', $result->get_error_code() );
	}
}
