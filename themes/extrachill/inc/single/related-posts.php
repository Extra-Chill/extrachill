<?php
/**
 * Related Posts
 *
 * Default related-posts display for singular posts (taxonomy term + 1-hour
 * transient cache), plus the shared related-posts section renderer used by
 * this theme and by network plugins.
 *
 * Plugins that need custom related-content queries for their post types
 * return true from the `extrachill_override_related_posts_display` filter,
 * render on the `extrachill_custom_related_posts_display` action, and feed
 * their already-resolved data through extrachill_render_related_tax_section()
 * instead of forking the card markup. Plugins extend via hooks, not template
 * forks.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

/**
 * Filter IDs against the request-level displayed-posts registry.
 *
 * A single page can render multiple related-posts sections (one per
 * taxonomy). This shared registry keeps the same post from appearing in more
 * than one section per request. The shared renderer applies it to every item
 * that carries an `id`, so external callers inherit the guard instead of
 * reimplementing it.
 *
 * @param int[] $post_ids Candidate post IDs, in display order.
 * @return int[] IDs not yet displayed, in order. Each returned ID is marked as displayed.
 */
function extrachill_related_posts_filter_displayed( array $post_ids ) {
	static $displayed_posts = array();

	$fresh_ids = array();
	foreach ( $post_ids as $post_id ) {
		if ( in_array( $post_id, $displayed_posts, true ) ) {
			continue;
		}
		$displayed_posts[] = $post_id;
		$fresh_ids[]       = $post_id;
	}

	return $fresh_ids;
}

/**
 * Render one related-posts card from prepared data.
 *
 * Two generic layouts are supported:
 *
 * - `link` (default): the whole card is a single link. Legacy theme card.
 * - `block`: a block container with a linked title heading, for callers whose
 *   cards carry richer meta (badges, date rows, action buttons).
 *
 * Trusted fields (`title`, `thumb_html`, `badges_html`, `meta_html`) are
 * emitted as-is. Callers must pass template-tag output (get_the_title(),
 * get_the_post_thumbnail()) or pre-escaped markup, matching the_title() /
 * the_post_thumbnail() semantics. `permalink` is escaped here.
 *
 * @param array $item {
 *     Card data.
 *
 *     @type string $layout      Optional. 'link' (default) or 'block'.
 *     @type string $permalink   Raw URL for the card link(s). Escaped internally.
 *     @type string $title       Trusted title markup, as produced by get_the_title().
 *     @type string $thumb_html  Optional. Trusted inner thumbnail markup (the <img> only).
 *                               Empty string omits the thumbnail.
 *     @type string $badges_html Optional. Trusted markup rendered between thumbnail and
 *                               title. Block layout only.
 *     @type string $meta_html   Trusted meta content. Link layout: plain inline text.
 *                               Block layout: full inner markup of the meta container.
 * }
 * @return string Card HTML.
 */
function extrachill_render_related_tax_card( array $item ) {
	$layout      = isset( $item['layout'] ) ? (string) $item['layout'] : 'link';
	$permalink   = isset( $item['permalink'] ) ? (string) $item['permalink'] : '';
	$title       = isset( $item['title'] ) ? (string) $item['title'] : '';
	$thumb_html  = isset( $item['thumb_html'] ) ? (string) $item['thumb_html'] : '';
	$badges_html = isset( $item['badges_html'] ) ? (string) $item['badges_html'] : '';
	$meta_html   = isset( $item['meta_html'] ) ? (string) $item['meta_html'] : '';

	if ( 'block' === $layout ) {
		$t5 = "\t\t\t\t\t";
		$t6 = "\t\t\t\t\t\t";
		$t7 = "\t\t\t\t\t\t\t";
		$t8 = "\t\t\t\t\t\t\t\t";
		$t9 = "\t\t\t\t\t\t\t\t\t";

		$card = $t5 . '<div class="related-tax-card">' . "\n" . $t6;
		if ( '' !== $thumb_html ) {
			$card .= $t7 . '<div class="related-tax-thumb">' . "\n" . $t8 . '<a href="' . esc_url( $permalink ) . '">' . "\n" . $t9 . $thumb_html . "\n" . $t8 . '</a>' . "\n" . $t7 . '</div>' . "\n" . $t6;
		}
		$card .= $t6 . "\n";
		$card .= $t6 . $badges_html;
		$card .= $t6 . '<h4 class="related-tax-title">' . "\n";
		$card .= $t7 . '<a href="' . esc_url( $permalink ) . '">' . $title . '</a>' . "\n";
		$card .= $t6 . '</h4>' . "\n";
		$card .= $t6 . "\n";
		$card .= $t6 . '<div class="related-tax-meta">' . "\n";
		$card .= $meta_html;
		$card .= $t6 . '</div>' . "\n";
		$card .= $t5 . '</div>' . "\n";
		$card .= $t5;
		return $card;
	}

	$t10 = "\t\t\t\t\t\t\t\t\t\t";
	$t12 = "\t\t\t\t\t\t\t\t\t\t\t\t";
	$t14 = "\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

	$card = $t10 . '<a href="' . esc_url( $permalink ) . '" class="related-tax-card">' . "\n" . $t12;
	if ( '' !== $thumb_html ) {
		$card .= $t14 . '<span class="related-tax-thumb">' . $thumb_html . '</span>' . "\n" . $t12;
	}
	$card .= $t12 . '<span class="related-tax-title">' . $title . '</span>' . "\n" . $t12;
	$card .= '<span class="related-tax-meta">' . $meta_html . '</span>' . "\n" . $t10;
	$card .= '</a>' . "\n" . $t10;
	return $card;
}

