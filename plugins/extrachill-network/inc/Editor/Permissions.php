<?php
/**
 * Editor permissions block builder.
 *
 * Generic helper that computes the {canSave, canUploadMedia, canDelete}
 * envelope for any editor load response. Caller passes the cap names so this
 * primitive stays decoupled from any specific content type — every consumer
 * feeds it whatever caps gate the underlying object.
 *
 * @package ExtraChillNetwork\Editor
 */

namespace ExtraChillNetwork\Editor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Permissions {

	/**
	 * Build the {canSave, canUploadMedia, canDelete} permissions envelope.
	 *
	 * The caller passes:
	 *   - `object_id` — the post/comment/etc. ID to check caps against.
	 *   - `edit_cap` — capability name for save/edit.
	 *   - `delete_cap` — capability name for delete.
	 *   - optional `save_guard` — callable returning false to override canSave
	 *     to false (e.g. an edit-lock window or other domain-specific gate).
	 *   - optional `upload_cap` — defaults to 'upload_files'.
	 *
	 * Returns the array shape contracted in extrachill-network#33.
	 *
	 * @param array $args See above.
	 * @return array{canSave: bool, canUploadMedia: bool, canDelete: bool}
	 */
	public static function build( array $args ): array {
		$object_id  = isset( $args['object_id'] ) ? (int) $args['object_id'] : 0;
		$edit_cap   = isset( $args['edit_cap'] ) ? (string) $args['edit_cap'] : '';
		$delete_cap = isset( $args['delete_cap'] ) ? (string) $args['delete_cap'] : '';
		$upload_cap = isset( $args['upload_cap'] ) ? (string) $args['upload_cap'] : 'upload_files';
		$save_guard = isset( $args['save_guard'] ) && is_callable( $args['save_guard'] )
			? $args['save_guard']
			: null;

		$can_save = 0 < $object_id && '' !== $edit_cap && current_user_can( $edit_cap, $object_id );
		if ( $can_save && $save_guard ) {
			$can_save = (bool) call_user_func( $save_guard, $object_id );
		}

		$can_delete = 0 < $object_id && '' !== $delete_cap && current_user_can( $delete_cap, $object_id );

		return array(
			'canSave'        => (bool) $can_save,
			'canUploadMedia' => (bool) ( is_user_logged_in() && current_user_can( $upload_cap ) ),
			'canDelete'      => (bool) $can_delete,
		);
	}
}
