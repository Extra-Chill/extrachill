<?php
/** Artist access pending-action contract tests. */
namespace DataMachine\Engine\AI\Actions {
	if ( ! class_exists( PendingActionAuthorizationReceipt::class ) ) {
		class PendingActionAuthorizationReceipt {
			public static $calls = array();
			public static function consume( ...$args ) {
				self::$calls[] = $args;
				return ! empty( $args[0]['valid'] ) ? true : new \WP_Error( 'invalid_receipt', 'Invalid receipt.' );
			}
		}
	}
	if ( ! class_exists( PendingActionHelper::class ) ) {
		class PendingActionHelper {
			public static $args;
			public static $fail = false;
			public static function stage( array $args ): array {
				self::$args = $args;
				return self::$fail ? array( 'staged' => false ) : array( 'staged' => true, 'action_id' => 'act_test' );
			}
		}
	}
	if ( ! class_exists( SignPendingActionResolutionAbility::class ) ) {
		class SignPendingActionResolutionAbility {
			public static $input;
			public static function execute( array $input ): array {
				self::$input = $input;
				return array( 'success' => true, 'approve_url' => 'https://example.org/approve', 'reject_url' => 'https://example.org/reject' );
			}
		}
	}
}
namespace {
	use DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt;
	use DataMachine\Engine\AI\Actions\PendingActionHelper;
	use DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility;

	class Test_Artist_Access_Approval extends WP_UnitTestCase {
		protected function setUp(): void {
			parent::setUp();
			require_once dirname( __DIR__, 2 ) . '/inc/core/artist-access-approval.php';
			require_once dirname( __DIR__, 2 ) . '/inc/core/abilities/artist-access.php';
			wp_set_current_user( 0 );
		}

		public function test_resolver_authorization(): void {
			$this->assertTrue( extrachill_users_can_resolve_artist_access_action( array(), 'accepted', 0, array( 'resolution_transport' => 'signed_url' ) ) );
			$admin = self::factory()->user->create();
			grant_super_admin( $admin );
			$this->assertTrue( extrachill_users_can_resolve_artist_access_action( array(), 'accepted', $admin ) );
			foreach ( array( 0, self::factory()->user->create( array( 'role' => 'subscriber' ) ) ) as $user_id ) {
				$error = extrachill_users_can_resolve_artist_access_action( array(), 'accepted', $user_id );
				$this->assertWPError( $error );
				$this->assertSame( 403, $error->get_error_data()['status'] );
			}
		}

		public function test_grant_validation_and_idempotence(): void {
			$id = self::factory()->user->create();
			$this->assertSame( 'missing_user_id', extrachill_users_grant_artist_access( 0, 'artist' )->get_error_code() );
			$this->assertSame( 'invalid_type', extrachill_users_grant_artist_access( $id, 'other' )->get_error_code() );
			$this->assertSame( 'user_not_found', extrachill_users_grant_artist_access( 99999999, 'artist' )->get_error_code() );
			$this->assertSame( 'no_pending_request', extrachill_users_grant_artist_access( $id, 'artist' )->get_error_code() );
			update_user_meta( $id, 'artist_access_request', array( 'type' => 'professional' ) );
			$this->assertTrue( extrachill_users_grant_artist_access( $id, 'professional' )['success'] );
			$this->assertSame( '1', get_user_meta( $id, 'user_is_professional', true ) );
			$this->assertEmpty( get_user_meta( $id, 'artist_access_request', true ) );
			$this->assertTrue( extrachill_users_grant_artist_access( $id, 'professional' )['skipped'] );
		}

		public function test_anonymous_apply_requires_receipt_then_grants(): void {
			if ( ! property_exists( PendingActionAuthorizationReceipt::class, 'calls' ) ) {
				$this->markTestSkipped( 'Requires the receipt test double.' );
			}
			$id = self::factory()->user->create();
			update_user_meta( $id, 'artist_access_request', array( 'type' => 'artist' ) );
			$input = array( 'user_id' => $id, 'type' => 'artist' );
			$payload = array( 'creator' => 'user:' . $id, 'workspace' => array() );
			$handler = extrachill_users_artist_access_action_handlers( array() )['extrachill_artist_access']['apply'];
			$this->assertWPError( $handler( $input, $payload, array() ) );
			$this->assertEmpty( get_user_meta( $id, 'user_is_artist', true ) );
			$this->assertTrue( $handler( $input, $payload, array( 'valid' => true ) )['success'] );
			$this->assertSame( 0, get_current_user_id() );
			$this->assertSame( '1', get_user_meta( $id, 'user_is_artist', true ) );
			$this->assertEmpty( get_user_meta( $id, 'artist_access_request', true ) );
			$args = end( PendingActionAuthorizationReceipt::$calls );
			$this->assertSame( array( 'extrachill_artist_access', 'extrachill_artist_access', $input, $input, 'user:' . $id, array() ), array_slice( $args, 1 ) );
		}

		public function test_staging_stores_id_and_operator_only_grant(): void {
			if ( ! property_exists( PendingActionHelper::class, 'fail' ) ) {
				$this->markTestSkipped( 'Requires the staging test double.' );
			}
			$id = self::factory()->user->create();
			update_user_meta( $id, 'artist_access_request', array( 'type' => 'artist' ) );
			$this->assertSame( 'https://example.org/approve', extrachill_users_stage_artist_access_approval( $id, get_userdata( $id ), 'artist' ) );
			$this->assertSame( 'act_test', get_user_meta( $id, 'artist_access_request', true )['action_id'] );
			$this->assertSame( 2592000, PendingActionHelper::$args['ttl'] );
			$this->assertSame( array( array( 'type' => 'operator', 'kinds' => array( 'extrachill_artist_access' ) ) ), PendingActionHelper::$args['resolver_grants'] );
			$this->assertSame( array( 'action_id' => 'act_test', 'lifetime' => 2592000 ), SignPendingActionResolutionAbility::$input );
			PendingActionHelper::$fail = true;
			try {
				$this->assertSame( '', extrachill_users_stage_artist_access_approval( $id, get_userdata( $id ), 'artist' ) );
			} finally {
				PendingActionHelper::$fail = false;
			}
		}
	}
}
