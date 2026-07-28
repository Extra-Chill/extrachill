<?php
/**
 * Handler: extrachill/artist-export-subscribers
 *
 * Returns all subscribers for client-side CSV generation.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Export all subscribers for an artist.
 *
 * @param array $input {
 *     @type int  $id               Artist profile post ID.
 *     @type bool $include_exported Whether to include already exported subscribers (default false).
 * }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artist_export_subscribers( array $input ) {
	$artist_id        = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$include_exported = ! empty( $input['include_exported'] );

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' );
	}

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error( 'invalid_artist', __( 'Invalid artist specified.', 'extrachill-artist-platform' ) );
	}

	if ( ! function_exists( 'extrachill_artist_get_artist_subscribers' ) ) {
		return new WP_Error( 'dependency_missing', 'Subscriber functions not available.' );
	}

	$exported_filter = $include_exported ? null : 0;

	$subscribers = extrachill_artist_get_artist_subscribers( $artist_id, array(
		'limit'    => -1,
		'exported' => $exported_filter,
	) );
	if ( is_wp_error( $subscribers ) ) {
		return $subscribers;
	}

	$subscriber_ids_to_mark = extrachill_artist_subscriber_ids_to_mark_exported( $subscribers, $include_exported );
	$export_data            = array();

	foreach ( $subscribers as $subscriber ) {
		$is_exported = isset( $subscriber->exported ) && 1 === (int) $subscriber->exported;

		$export_data[] = array(
			'email'         => $subscriber->subscriber_email,
			'username'      => $subscriber->username ?? '',
			'subscribed_at' => $subscriber->subscribed_at,
			'exported'      => $is_exported,
		);

	}

	// Mark newly exported subscribers.
	if ( ! $include_exported && ! empty( $subscriber_ids_to_mark ) ) {
		global $wpdb;
		$table      = $wpdb->prefix . 'artist_subscribers';
		$ids_string = implode( ', ', array_map( 'absint', $subscriber_ids_to_mark ) );
		$wpdb->query( "UPDATE {$table} SET exported = 1 WHERE subscriber_id IN ({$ids_string})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$artist_name = get_the_title( $artist_id );

	return array(
		'subscribers'  => $export_data,
		'artist_name'  => $artist_name,
		'export_date'  => current_time( 'Y-m-d' ),
		'total'        => count( $export_data ),
		'marked_count' => count( $subscriber_ids_to_mark ),
	);
}
