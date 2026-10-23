<?php
/** Entry QR validation and temporary permission boundary tests. */

use PHPUnit\Framework\TestCase;

class PhotoContestEntryQrTest extends TestCase {
	private int $post_id;
	private int $entry_id;

	protected function setUp(): void {
		wp_stubs_reset();
		extrachill_content_blocks_register_photo_contest_entry_qr_ability();
		$this->post_id = wp_stubs_insert_post( array() );
		$GLOBALS['wp_test_posts'][ $this->post_id ]->post_content = 'blocks:' . $this->post_id;
		$GLOBALS['wp_test_blocks'][ $this->post_id ] = array(
			array( 'blockName' => 'extrachill/photo-contest', 'attrs' => array( 'contestId' => 'contest' ) ),
			array( 'blockName' => 'extrachill/photo-contest', 'attrs' => array( 'contestId' => 'other' ) ),
		);
		$this->entry_id = wp_stubs_insert_post( array( 'post_type' => 'attachment', 'post_parent' => $this->post_id ) );
		update_post_meta( $this->entry_id, EXTRACHILL_PHOTO_CONTEST_META_CONTEST, 'contest' );
	}

	private function registerQr( callable $execute ): void {
		wp_register_ability( 'extrachill/generate-qr-code', array(
			'permission_callback' => static fn() => false,
			'execute_callback' => $execute,
		) );
	}

	private function execute( array $overrides = array() ) {
		return wp_get_ability( 'extrachill/photo-contest-entry-qr' )->execute( array_merge( array(
			'post_id' => $this->post_id,
			'contest_id' => 'contest',
			'entry_id' => $this->entry_id,
		), $overrides ) );
	}

	public function test_valid_entry_and_scoped_grant(): void {
		$url = add_query_arg( 'entry', $this->entry_id, get_permalink( $this->post_id ) );
		$observer = static fn( $permission ) => $permission;
		add_filter( 'wp_ability_permission_result', $observer, 5 );
		$this->registerQr( function ( $input ) use ( $url ) {
			$this->assertSame( array( 'url' => $url, 'size' => 600 ), $input );
			$qr = wp_get_ability( 'extrachill/generate-qr-code' );
			$this->assertFalse( $qr->has_permission( array( 'url' => 'https://example.org/arbitrary' ) ) );
			$this->assertFalse( apply_filters( 'wp_ability_permission_result', false, 'other/ability', $input ) );
			$this->assertFalse( $qr->has_permission( null ) );
			return array( 'image' => base64_encode( 'png' ), 'mime_type' => 'image/png' );
		} );
		$this->assertSame( array( 'image' => base64_encode( 'png' ), 'mime_type' => 'image/png' ), $this->execute( array( 'url' => 'https://ignored.example' ) ) );
		$this->assertFalse( wp_get_ability( 'extrachill/generate-qr-code' )->has_permission( array( 'url' => $url ) ) );
		$this->assertCount( 1, $GLOBALS['wp_test_filters']['wp_ability_permission_result'] );
		$meta = wp_get_ability( 'extrachill/photo-contest-entry-qr' )->get_meta();
		$this->assertFalse( $meta['show_in_rest'] );
		$this->assertTrue( $meta['annotations']['readonly'] );
	}

	public function test_hidden_invalid_and_wrong_contest_entries_are_rejected(): void {
		$this->registerQr( function () { $this->fail( 'Invalid entry must not invoke QR generator.' ); } );
		foreach ( array( array( 'entry_id' => 99999 ), array( 'entry_id' => $this->post_id ), array( 'contest_id' => 'other' ), array( 'post_id' => 99999 ) ) as $input ) {
			$error = $this->execute( $input );
			$this->assertInstanceOf( WP_Error::class, $error );
			$this->assertSame( 404, $error->get_error_data()['status'] );
		}
		update_post_meta( $this->entry_id, EXTRACHILL_PHOTO_CONTEST_META_HIDDEN, '1' );
		$this->assertSame( 404, $this->execute()->get_error_data()['status'] );
		$this->assertSame( 400, $this->execute( array( 'entry_id' => 0 ) )->get_error_data()['status'] );
	}

	public function test_filter_is_removed_on_returned_error_and_exception(): void {
		foreach ( array( 'error', 'exception', 'malformed' ) as $mode ) {
			$this->registerQr( static function () use ( $mode ) {
				if ( 'exception' === $mode ) {
					throw new RuntimeException( 'render failed' );
				}
				return 'error' === $mode ? new WP_Error( 'render_failed', 'failed' ) : array();
			} );
			$this->assertSame( 503, $this->execute()->get_error_data()['status'] );
			$this->assertEmpty( $GLOBALS['wp_test_filters']['wp_ability_permission_result'] );
		}
	}

	public function test_missing_generator_is_status_coded(): void {
		$this->assertSame( 503, $this->execute()->get_error_data()['status'] );
	}
}
