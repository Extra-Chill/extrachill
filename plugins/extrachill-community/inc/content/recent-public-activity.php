<?php
/**
 * Recent Public Activity Ability
 *
 * Owns the portable, read-only projection of public Community activity.
 * bbPress storage details remain contained in this file.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_COMMUNITY_RECENT_ACTIVITY_MAX = 20;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_recent_public_activity_ability' );

/** Register the bounded public activity projection. */
function extrachill_community_register_recent_public_activity_ability() {
	$nullable_url = array(
		'oneOf' => array(
			array( 'type' => 'null' ),
			array(
				'type'   => 'string',
				'format' => 'uri',
			),
		),
	);
	$term_schema  = array(
		'type'                 => 'object',
		'properties'           => array(
			'name'          => array( 'type' => 'string' ),
			'slug'          => array( 'type' => 'string' ),
			'canonical_url' => array(
				'type'   => 'string',
				'format' => 'uri',
			),
		),
		'required'             => array( 'name', 'slug', 'canonical_url' ),
		'additionalProperties' => false,
	);
	$item_schema  = array(
		'type'                 => 'object',
		'properties'           => array(
			'canonical_url' => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'title'         => array( 'type' => 'string' ),
			'timestamp'     => array(
				'type'   => 'string',
				'format' => 'date-time',
			),
			'activity_type' => array(
				'type' => 'string',
				'enum' => array( 'discussion', 'reply' ),
			),
			'actor'         => array(
				'type'                 => 'object',
				'properties'           => array(
					'display_name' => array( 'type' => 'string' ),
					'profile_url'  => $nullable_url,
				),
				'required'             => array( 'display_name', 'profile_url' ),
				'additionalProperties' => false,
			),
			'relationships' => array(
				'type'                 => 'object',
				'properties'           => array(
					'forum'   => $term_schema,
					'artists' => array(
						'type'     => 'array',
						'items'    => $term_schema,
						'maxItems' => 10,
					),
				),
				'required'             => array( 'forum', 'artists' ),
				'additionalProperties' => false,
			),
		),
		'required'             => array( 'canonical_url', 'title', 'timestamp', 'activity_type', 'actor', 'relationships' ),
		'additionalProperties' => false,
	);

	wp_register_ability(
		'extrachill/community-recent-public-activity',
		array(
			'label'               => __( 'Get Recent Public Community Activity', 'extra-chill-community' ),
			'description'         => __( 'Get a bounded, portable projection of recent public discussions and replies.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'limit'       => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => EXTRACHILL_COMMUNITY_RECENT_ACTIVITY_MAX,
						'default' => 5,
					),
					'artist_slug' => array(
						'type'      => 'string',
						'pattern'   => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
						'maxLength' => 200,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'schema_version' => array(
						'type' => 'string',
						'enum' => array( '1' ),
					),
					'items'          => array(
						'type'     => 'array',
						'items'    => $item_schema,
						'maxItems' => EXTRACHILL_COMMUNITY_RECENT_ACTIVITY_MAX,
					),
				),
				'required'             => array( 'schema_version', 'items' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'extrachill_community_ability_recent_public_activity',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Return recent public activity without exposing Community storage details.
 *
 * @param array $input Ability input.
 * @return array{schema_version:string,items:array}
 */
function extrachill_community_ability_recent_public_activity( $input ) {
	if ( ! function_exists( 'bbp_get_topic_post_type' ) || ! function_exists( 'bbp_get_reply_post_type' ) ) {
		return array(
			'schema_version' => '1',
			'items'          => array(),
		);
	}

	$limit       = min( EXTRACHILL_COMMUNITY_RECENT_ACTIVITY_MAX, max( 1, (int) ( $input['limit'] ?? 5 ) ) );
	$artist_slug = isset( $input['artist_slug'] ) ? (string) $input['artist_slug'] : '';
	$query_args  = array(
		'post_type'              => '' === $artist_slug ? array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ) : bbp_get_topic_post_type(),
		'post_status'            => array( bbp_get_public_status_id(), bbp_get_closed_status_id() ),
		'posts_per_page'         => $limit,
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'update_post_term_cache' => true,
		'update_post_meta_cache' => true,
	);

	if ( '' !== $artist_slug ) {
		$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Explicit bounded relationship filter.
			array(
				'taxonomy' => 'artist',
				'field'    => 'slug',
				'terms'    => $artist_slug,
			),
		);
	}

	$items = array();
	$query = new WP_Query( $query_args );
	foreach ( $query->posts as $activity ) {
		$activity = $activity instanceof WP_Post ? $activity : get_post( $activity );
		if ( ! $activity ) {
			continue;
		}
		$item = extrachill_community_prepare_recent_public_activity_item( $activity );
		if ( $item ) {
			$items[] = $item;
		}
	}

	return array(
		'schema_version' => '1',
		'items'          => $items,
	);
}

/**
 * Shape one public activity item.
 *
 * @param WP_Post $activity Activity post.
 * @return array|null Portable item, or null when any owner visibility check fails.
 */
function extrachill_community_prepare_recent_public_activity_item( $activity ) {
	$topic_type = bbp_get_topic_post_type();
	$reply_type = bbp_get_reply_post_type();
	$is_reply   = $reply_type === $activity->post_type;
	$is_topic   = $topic_type === $activity->post_type;

	if ( ! $is_topic && ! $is_reply ) {
		return null;
	}

	$public_status = bbp_get_public_status_id();
	$closed_status = bbp_get_closed_status_id();
	if ( ( $is_reply && $public_status !== $activity->post_status ) || ( $is_topic && ! in_array( $activity->post_status, array( $public_status, $closed_status ), true ) ) ) {
		return null;
	}

	$topic_id = $is_topic ? (int) $activity->ID : (int) bbp_get_reply_topic_id( $activity->ID );
	$topic    = $topic_id ? get_post( $topic_id ) : null;
	if ( ! $topic || $topic_type !== $topic->post_type || ! in_array( $topic->post_status, array( $public_status, $closed_status ), true ) ) {
		return null;
	}

	$forum_id = (int) bbp_get_topic_forum_id( $topic_id );
	$forum    = $forum_id ? get_post( $forum_id ) : null;
	if ( ! $forum || bbp_get_forum_post_type() !== $forum->post_type || $public_status !== $forum->post_status ) {
		return null;
	}

	$canonical_url = $is_reply ? bbp_get_reply_url( $activity->ID ) : bbp_get_topic_permalink( $topic_id );
	$forum_url     = bbp_get_forum_permalink( $forum_id );
	$activity_date = $activity->post_date_gmt ? $activity->post_date_gmt : $activity->post_date;
	$timestamp     = mysql2date( DATE_W3C, $activity_date, false );
	if ( ! $canonical_url || ! $forum_url || ! $timestamp ) {
		return null;
	}

	return array(
		'canonical_url' => (string) $canonical_url,
		'title'         => html_entity_decode( get_the_title( $topic ), ENT_QUOTES, 'UTF-8' ),
		'timestamp'     => $timestamp,
		'activity_type' => $is_reply ? 'reply' : 'discussion',
		'actor'         => extrachill_community_recent_activity_actor( $activity ),
		'relationships' => array(
			'forum'   => array(
				'name'          => html_entity_decode( get_the_title( $forum ), ENT_QUOTES, 'UTF-8' ),
				'slug'          => (string) $forum->post_name,
				'canonical_url' => (string) $forum_url,
			),
			'artists' => extrachill_community_recent_activity_artists( $topic_id ),
		),
	);
}

/** Return the public identity displayed for an activity item. */
function extrachill_community_recent_activity_actor( $activity ) {
	if ( function_exists( 'extrachill_community_format_post_public_voice' ) ) {
		$voice = extrachill_community_format_post_public_voice( $activity->ID );
		if ( $voice ) {
			return array(
				'display_name' => (string) $voice['name'],
				'profile_url'  => ! empty( $voice['url'] ) ? (string) $voice['url'] : null,
			);
		}
	}

	$user = get_userdata( (int) $activity->post_author );
	if ( $user ) {
		$profile_url = function_exists( 'extrachill_get_user_profile_url' ) ? extrachill_get_user_profile_url( $user->ID ) : '';
		return array(
			'display_name' => (string) $user->display_name,
			'profile_url'  => $profile_url ? (string) $profile_url : null,
		);
	}

	return array(
		'display_name' => (string) get_post_meta( $activity->ID, '_bbp_anonymous_name', true ),
		'profile_url'  => null,
	);
}

/** Return bounded Community artist archive relationships for a topic. */
function extrachill_community_recent_activity_artists( $topic_id ) {
	$terms = get_the_terms( $topic_id, 'artist' );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return array();
	}

	$artists = array();
	foreach ( array_slice( $terms, 0, 10 ) as $term ) {
		$url = get_term_link( $term );
		if ( is_wp_error( $url ) || ! $url ) {
			continue;
		}
		$artists[] = array(
			'name'          => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
			'slug'          => (string) $term->slug,
			'canonical_url' => (string) $url,
		);
	}

	return $artists;
}
