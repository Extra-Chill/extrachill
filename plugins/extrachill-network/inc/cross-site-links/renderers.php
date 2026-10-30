<?php
/**
 * Cross-Site Link Renderers
 *
 * Display components for rendering cross-site navigation buttons.
 * Uses button-3 button-small classes from theme root.css for consistent styling.
 *
 * @package ExtraChillNetwork
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render cross-site taxonomy links on archive pages
 *
 * Hooked to: extrachill_archive_below_description
 */
function extrachill_render_cross_site_taxonomy_links() {
	if ( ! is_tax() ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term || ! isset( $term->taxonomy ) ) {
		return;
	}

	$links = extrachill_get_cross_site_term_links( $term, $term->taxonomy );
	if ( empty( $links ) ) {
		return;
	}

	echo '<div class="ec-cross-site-links ec-cross-site-taxonomy-links">';
	foreach ( $links as $link ) {
		extrachill_cross_site_link_button( $link );
	}
	echo '</div>';
}

/**
 * Render cross-site user links on author archives
 *
 * Hooked to: extrachill_after_author_bio
 *
 * @param int $user_id Author user ID.
 */
function extrachill_render_cross_site_user_links( $user_id ) {
	if ( ! $user_id || ! is_int( $user_id ) ) {
		return;
	}

	$links = extrachill_get_cross_site_user_links( $user_id );
	if ( empty( $links ) ) {
		return;
	}

	echo '<div class="ec-cross-site-links ec-cross-site-user-links">';
	foreach ( $links as $link ) {
		extrachill_cross_site_link_button( $link );
	}
	echo '</div>';
}

/**
 * Render a single cross-site link button
 *
 * Builds descriptive labels: "{Term Name} {Content Type} ({Count})"
 * Example: "Charleston Blog Posts (5)" instead of "Blog (5)"
 *
 * @param array  $link        Link data with 'url', 'label', optional 'term_name', and optional 'count'.
 * @param string $extra_class Additional CSS class.
 */
function extrachill_cross_site_link_button( $link, $extra_class = '' ) {
	if ( empty( $link['url'] ) || empty( $link['label'] ) ) {
		return;
	}

	$button_class = 'button-3 button-small ec-cross-site-link';
	if ( ! empty( $extra_class ) ) {
		$button_class .= ' ' . esc_attr( $extra_class );
	}

	// Build descriptive label: "{Term Name} {Content Type} ({Count})".
	// Kept raw here; escaped once at output.
	$label_parts = array();

	if ( ! empty( $link['term_name'] ) ) {
		$label_parts[] = $link['term_name'];
	}

	$label_parts[] = $link['label'];

	$label = implode( ' ', $label_parts );

	// Only display count when it meets the minimum threshold (default: 3).
	$min_count = apply_filters( 'extrachill_cross_site_link_min_count_display', 3 );
	if ( isset( $link['count'] ) && $link['count'] >= $min_count ) {
		$label .= ' (' . (int) $link['count'] . ')';
	}

	printf(
		'<a href="%s" class="%s">%s</a>',
		esc_url( $link['url'] ),
		esc_attr( $button_class ),
		esc_html( $label )
	);
}
