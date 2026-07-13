<?php
/**
 * Festival Topic Notification Capture.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Community's stable entity-subscription producer identifier.
 */
const EXTRACHILL_COMMUNITY_TOPIC_NOTIFICATION_PRODUCER    = 'extrachill-community';
const EXTRACHILL_COMMUNITY_FESTIVAL_TOPIC_NOTIFIED_META   = '_extrachill_community_festival_topic_notified';
const EXTRACHILL_COMMUNITY_ARTIST_TOPIC_NOTIFIED_META     = '_extrachill_community_artist_topic_notified';

/**
 * Authorize Community to resolve private entity notification recipients.
 *
 * @param bool   $authorized Whether the producer is authorized.
 * @param string $producer   Producer identifier.
 * @return bool
 */
function extrachill_community_authorize_topic_notification_producer( $authorized, $producer ) {
	return $authorized || EXTRACHILL_COMMUNITY_TOPIC_NOTIFICATION_PRODUCER === $producer;
}
add_filter( 'extrachill_users_entity_subscription_producer_authorized', 'extrachill_community_authorize_topic_notification_producer', 10, 2 );

/**
 * Notify festival subscribers when a new festival-tagged topic is published.
 *
 * This runs after the topic composer persists its festival terms. It intentionally
 * observes only `bbp_new_topic`; replies and topic edits are handled separately.
 *
 * @param int $topic_id Topic post ID.
 * @return void
 */
function extrachill_community_notify_festival_topic_subscribers( $topic_id ) {
	if ( ! function_exists( 'extrachill_users_entity_subscription_recipients' ) || ! function_exists( 'ec_users_notify' ) ) {
		return;
	}

	$topic_id = (int) $topic_id;
	if ( $topic_id <= 0 ) {
		return;
	}
	if ( bbp_get_public_status_id() !== get_post_status( $topic_id ) || get_post_meta( $topic_id, EXTRACHILL_COMMUNITY_FESTIVAL_TOPIC_NOTIFIED_META, true ) ) {
		return;
	}

	$terms = get_the_terms( $topic_id, 'festival' );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return;
	}

	$recipients = array();
	foreach ( $terms as $term ) {
		$ids = extrachill_users_entity_subscription_recipients(
			EXTRACHILL_COMMUNITY_TOPIC_NOTIFICATION_PRODUCER,
			'festival',
			'festival',
			$term->slug,
			'email'
		);

		if ( is_wp_error( $ids ) ) {
			continue;
		}

		$recipients = array_merge( $recipients, $ids );
	}

	$author_id  = (int) get_post_field( 'post_author', $topic_id );
	$recipients = array_values(
		array_diff(
			array_unique( array_map( 'absint', $recipients ) ),
			array( $author_id, 0 )
		)
	);
	if ( empty( $recipients ) ) {
		return;
	}

	// Claim before delivery so concurrent bbPress hooks cannot duplicate notices.
	// The claim remains when delivery inserts no rows, making retries at-most-once.
	if ( ! add_post_meta( $topic_id, EXTRACHILL_COMMUNITY_FESTIVAL_TOPIC_NOTIFIED_META, current_time( 'mysql', true ), true ) ) {
		return;
	}

	ec_users_notify(
		$recipients,
		array(
			'actor_id' => $author_id,
			'type'     => 'festival_discussion',
			'title'    => get_the_title( $topic_id ),
			'link'     => function_exists( 'bbp_get_topic_permalink' ) ? bbp_get_topic_permalink( $topic_id ) : get_permalink( $topic_id ),
			'item_id'  => $topic_id,
		)
	);
}
add_action( 'bbp_new_topic', 'extrachill_community_notify_festival_topic_subscribers', 30 );

/**
 * Notify artist subscribers when a new artist-tagged topic is published.
 *
 * This runs after the topic composer persists its artist terms. It intentionally
 * observes only `bbp_new_topic`; replies and topic edits are handled separately.
 *
 * @param int $topic_id Topic post ID.
 * @return void
 */
function extrachill_community_notify_artist_topic_subscribers( $topic_id ) {
	if ( ! function_exists( 'extrachill_users_entity_subscription_recipients' ) || ! function_exists( 'ec_users_notify' ) ) {
		return;
	}

	$topic_id = (int) $topic_id;
	if ( $topic_id <= 0 ) {
		return;
	}
	if ( bbp_get_public_status_id() !== get_post_status( $topic_id ) || get_post_meta( $topic_id, EXTRACHILL_COMMUNITY_ARTIST_TOPIC_NOTIFIED_META, true ) ) {
		return;
	}

	$terms = get_the_terms( $topic_id, 'artist' );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return;
	}

	$recipients = array();
	foreach ( $terms as $term ) {
		$ids = extrachill_users_entity_subscription_recipients(
			EXTRACHILL_COMMUNITY_TOPIC_NOTIFICATION_PRODUCER,
			'artist',
			'artist',
			$term->slug
		);

		if ( is_wp_error( $ids ) ) {
			continue;
		}

		$recipients = array_merge( $recipients, $ids );
	}

	$author_id  = (int) get_post_field( 'post_author', $topic_id );
	$recipients = array_values(
		array_diff(
			array_unique( array_map( 'absint', $recipients ) ),
			array( $author_id, 0 )
		)
	);
	if ( empty( $recipients ) ) {
		return;
	}

	// Claim before delivery so concurrent bbPress hooks cannot duplicate notices.
	// The claim remains when delivery inserts no rows, making retries at-most-once.
	if ( ! add_post_meta( $topic_id, EXTRACHILL_COMMUNITY_ARTIST_TOPIC_NOTIFIED_META, current_time( 'mysql', true ), true ) ) {
		return;
	}

	ec_users_notify(
		$recipients,
		array(
			'actor_id' => $author_id,
			'type'     => 'artist_discussion',
			'title'    => get_the_title( $topic_id ),
			'link'     => function_exists( 'bbp_get_topic_permalink' ) ? bbp_get_topic_permalink( $topic_id ) : get_permalink( $topic_id ),
			'item_id'  => $topic_id,
		)
	);
}
add_action( 'bbp_new_topic', 'extrachill_community_notify_artist_topic_subscribers', 30 );