/**
 * Render a related-posts section (heading + card grid) from prepared data.
 *
 * Public, theme-owned affordance for the whole network: any plugin or site
 * can feed already-resolved data and receive the canonical related-posts
 * markup without forking it. This function never queries; callers own data
 * resolution. Items that carry an `id` are deduplicated against the
 * request-level displayed-posts registry (see
 * extrachill_related_posts_filter_displayed()).
 *
 * @param array $args {
 *     Section data.
 *
 *     @type string $heading_prefix Optional. Plain-text heading prefix before the term
 *                                   link, e.g. 'More from '. Escaped internally.
 *     @type string $term_link      Term archive URL. Escaped internally.
 *     @type string $term_name      Trusted term-name markup, as produced by
 *                                   esc_html( $term->name ).
 *     @type array  $items          Card items, each shaped like
 *                                   extrachill_render_related_tax_card()'s $item,
 *                                   plus an optional `id` (int) used for
 *                                   cross-section deduplication.
 * }
 * @return string Section HTML, or '' when no renderable items remain.
 */
function extrachill_render_related_tax_section( array $args ) {
	$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();

	$identified_ids = array();
	foreach ( $items as $item ) {
		if ( isset( $item['id'] ) ) {
			$identified_ids[] = (int) $item['id'];
		}
	}

	if ( ! empty( $identified_ids ) ) {
		$fresh_ids      = extrachill_related_posts_filter_displayed( $identified_ids );
		$fresh_id_set   = array_flip( $fresh_ids );
		$filtered_items = array();
		foreach ( $items as $item ) {
			if ( ! isset( $item['id'] ) || isset( $fresh_id_set[ (int) $item['id'] ] ) ) {
				$filtered_items[] = $item;
			}
		}
		$items = $filtered_items;
	}

	if ( empty( $items ) ) {
		return '';
	}

	$heading_prefix = isset( $args['heading_prefix'] ) ? (string) $args['heading_prefix'] : '';
	$term_link      = isset( $args['term_link'] ) ? (string) $args['term_link'] : '';
	$term_name      = isset( $args['term_name'] ) ? (string) $args['term_name'] : '';

	$t4 = "\t\t\t\t";
	$t6 = "\t\t\t\t\t\t";
	$t8 = "\t\t\t\t\t\t\t\t";

	$html  = $t4 . '<div class="related-tax-section">' . "\n";
	$html .= $t6 . '<h3 class="related-tax-header">' . esc_html( $heading_prefix ) . '<a href="' . esc_url( $term_link ) . '">' . $term_name . '</a></h3>' . "\n";
	$html .= $t6 . '<div class="related-tax-grid">' . "\n" . $t8;

	foreach ( $items as $item ) {
		$html .= extrachill_render_related_tax_card( $item );
	}

	$html .= $t6 . '</div>' . "\n";
	$html .= $t4 . '</div>' . "\n";
	$html .= $t4;

	return $html;
}

/**
 * Display related posts from taxonomy term
 *
 * @param string $taxonomy artist or venue
 * @param int    $post_id Current post to exclude
 */
function extrachill_display_related_posts( $taxonomy, $post_id ) {
	$allowed_taxonomies = apply_filters( 'extrachill_related_posts_allowed_taxonomies', array( 'category', 'post_tag' ), get_post_type( $post_id ) );

	if ( ! in_array( $taxonomy, $allowed_taxonomies, true ) ) {
		return;
	}

	if ( apply_filters( 'extrachill_override_related_posts_display', false, $taxonomy, $post_id ) ) {
		do_action( 'extrachill_custom_related_posts_display', $taxonomy, $post_id );
		return;
	}

	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return;
	}

	$term      = $terms[0];
	$term_id   = $term->term_id;
	$term_link = get_term_link( $term );
	if ( is_wp_error( $term_link ) ) {
		$term_link = '';
	}
	$term_name = $term->name;

	$cache_key          = $taxonomy . '_posts_' . $term_id . '_' . $post_id;
	$related_posts_data = get_transient( $cache_key );

	if ( false === $related_posts_data ) {
		$tax_query = array(
			array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $term_id,
			),
		);

		$tax_query = apply_filters( 'extrachill_related_posts_tax_query', $tax_query, $taxonomy, $term_id, $post_id, get_post_type( $post_id ) );

		$query_args = array(
			'post_type'      => 'post',
			'posts_per_page' => 3,
			'post_status'    => 'publish',
			'tax_query'      => $tax_query,
			'post__not_in'   => array( $post_id ),
		);

		$query_args = apply_filters( 'extrachill_related_posts_query_args', $query_args, $taxonomy, $post_id, get_post_type( $post_id ) );

		$related_posts = new WP_Query( $query_args );

		$related_posts_data = $related_posts->posts;
		set_transient( $cache_key, $related_posts_data, 3600 );
	}

	$items = array();
	foreach ( $related_posts_data as $related_post ) {
		if ( ! $related_post instanceof WP_Post ) {
			continue;
		}

		$items[] = array(
			'id'         => $related_post->ID,
			'layout'     => 'link',
			'permalink'  => get_permalink( $related_post ),
			'title'      => get_the_title( $related_post ),
			'thumb_html' => has_post_thumbnail( $related_post ) ? get_the_post_thumbnail( $related_post, 'medium' ) : '',
			'meta_html'  => get_the_date( '', $related_post ),
		);
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns pre-escaped markup from already-resolved data.
	echo extrachill_render_related_tax_section(
		array(
			'heading_prefix' => 'More from ',
			'term_link'      => $term_link,
			'term_name'      => esc_html( $term_name ),
			'items'          => $items,
		)
	);
}
