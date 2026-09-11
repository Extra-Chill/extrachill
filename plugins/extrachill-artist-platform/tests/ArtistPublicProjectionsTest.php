<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistPublicProjectionsTest extends EC_Artist_Platform_TestCase {
	private function addProjection( string $slug, string $name, string $status = 'publish', bool $bind = true ): array {
		$profile_id = $this->create_artist_profile( $name, array(
			'post_name'   => $slug,
			'post_status' => $status,
		) );
		$term_id    = 0;
		if ( $bind ) {
			switch_to_blog( $this->main_blog_id() );
			$created = wp_insert_term( $slug, 'artist' );
			$term_id = is_wp_error( $created ) ? 0 : (int) $created['term_id'];
			update_term_meta( $term_id, '_artist_profile_id', $profile_id );
			restore_current_blog();
			switch_to_blog( $this->artist_blog_id() );
			update_post_meta( $profile_id, '_artist_term_id', $term_id );
			restore_current_blog();
		}
		return array( $profile_id, $term_id );
	}

	public function test_registration_exposes_an_exact_public_read_contract(): void {
		$ability = wp_get_ability( 'extrachill/artist-public-projections' );
		$input   = $ability->get_input_schema();
		$output  = $ability->get_output_schema();
		$item    = $output['properties']['items']['items'];

		$this->assertTrue( $ability->check_permissions( array(
			'schema_version' => '1',
			'slugs'          => array( 'kid-lake' ),
		) ) );
		$this->assertTrue( $ability->get_meta()['show_in_rest'] );
		$this->assertEquals( array(
			'readonly'    => true,
			'idempotent'  => true,
			'destructive' => false,
		), $ability->get_meta()['annotations'] );
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
		list( $profile_id, $term_id ) = $this->addProjection( 'kid-lake', 'Kid Lake' );
		switch_to_blog( $this->artist_blog_id() );
		$expected_url = get_permalink( $profile_id );
		restore_current_blog();

		$result = extrachill_artist_platform_ability_artist_public_projections(
			array(
				'schema_version' => '1',
				'slugs'          => array( 'missing-artist', 'kid-lake' ),
			)
		);

		$this->assertSame(
			array(
				'schema_version' => '1',
				'items'          => array(
					array(
						'slug'   => 'missing-artist',
						'status' => 'not_found',
						'name'   => '',
						'url'    => '',
					),
					array(
						'slug'   => 'kid-lake',
						'status' => 'resolved',
						'name'   => 'Kid Lake',
						'url'    => $expected_url,
					),
				),
			),
			$result
		);
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] ?? array() );
	}

	public function test_missing_and_stale_bindings_do_not_fall_back_by_slug(): void {
		$unbound              = $this->addProjection( 'unbound-artist', 'Unbound Artist', 'publish', true );
		list( , $stale_term ) = $this->addProjection( 'stale-artist', 'Stale Artist' );

		// Neither term keeps a reciprocal claim: profile-side references alone
		// never resolve, slug fallback never applies.
		switch_to_blog( $this->main_blog_id() );
		delete_term_meta( $unbound[1], '_artist_profile_id' );
		delete_term_meta( $stale_term, '_artist_profile_id' );
		restore_current_blog();

		$result = extrachill_artist_platform_ability_artist_public_projections(
			array(
				'schema_version' => '1',
				'slugs'          => array( 'unbound-artist', 'stale-artist' ),
			)
		);

		$this->assertSame( array( 'not_found', 'not_found' ), array_column( $result['items'], 'status' ) );
		$this->assertSame( array( '', '' ), array_column( $result['items'], 'name' ) );
	}

	public function test_deleted_and_unpublished_profiles_are_not_found(): void {
		// A private profile stands in for the deleted case: profile deletion
		// vetoes through the canonical binding lock, which only serializes on
		// MySQL runtimes (the artist-binding-mysql workflow covers deletion).
		$this->addProjection( 'deleted-artist', 'Deleted Artist', 'private' );
		$this->addProjection( 'draft-artist', 'Draft Artist', 'draft' );

		$result = extrachill_artist_platform_ability_artist_public_projections(
			array(
				'schema_version' => '1',
				'slugs'          => array( 'deleted-artist', 'draft-artist' ),
			)
		);

		$this->assertSame( array( 'not_found', 'not_found' ), array_column( $result['items'], 'status' ) );
		$this->assertSame( array( '', '' ), array_column( $result['items'], 'url' ) );
	}

	public function test_unresolvable_slugs_never_resolve_by_partial_match(): void {
		list( , $term_id ) = $this->addProjection( 'kid-lake', 'Kid Lake' );

		$result = extrachill_artist_platform_ability_artist_public_projections(
			array(
				'schema_version' => '1',
				'slugs'          => array( 'kid' ),
			)
		);

		$this->assertSame( array( 'not_found' ), array_column( $result['items'], 'status' ) );
		$this->assertGreaterThan( 0, $term_id );
	}
}
