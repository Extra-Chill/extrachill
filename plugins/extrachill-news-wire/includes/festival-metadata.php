<?php
/**
 * Festival Metadata
 *
 * Single source of truth for festival term metadata used by the News Wire hub.
 * Data is stored as term meta on the wire site.
 *
 * @package ExtraChillNewsWire
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get festival metadata for a festival term slug.
 *
 * @param string $festival_slug Festival term slug.
 * @return array|null Festival metadata, or null if term not found.
 */
function ec_news_wire_get_festival_metadata( $festival_slug ) {
	$festival_slug = sanitize_title( (string) $festival_slug );
	if ( '' === $festival_slug ) {
		return null;
	}

	if ( ! taxonomy_exists( 'festival' ) ) {
		return null;
	}

	$term = get_term_by( 'slug', $festival_slug, 'festival' );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}

	$start_date = (string) get_term_meta( $term->term_id, '_ec_festival_start_date', true );
	$end_date   = (string) get_term_meta( $term->term_id, '_ec_festival_end_date', true );

	return array(
		'term_id'     => (int) $term->term_id,
		'slug'        => (string) $term->slug,
		'name'        => (string) $term->name,
		'description' => (string) $term->description,
		'start_date'  => $start_date,
		'end_date'    => $end_date,
	);
}

/**
 * Check if a festival term has hub metadata.
 *
 * @param string $festival_slug Festival term slug.
 * @return bool True if any hub metadata exists.
 */
function ec_news_wire_festival_has_hub_metadata( $festival_slug ) {
	$meta = ec_news_wire_get_festival_metadata( $festival_slug );
	if ( ! $meta ) {
		return false;
	}

	return ( '' !== $meta['start_date'] || '' !== $meta['end_date'] );
}
