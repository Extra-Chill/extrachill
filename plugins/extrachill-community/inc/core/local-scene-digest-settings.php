<?php
/**
 * Local Scene digest integration for Community settings.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

/** Register the Events-owned consent identity on the Community site. */
function extrachill_community_register_local_scene_digest_entity( array $entities ): array {
	$entities['local_scene_digest'] = 'location';
	return $entities;
}
add_filter( 'extrachill_users_entity_subscription_entities', 'extrachill_community_register_local_scene_digest_entity' );
