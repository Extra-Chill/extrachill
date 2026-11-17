<?php
/**
 * The multisite-search ability only ever searches published content.
 *
 * The ability is REST-visible behind the `read` capability, so a
 * caller-supplied post_status must never reach the network query.
 *
 * @package ExtraChill\Search
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

class MultisiteSearchAbilityStatusTest extends WP_UnitTestCase {

	public function test_default_status_is_publish() {
		$args = extrachill_multisite_search_ability_args( array( 'search_term' => 'phish' ) );

		$this->assertSame( array( 'publish' ), $args['post_status'] );
	}

	public function test_non_public_statuses_are_never_forwarded() {
		$args = extrachill_multisite_search_ability_args(
			array(
				'search_term' => 'phish',
				'post_status' => array( 'draft', 'private', 'pending', 'future' ),
			)
		);

		$this->assertSame( array( 'publish' ), $args['post_status'] );
	}

	public function test_other_inputs_still_pass_through() {
		$args = extrachill_multisite_search_ability_args(
			array(
				'search_term'  => 'phish',
				'limit'        => 5,
				'offset'       => 10,
				'order'        => 'ASC',
				'return_count' => true,
			)
		);

		$this->assertSame( 5, $args['limit'] );
		$this->assertSame( 10, $args['offset'] );
		$this->assertSame( 'ASC', $args['order'] );
		$this->assertTrue( $args['return_count'] );
	}

	public function test_schema_only_allows_publish() {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/multisite-search' ) : null;
		if ( ! $ability ) {
			$this->markTestSkipped( 'Abilities API unavailable in this environment.' );
		}

		$schema = $ability->get_input_schema();
		$this->assertSame( array( 'publish' ), $schema['properties']['post_status']['items']['enum'] );
	}
}
