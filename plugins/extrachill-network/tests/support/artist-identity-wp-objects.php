<?php
/**
 * Minimal WordPress object doubles for tests/artist-identity-smoke.php.
 *
 * Classes only, so the smoke itself holds only functions.
 *
 * @package ExtraChillNetwork
 */

// phpcs:disable -- Standalone WordPress object doubles.

if ( ! class_exists( 'WP_Post' ) ) {
	/** Post double. */
	class WP_Post {
		/**
		 * @param int    $ID          Post ID.
		 * @param string $post_type   Post type.
		 * @param string $post_status Post status.
		 * @param string $post_name   Post slug.
		 * @param string $post_title  Post title.
		 */
		public function __construct(
			public int $ID,
			public string $post_type,
			public string $post_status,
			public string $post_name,
			public string $post_title
		) {}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/** Term double. */
	class WP_Term {
		/**
		 * @param int    $term_id  Term ID.
		 * @param string $taxonomy Taxonomy.
		 * @param string $slug     Term slug.
		 * @param string $name     Term name.
		 */
		public function __construct(
			public int $term_id,
			public string $taxonomy,
			public string $slug,
			public string $name
		) {}
	}
}

if ( ! class_exists( 'WP_Post_Type' ) ) {
	/** Post type double. */
	class WP_Post_Type {
		/**
		 * @param array|bool $rewrite Rewrite args.
		 */
		public function __construct( public $rewrite ) {}
	}
}
