<?php
/**
 * Event-scoped vendor request domain service.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

use function do_action;
use function is_wp_error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns vendor request lifecycle, application privacy, and idempotency. */
class VendorRequestService {

	public const APPLICATION_STATUSES = array( 'submitted', 'reviewing', 'accepted', 'declined', 'withdrawn' );

	/** @var VendorRequestRepository */
	private $repository;
	/** @var VendorRequestAuthorization */
	private $authorization;

	public function __construct( ?VendorRequestRepository $repository = null, ?VendorRequestAuthorization $authorization = null ) {
		$this->repository    = $repository ? $repository : new VendorRequestRepository();
		$this->authorization = $authorization ? $authorization : new VendorRequestAuthorization();
	}

	/** Open one event request and bind the opener as its exact coordinator. */
	public function open_request( int $event_id, array $policy, string $idempotency_key, int $actor_id ) {
		$key = $this->key( $idempotency_key );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$context = $this->authorization->event_context( $event_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$request = array(
			'event_id'            => $event_id,
			'venue_term_id'       => $context['venue_term_id'],
			'coordinator_user_id' => $actor_id,
		);
		$allowed = $this->authorization->authorize_coordinator( $request, $actor_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$policy = $this->normalize_policy( $policy );
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}
		$hash     = $this->hash(
			'open',
			array(
				'event_id' => $event_id,
				'policy'   => $policy,
			),
			$actor_id
		);
		$existing = $this->repository->get_request_by_event( $event_id );
		if ( is_array( $existing ) ) {
			return (int) $existing['coordinator_user_id'] === $actor_id && $existing['policy'] === $policy ? $existing : $this->idempotency_conflict();
		}
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$allowed = $this->authorization->authorize_coordinator( $request, $actor_id );
		if ( true !== $allowed ) {
			return $this->rollback( $allowed );
		}
		$created = $this->repository->create_request( array_merge( $request, array( 'policy' => $policy ) ) );
		if ( is_wp_error( $created ) ) {
			return $this->rollback( $created );
		}
		$activity = $this->record( $created['id'], null, 'request_opened', $actor_id, $key, $hash, 1, array( 'status' => 'open' ) );
		if ( is_wp_error( $activity ) ) {
			return $this->rollback( $activity );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		$this->emit( 'request_opened', $created['id'], null, 1 );
		return $created;
	}

	/** Open or close only as the exact persisted coordinator. */
	public function set_request_open( int $request_id, bool $open, int $expected_version, string $idempotency_key, int $actor_id ) {
		$request = $this->repository->get_request( $request_id );
		if ( ! is_array( $request ) ) {
			return is_wp_error( $request ) ? $request : $this->not_found();
		}
		$allowed = $this->authorization->authorize_coordinator( $request, $actor_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$status = $open ? 'open' : 'closed';
		if ( $request['status'] === $status ) {
			return $request;
		}
		$key = $this->key( $idempotency_key );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( (int) $request['version'] !== $expected_version ) {
			return $this->version_conflict();
		}
		$hash    = $this->hash( 'request_status_changed', array( 'status' => $status ), $actor_id );
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->repository->get_request( $request_id, true );
		if ( ! is_array( $locked ) || (int) $locked['version'] !== $expected_version ) {
			return $this->rollback( $this->version_conflict() );
		}
		$allowed = $this->authorization->authorize_coordinator( $locked, $actor_id );
		if ( true !== $allowed ) {
			return $this->rollback( $allowed );
		}
		$updated  = $this->repository->update_request( $request_id, $expected_version, array( 'status' => $status ) );
		$activity = is_wp_error( $updated ) ? $updated : $this->record( $request_id, null, 'request_status_changed', $actor_id, $key, $hash, $updated['version'], array( 'status' => $status ) );
		if ( is_wp_error( $activity ) ) {
			return $this->rollback( $activity );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		$this->emit( 'request_status_changed', $request_id, null, $updated['version'] );
		return $updated;
	}

	/** Return a deliberately contact-free public projection only while open. */
	public function public_request_for_event( int $event_id ) {
		$request = $this->repository->get_request_by_event( $event_id );
		if ( ! is_array( $request ) || 'open' !== $request['status'] ) {
			return is_wp_error( $request ) ? $request : null;
		}
		return array(
			'public_id' => $request['public_id'],
			'event_id'  => $request['event_id'],
			'policy'    => $request['policy'],
			'status'    => 'open',
		);
	}

	/** Admit one private application under a request-row close-race lock. */
	public function apply( array $input, int $actor_id = 0 ) {
		$key = $this->key( $input['idempotency_key'] ?? '' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$request = $this->repository->get_request_by_event( absint( $input['event_id'] ?? 0 ) );
		if ( ! is_array( $request ) || 'open' !== $request['status'] ) {
			return is_wp_error( $request ) ? $request : new \WP_Error( 'vendor_request_not_open', __( 'This event is not accepting vendor applications.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$application = $this->normalize_application( $input, $request['policy'] );
		if ( is_wp_error( $application ) ) {
			return $application;
		}
		$hash   = $this->hash( 'apply', $application, $actor_id );
		$replay = $this->repository->find_application_retry( $request['id'], $key );
		if ( is_array( $replay ) ) {
			if ( ! hash_equals( (string) $replay['_replay_hash'], $hash ) ) {
				return $this->idempotency_conflict();
			}
			unset( $replay['_replay_hash'] );
			return array_merge( $this->public_receipt( $replay ), array( 'access_token' => $this->application_token( $request, $key, $hash ) ) );
		}
		if ( is_wp_error( $replay ) ) {
			return $replay;
		}
		$token   = $this->application_token( $request, $key, $hash );
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->repository->get_request( $request['id'], true );
		if ( ! is_array( $locked ) || 'open' !== $locked['status'] ) {
			return $this->rollback( new \WP_Error( 'vendor_request_not_open', __( 'This event is not accepting vendor applications.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		$created = $this->repository->create_application(
			array_merge(
				$application,
				array(
					'request_id'        => $request['id'],
					'idempotency_key'   => $key,
					'request_hash'      => $hash,
					'access_token_hash' => hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ),
					'submitter_user_id' => $actor_id > 0 ? $actor_id : null,
				)
			)
		);
		if ( is_wp_error( $created ) ) {
			return $this->rollback( $created );
		}
		$activity = $this->record( $request['id'], $created['id'], 'application_submitted', $actor_id, $key, $hash, 1, array( 'status' => 'submitted' ) );
		if ( is_wp_error( $activity ) ) {
			return $this->rollback( $activity );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		$this->emit( 'application_submitted', $request['id'], $created['id'], 1 );
		return array_merge( $this->public_receipt( $created ), array( 'access_token' => $token ) );
	}

	/** List private applications after current event organizer authorization. */
	public function list_applications( int $request_id, int $actor_id ) {
		$request = $this->repository->get_request( $request_id );
		if ( ! is_array( $request ) ) {
			return is_wp_error( $request ) ? $request : $this->not_found();
		}
		$allowed = $this->authorization->authorize_organizer( $request, $actor_id );
		return true === $allowed ? $this->repository->list_applications( $request_id ) : $allowed;
	}

	/** Update organizer-owned review status and notes without changing contact consent. */
	public function review_application( int $application_id, string $status, string $notes, int $expected_version, string $idempotency_key, int $actor_id ) {
		$application = $this->repository->get_application( $application_id );
		$request     = is_array( $application ) ? $this->repository->get_request( $application['request_id'] ) : null;
		if ( ! is_array( $application ) || ! is_array( $request ) ) {
			return is_wp_error( $application ) ? $application : $this->not_found();
		}
		$allowed = $this->authorization->authorize_organizer( $request, $actor_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$status = sanitize_key( $status );
		if ( ! in_array( $status, array( 'submitted', 'reviewing', 'accepted', 'declined' ), true ) || 'withdrawn' === $application['status'] ) {
			return new \WP_Error( 'vendor_application_transition_forbidden', __( 'That application review status is not available.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( (int) $application['version'] !== $expected_version ) {
			return $this->version_conflict();
		}
		$key     = $this->key( $idempotency_key );
		$notes   = mb_substr( sanitize_textarea_field( $notes ), 0, 5000 );
		$changes = array(
			'status'        => $status,
			'private_notes' => '' === $notes ? null : $notes,
		);
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$hash    = $this->hash( 'application_reviewed', $changes, $actor_id );
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->repository->get_application( $application_id, true );
		if ( ! is_array( $locked ) || (int) $locked['version'] !== $expected_version ) {
			return $this->rollback( $this->version_conflict() );
		}
		$allowed = $this->authorization->authorize_organizer( $request, $actor_id );
		if ( true !== $allowed ) {
			return $this->rollback( $allowed );
		}
		$updated  = $this->repository->update_application( $application_id, $expected_version, $changes );
		$activity = is_wp_error( $updated ) ? $updated : $this->record( $request['id'], $application_id, 'application_reviewed', $actor_id, $key, $hash, $updated['version'], array( 'status' => $status ) );
		if ( is_wp_error( $activity ) ) {
			return $this->rollback( $activity );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		$this->emit( 'application_reviewed', $request['id'], $application_id, $updated['version'] );
		return $updated;
	}

	/** Queue coordinator-initiated contact through the managed Extra Chill mail layer. */
	public function contact_applicant( int $application_id, string $subject, string $message, string $idempotency_key, int $actor_id, $queue = null ) {
		$application = $this->repository->get_application( $application_id );
		$request     = is_array( $application ) ? $this->repository->get_request( $application['request_id'] ) : null;
		if ( ! is_array( $application ) || ! is_array( $request ) ) {
			return $this->not_found();
		}
		$allowed = $this->authorization->authorize_organizer( $request, $actor_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		if ( ! is_array( $application['contact'] ) || empty( $application['contact']['email'] ) || 'withdrawn' === $application['status'] ) {
			return new \WP_Error( 'vendor_application_contact_unavailable', __( 'The applicant has not authorized managed contact.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$key     = $this->key( $idempotency_key );
		$subject = mb_substr( sanitize_text_field( $subject ), 0, 255 );
		$message = mb_substr( sanitize_textarea_field( $message ), 0, 10000 );
		if ( is_wp_error( $key ) || '' === $subject || '' === $message ) {
			return is_wp_error( $key ) ? $key : new \WP_Error( 'invalid_vendor_correspondence', __( 'A subject and message are required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$hash     = $this->hash( 'applicant_contacted', compact( 'application_id', 'subject', 'message' ), $actor_id );
		$existing = $this->repository->find_activity( $request['id'], $key );
		if ( is_array( $existing ) ) {
			return hash_equals( (string) $existing['request_hash'], $hash ) ? $existing['payload'] : $this->idempotency_conflict();
		}
		$body   = wpautop( esc_html( $message ) );
		$body  .= '<p><strong>Powered by Extra Chill.</strong> Reply through the managed vendor application workflow; applicant and coordinator directories are not shared.</p>';
		$input  = array(
			'to'           => $application['contact']['email'],
			'cc'           => '',
			'subject'      => $subject,
			'body'         => $body,
			'content_type' => 'text/html',
			'from_name'    => 'Extra Chill Events',
		);
		$result = is_callable( $queue ) ? call_user_func( $queue, $input ) : ( function_exists( 'ec_send_email_queued' ) ? ec_send_email_queued( $input ) : new \WP_Error( 'vendor_correspondence_unavailable' ) );
		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['success'] ) || absint( $result['action_id'] ?? 0 ) < 1 ) {
			return new \WP_Error( 'vendor_correspondence_uncertain', __( 'The managed message queue outcome is uncertain.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$receipt = array(
			'status'    => 'queued',
			'action_id' => absint( $result['action_id'] ),
		);
		$record  = $this->record( $request['id'], $application_id, 'applicant_contact_queued', $actor_id, $key, $hash, $application['version'], $receipt );
		return is_wp_error( $record ) ? $record : $receipt;
	}

	/** Applicant-owned withdrawal atomically revokes all contact disclosure. */
	public function withdraw( string $public_id, string $access_token, int $expected_version, string $idempotency_key ) {
		$application = $this->repository->get_application_by_public_id( sanitize_text_field( $public_id ) );
		if ( ! is_array( $application ) ) {
			return $this->not_found();
		}
		$key = $this->key( $idempotency_key );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$hash    = $this->hash( 'application_withdrawn', array( 'public_id' => $public_id ), 0 );
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->repository->get_application( $application['id'], true );
		if ( ! is_array( $locked ) || (int) $locked['version'] !== $expected_version || ! $this->repository->verify_application_token( $public_id, $access_token ) ) {
			return $this->rollback( new \WP_Error( 'vendor_application_forbidden', __( 'This vendor application is unavailable.', 'extrachill-events' ), array( 'status' => 403 ) ) );
		}
		$changes = array(
			'status'          => 'withdrawn',
			'contact_payload' => wp_json_encode( array() ),
			'consent_version' => $locked['consent_version'] + 1,
			'revoked_at'      => gmdate( 'Y-m-d H:i:s' ),
		);
		$updated = $this->repository->update_application( $application['id'], $expected_version, $changes );
		if ( is_wp_error( $updated ) ) {
			return $this->rollback( $updated );
		}
		$activity = $this->record( $application['request_id'], $application['id'], 'application_withdrawn', 0, $key, $hash, $updated['version'], array( 'contact_revoked' => true ) );
		if ( is_wp_error( $activity ) ) {
			return $this->rollback( $activity );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		$this->emit( 'application_withdrawn', $application['request_id'], $application['id'], $updated['version'] );
		return array(
			'public_id' => $updated['public_id'],
			'status'    => 'withdrawn',
		);
	}

	/** Recreate the opaque applicant token for exact idempotent retries. */
	private function application_token( array $request, string $key, string $hash ): string {
		return hash_hmac( 'sha256', $request['public_id'] . "\0" . $key . "\0" . $hash, wp_salt( 'secure_auth' ) );
	}

	private function normalize_policy( array $policy ) {
		$categories = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $policy['categories'] ?? array() ) ) ) ) );
		if ( count( $categories ) > 30 ) {
			return new \WP_Error( 'invalid_vendor_request_policy', __( 'Vendor category policy is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return array(
			'categories'         => array_map(
				static function ( $value ) {
					return mb_substr( $value, 0, 100 ); },
				$categories
			),
			'power_required'     => ! empty( $policy['power_required'] ),
			'insurance_required' => ! empty( $policy['insurance_required'] ),
			'instructions'       => mb_substr( sanitize_textarea_field( (string) ( $policy['instructions'] ?? '' ) ), 0, 2000 ),
		);
	}

	private function normalize_application( array $input, array $policy ) {
		if ( empty( $input['contact_consent'] ) ) {
			return new \WP_Error( 'vendor_application_consent_required', __( 'Contact-sharing consent is required for this event application.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$data = array(
			'business_name'   => mb_substr( sanitize_text_field( (string) ( $input['business_name'] ?? '' ) ), 0, 255 ),
			'category'        => mb_substr( sanitize_text_field( (string) ( $input['category'] ?? '' ) ), 0, 191 ),
			'website_url'     => esc_url_raw( (string) ( $input['website_url'] ?? '' ) ),
			'footprint'       => mb_substr( sanitize_text_field( (string) ( $input['footprint'] ?? '' ) ), 0, 255 ),
			'power_needs'     => mb_substr( sanitize_textarea_field( (string) ( $input['power_needs'] ?? '' ) ), 0, 2000 ),
			'insurance_notes' => mb_substr( sanitize_textarea_field( (string) ( $input['insurance_notes'] ?? '' ) ), 0, 2000 ),
			'message'         => mb_substr( sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ), 0, 5000 ),
			'contact'         => array(
				'name'  => mb_substr( sanitize_text_field( (string) ( $input['contact_name'] ?? '' ) ), 0, 255 ),
				'email' => sanitize_email( (string) ( $input['contact_email'] ?? '' ) ),
				'phone' => mb_substr( sanitize_text_field( (string) ( $input['contact_phone'] ?? '' ) ), 0, 64 ),
			),
		);
		if ( '' === $data['business_name'] || '' === $data['category'] || '' === $data['footprint'] || '' === $data['message'] || '' === $data['contact']['name'] || ! is_email( $data['contact']['email'] ) ) {
			return new \WP_Error( 'invalid_vendor_application', __( 'Complete all required vendor application fields.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $policy['categories'] ) && ! in_array( $data['category'], $policy['categories'], true ) ) {
			return new \WP_Error( 'invalid_vendor_application_category', __( 'Select an available vendor category.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $policy['power_required'] ) && '' === $data['power_needs'] ) {
			return new \WP_Error( 'invalid_vendor_application', __( 'Describe the vendor power needs.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $policy['insurance_required'] ) && '' === $data['insurance_notes'] ) {
			return new \WP_Error( 'invalid_vendor_application', __( 'Describe insurance or permit readiness.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return $data;
	}

	private function public_receipt( array $application ): array {
		return array(
			'public_id'    => $application['public_id'],
			'status'       => $application['status'],
			'submitted_at' => $application['created_at'],
			'version'      => $application['version'],
		);
	}

	private function record( int $request_id, ?int $application_id, string $kind, int $actor_id, string $key, string $hash, int $version, array $payload ) {
		return $this->repository->append_activity(
			array(
				'request_id'      => $request_id,
				'application_id'  => $application_id,
				'kind'            => $kind,
				'actor_user_id'   => 0 < $actor_id ? $actor_id : null,
				'idempotency_key' => $key,
				'request_hash'    => $hash,
				'result_version'  => $version,
				'payload'         => $payload,
			)
		);
	}

	private function emit( string $kind, int $request_id, ?int $application_id, int $version ): void {
		do_action(
			'extrachill_events_vendor_request_changed',
			array(
				'kind'           => $kind,
				'request_id'     => $request_id,
				'application_id' => $application_id,
				'version'        => $version,
			)
		);
	}

	private function key( $key ) {
		$key = trim( sanitize_text_field( (string) $key ) );
		if ( '' === $key || strlen( $key ) > 191 ) {
			return new \WP_Error( 'vendor_request_idempotency_key_required', __( 'A bounded idempotency key is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return $key;
	}

	private function hash( string $operation, array $data, int $actor_id ): string {
		$this->canonicalize( $data );
		$encoded = wp_json_encode(
			array(
				'operation' => $operation,
				'actor_id'  => $actor_id,
				'data'      => $data,
			)
		);
		return hash_hmac( 'sha256', is_string( $encoded ) ? $encoded : '', wp_salt( 'auth' ) );
	}

	private function canonicalize( array &$value ): void {
		if ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as &$item ) {
			if ( is_array( $item ) ) {
				$this->canonicalize( $item );
			}
		}
	}

	private function begin() {
		global $wpdb;
		return false === $wpdb->query( 'START TRANSACTION' ) ? new \WP_Error( 'vendor_request_transaction_start_failed', __( 'The vendor request transaction could not start.', 'extrachill-events' ) ) : true; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
	}

	private function commit() {
		global $wpdb;
		return false === $wpdb->query( 'COMMIT' ) ? new \WP_Error( 'vendor_request_transaction_commit_uncertain', __( 'The vendor request transaction outcome is uncertain.', 'extrachill-events' ) ) : true; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
	}

	private function rollback( \WP_Error $error ): \WP_Error {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
		return $error;
	}

	private function not_found(): \WP_Error {
		return new \WP_Error( 'vendor_request_not_found', __( 'The vendor request was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	private function version_conflict(): \WP_Error {
		return new \WP_Error( 'vendor_request_version_conflict', __( 'The vendor request changed since it was read.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	private function idempotency_conflict(): \WP_Error {
		return new \WP_Error( 'vendor_request_idempotency_conflict', __( 'The idempotency key was already used for different details.', 'extrachill-events' ), array( 'status' => 409 ) );
	}
}
