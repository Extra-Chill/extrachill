<?php
/**
 * Regression coverage for omitted/null ability input.
 *
 * @package ExtraChill\Users
 */

class Test_Optional_Ability_Input extends WP_UnitTestCase {

	public function test_optional_object_inputs_default_to_empty_arrays_and_validate_null(): void {
		$checked = array();

		foreach ( wp_get_abilities() as $ability ) {
			if ( 'extrachill-users' !== $ability->get_category() ) {
				continue;
			}

			$schema = $ability->get_input_schema();
			if ( 'object' !== ( $schema['type'] ?? null ) || ! empty( $schema['required'] ) ) {
				continue;
			}

			$name = $ability->get_name();
			$this->assertArrayHasKey( 'default', $schema, $name );
			$this->assertSame( array(), $schema['default'], $name );
			// Mirror WP_Ability::execute() without invoking permissions or writes.
			$input = $ability->normalize_input( null );
			$this->assertSame( array(), $input, $name );
			$this->assertTrue( $ability->validate_input( $input ), $name );
			$checked[] = $name;
		}

		$this->assertNotEmpty( $checked, 'Users abilities must be registered for this test.' );
		$this->assertContains( 'extrachill/get-user-settings', $checked );
	}

	public function test_get_user_settings_executes_without_input(): void {
		wp_set_current_user( self::factory()->user->create() );
		try {
			$ability = wp_get_ability( 'extrachill/get-user-settings' );
			$result  = $ability->execute();
			$this->assertNotWPError( $result );
			$this->assertSame( get_current_user_id(), $result['user_id'] );
			$this->assertSame( $result, $ability->execute( null ) );
		} finally {
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Gutenberg's ability client compiles the published input schema with
	 * ajv before running it. An empty PHP `properties` array is published as
	 * JSON `[]`, which ajv rejects as an invalid schema, so the ability can
	 * never run from the browser.
	 */
	public function test_published_input_schemas_have_object_property_maps(): void {
		$checked = array();

		foreach ( wp_get_abilities() as $ability ) {
			if ( 'extrachill-users' !== $ability->get_category() ) {
				continue;
			}

			$name   = $ability->get_name();
			$schema = wp_json_encode( wp_prepare_json_schema_for_client( (array) $ability->get_input_schema() ) );
			foreach ( array( 'properties', 'patternProperties', 'definitions' ) as $keyword ) {
				$this->assertStringNotContainsString( '"' . $keyword . '":[]', $schema, $name );
			}
			$checked[] = $name;
		}

		$this->assertContains( 'extrachill/get-user-settings', $checked );
		$this->assertContains( 'extrachill/get-notification-preferences', $checked );
	}

	public function test_required_object_inputs_still_reject_null(): void {
		$ability = wp_get_ability( 'extrachill/change-user-email' );
		$this->assertWPError( $ability->validate_input( $ability->normalize_input( null ) ) );
	}
}
