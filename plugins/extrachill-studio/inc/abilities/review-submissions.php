<?php
/**
 * Read-only access to the existing main-site editorial queue.
 *
 * @package ExtraChillStudio
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared permission gate for render and abilities.
 *
 * @return bool Whether review access is granted.
 */
function ec_studio_can_review_submissions(): bool {
	return function_exists( 'ec_can_review_submissions' ) && ec_can_review_submissions();
}

/**
 * List pending submissions using the authoritative queue admission rules.
 *
 * @return array|WP_Error Queue rows or access error.
 */
function ec_studio_list_review_submissions() {
	if ( ! ec_studio_can_review_submissions() ) {
		return new WP_Error( 'review_forbidden', __( 'Submission review access is required.', 'extrachill-studio' ), array( 'status' => 403 ) );
	}
	$main = (int) ec_get_blog_id( 'main' );
	$rows = ec_studio_review_queue_fetch_submissions( $main );
	$edit = ec_studio_review_queue_user_can_review_main( $main );
	foreach ( $rows as &$row ) {
		unset( $row['preview_url'] );
		if ( ! $edit ) {
			unset( $row['edit_url'] );
		}
	}
	return $rows;
}

/**
 * Read only a post admitted by the existing pending team queue.
 *
 * @param array $input Ability input with submission ID.
 * @return array|WP_Error Article or access/not-found error.
 */
function ec_studio_read_review_submission( array $input ) {
	$rows = ec_studio_list_review_submissions();
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$id = (int) ( $input['id'] ?? 0 );
	foreach ( $rows as $row ) {
		if ( $row['id'] !== $id ) {
			continue;
		}
		switch_to_blog( (int) ec_get_blog_id( 'main' ) );
		$previous_post = $GLOBALS['post'] ?? null;
		try {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || 'pending' !== $post->post_status || 'post' !== $post->post_type ) {
				break;
			}
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Content filters require the main-site article as loop context.
			$GLOBALS['post'] = $post;
			setup_postdata( $post );
			$row['content']        = wp_kses_post( apply_filters( 'the_content', $post->post_content ) );
			$row['featured_image'] = (string) get_the_post_thumbnail_url( $id, 'large' );
			$row['categories']     = wp_get_post_categories( $id, array( 'fields' => 'names' ) );
			$row['tags']           = wp_get_post_tags( $id, array( 'fields' => 'names' ) );
			if ( is_wp_error( $row['categories'] ) ) {
				$row['categories'] = array();
			}
			if ( is_wp_error( $row['tags'] ) ) {
				$row['tags'] = array();
			}
			return $row;
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the caller's loop context even if rendering throws.
			$GLOBALS['post'] = $previous_post;
			if ( $previous_post instanceof WP_Post ) {
				setup_postdata( $previous_post );
			}
			restore_current_blog();
		}
	}
	return new WP_Error( 'submission_not_found', __( 'Submission not found in the pending queue.', 'extrachill-studio' ), array( 'status' => 404 ) );
}

/** Register read-only abilities on core's REST transport. */
function ec_studio_register_review_abilities(): void {
	foreach ( array( 'list', 'read' ) as $action ) {
		wp_register_ability(
			'extrachill/' . $action . '-review-submissions',
			array(
				'label'               => 'list' === $action ? __( 'List submissions', 'extrachill-studio' ) : __( 'Read submission', 'extrachill-studio' ),
				'description'         => __( 'Read pending main-site team articles without editor access.', 'extrachill-studio' ),
				'category'            => 'extrachill',
				'input_schema'        => 'read' === $action ? array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'   => array( 'id' ),
				) : array( 'type' => 'object' ),
				'output_schema'       => 'list' === $action ? array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				) : array( 'type' => 'object' ),
				'permission_callback' => 'ec_studio_can_review_submissions',
				'execute_callback'    => 'list' === $action ? 'ec_studio_list_review_submissions' : 'ec_studio_read_review_submission',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}
}
add_action( 'wp_abilities_api_init', 'ec_studio_register_review_abilities' );
