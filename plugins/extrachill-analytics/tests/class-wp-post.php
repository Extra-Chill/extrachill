<?php
/**
 * Minimal WP_Post fixture.
 *
 * @package ExtraChill\Analytics
 */

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal post fixture for classifier tests.
	 */
	class WP_Post {
		/**
		 * Post ID.
		 *
		 * @var int
		 */
		public $ID;

		/**
		 * Post title.
		 *
		 * @var string
		 */
		public $post_title = '';

		/**
		 * Post slug.
		 *
		 * @var string
		 */
		public $post_name = '';

		/**
		 * Post status.
		 *
		 * @var string
		 */
		public $post_status = 'publish';
	}
}
