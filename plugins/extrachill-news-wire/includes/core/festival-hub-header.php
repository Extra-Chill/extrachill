<?php
/**
 * Festival Hub Header
 *
 * Renders the festival hub header metadata on festival term archives
 * for the wire site.
 *
 * @package ExtraChillNewsWire
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render festival hub metadata (wire festival term archives).
 *
 * @return void
 */
function ec_news_wire_render_festival_hub_header() {
	if ( ! is_tax( 'festival' ) ) {
		return;
	}

	if ( function_exists( 'ec_get_current_site_key' ) ) {
		if ( 'wire' !== ec_get_current_site_key() ) {
			return;
		}
	} elseif ( 11 !== (int) get_current_blog_id() ) {
		return;
	}

	if ( ! function_exists( 'ec_news_wire_get_festival_metadata' ) ) {
		return;
	}

	$festival_term = get_queried_object();
	if ( ! ( $festival_term instanceof \WP_Term ) ) {
		return;
	}

	$meta = ec_news_wire_get_festival_metadata( $festival_term->slug );
	if ( ! $meta ) {
		return;
	}

	$location_term = ec_news_wire_get_festival_single_location_term( $festival_term );

	if ( '' === $meta['start_date'] && '' === $meta['end_date'] && ! $location_term ) {
		return;
	}

	echo '<div class="full-width-breakout ec-edge-shell">';
	echo '<div class="article-container">';
	echo '<div class="archive-extra-meta ec-edge-gutter">';

	if ( '' !== $meta['start_date'] || '' !== $meta['end_date'] ) {
		echo '<div class="archive-extra-meta-item">';
		echo '<strong>' . esc_html__( 'Dates:', 'extrachill' ) . '</strong> ';
		echo esc_html( trim( $meta['start_date'] . ' – ' . $meta['end_date'], " –" ) );
		echo '</div>';
	}

	if ( $location_term ) {
		$location_url = get_term_link( $location_term );
		if ( ! is_wp_error( $location_url ) ) {
			echo '<div class="archive-extra-meta-item">';
			echo '<strong>' . esc_html__( 'Location:', 'extrachill' ) . '</strong> ';
			echo '<a href="' . esc_url( $location_url ) . '">';
			echo esc_html( $location_term->name );
			echo '</a>';
			echo '</div>';
		}
	}

	echo '</div>';
	echo '</div>';
	echo '</div>';
}
add_action( 'extrachill_archive_below_description', 'ec_news_wire_render_festival_hub_header' );

/**
 * Get the single location term for a festival archive.
 *
 * Production design expects festival posts to all share exactly one location.
 *
 * @param \WP_Term $festival_term Festival term.
 * @return \WP_Term|null Location term if exactly one exists.
 */
function ec_news_wire_get_festival_single_location_term( $festival_term ) {
	if ( ! taxonomy_exists( 'festival' ) ) {
		return null;
	}

	$post_types = get_taxonomy( 'festival' )->object_type;

	$post_ids = get_posts(
		array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'tax_query'      => array(
				array(
					'taxonomy' => 'festival',
					'field'    => 'term_id',
					'terms'    => (int) $festival_term->term_id,
				),
			),
		)
	);

	if ( empty( $post_ids ) ) {
		return null;
	}

	$location_terms = wp_get_object_terms(
		$post_ids,
		'location',
		array(
			'fields' => 'all',
		)
	);

	if ( is_wp_error( $location_terms ) ) {
		return null;
	}

	$unique = array();
	foreach ( $location_terms as $location_term ) {
		if ( ! ( $location_term instanceof \WP_Term ) ) {
			continue;
		}
		$unique[ (int) $location_term->term_id ] = $location_term;
	}

	if ( 1 !== count( $unique ) ) {
		return null;
	}

	return array_values( $unique )[0];
}
