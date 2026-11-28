<?php
/**
 * Centralized Taxonomy Badges Template
 *
 * Single source of truth for all taxonomy badge display logic across the theme.
 * Supports categories, locations, festivals, artists, and venues with consistent styling.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

/**
 * Display taxonomy badges for a post
 *
 * Dynamically displays badges for ANY taxonomy assigned to the post.
 * Maintains CSS compatibility with existing badge-colors.css styles.
 *
 * @param int|null $post_id Post ID. Defaults to current post.
 * @param array    $args Configuration arguments.
 */
function extrachill_display_taxonomy_badges( $post_id = null, $args = array() ) {
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}

	if ( ! $post_id ) {
		return;
	}

	// Default arguments
	$defaults = array(
		'wrapper_class' => 'taxonomy-badges',
		'show_wrapper'  => true,
		'wrapper_style' => '',
	);

	$args = wp_parse_args( $args, $defaults );

	$badges_html = '';

	// Check if this is a cross-site search result
	$origin_site_id  = isset( $GLOBALS['post']->_origin_site_id ) ? $GLOBALS['post']->_origin_site_id : null;
	$current_site_id = get_current_blog_id();
	$is_cross_site   = $origin_site_id && $origin_site_id !== $current_site_id;

	// For cross-site results, switch to origin site FIRST to get correct taxonomies
	if ( $origin_site_id ) {
		switch_to_blog( $origin_site_id );
	}

	try {
		// Get ALL taxonomies for this post type (from origin site if cross-site)
		$post_type  = get_post_type( $post_id );
		$taxonomies = $post_type ? get_object_taxonomies( $post_type ) : array();

		// Define display order for taxonomy badges
		$taxonomy_order = array(
			'category' => 1,
			'location' => 2,
			'festival' => 3,
			'venue'    => 4,
			'artist'   => 5,
			'post_tag' => 99,
		);

		// Sort taxonomies by defined order (unlisted = 50, before post_tag)
		usort(
			$taxonomies,
			function ( $a, $b ) use ( $taxonomy_order ) {
				$order_a = isset( $taxonomy_order[ $a ] ) ? $taxonomy_order[ $a ] : 50;
				$order_b = isset( $taxonomy_order[ $b ] ) ? $taxonomy_order[ $b ] : 50;
				return $order_a - $order_b;
			}
		);

		// Exclude internal/system taxonomies from badge system
		$excluded_taxonomies = array(
			'author',
			'product_type',           // WooCommerce: simple, variable, grouped, external
			'product_visibility',     // WooCommerce: featured, rated-1, rated-2, etc.
			'product_shipping_class', // WooCommerce: shipping classes
		);

		// Process each taxonomy dynamically
		$cross_site_links = array();
		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy, $excluded_taxonomies, true ) ) {
				continue;
			}

				// Use stored taxonomies if available (for search results), otherwise fetch normally
			if ( isset( $GLOBALS['post']->taxonomies ) && isset( $GLOBALS['post']->taxonomies[ $taxonomy ] ) ) {
				$term_ids = $GLOBALS['post']->taxonomies[ $taxonomy ];
				$terms    = array();

				foreach ( $term_ids as $term_name => $term_id ) {
					$term = get_term( $term_id );
					if ( $term && ! is_wp_error( $term ) ) {
						// For cross-site, manually construct the archive URL
						if ( $is_cross_site ) {
							$origin_site_url = get_site_url( $origin_site_id );
							$term_slug       = $term->slug;

							// Get taxonomy object to determine archive path
							$tax_obj      = get_taxonomy( $taxonomy );
							$rewrite_slug = $tax_obj && isset( $tax_obj->rewrite['slug'] ) ? $tax_obj->rewrite['slug'] : $taxonomy;

							// Manually construct archive URL: https://site.com/taxonomy-slug/term-slug/
							$cross_site_links[ $term->term_id ] = trailingslashit( $origin_site_url ) . trailingslashit( $rewrite_slug ) . $term_slug . '/';
						}

						$terms[] = $term;
					}
				}
			} else {
				$terms = get_the_terms( $post_id, $taxonomy );
			}

				// Skip if no terms assigned
			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}

			// Limit to maximum 3 terms per taxonomy. Sort-enabled taxonomies
			// lead with their stored per-object order (core term_order).
			if ( count( $terms ) > 3 ) {
				$tax_object  = get_taxonomy( $taxonomy );
				$term_orders = ( $tax_object && ! empty( $tax_object->sort ) ) ? extrachill_get_object_term_orders( $post_id, $taxonomy ) : array();
				$terms       = extrachill_select_badge_terms( $terms, $term_orders, 3 );
			}

			foreach ( $terms as $term ) {
				$skip_term = apply_filters( 'extrachill_taxonomy_badges_skip_term', false, $term, $taxonomy, $post_id );
				if ( $skip_term ) {
					continue;
				}

				$term_slug   = sanitize_html_class( $term->slug );
				$badge_class = $taxonomy . '-badge';

				// Add taxonomy-specific class modifier for categories (maintains CSS compatibility)
				if ( 'category' === $taxonomy ) {
					$badge_class .= ' category-' . $term_slug . '-badge';
				} else {
					$badge_class .= ' ' . $taxonomy . '-' . $term_slug;
				}

				// Use manually constructed cross-site link if available, otherwise use get_term_link()
				$term_link = isset( $cross_site_links[ $term->term_id ] ) ? $cross_site_links[ $term->term_id ] : get_term_link( $term );
				if ( ! is_string( $term_link ) ) {
					continue;
				}

				$badge_label = $term->name;

				$badges_html .= sprintf(
					'<a href="%s" class="taxonomy-badge %s">%s</a>',
					esc_url( $term_link ),
					esc_attr( $badge_class ),
					esc_html( $badge_label )
				);
			}
		}
	} finally {
		// Restore current blog if we switched
		if ( $origin_site_id ) {
			restore_current_blog();
		}
	}

	// Output badges
	if ( $badges_html ) {
		if ( $args['show_wrapper'] ) {
			echo '<div class="' . esc_attr( $args['wrapper_class'] ) . '"';
			if ( $args['wrapper_style'] ) {
				echo ' style="' . esc_attr( $args['wrapper_style'] ) . '"';
			}
			echo '>';
			echo wp_kses_post( $badges_html );
			echo '</div>';
		} else {
			echo wp_kses_post( $badges_html );
		}
	}
}

