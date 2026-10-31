<?php
namespace DataMachine\Engine\AI\Actions {
	class PendingActionHelper {
		public static function stage( array $args ): array {
			$GLOBALS['review_stages'][] = $args;
			return array(
				'staged'    => true,
				'action_id' => 'act_test',
			);
		}
	}
	class SignPendingActionResolutionAbility {
		public static function execute( array $args ): array {
			$GLOBALS['review_signs'][] = $args;
			return $GLOBALS['review_sign_result'];
		}
	}
	class PendingActionAuthorizationReceipt {
		public static function consume( ...$args ) {
			return $GLOBALS['review_receipt_valid'] ? true : new \WP_Error( 'invalid_receipt', 'Invalid receipt' );
		}
	}
}

namespace ExtraChillEvents\Abilities {
	class EventSubmissionAbilities {
		public static function dispatchEmail( array $args, string $audience ): void {
			$GLOBALS['review_emails'][] = $args;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	class WP_Error {
		public function __construct( public $code, public $message ) {}
		public function get_error_message() {
			return $this->message;
		}
	}
	function is_wp_error( $v ) {
		return $v instanceof WP_Error;
	}
	function add_action( ...$args ) {
		$GLOBALS['review_hooks'][ $args[0] ] = $args[1];
	}
	function add_filter( ...$args ) {
		add_action( ...$args );
	}
	function do_action( $name, ...$args ) {
		if ( isset( $GLOBALS['review_hooks'][ $name ] ) ) {
			call_user_func_array( $GLOBALS['review_hooks'][ $name ], $args );
		}
	}
	function apply_filters( $name, $value ) {
		return $GLOBALS['review_recipient'] ?? $value;
	}
	function get_post( $id ) {
		return $GLOBALS['review_posts'][ $id ] ?? null;
	}
	function get_post_meta( $id, $key, $single ) {
		return $GLOBALS['review_meta'][ $id ][ $key ] ?? '';
	}
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['review_meta'][ $id ][ $key ] = $value;
	}
	function add_post_meta( $id, $key, $value, $unique ) {
		if ( isset( $GLOBALS['review_meta'][ $id ][ $key ] ) ) {
			return false;
		}
		update_post_meta( $id, $key, $value );
		return true;
	}
	function delete_post_meta( $id, $key ) {
		unset( $GLOBALS['review_meta'][ $id ][ $key ] );
	}
	function get_the_title( $id ) {
		return get_post( $id )->post_title;
	}
	function get_option( $name ) {
		return 'admin@example.test';
	}
	function get_bloginfo( $name ) {
		return 'Test';
	}
	function is_email( $email ) {
		return filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
	function wp_get_post_terms( ...$args ) {
		return array( 'Test Venue' );
	}
	function admin_url( $path ) {
		return 'https://example.test/wp-admin/' . $path;
	}
	function get_permalink( $id ) {
		return 'https://example.test/event/' . $id;
	}
	function esc_html( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES );
	}
	function esc_url( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES );
	}
	function user_can( $id, $cap, ...$args ) {
		return in_array( $cap, $GLOBALS['review_caps'], true );
	}
	function get_post_type_object( $type ) {
		return (object) array( 'cap' => (object) array( 'publish_posts' => 'publish_events' ) );
	}
	function wp_update_post( $args, $error ) {
		$post              = get_post( $args['ID'] );
		$old               = $post->post_status;
		$post->post_status = $args['post_status'];
		do_action( 'transition_post_status', $post->post_status, $old, $post );
		return $post->ID;
	}
	function wp_trash_post( $id ) {
		return wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'trash',
			),
			true
		);
	}
	require_once dirname( __DIR__, 3 ) . '/inc/Abilities/EventSubmissionApproval.php';

	use ExtraChillEvents\Abilities\EventSubmissionApproval as Review;

	final class EventSubmissionApprovalTest extends \PHPUnit\Framework\TestCase {
		protected function setUp(): void {
			$GLOBALS['review_posts']         = array(
				1 => (object) array(
					'ID'          => 1,
					'post_type'   => 'data_machine_events',
					'post_status' => 'pending',
					'post_title'  => 'A & B',
				),
			);
			$GLOBALS['review_meta']          = array(
				1 => array(
					'_datamachine_submitter_email' => 'fan@example.test',
					'_datamachine_submitter_name'  => 'Fan',
				),
			);
			$GLOBALS['review_emails']        = array();
			$GLOBALS['review_stages']        = array();
			$GLOBALS['review_signs']         = array();
			$GLOBALS['review_caps']          = array();
			$GLOBALS['review_receipt_valid'] = true;
			$GLOBALS['review_sign_result']   = array(
				'success'     => true,
				'approve_url' => 'https://example.test/approve',
				'reject_url'  => 'https://example.test/reject',
				'expires_at'  => '2030-01-08T00:00:00Z',
			);
			unset( $GLOBALS['review_recipient'], $GLOBALS['review_hooks']['transition_post_status'] );
			Review::register();
		}

		private function upsert(): void {
			do_action( 'datamachine_events_after_event_upsert_persistence', array(), 1, array( 'success' => true ) );
		}

		public function test_stages_after_persistence_and_sends_one_actionable_email(): void {
			$GLOBALS['review_recipient'] = 'founder@example.test';
			$this->upsert();
			$this->upsert();
			$this->assertCount( 1, $GLOBALS['review_stages'] );
			$stage = $GLOBALS['review_stages'][0];
			$this->assertSame( Review::KIND, $stage['kind'] );
			$this->assertSame( array( 'post_id' => 1 ), $stage['apply_input'] );
			$this->assertSame( 604800, $stage['ttl'] );
			$this->assertSame( array(), $stage['resolver_grants'] );
			$this->assertSame( 0, $stage['agent_id'] );
			$this->assertSame( 604800, $GLOBALS['review_signs'][0]['lifetime'] );
			$this->assertCount( 1, $GLOBALS['review_emails'] );
			$mail = $GLOBALS['review_emails'][0];
			$this->assertSame( 'founder@example.test', $mail['to'] );
			foreach ( array( 'A &amp; B', 'Date:', 'Test Venue', 'fan@example.test', 'View event', 'Edit event', '>Approve<', '>Reject<', '2030-01-08' ) as $text ) {
				$this->assertStringContainsString( $text, $mail['context']['body_html'] );
			}
			$this->assertSame( 'https://example.test/wp-admin/edit.php?post_status=pending&post_type=data_machine_events', $mail['context']['cta_url'] );
		}

		public function test_only_successful_pending_public_submissions_are_staged(): void {
			get_post( 1 )->post_status = 'publish';
			$this->upsert();
			get_post( 1 )->post_status = 'pending';
			unset( $GLOBALS['review_meta'][1]['_datamachine_submitter_email'] );
			$this->upsert();
			$GLOBALS['review_meta'][1]['_datamachine_submitter_email'] = 'fan@example.test';
			Review::afterUpsert( array(), 1, new WP_Error( 'failed', 'Failed' ) );
			$this->assertCount( 0, $GLOBALS['review_stages'] );
		}

		public function test_approval_publishes_through_normal_transition_and_is_noop_afterwards(): void {
			$transitions = array();
			add_action(
				'transition_post_status',
				function ( $new, $old ) use ( &$transitions ) {
					$transitions[] = array( $old, $new );
				}
			);
			$handler = Review::handlers( array() )[ Review::KIND ];
			$result  = call_user_func( $handler['apply'], array( 'post_id' => 1 ), array(), array() );
			$this->assertTrue( $result['success'] );
			$this->assertSame( array( array( 'pending', 'publish' ) ), $transitions );
			$this->assertTrue( Review::approve( array( 'post_id' => 1 ), array(), array() )['noop'] );
			$this->assertCount( 1, $transitions );
		}

		public function test_invalid_receipt_cannot_publish(): void {
			$GLOBALS['review_receipt_valid'] = false;
			$this->assertInstanceOf( WP_Error::class, Review::approve( array( 'post_id' => 1 ), array(), array() ) );
			$this->assertSame( 'pending', get_post( 1 )->post_status );
		}

		public function test_rejection_trashes_but_does_not_touch_an_already_resolved_event(): void {
			$action   = new class() {
				public function get_kind() {
					return Review::KIND;
				}
				public function get_apply_input() {
					return array( 'post_id' => 1 );
				}
			};
			$decision = new class() {
				public function is_rejected() {
					return true;
				}
			};
			do_action( 'datamachine_pending_action_resolved', $action, $decision );
			$this->assertSame( 'trash', get_post( 1 )->post_status );
			get_post( 1 )->post_status = 'publish';
			do_action( 'datamachine_pending_action_resolved', $action, $decision );
			$this->assertSame( 'publish', get_post( 1 )->post_status );
		}

		public function test_permissions_and_expiry_leave_manual_review_available(): void {
			$this->assertTrue( Review::canResolve( array(), 'accepted', 0, array( 'resolution_transport' => 'signed_url' ) ) );
			$this->assertInstanceOf( WP_Error::class, Review::canResolve( array(), 'accepted', 0 ) );
			$payload                = array( 'apply_input' => array( 'post_id' => 1 ) );
			$GLOBALS['review_caps'] = array( 'edit_post', 'publish_events' );
			$this->assertTrue( Review::canResolve( $payload, 'accepted', 5 ) );
			$GLOBALS['review_caps'] = array( 'edit_post' );
			$this->assertInstanceOf( WP_Error::class, Review::canResolve( $payload, 'accepted', 5 ) );
			do_action( 'datamachine_pending_action_expired', array() );
			$this->assertSame( 'pending', get_post( 1 )->post_status );
		}

		public function test_failed_signing_keeps_manual_review_links_and_reuses_action(): void {
			$GLOBALS['review_sign_result'] = array( 'success' => false );
			$this->upsert();
			$this->assertCount( 1, $GLOBALS['review_emails'] );
			$body = $GLOBALS['review_emails'][0]['context']['body_html'];
			$this->assertStringContainsString( 'Signed review links are unavailable', $body );
			$this->assertStringContainsString( 'Edit event', $body );
			Review::approvalLinks( 1 );
			$this->assertCount( 1, $GLOBALS['review_stages'] );
		}

		public function test_queueing_no_longer_sends_processing_email_and_legacy_cta_is_events(): void {
			$source = file_get_contents( dirname( __DIR__, 3 ) . '/inc/Abilities/EventSubmissionAbilities.php' );
			$this->assertStringNotContainsString( '$this->notifyAdmin( $submission, $job_id )', $source );
			$this->assertStringNotContainsString( 'post_status=pending&post_type=post', $source );
		}
	}
}
