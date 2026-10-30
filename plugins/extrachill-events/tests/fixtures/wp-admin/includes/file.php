<?php
/**
 * Test stand-in for `wp-admin/includes/file.php`.
 *
 * `EventSubmissionAbilities::storeFlyer()` does
 * `require_once ABSPATH . 'wp-admin/includes/file.php'` before calling
 * `wp_handle_upload()`. The plain-PHP unit harness points ABSPATH at
 * tests/fixtures/ (see tests/bootstrap.php) and has no real WordPress
 * core, so this fixture supplies a minimal, deterministic stand-in for
 * the one function that call site needs — following the same pattern as
 * tests/fixtures/wp-admin/includes/upgrade.php's `dbDelta()` stub.
 *
 * @package ExtraChillEvents\Tests
 */

if ( ! function_exists( 'wp_handle_upload' ) ) {
	/**
	 * Simulate a successful upload without touching the filesystem.
	 *
	 * Tests that need a failed upload set
	 * $GLOBALS['ec_test_wp_handle_upload_error'] before calling into
	 * storeFlyer().
	 *
	 * @param array $file Raw $_FILES-shaped array.
	 * @param array $overrides Unused; matches core signature.
	 * @return array Upload result shaped like core's wp_handle_upload().
	 */
	function wp_handle_upload( $file, $overrides = array() ) {
		unset( $overrides );

		if ( ! empty( $GLOBALS['ec_test_wp_handle_upload_error'] ) ) {
			return array( 'error' => $GLOBALS['ec_test_wp_handle_upload_error'] );
		}

		return array(
			'file' => $file['tmp_name'] ?? '/tmp/ec-test-upload-does-not-exist',
			'url'  => 'https://example.test/uploads/' . ( $file['name'] ?? 'flyer' ),
			'type' => $file['type'] ?? 'application/octet-stream',
		);
	}
}
