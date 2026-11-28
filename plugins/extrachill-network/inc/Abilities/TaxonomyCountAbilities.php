<?php
/**
 * Taxonomy Count Abilities
 *
 * Generic primitive for counting published posts per taxonomy term on any site.
 * Used by cross-site linking, homepage badges, and mobile app.
 *
 * This is a network-level concern: "how many published posts does term X have
 * on site Y?" The ability handles switch_to_blog internally when a site key
 * is provided, so callers don't need to manage blog context.
 *
 * @package ExtraChillNetwork\Abilities
 */

namespace ExtraChillNetwork\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TaxonomyCountAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		// Category is registered once via inc/Abilities/CategoryRegistration.php.
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/taxonomy-post-counts',
			array(
				'label'               => __( 'Taxonomy Post Counts', 'extrachill-network' ),
				'description'         => __( 'Count published posts per taxonomy term on a given site. Returns terms sorted by post count descending.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'taxonomy', 'site' ),
					'properties' => array(
						'taxonomy'  => array(
							'type'        => 'string',
							'description' => __( 'Taxonomy slug to count.', 'extrachill-network' ),
						),
						'site'      => array(
							'type'        => 'string',
							'description' => __( 'Site key (e.g. "wire", "main", "shop"). Uses ec_get_blog_id().', 'extrachill-network' ),
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Post type to count. If omitted, uses all post types registered for the taxonomy.', 'extrachill-network' ),
						),
						'number'    => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Maximum terms to return. Omit or use 0 for no limit. Ignored when slug is provided.', 'extrachill-network' ),
						),
						'offset'    => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Number of matching terms to skip. Defaults to 0. Ignored when slug is provided.', 'extrachill-network' ),
						),
						'search'    => array(
							'type'        => 'string',
							'description' => __( 'Only include terms whose names contain this text. Ignored when slug is provided.', 'extrachill-network' ),
						),
						'min_count' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Minimum published post count (inclusive). Defaults to 1; only terms with published posts are listed. Ignored when slug is provided.', 'extrachill-network' ),
						),
						'slug'      => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Optional term slug. When provided, the result contains only that single term (still returned under the "terms" array). Omit for the full bulk listing.', 'extrachill-network' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'site'     => array( 'type' => 'string' ),
						'taxonomy' => array( 'type' => 'string' ),
						'terms'    => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'term_id' => array( 'type' => 'integer' ),
									'name'    => array( 'type' => 'string' ),
									'slug'    => array( 'type' => 'string' ),
									'count'   => array( 'type' => 'integer' ),
									'url'     => array( 'type' => 'string' ),
								),
							),
						),
						'total'    => array(
							'type'        => 'integer',
							'description' => __( 'Matching terms before pagination.', 'extrachill-network' ),
						),
						'has_more' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetTaxonomyPostCounts' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	/**
	 * Execute taxonomy-post-counts ability.
	 *
	 * Switches to the target site, runs a single SQL query counting published
	 * posts per term, and returns structured results.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Term counts sorted by post count descending, or an error.
	 */
	public function executeGetTaxonomyPostCounts( array $input ): array|\WP_Error {
		$taxonomy  = $input['taxonomy'];
		$site_key  = $input['site'];
		$post_type = $input['post_type'] ?? null;
		$slug      = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';

		$blog_id = ec_get_blog_id( $site_key );
		if ( ! $blog_id ) {
			return new \WP_Error(
				'unknown_site',
				/* translators: %s: site key */
				sprintf( __( 'Unknown site key "%s".', 'extrachill-network' ), $site_key ),
				array( 'status' => 400 )
			);
		}

		switch_to_blog( $blog_id );
		try {
			if ( '' !== $slug ) {
				return $this->computeSingleCount( $taxonomy, $site_key, $post_type, $slug );
			}

			return $this->computeCounts( $taxonomy, $site_key, $post_type, $input );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Compute the published-post count for a single taxonomy term by slug.
	 *
	 * Mirrors computeCounts() but scopes to one term so cross-site link
	 * resolution can answer "does this exact term have content here?" without
	 * pulling the full bulk listing or making an HTTP loopback call. Must be
	 * called in the correct blog context (after switch_to_blog).
	 *
	 * @param string      $taxonomy  Taxonomy slug.
	 * @param string      $site_key  Site key for the response.
	 * @param string|null $post_type Optional explicit post type.
	 * @param string      $slug      Term slug to count.
	 * @return array|\WP_Error Structured result (same shape as computeCounts), or an error.
	 */
	private function computeSingleCount( string $taxonomy, string $site_key, ?string $post_type, string $slug ): array|\WP_Error {
		$tax_obj = get_taxonomy( $taxonomy );
		if ( false === $tax_obj ) {
			return new \WP_Error(
				'taxonomy_not_found',
				/* translators: %s: taxonomy slug */
				sprintf( __( 'Taxonomy "%s" does not exist on this site.', 'extrachill-network' ), $taxonomy ),
				array( 'status' => 400 )
			);
		}

		$empty = array(
			'site'     => $site_key,
			'taxonomy' => $taxonomy,
			'terms'    => array(),
			'total'    => 0,
			'has_more' => false,
		);

		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( false === $term ) {
			return $empty;
		}

		if ( $post_type ) {
			$post_types = array( $post_type );
		} else {
			$post_types = $tax_obj->object_type;
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				),
			)
		);

		if ( $query->found_posts < 1 ) {
			return $empty;
		}

		$url = get_term_link( $term );
		if ( is_wp_error( $url ) ) {
			return $empty;
		}

		return array(
			'site'     => $site_key,
			'taxonomy' => $taxonomy,
			'terms'    => array(
				array(
					'term_id' => (int) $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
					'count'   => (int) $query->found_posts,
					'url'     => $url,
				),
			),
			'total'    => 1,
			'has_more' => false,
		);
	}

	/**
	 * Compute post counts per term using a single SQL query.
	 *
	 * Must be called in the correct blog context (after switch_to_blog).
	 *
	 * @param string      $taxonomy  Taxonomy slug.
	 * @param string      $site_key  Site key for the response.
	 * @param string|null $post_type Optional explicit post type.
	 * @param array       $input     Optional bulk filters and pagination.
	 * @return array|\WP_Error Structured result, or an error.
	 */
	private function computeCounts( string $taxonomy, string $site_key, ?string $post_type, array $input ): array|\WP_Error {
		$tax_obj = get_taxonomy( $taxonomy );
		if ( false === $tax_obj ) {
			return new \WP_Error(
				'taxonomy_not_found',
				/* translators: %s: taxonomy slug */
				sprintf( __( 'Taxonomy "%s" does not exist on this site.', 'extrachill-network' ), $taxonomy ),
				array( 'status' => 400 )
			);
		}

		// Resolve post types.
		if ( $post_type ) {
			$post_types = array( $post_type );
		} else {
			$post_types = $tax_obj->object_type;
		}

		global $wpdb;

		$query_args   = array_merge( array( $taxonomy ), $post_types );
		$query_args[] = '%' . $wpdb->esc_like( $input['search'] ?? '' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT p.ID) AS post_count
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
				WHERE tt.taxonomy = %s
				AND p.post_type IN (" . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ")
				AND p.post_status = 'publish'
				AND t.name LIKE %s
				GROUP BY t.term_id
				ORDER BY post_count DESC, t.term_id ASC",
				$query_args
			)
		);

		if ( empty( $rows ) ) {
			return array(
				'site'     => $site_key,
				'taxonomy' => $taxonomy,
				'terms'    => array(),
				'total'    => 0,
				'has_more' => false,
			);
		}

		$terms     = array();
		$min_count = max( 1, (int) ( $input['min_count'] ?? 1 ) );
		foreach ( $rows as $row ) {
			if ( (int) $row->post_count < $min_count ) {
				continue;
			}

			$url = get_term_link( (int) $row->term_id, $taxonomy );
			if ( is_wp_error( $url ) ) {
				continue;
			}

			$terms[] = array(
				'term_id' => (int) $row->term_id,
				'name'    => $row->name,
				'slug'    => $row->slug,
				'count'   => (int) $row->post_count,
				'url'     => $url,
			);
		}

		// Page after filtering invalid links so total describes the usable listing.
		$total  = count( $terms );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$number = max( 0, (int) ( $input['number'] ?? 0 ) );
		$terms  = array_slice( $terms, $offset, $number > 0 ? $number : null );

		return array(
			'site'     => $site_key,
			'taxonomy' => $taxonomy,
			'terms'    => $terms,
			'total'    => $total,
			'has_more' => $offset + count( $terms ) < $total,
		);
	}
}
