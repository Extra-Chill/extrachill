<?php
/**
 * Artist compatibility adapter for Link Page owner references.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return artist compatibility claims for one provider operation.
 *
 * @param string $operation Provider operation.
 * @param array  $context   Operation context.
 * @return array|WP_Error
 */
function ec_artist_link_page_owner_compatibility_provider( $operation, $context ) {
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : get_current_blog_id();
	if ( 'page_owner' === $operation ) {
		$link_page_id = (int) ( $context['link_page_id'] ?? 0 );
		$artist_id    = (int) get_post_meta( $link_page_id, '_associated_artist_profile_id', true );
		if ( ! $artist_id || ! $artist_blog_id ) {
			return array();
		}
		$reference = ec_format_link_page_owner_reference(
			array(
				'kind'      => 'post',
				'blog_id'   => $artist_blog_id,
				'subtype'   => 'artist_profile',
				'object_id' => $artist_id,
			)
		);
		return is_wp_error( $reference ) ? $reference : array(
			array(
				'link_page_id'    => $link_page_id,
				'owner_reference' => $reference,
			),
		);
	}

	$reference = $context['owner_reference'] ?? '';
	$owner     = ec_parse_link_page_owner_reference( $reference );
	if ( is_wp_error( $owner ) ) {
		return $owner;
	}
	if ( 'owner_pages' !== $operation || 'post' !== $owner['kind'] || $artist_blog_id !== $owner['blog_id'] || 'artist_profile' !== $owner['subtype'] ) {
		return array();
	}

	// Compatibility lookup requires the legacy reciprocal metadata value.
	// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	$legacy_ids = get_posts(
		array(
			'post_type'      => 'artist_link_page',
			'post_status'    => 'any',
			'meta_key'       => '_associated_artist_profile_id',
			'meta_value'     => (string) $owner['object_id'],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value

	$claims = array();
	foreach ( $legacy_ids as $legacy_id ) {
		$claims[] = array(
			'link_page_id'    => (int) $legacy_id,
			'owner_reference' => $reference,
		);
	}
	return $claims;
}
