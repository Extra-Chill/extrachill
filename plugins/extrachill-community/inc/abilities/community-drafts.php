<?php
declare(strict_types=1);
/**
 * Ability: extrachill/community-drafts
 *
 * Retrieve a stored bbPress topic or reply draft for the current user.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_community_drafts_ability' );

/**
 * Register the community-drafts ability.
 */
function extrachill_community_register_community_drafts_ability(): void {

	wp_register_ability(
		'extrachill/community-drafts',
		array(
			'label'               => __( 'Get Community Drafts', 'extra-chill-community' ),
			'description'         => __( 'Retrieve a stored bbPress topic or reply draft for the current user.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'type'              => array(
						'type'        => 'string',
						'enum'        => array( 'topic', 'reply' ),
						'description' => 'Draft type: topic or reply.',
					),
					'forum_id'          => array(
						'type'        => 'integer',
						'description' => 'Forum ID (required for topic drafts).',
					),
					'topic_id'          => array(
						'type'        => 'integer',
						'description' => 'Topic ID (required for reply drafts).',
					),
					'reply_to'          => array(
						'type'        => 'integer',
						'description' => 'Parent reply ID for nested replies.',
					),
					'prefer_unassigned' => array(
						'type'        => 'boolean',
						'description' => 'Fall back to forum_id=0 draft when no exact match.',
					),
				),
				'required'   => array( 'type' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'draft' => array(
						'anyOf' => array(
							array( 'type' => 'object' ),
							array( 'type' => 'null' ),
						),
					),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_community_drafts',
			'permission_callback' => static function (): bool {
				return is_user_logged_in();
			},
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

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Retrieve a bbPress draft for the current user.
 *
 * Validates context (type + required IDs), then delegates to the existing
 * draft-retrieval helper already registered in this plugin.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_community_drafts( array $input ): array|WP_Error {
	$type     = isset( $input['type'] ) ? (string) $input['type'] : '';
	$forum_id = isset( $input['forum_id'] ) ? (int) $input['forum_id'] : 0;
	$topic_id = isset( $input['topic_id'] ) ? (int) $input['topic_id'] : 0;

	// --- Context validation (mirrors REST route logic) ---
	if ( 'topic' === $type ) {
		if ( ! isset( $input['forum_id'] ) ) {
			return new WP_Error( 'missing_forum_id', 'forum_id is required for topic drafts.', array( 'status' => 400 ) );
		}
		if ( $forum_id < 0 ) {
			return new WP_Error( 'invalid_forum_id', 'forum_id must be >= 0.', array( 'status' => 400 ) );
		}
	} elseif ( 'reply' === $type ) {
		if ( $topic_id <= 0 ) {
			return new WP_Error( 'invalid_topic_id', 'topic_id is required for reply drafts.', array( 'status' => 400 ) );
		}
	} else {
		return new WP_Error( 'invalid_type', 'Invalid type.', array( 'status' => 400 ) );
	}

	// --- Build context and retrieve draft ---
	$user_id = extrachill_community_resolve_user_id( $input );
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}
	if ( ! $user_id ) {
		return new WP_Error( 'not_logged_in', 'A valid user is required.', array( 'status' => 401 ) );
	}

	$draft_input = array(
		'user_id'           => $user_id,
		'type'              => $type,
		'blog_id'           => (int) get_current_blog_id(),
		'forum_id'          => $forum_id,
		'topic_id'          => $topic_id,
		'reply_to'          => isset( $input['reply_to'] ) ? (int) $input['reply_to'] : 0,
		'prefer_unassigned' => ! empty( $input['prefer_unassigned'] ),
	);

	$draft = extrachill_community_ability_get_bbpress_draft( $draft_input );

	if ( is_wp_error( $draft ) ) {
		return $draft;
	}

	return array( 'draft' => $draft );
}
