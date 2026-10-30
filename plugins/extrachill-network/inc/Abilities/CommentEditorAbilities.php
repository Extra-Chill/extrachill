<?php
/**
 * Comment Editor Abilities
 *
 * Network-active abilities for loading and updating comment content from the
 * native editor and any other headless caller. Comments live on multiple
 * subsites (main publication + any subsite with comments enabled) and use WP
 * core caps + wp_update_comment(), so the right home is this network plugin
 * rather than any per-site plugin.
 *
 * Block content is stored as serialized markup in comment_content, mirroring
 * the topic/reply storage decision documented in extrachill-network#33.
 *
 * @package ExtraChillNetwork\Abilities
 */

namespace ExtraChillNetwork\Abilities;

use ExtraChillNetwork\Editor\BlogResolver;
use ExtraChillNetwork\Editor\LoadEnvelope;
use ExtraChillNetwork\Editor\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CommentEditorAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/comment-get-for-editor',
			array(
				'label'               => __( 'Get Comment For Editor', 'extrachill-network' ),
				'description'         => __( 'Load a comment for editing: returns serialized comment_content (block markup as-stored) and permissions envelope.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id' => array( 'type' => 'integer' ),
						'blog_id'    => array(
							'type'        => 'integer',
							'description' => 'Defaults to current blog.',
						),
					),
					'required'   => array( 'comment_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'type'        => array(
							'type' => 'string',
							'enum' => array( 'comment' ),
						),
						'content'     => array( 'type' => 'string' ),
						'raw'         => array( 'type' => 'string' ),
						'status'      => array( 'type' => 'string' ),
						'post_id'     => array( 'type' => 'integer' ),
						'permalink'   => array( 'type' => 'string' ),
						'updated_at'  => array(
							'type'   => 'string',
							'format' => 'date-time',
						),
						'context'     => array(
							'type'       => 'object',
							'properties' => array(
								'blog_id' => array( 'type' => 'integer' ),
								'post_id' => array( 'type' => 'integer' ),
							),
						),
						'permissions' => array(
							'type'       => 'object',
							'properties' => array(
								'canSave'        => array( 'type' => 'boolean' ),
								'canUploadMedia' => array( 'type' => 'boolean' ),
								'canDelete'      => array( 'type' => 'boolean' ),
							),
						),
						'draft'       => array(
							'anyOf' => array(
								array( 'type' => 'object' ),
								array( 'type' => 'null' ),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'executeGetForEditor' ),
				'permission_callback' => array( $this, 'permissionGetForEditor' ),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => true,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/comment-update',
			array(
				'label'               => __( 'Update Comment', 'extrachill-network' ),
				'description'         => __( 'Update an existing comment\'s content. Sanitises via wp_kses_post(), calls wp_update_comment(), fires edit_comment so cache and notification hooks trigger.', 'extrachill-network' ),
				'category'            => 'extrachill-network',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'comment_id' => array( 'type' => 'integer' ),
						'content'    => array(
							'type'        => 'string',
							'description' => 'Serialized block markup.',
						),
						'blog_id'    => array( 'type' => 'integer' ),
					),
					'required'   => array( 'comment_id', 'content' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer' ),
						'status'     => array( 'type' => 'string' ),
						'content'    => array( 'type' => 'string' ),
						'permalink'  => array( 'type' => 'string' ),
						'updated_at' => array(
							'type'   => 'string',
							'format' => 'date-time',
						),
					),
				),
				'execute_callback'    => array( $this, 'executeUpdate' ),
				'permission_callback' => array( $this, 'permissionUpdate' ),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);
	}

	// ─── Permission callbacks ──────────────────────────────────────────────

	/**
	 * Permission for comment-get-for-editor.
	 *
	 * Caller must be logged in. Either they authored the comment or they
	 * hold the edit_comment cap against this specific comment ID.
	 *
	 * @param array $input Ability input.
	 * @return bool
	 */
	public function permissionGetForEditor( array $input = array() ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$comment_id = isset( $input['comment_id'] ) ? (int) $input['comment_id'] : 0;
		if ( $comment_id <= 0 ) {
			return false;
		}

		$blog_id = BlogResolver::resolve( $input );
		return (bool) BlogResolver::withBlog(
			$blog_id,
			static function () use ( $comment_id ) {
				$comment = get_comment( $comment_id );
				if ( ! $comment ) {
					return false;
				}
				if ( 0 < (int) $comment->user_id && get_current_user_id() === (int) $comment->user_id ) {
					return true;
				}
				return current_user_can( 'edit_comment', $comment_id );
			}
		);
	}

	/**
	 * Permission for comment-update.
	 *
	 * @param array $input Ability input.
	 * @return bool
	 */
	public function permissionUpdate( array $input = array() ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$comment_id = isset( $input['comment_id'] ) ? (int) $input['comment_id'] : 0;
		if ( $comment_id <= 0 ) {
			return false;
		}

		$blog_id = BlogResolver::resolve( $input );
		return (bool) BlogResolver::withBlog(
			$blog_id,
			static function () use ( $comment_id ) {
				return current_user_can( 'edit_comment', $comment_id );
			}
		);
	}

	// ─── Execute callbacks ─────────────────────────────────────────────────

	/**
	 * Load a comment for the editor.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function executeGetForEditor( array $input ) {
		$comment_id = isset( $input['comment_id'] ) ? (int) $input['comment_id'] : 0;
		if ( $comment_id <= 0 ) {
			return new \WP_Error( 'missing_comment_id', 'A comment_id is required.' );
		}

		$blog_id = BlogResolver::resolve( $input );

		return BlogResolver::withBlog(
			$blog_id,
			static function () use ( $comment_id, $blog_id ) {
				$comment = get_comment( $comment_id );
				if ( ! $comment ) {
					return new \WP_Error( 'comment_not_found', 'Comment not found.', array( 'status' => 404 ) );
				}

				$post_id = (int) $comment->comment_post_ID;

				$permissions = Permissions::build(
					array(
						'object_id'  => $comment_id,
						'edit_cap'   => 'edit_comment',
						'delete_cap' => 'edit_comment', // WP comments use edit_comment as the gate for delete too.
					)
				);

				// "approved" maps to status 'publish' for the editor; everything else passes through.
				$status = '1' === (string) $comment->comment_approved ? 'publish' : (string) $comment->comment_approved;

				return LoadEnvelope::build(
					array(
						'id'          => $comment_id,
						'type'        => 'comment',
						'content'     => (string) $comment->comment_content,
						'status'      => $status,
						'permalink'   => (string) get_comment_link( $comment_id ),
						'updated_at'  => mysql_to_rfc3339( $comment->comment_date_gmt ),
						'context'     => array(
							'blog_id' => $blog_id,
							'post_id' => $post_id,
						),
						'permissions' => $permissions,
						'draft'       => null,
						'extra'       => array(
							'post_id' => $post_id,
						),
					)
				);
			}
		);
	}

	/**
	 * Update an existing comment.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function executeUpdate( array $input ) {
		$comment_id = isset( $input['comment_id'] ) ? (int) $input['comment_id'] : 0;
		if ( $comment_id <= 0 ) {
			return new \WP_Error( 'missing_comment_id', 'A comment_id is required.' );
		}

		$raw_content = isset( $input['content'] ) ? (string) $input['content'] : '';
		$content     = wp_kses_post( $raw_content );
		if ( '' === $content ) {
			return new \WP_Error( 'missing_content', 'Content is required.' );
		}

		$blog_id = BlogResolver::resolve( $input );

		return BlogResolver::withBlog(
			$blog_id,
			static function () use ( $comment_id, $content, $blog_id ) {
				$comment = get_comment( $comment_id );
				if ( ! $comment ) {
					return new \WP_Error( 'comment_not_found', 'Comment not found.', array( 'status' => 404 ) );
				}

				$result = wp_update_comment(
					array(
						'comment_ID'      => $comment_id,
						'comment_content' => $content,
					),
					true
				);

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				if ( false === $result ) {
					return new \WP_Error( 'update_failed', 'Failed to update comment.' );
				}

				$fresh  = get_comment( $comment_id );
				$status = $fresh && '1' === (string) $fresh->comment_approved
					? 'publish'
					: (string) ( $fresh ? $fresh->comment_approved : $comment->comment_approved );

				return array(
					'id'         => (int) $comment_id,
					'status'     => $status,
					'content'    => $fresh ? (string) $fresh->comment_content : $content,
					'permalink'  => (string) get_comment_link( $comment_id ),
					'updated_at' => $fresh
						? mysql_to_rfc3339( $fresh->comment_date_gmt )
						: mysql_to_rfc3339( gmdate( 'Y-m-d H:i:s' ) ),
				);
			}
		);
	}
}
