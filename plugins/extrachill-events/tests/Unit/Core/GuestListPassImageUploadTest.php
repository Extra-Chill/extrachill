<?php
/**
 * Host-panel pass image upload (extrachill#1118): authorization and file
 * validation run before anything reaches the media library.
 *
 * Pure-PHP unit test with bespoke WP-function doubles (see
 * tests/PromoterAuthorityTest.php for the convention).
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims with the test case class.

if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1024 * 1024 );
}

if ( ! function_exists( 'extrachill_events_user_can_manage_event' ) ) {
	function extrachill_events_user_can_manage_event( $user_id, $event_id ) {
		return in_array( (int) $user_id, $GLOBALS['pass_image_upload_test']['managers'] ?? array(), true );
	}
}

if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
	function wp_check_filetype_and_ext( $file, $filename, $mimes = null ) {
		$ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		foreach ( (array) $mimes as $exts => $mime ) {
			if ( in_array( $ext, explode( '|', $exts ), true ) ) {
				return array(
					'ext'  => $ext,
					'type' => $mime,
				);
			}
		}
		return array(
			'ext'  => false,
			'type' => false,
		);
	}
}

if ( ! function_exists( 'media_handle_upload' ) ) {
	function media_handle_upload( $file_id, $post_id, $post_data = array(), $overrides = array() ) {
		$GLOBALS['pass_image_upload_test']['uploaded'][] = array( $file_id, $post_id, $post_data );
		return 777;
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	// Same shape as EventPerkTest's double, so suite load order doesn't matter.
	function wp_attachment_is_image( $post_id ) {
		return in_array( (int) $post_id, $GLOBALS['event_perk_test']['images'] ?? array(), true );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/guest-list-management.php';

final class GuestListPassImageUploadTest extends PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['pass_image_upload_test'] = array(
			'managers' => array( 5 ),
			'uploaded' => array(),
		);
		$GLOBALS['event_perk_test']        = array( 'images' => array( 777 ) );
		$_FILES = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['pass_image_upload_test'], $GLOBALS['event_perk_test'] );
		$_FILES = array();
		parent::tearDown();
	}

	private function file( string $name, int $size = 2048, int $error = UPLOAD_ERR_OK ): void {
		$_FILES['pass_image'] = array(
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => '/tmp/php-upload-test',
			'error'    => $error,
			'size'     => $size,
		);
	}

	public function test_a_user_who_cannot_manage_the_event_is_rejected(): void {
		$this->file( 'deer.png' );

		$result = extrachill_events_handle_pass_image_upload( 42, 9 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['pass_image_upload_test']['uploaded'] );
	}

	public function test_a_failed_upload_is_rejected(): void {
		$this->file( 'deer.png', 2048, UPLOAD_ERR_PARTIAL );

		$result = extrachill_events_handle_pass_image_upload( 42, 5 );

		$this->assertSame( 'invalid_pass_image', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['pass_image_upload_test']['uploaded'] );
	}

	public function test_an_oversized_image_is_rejected(): void {
		$this->file( 'deer.png', EXTRACHILL_EVENTS_PASS_IMAGE_MAX_BYTES + 1 );

		$result = extrachill_events_handle_pass_image_upload( 42, 5 );

		$this->assertSame( 'invalid_pass_image', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['pass_image_upload_test']['uploaded'] );
	}

	public function test_a_non_image_file_is_rejected(): void {
		$this->file( 'guest-list.pdf' );

		$result = extrachill_events_handle_pass_image_upload( 42, 5 );

		$this->assertSame( 'invalid_pass_image', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['pass_image_upload_test']['uploaded'] );
	}

	public function test_a_manager_uploads_an_image_attached_to_the_event(): void {
		$this->file( 'deer.png' );

		$result = extrachill_events_handle_pass_image_upload( 42, 5 );

		$this->assertSame( 777, $result );
		$this->assertSame( array( array( 'pass_image', 42, array( 'post_author' => 5 ) ) ), $GLOBALS['pass_image_upload_test']['uploaded'] );
	}
}
