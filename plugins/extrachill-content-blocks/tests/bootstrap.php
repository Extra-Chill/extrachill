<?php
/**
 * PHPUnit bootstrap for source and generated-asset contracts plus
 * business-logic tests (run against minimal in-memory WordPress stubs).
 *
 * @package ExtraChillContentBlocks
 */

define( 'EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT', dirname( __DIR__ ) );

require_once __DIR__ . '/phpunit/includes/wp-stubs.php';

wp_stubs_reset();

/**
 * Test double for the entry photo storage step (the real implementation is
 * guarded with function_exists in src/blocks/photo-contest/index.php so this
 * fake wins when defined first). Skips the filesystem and registers an
 * in-memory attachment parented to the contest post.
 *
 * @param int    $post_id      Contest post ID.
 * @param array  $file         $_FILES-shaped upload.
 * @param string $display_name Entrant display name.
 * @return int|array Attachment ID, or failure array.
 */
function extrachill_content_blocks_photo_contest_store_photo( $post_id, array $file, $display_name ) {
	if ( ! empty( $file['force_store_failure'] ) ) {
		return array(
			'success' => false,
			'message' => 'Simulated storage failure.',
			'code'    => 'upload_failed',
		);
	}

	return wp_stubs_insert_post(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'post_parent' => (int) $post_id,
			'post_title'  => $display_name . ' — contest entry',
		)
	);
}

require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/src/blocks/image-voting/index.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/src/blocks/photo-contest/index.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/abilities/photo-contest-vote.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/abilities/photo-contest-hide.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/abilities/photo-contest-list.php';
