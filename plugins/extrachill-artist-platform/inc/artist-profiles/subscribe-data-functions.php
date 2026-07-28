<?php
/**
 * Artist profile subscriber data functions.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_ARTIST_EMAIL_SHARING_PRODUCER = 'artist-platform-subscriber-list-export';

/**
 * Register the product-owned artist email-sharing purpose identity.
 *
 * @param array $entities Entity subscription descriptors.
 * @return array
 */
function extrachill_artist_register_email_sharing_identity( array $entities ): array {
	$entities['artist-email-sharing'] = array(
		'taxonomy'                           => 'artist',
		'uses_notification_email_preference' => false,
	);

	return $entities;
}
add_filter( 'extrachill_users_entity_subscription_entities', 'extrachill_artist_register_email_sharing_identity' );

/**
 * Authorize only this product's private list/export recipient resolution.
 *
 * @param bool   $authorized Existing authorization result.
 * @param string $producer Producer identifier.
 * @param array  $entity Canonical entity identity.
 * @param string $delivery Delivery channel.
 * @return bool
 */
function extrachill_artist_authorize_email_sharing_producer( $authorized, $producer, $entity, $delivery ): bool {
	return (bool) $authorized || (
		EXTRACHILL_ARTIST_EMAIL_SHARING_PRODUCER === $producer
		&& 'artist-email-sharing' === ( $entity['entity_type'] ?? '' )
		&& 'artist' === ( $entity['taxonomy'] ?? '' )
		&& 'email' === $delivery
	);
}
add_filter( 'extrachill_users_entity_subscription_producer_authorized', 'extrachill_artist_authorize_email_sharing_producer', 10, 4 );

/**
 * Resolve an artist profile to its canonical artist term slug.
 *
 * @param int $artist_id Artist profile post ID.
 * @return string|WP_Error
 */
function extrachill_artist_email_sharing_slug( $artist_id ) {
	$term_id      = function_exists( 'ec_get_artist_term_id' ) ? absint( ec_get_artist_term_id( $artist_id ) ) : 0;
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'main' ) ) : 0;
	if ( ! $term_id || ! $main_blog_id ) {
		return new WP_Error( 'artist_email_sharing_identity_unavailable', __( 'The canonical artist identity is unavailable.', 'extrachill-artist-platform' ) );
	}

	switch_to_blog( $main_blog_id );
	try {
		$term = get_term( $term_id, 'artist' );
	} finally {
		restore_current_blog();
	}

	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'artist_email_sharing_identity_unavailable', __( 'The canonical artist identity is unavailable.', 'extrachill-artist-platform' ) );
	}

	return $term->slug;
}

/**
 * Resolve current account emails for canonical artist email-sharing consent.
 *
 * @param int $artist_id Artist profile post ID.
 * @return array|WP_Error
 */
function extrachill_artist_get_canonical_email_subscribers( $artist_id ) {
	$slug = extrachill_artist_email_sharing_slug( $artist_id );
	if ( is_wp_error( $slug ) ) {
		return $slug;
	}

	$recipient_ids = extrachill_users_entity_subscription_recipients(
		EXTRACHILL_ARTIST_EMAIL_SHARING_PRODUCER,
		'artist-email-sharing',
		'artist',
		$slug,
		'email'
	);
	if ( is_wp_error( $recipient_ids ) ) {
		return $recipient_ids;
	}

	$subscribers = array();
	foreach ( $recipient_ids as $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			continue;
		}
		$email = sanitize_email( $user->user_email );
		if ( ! is_email( $email ) ) {
			continue;
		}

		$subscribers[] = (object) array(
			'subscriber_id'    => 0,
			'user_id'          => absint( $user_id ),
			'subscriber_email' => $email,
			'username'         => $user->user_login,
			'source'           => 'entity_subscription',
			'subscribed_at'    => '',
			'exported'         => 0,
		);
	}

	return $subscribers;
}

/**
 * Merge product-owned and account-derived subscribers by normalized email.
 *
 * Product-owned rows take precedence so their export bookkeeping remains
 * attached when the same address also has canonical account consent.
 *
 * @param array $direct Direct subscriber rows.
 * @param array $canonical Canonical account-derived rows.
 * @return array
 */
function extrachill_artist_merge_subscriber_sources( array $direct, array $canonical ): array {
	$merged = array();
	foreach ( array_merge( $direct, $canonical ) as $subscriber ) {
		$email = strtolower( sanitize_email( $subscriber->subscriber_email ?? '' ) );
		if ( ! is_email( $email ) || isset( $merged[ $email ] ) ) {
			continue;
		}
		$subscriber->subscriber_email = $email;
		$merged[ $email ]             = $subscriber;
	}

	return array_values( $merged );
}

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
 * Fetch mixed-source subscriber data for an artist profile.
 *
 * Legacy account-derived rows remain readable only before the canonical Users
 * resolver is available. Once available, it is authoritative for revocation
 * and current account email resolution.
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

	$table_name        = $wpdb->prefix . 'artist_subscribers';
	$canonical_enabled = function_exists( 'extrachill_users_entity_subscription_recipients' );
	$sql               = "SELECT * FROM {$table_name} WHERE artist_profile_id = %d";
	$sql_args          = array( $artist_id );
	if ( $canonical_enabled ) {
		$sql       .= ' AND source <> %s';
		$sql_args[] = 'platform_follow_consent';
	}
	if ( null !== $args['exported'] ) {
		$sql       .= ' AND exported = %d';
		$sql_args[] = absint( $args['exported'] );
	}

	$orderby = sanitize_key( $args['orderby'] );
	if ( in_array( $orderby, array( 'subscriber_id', 'artist_profile_id', 'subscriber_email', 'username', 'subscribed_at', 'exported' ), true ) ) {
		$sql .= ' ORDER BY ' . $orderby . ( 'ASC' === strtoupper( $args['order'] ) ? ' ASC' : ' DESC' );
	}

	$direct = $wpdb->get_results( $wpdb->prepare( $sql, $sql_args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is assembled from fixed clauses and an allowlisted order column.
	if ( ! is_array( $direct ) ) {
		return new WP_Error( 'artist_subscribers_read_failed', __( 'Subscriber data could not be loaded.', 'extrachill-artist-platform' ) );
	}

	$subscribers = $direct;
	if ( $canonical_enabled ) {
		$canonical = extrachill_artist_get_canonical_email_subscribers( $artist_id );
		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}
		$subscribers = extrachill_artist_merge_subscriber_sources( $direct, $canonical );
	}

	$offset = max( 0, absint( $args['offset'] ) );
	$limit  = (int) $args['limit'];
	return $limit > 0 ? array_slice( $subscribers, $offset, $limit ) : array_slice( $subscribers, $offset );
}