/**
 * Read stored per-object term order for a sort-enabled taxonomy.
 *
 * Core persists term_relationships.term_order when a taxonomy registers
 * with 'sort' => true but does not expose it on WP_Term.
 *
 * @param int    $post_id  Object ID.
 * @param string $taxonomy Taxonomy slug.
 * @return array<int,int> Map of term_taxonomy_id => term_order.
 */
function extrachill_get_object_term_orders( $post_id, $taxonomy ) {
	global $wpdb;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- term_order is not exposed by the term APIs; read per render of a capped badge list.
		$wpdb->prepare(
			"SELECT tr.term_taxonomy_id, tr.term_order FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s",
			(int) $post_id,
			$taxonomy
		)
	);

	$orders = array();
	foreach ( (array) $rows as $row ) {
		$orders[ (int) $row->term_taxonomy_id ] = (int) $row->term_order;
	}
	return $orders;
}

/**
 * Select the badge terms to display.
 *
 * Terms with a stored order (> 0) lead in that order. Terms without one
 * (legacy relationships or non-sort taxonomies) follow, by post count
 * descending, then name, which matches the historical selection.
 *
 * @param WP_Term[]      $terms       Terms assigned to the object.
 * @param array<int,int> $term_orders Map of term_taxonomy_id => term_order.
 * @param int            $limit       Maximum terms to return.
 * @return WP_Term[]
 */
function extrachill_select_badge_terms( array $terms, array $term_orders, $limit = 3 ) {
	usort(
		$terms,
		function ( $a, $b ) use ( $term_orders ) {
			$order_a = (int) ( $term_orders[ (int) $a->term_taxonomy_id ] ?? 0 );
			$order_b = (int) ( $term_orders[ (int) $b->term_taxonomy_id ] ?? 0 );
			$order_a = $order_a > 0 ? $order_a : PHP_INT_MAX;
			$order_b = $order_b > 0 ? $order_b : PHP_INT_MAX;
			if ( $order_a !== $order_b ) {
				return $order_a <=> $order_b;
			}
			if ( $a->count !== $b->count ) {
				return $b->count - $a->count;
			}
			return strcmp( $a->name, $b->name );
		}
	);

	return array_slice( $terms, 0, (int) $limit );
}
