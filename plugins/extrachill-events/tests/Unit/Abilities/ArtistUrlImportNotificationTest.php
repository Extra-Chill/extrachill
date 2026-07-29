<?php
/**
 * Tests for artist URL import submitter notification receipts.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- This isolated unit fixture intentionally declares WordPress test doubles.

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return $GLOBALS['artist_import_notification_actor_id'] ?? 0;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return 0 < (int) $user_id ? (object) array( 'ID' => (int) $user_id ) : false;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'ec_users_notify_with_receipts' ) ) {
	function ec_users_notify_with_receipts( $user_ids, array $data ) {
		$GLOBALS['artist_import_notification_calls'][] = compact( 'user_ids', 'data' );
		return $GLOBALS['artist_import_notification_receipt'];
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/Abilities/ArtistUrlImportAbilities.php';

use ExtraChillEvents\Abilities\ArtistUrlImportAbilities;

/** Verify the feature's receipt and replay contracts. */
final class ArtistUrlImportNotificationTest extends PHPUnit\Framework\TestCase {
	/** Reset notification fixtures. */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['artist_import_notification_actor_id'] = 31;
		$GLOBALS['artist_import_notification_calls']    = array();
		$GLOBALS['artist_import_notification_receipt']  = array(
			'requested'  => 1,
			'inserted'   => 0,
			'existing'   => 1,
			'failed'     => 0,
			'recipients' => array( 22 => array( 'status' => 'existing' ) ),
		);
		$GLOBALS['ec_artist_test']                      = array( 'fired_actions' => array() );
	}

	/**
	 * Invoke the private delivery helper through reflection.
	 *
	 * @param array  $submission Submission fixture.
	 * @param string $type       Notification type.
	 * @param int    $item_id    Related object ID.
	 */
	private function notify_submitter( array $submission, string $type, int $item_id ): void {
		$reflection = new ReflectionClass( ArtistUrlImportAbilities::class );
		$instance   = $reflection->newInstanceWithoutConstructor();
		$method     = $reflection->getMethod( 'notifySubmitter' );
		$method->setAccessible( true );
		$method->invoke( $instance, $submission, $type, 'Moderation result', 'https://events.example/artist/', $item_id );
	}

	/** Existing receipts succeed and use the immutable submission row identity. */
	public function test_existing_receipt_uses_submission_id_and_type_as_replay_key(): void {
		$this->notify_submitter(
			array(
				'id'             => 456,
				'user_id'        => 22,
				'artist_term_id' => 999,
			),
			'artist_import_approved',
			999
		);

		$this->assertCount( 1, $GLOBALS['artist_import_notification_calls'] );
		$call = $GLOBALS['artist_import_notification_calls'][0];
		$this->assertSame( array( 22 ), $call['user_ids'] );
		$this->assertSame( 'extrachill-events-artist-url-import', $call['data']['producer'] );
		$this->assertSame( 'submission:456:artist_import_approved', $call['data']['idempotency_key'] );
		$this->assertSame( 999, $call['data']['item_id'] );
		$this->assertArrayNotHasKey( 'datamachine_log', $GLOBALS['ec_artist_test']['fired_actions'] );
	}

	/** Failed receipt status preserves warning logging. */
	public function test_failed_receipt_is_logged(): void {
		$GLOBALS['artist_import_notification_receipt'] = array(
			'requested'  => 1,
			'inserted'   => 0,
			'existing'   => 0,
			'failed'     => 1,
			'recipients' => array(
				22 => array(
					'status' => 'failed',
					'error'  => 'insert_failed',
				),
			),
		);

		$this->notify_submitter(
			array(
				'id'      => 457,
				'user_id' => 22,
			),
			'artist_import_rejected',
			457
		);

		$this->assertCount( 1, $GLOBALS['ec_artist_test']['fired_actions']['datamachine_log'] );
		$log = $GLOBALS['ec_artist_test']['fired_actions']['datamachine_log'][0];
		$this->assertSame( 'warning', $log[0] );
		$this->assertSame( 'ArtistUrlImportAbilities: submitter notification failed', $log[1] );
		$this->assertSame( 1, $log[2]['receipt']['failed'] );
	}
}
