<?php
/**
 * Tests for the docs-info route post-type support collection.
 *
 * @package ExtraChill\API\Tests
 */

/**
 * Regression coverage for issue #103: the docs route must read post-type
 * supports via the canonical core API (get_all_post_type_supports()) rather
 * than the transient WP_Post_Type::$supports property, which core unsets
 * after register_post_type() runs.
 */
class Docs_Info_Post_Type_SupportsTest extends WP_UnitTestCase {

	/**
	 * Clean up post types registered during a test so they cannot leak into
	 * other test classes sharing the process.
	 */
	public function tear_down() {
		foreach ( array( 'ec_doc_sup', 'ec_doc_nosup' ) as $pt ) {
			if ( post_type_exists( $pt ) ) {
				unregister_post_type( $pt );
			}
		}

		parent::tear_down();
	}

	/**
	 * The collect helper should return feature names (not undefined-property
	 * values) for a post type that declares supports.
	 */
	public function test_supports_returned_as_feature_names_for_supported_type() {
		register_post_type(
			'ec_doc_sup',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			)
		);

		$data = extrachill_api_docs_info_collect_post_types();

		$this->assertArrayHasKey( 'ec_doc_sup', $data );

		$supports = $data['ec_doc_sup']['supports'];
		$this->assertIsArray( $supports );

		// Each entry must be a feature string, not a boolean/integer leftover
		// from reading the unset $supports property.
		foreach ( $supports as $feature ) {
			$this->assertIsString( $feature );
		}

		$this->assertContains( 'title', $supports );
		$this->assertContains( 'editor', $supports );
		$this->assertContains( 'thumbnail', $supports );
		$this->assertContains( 'excerpt', $supports );
	}

	/**
	 * A post type registered with supports => false should produce an empty
	 * list, not an undefined-property warning.
	 */
	public function test_supports_empty_when_none_declared() {
		register_post_type(
			'ec_doc_nosup',
			array(
				'public'   => true,
				'supports' => false,
			)
		);

		$data = extrachill_api_docs_info_collect_post_types();

		$this->assertArrayHasKey( 'ec_doc_nosup', $data );
		$this->assertSame( array(), $data['ec_doc_nosup']['supports'] );
	}

	/**
	 * Core built-in public post types should report their registered supports.
	 */
	public function test_supports_populated_for_core_post_type() {
		$data = extrachill_api_docs_info_collect_post_types();

		$this->assertArrayHasKey( 'post', $data );
		$this->assertIsArray( $data['post']['supports'] );
		$this->assertContains( 'title', $data['post']['supports'] );
		$this->assertContains( 'editor', $data['post']['supports'] );
	}
}
