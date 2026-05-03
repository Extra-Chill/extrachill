<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artist-subscribe
 *
 * Public subscription to an artist's updates.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscribe an email address to an artist.
 *
 * @param array $input {
 *     @type int    $id    Artist profile post ID.
 *     @type string $email Subscriber email address.
 * }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artist_subscribe( array $input ): array|WP_Error {
	$artist_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$email     = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'extrachill-artist-platform' ) );
	}

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error( 'invalid_artist', __( 'Invalid artist specified.', 'extrachill-artist-platform' ) );
	}

	global $wpdb;
	$table = $wpdb->prefix . 'artist_subscribers';

	// Check for existing subscription.
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE artist_profile_id = %d AND subscriber_email = %s",
			$artist_id,
			$email
		)
	);

	if ( $exists ) {
		return new WP_Error(
			'already_subscribed',
			__( 'You are already subscribed to this artist.', 'extrachill-artist-platform' ),
			array( 'status' => 409 )
		);
	}

	// Insert new subscriber.
	$inserted = $wpdb->insert(
		$table,
		array(
			'artist_profile_id' => $artist_id,
			'subscriber_email'  => $email,
			'username'          => '',
			'subscribed_at'     => current_time( 'mysql', 1 ),
			'exported'          => 0,
		),
		array( '%d', '%s', '%s', '%s', '%d' )
	);

	if ( ! $inserted ) {
		return new WP_Error(
			'subscription_failed',
			__( 'Could not save your subscription. Please try again later.', 'extrachill-artist-platform' ),
			array( 'status' => 500 )
		);
	}

	return array(
		'message' => __( 'Thank you for subscribing!', 'extrachill-artist-platform' ),
	);
}
