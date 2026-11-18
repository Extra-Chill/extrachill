<?php
/**
 * Real-registry output validation for the venue profile abilities (extrachill#1693).
 *
 * Data Machine Events owns the venue profile document. Its editable fields
 * include `instagram`, but the strict `additionalProperties: false` output
 * schema did not list it, so `extrachill/get-venue-profile` failed with
 * `ability_invalid_output: instagram is not a valid property of Object`.
 * These tests validate profile documents against the registered schema with
 * the same core validator WP_Ability::validate_output() uses.
 *
 * @package ExtraChillEvents\Tests
 */

/** Covers every field the canonical venue profile document can carry. */
final class VenueProfileOutputSchemaManagedTest extends WP_UnitTestCase {

	/** A venue profile carrying a normalized Instagram handle validates. */
	public function test_profile_with_instagram_validates(): void {
		$this->assertValid( $this->profile( array( 'instagram' => 'lofi.brewing_co' ) ) );
	}

	/** A cleared Instagram handle (empty string) validates. */
	public function test_profile_with_empty_instagram_validates(): void {
		$this->assertValid( $this->profile( array( 'instagram' => '' ) ) );
	}

	/** Profiles from owners that predate the field still validate. */
	public function test_profile_without_instagram_validates(): void {
		$this->assertValid( $this->profile() );
	}

	/** The field is a string handle, never null or another type. */
	public function test_non_string_instagram_is_rejected(): void {
		$this->assertWPError( $this->validate( $this->profile( array( 'instagram' => null ) ) ) );
		$this->assertWPError( $this->validate( $this->profile( array( 'instagram' => array( 'lofi' ) ) ) ) );
	}

	/** Unknown fields remain rejected, so the schema stays an exact contract. */
	public function test_unknown_profile_fields_are_still_rejected(): void {
		$this->assertWPError( $this->validate( $this->profile( array( 'tiktok' => 'lofi' ) ) ) );
	}

	/** The update ability returns the same profile document shape. */
	public function test_update_result_profile_with_instagram_validates(): void {
		$profile = $this->profile( array( 'instagram' => 'lofibrewing' ) );
		$result  = array(
			'success'        => true,
			'term_id'        => $profile['term_id'],
			'updated_fields' => array( 'instagram' ),
			'revision'       => $profile['revision'],
			'profile'        => $profile,
		);

		$schema = $this->ability( 'extrachill/update-venue-profile' )->get_output_schema();
		$this->assertTrue( rest_validate_value_from_schema( $result, $schema, 'output' ) );
	}

	/** The update ability accepts an Instagram change for DME to normalize. */
	public function test_update_input_accepts_instagram(): void {
		$schema = $this->ability( 'extrachill/update-venue-profile' )->get_input_schema();
		$input  = array(
			'venue_term_id'     => 1524,
			'expected_revision' => str_repeat( 'a', 64 ),
			'profile'           => array( 'instagram' => 'https://www.instagram.com/lofibrewing/' ),
		);

		$this->assertTrue( rest_validate_value_from_schema( $input, $schema, 'input' ) );
	}

	/** Every DME editable field and owner-built key is part of the output schema. */
	public function test_schema_lists_every_owner_editable_field(): void {
		if ( ! class_exists( \DataMachineEvents\Core\VenueProfileMutations::class ) ) {
			$this->markTestSkipped( 'Data Machine Events is not loaded.' );
		}

		$editable   = ( new ReflectionClassConstant( \DataMachineEvents\Core\VenueProfileMutations::class, 'EDITABLE_FIELDS' ) )->getValue();
		$properties = array_keys( $this->schema()['properties'] );
		foreach ( array_merge( array( 'term_id', 'name', 'description', 'logo', 'revision' ), $editable ) as $field ) {
			$this->assertContains( $field, $properties, $field . ' must be declared in the venue profile output schema.' );
		}
	}

	/** A real DME profile document for a venue with Instagram validates. */
	public function test_real_owner_profile_document_validates(): void {
		if ( ! function_exists( 'data_machine_events_get_venue_profile' ) || ! class_exists( \DataMachineEvents\Core\Venue_Taxonomy::class ) ) {
			$this->markTestSkipped( 'Data Machine Events is not loaded.' );
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			register_taxonomy( 'venue', 'data_machine_events', array( 'public' => true ) );
		}

		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'venue',
				'name'     => 'Schema Contract Hall',
			)
		);
		foreach ( \DataMachineEvents\Core\Venue_Taxonomy::$meta_fields as $field => $meta_key ) {
			if ( 'logo_attachment_id' !== $field ) {
				update_term_meta( $term_id, $meta_key, 'instagram' === $field ? 'schemacontracthall' : 'Value' );
			}
		}

		$profile = data_machine_events_get_venue_profile( $term_id );

		$this->assertNotWPError( $profile );
		$this->assertSame( 'schemacontracthall', $profile['instagram'] ?? null );
		$this->assertValid( $profile );
	}

	/**
	 * Assert one document is valid against the get-venue-profile output schema.
	 *
	 * @param array $profile Profile document.
	 */
	private function assertValid( array $profile ): void {
		$this->assertTrue( $this->validate( $profile ) );
	}

	/**
	 * Validate one document the way WP_Ability::validate_output() does.
	 *
	 * @param array $profile Profile document.
	 * @return true|WP_Error
	 */
	private function validate( array $profile ) {
		return rest_validate_value_from_schema( $profile, $this->schema(), 'output' );
	}

	/** Return the registered get-venue-profile output schema. */
	private function schema(): array {
		return $this->ability( 'extrachill/get-venue-profile' )->get_output_schema();
	}

	/**
	 * Resolve one registered ability through the real registry.
	 *
	 * @param string $name Ability name.
	 */
	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, $name . ' must be registered.' );

		return $ability;
	}

	/**
	 * Build a complete profile document in the DME read() shape.
	 *
	 * @param array $overrides Fields to add or replace.
	 */
	private function profile( array $overrides = array() ): array {
		return array_merge(
			array(
				'term_id'            => 1524,
				'name'               => 'Lo-Fi Brewing',
				'description'        => '',
				'address'            => '2610 Commerce St',
				'city'               => 'Charleston',
				'state'              => 'SC',
				'zip'                => '29405',
				'country'            => 'US',
				'phone'              => '',
				'website'            => 'https://lofibrewing.com',
				'ticketing_url'      => '',
				'capacity'           => '',
				'logo_attachment_id' => 0,
				'logo'               => null,
				'revision'           => str_repeat( 'a', 64 ),
			),
			$overrides
		);
	}
}
