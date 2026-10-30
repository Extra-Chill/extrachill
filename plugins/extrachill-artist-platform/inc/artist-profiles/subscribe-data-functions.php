<?php
/**
 * Artist profile subscriber data functions.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Select only unexported product-owned rows for export bookkeeping.
 *
 * @param array $subscribers Subscriber rows included in an export.
 * @param bool  $include_exported Whether previously exported rows were requested.
 * @return int[]
 */
function extrachill_artist_subscriber_ids_to_mark_exported( array $subscribers, bool $include_exported ): array {
	if ( $include_exported ) {
		return array();
	}

	$ids = array();
	foreach ( $subscribers as $subscriber ) {
		if ( empty( $subscriber->exported ) && ! empty( $subscriber->subscriber_id ) ) {
			$ids[] = absint( $subscriber->subscriber_id );
		}
	}

	return $ids;
}

/**
 * Fetch direct subscriber data for an artist profile.
 *
 * @param int   $artist_id Artist profile post ID.
 * @param array $args Query arguments.
 * @return array|WP_Error
 */
function extrachill_artist_get_artist_subscribers( $artist_id, $args = array() ) {
	global $wpdb;

	$artist_id = absint( $artist_id );
	if ( ! $artist_id ) {
		return array();
	}

	$args = wp_parse_args(
		$args,
		array(
			'orderby'  => 'subscribed_at',
			'order'    => 'DESC',
			'limit'    => -1,
			'offset'   => 0,
			'exported' => null,
		)
	);

	$table_name = $wpdb->prefix . 'artist_subscribers';
	$sql        = "SELECT * FROM {$table_name} WHERE artist_profile_id = %d AND source <> %s";
	$sql_args   = array( $artist_id, 'platform_follow_consent' );
	if ( null !== $args['exported'] ) {
		$sql       .= ' AND exported = %d';
		$sql_args[] = absint( $args['exported'] );
	}

	$orderby = sanitize_key( $args['orderby'] );
	if ( in_array( $orderby, array( 'subscriber_id', 'artist_profile_id', 'subscriber_email', 'username', 'subscribed_at', 'exported' ), true ) ) {
		$sql .= ' ORDER BY ' . $orderby . ( 'ASC' === strtoupper( $args['order'] ) ? ' ASC' : ' DESC' );
	}

	$subscribers = $wpdb->get_results( $wpdb->prepare( $sql, $sql_args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is assembled from fixed clauses and an allowlisted order column.
	if ( ! is_array( $subscribers ) ) {
		return new WP_Error( 'artist_subscribers_read_failed', __( 'Subscriber data could not be loaded.', 'extrachill-artist-platform' ) );
	}

	$offset = max( 0, absint( $args['offset'] ) );
	$limit  = (int) $args['limit'];
	return $limit > 0 ? array_slice( $subscribers, $offset, $limit ) : array_slice( $subscribers, $offset );
}
