<?php
/**
 * Editor load-response envelope builder.
 *
 * Shared shape contract for "load X for the editor" abilities. The native
 * editor consumes a uniform envelope regardless of the underlying content
 * type — this primitive enforces the shape so callers across the network
 * cannot drift it.
 *
 * Generic by design: no knowledge of any specific content type. Callers
 * populate the type identifier and any type-specific fields via the `type`
 * and `extra` parameters.
 *
 * @package ExtraChillNetwork\Editor
 */

namespace ExtraChillNetwork\Editor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LoadEnvelope {

	/**
	 * Build a load-response envelope.
	 *
	 * Required `$args`:
	 *   - `id` int          — object ID.
	 *   - `type` string     — caller-defined type identifier.
	 *   - `content` string  — serialized block markup as-stored.
	 *   - `status` string   — content status (publish, draft, hold, etc.).
	 *   - `permalink` string — public URL.
	 *   - `updated_at` string — RFC3339 timestamp (see mysql_to_rfc3339()).
	 *   - `context` array   — context keys consumed by the editor (blog_id,
	 *                         parent IDs, etc.).
	 *   - `permissions` array — envelope from Permissions::build().
	 *
	 * Optional:
	 *   - `title` string    — for title-bearing types.
	 *   - `draft` array|null — pre-publish draft overlay if one exists.
	 *   - `extra` array     — additional type-specific fields merged into the
	 *                         envelope.
	 *
	 * @param array $args See above.
	 * @return array Envelope.
	 */
	public static function build( array $args ): array {
		$envelope = array(
			'id'          => isset( $args['id'] ) ? (int) $args['id'] : 0,
			'type'        => isset( $args['type'] ) ? (string) $args['type'] : '',
			'content'     => isset( $args['content'] ) ? (string) $args['content'] : '',
			'raw'         => isset( $args['content'] ) ? (string) $args['content'] : '',
			'status'      => isset( $args['status'] ) ? (string) $args['status'] : '',
			'permalink'   => isset( $args['permalink'] ) ? (string) $args['permalink'] : '',
			'updated_at'  => isset( $args['updated_at'] ) ? (string) $args['updated_at'] : '',
			'context'     => isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : array(),
			'permissions' => isset( $args['permissions'] ) && is_array( $args['permissions'] )
				? $args['permissions']
				: array(
					'canSave'        => false,
					'canUploadMedia' => false,
					'canDelete'      => false,
				),
			'draft'       => array_key_exists( 'draft', $args ) ? $args['draft'] : null,
		);

		if ( isset( $args['title'] ) ) {
			$envelope['title'] = (string) $args['title'];
		}

		if ( isset( $args['extra'] ) && is_array( $args['extra'] ) ) {
			$envelope = array_merge( $envelope, $args['extra'] );
		}

		return $envelope;
	}
}
