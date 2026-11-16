<?php
/**
 * Handlers: artist subscriber unsubscribe abilities.
 *
 * - extrachill/artist-subscriber-unsubscribe       signed one-click link page
 * - extrachill/list-my-artist-subscriptions        member self-service list
 * - extrachill/remove-my-artist-subscription       member self-service removal
 *
 * Business logic lives in inc/artist-profiles/subscriber-unsubscribe.php.
 *
 * @package ExtraChillArtistPlatform
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Resolve a signed unsubscribe link into its standalone page.
 *
 * @param array $input {
 *     @type string $token   Signed token from the link.
 *     @type bool   $confirm True for the confirming POST.
 * }
 * @return array
 */
function extrachill_artist_platform_ability_artist_subscriber_unsubscribe( array $input ): array {
	$token = isset( $input['token'] ) && is_string( $input['token'] ) ? $input['token'] : '';
	return extrachill_artist_subscriber_unsubscribe_page( $token, ! empty( $input['confirm'] ) );
}

/**
 * List the current member's artist subscriptions.
 *
 * @param array $input Unused.
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_list_my_artist_subscriptions( array $input = array() ) {
	unset( $input );
	$subscriptions = extrachill_artist_get_member_subscriptions( get_current_user_id() );
	if ( is_wp_error( $subscriptions ) ) {
		return $subscriptions;
	}

	return array( 'subscriptions' => $subscriptions );
}

/**
 * Remove one of the current member's artist subscriptions.
 *
 * @param array $input {
 *     @type int $subscriber_id Subscriber row ID.
 * }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_remove_my_artist_subscription( array $input ) {
	$subscriber_id = isset( $input['subscriber_id'] ) ? absint( $input['subscriber_id'] ) : 0;
	$removed       = extrachill_artist_remove_member_subscription( get_current_user_id(), $subscriber_id );
	if ( is_wp_error( $removed ) ) {
		return $removed;
	}

	return array(
		'removed'       => true,
		'subscriber_id' => $subscriber_id,
	);
}
