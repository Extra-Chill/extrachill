<?php
/**
 * WP_Term shadow class for tests that run without the managed WordPress runtime.
 *
 * Extracted from the inline prelude of AccountMarketTest so the stubs file
 * stays function-only and each test file keeps a single OO declaration.
 * Guarded: when the managed suite loads real WordPress, this is skipped.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id;
		public string $name;
		public string $slug;
		public function __construct( $term ) {
			foreach ( get_object_vars( $term ) as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}
