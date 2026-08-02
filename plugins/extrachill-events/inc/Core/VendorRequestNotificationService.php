<?php
/**
 * Vendor application notification receipts.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

use function add_action;
use function is_wp_error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Delivers privacy-safe coordinator notifications with Users receipts. */
class VendorRequestNotificationService {

	public const PRODUCER = 'extrachill-events-vendor-requests';
	/** @var VendorRequestRepository */
	private $repository;
	/** @var VendorRequestAuthorization */
	private $authorization;
	/** @var callable|null */
	private $delivery;
	/** @var callable|null */
	private $actor;

	public function __construct( ?VendorRequestRepository $repository = null, ?VendorRequestAuthorization $authorization = null, $delivery = null, $actor = null ) {
		$this->repository    = $repository ? $repository : new VendorRequestRepository();
		$this->authorization = $authorization ? $authorization : new VendorRequestAuthorization();
		$this->delivery      = is_callable( $delivery ) ? $delivery : null;
		$this->actor         = is_callable( $actor ) ? $actor : null;
	}

	public static function register(): void {
		add_action( 'extrachill_events_vendor_request_changed', array( self::class, 'handle_change' ) );
	}

	public static function handle_change( array $change ): void {
		( new self() )->notify_change( $change );
	}

	/** Deliver and persist one privacy-safe application receipt. */
	public function notify_change( array $change ) {
		if ( 'application_submitted' !== ( $change['kind'] ?? '' ) ) {
			return null;
		}
		$request = $this->repository->get_request( absint( $change['request_id'] ?? 0 ) );
		if ( ! is_array( $request ) ) {
			return is_wp_error( $request ) ? $request : new \WP_Error( 'vendor_notification_request_missing' );
		}
		$recipient = (int) $request['coordinator_user_id'];
		$allowed   = $this->authorization->authorize_coordinator( $request, $recipient );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$actor_id = $this->actor ? absint( call_user_func( $this->actor ) ) : ( function_exists( 'ec_get_network_bot_user_id' ) ? absint( ec_get_network_bot_user_id() ) : $recipient );
		$payload  = array(
			'actor_id'        => $actor_id,
			'type'            => 'vendor_application_submitted',
			'title'           => __( 'A vendor applied to your event', 'extrachill-events' ),
			'link'            => get_home_url( function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'events' ) ) : null, '/vendor-requests/' . $request['id'] . '/' ),
			'item_id'         => (int) $request['event_id'],
			'producer'        => self::PRODUCER,
			'idempotency_key' => sprintf( 'vendor-application:%d:%d', absint( $change['application_id'] ?? 0 ), absint( $change['version'] ?? 0 ) ),
		);
		$receipt  = $this->delivery
			? call_user_func( $this->delivery, array( $recipient ), $payload )
			: ( function_exists( 'ec_users_notify_with_receipts' ) ? ec_users_notify_with_receipts( array( $recipient ), $payload ) : new \WP_Error( 'vendor_notification_receipts_unavailable' ) );
		$status   = is_wp_error( $receipt ) ? 'failed' : ( $receipt['recipients'][ $recipient ]['status'] ?? 'failed' );
		return $this->repository->append_activity(
			array(
				'request_id'      => $request['id'],
				'application_id'  => absint( $change['application_id'] ?? 0 ),
				'kind'            => 'notification_receipt',
				'actor_user_id'   => $payload['actor_id'],
				'idempotency_key' => 'vendor-notification-receipt:' . $payload['idempotency_key'],
				'request_hash'    => hash( 'sha256', $payload['idempotency_key'] ),
				'result_version'  => absint( $change['version'] ?? 0 ),
				'payload'         => array( 'status' => sanitize_key( $status ) ),
			)
		);
	}
}
