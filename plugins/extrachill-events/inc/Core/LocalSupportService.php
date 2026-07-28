<?php
/**
 * Event-scoped local support aggregate service.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns local support lifecycle, privacy, idempotency, and transaction boundaries. */
class LocalSupportService {

	public const REQUEST_STATUSES  = array( 'open', 'paused', 'filled', 'closed', 'cancelled' );
	public const INTEREST_STATUSES = array( 'interested', 'withdrawn', 'shortlisted', 'selected', 'declined' );

	private const REQUEST_TRANSITIONS = array(
		'open'      => array( 'paused', 'filled', 'closed', 'cancelled' ),
		'paused'    => array( 'open', 'closed', 'cancelled' ),
		'filled'    => array( 'closed' ),
		'closed'    => array(),
		'cancelled' => array(),
	);

	private const INTEREST_TRANSITIONS = array(
		'interested'  => array( 'withdrawn', 'shortlisted', 'declined' ),
		'shortlisted' => array( 'withdrawn', 'selected', 'declined' ),
		'selected'    => array( 'withdrawn' ),
		'withdrawn'   => array(),
		'declined'    => array(),
	);

	/** @var LocalSupportRepository */
	private $repository;

	/** @var LocalSupportAuthorization */
	private $authorization;

	public function __construct( ?LocalSupportRepository $repository = null, ?LocalSupportAuthorization $authorization = null ) {
		$this->repository    = $repository ? $repository : new LocalSupportRepository();
		$this->authorization = $authorization ? $authorization : new LocalSupportAuthorization();
	}

	/** Open one request per canonical event. */
	public function open_request( array $input, int $actor_id ) {
		$key = $this->idempotency_key( $input['idempotency_key'] ?? '' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$event_id      = absint( $input['event_id'] ?? 0 );
		$organizer     = sanitize_key( (string) ( $input['organizer_type'] ?? '' ) );
		$organizer_id  = absint( $input['organizer_id'] ?? 0 );
		$booking_id    = empty( $input['booking_id'] ) ? null : absint( $input['booking_id'] );
		$event_context = $this->authorization->event_context( $event_id );
		if ( is_wp_error( $event_context ) ) {
			return $event_context;
		}
		$request = array(
			'event_id'       => $event_id,
			'venue_term_id'  => $event_context['venue_term_id'],
			'booking_id'     => $booking_id,
			'organizer_type' => $organizer,
			'organizer_id'   => $organizer_id,
		);
		$allowed = $this->authorization->authorize_organizer( $request, $actor_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		if ( null !== $booking_id ) {
			$booking = ( new BookingRepository() )->get( $booking_id );
			if ( ! is_array( $booking ) || (int) $booking['event_id'] !== $event_id || (int) $booking['venue_term_id'] !== $event_context['venue_term_id'] ) {
				return new \WP_Error( 'invalid_local_support_booking', __( 'The optional booking must belong to this exact event and venue.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
		}
		$hash     = $this->request_hash( 'open_request', $request, $actor_id );
		$existing = $this->repository->get_request_by_event( $event_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( is_array( $existing ) ) {
			return $this->replay( $existing, $key, $hash );
		}

		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$allowed = $this->authorization->authorize_organizer( $request, $actor_id );
		if ( true !== $allowed ) {
			return $this->rollback( $allowed );
		}
		$created = $this->repository->create_request( array_merge( $request, array( 'actor_id' => $actor_id ) ) );
		if ( is_wp_error( $created ) ) {
			$rolled = $this->rollback( $created );
			if ( 'local_support_request_create_failed' !== $rolled->get_error_code() ) {
				return $rolled;
			}
			$winner = $this->repository->get_request_by_event( $event_id );
			return is_array( $winner ) ? $this->replay( $winner, $key, $hash ) : $rolled;
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

	/** Read one request as its organizer. */
	public function get_request( int $request_id, int $actor_id ) {
		$request = $this->repository->get_request( $request_id );
		if ( ! is_array( $request ) ) {
			return is_wp_error( $request ) ? $request : $this->not_found();
		}
		$allowed = $this->authorization->authorize_organizer( $request, $actor_id );
		return true === $allowed ? $request : $allowed;
	}

	/** Apply one organizer-owned request transition. */
	public function transition_request( int $request_id, string $to_status, int $expected_version, string $idempotency_key, int $actor_id ) {
		$request = $this->repository->get_request( $request_id );
		if ( ! is_array( $request ) ) {
			return is_wp_error( $request ) ? $request : $this->not_found();
		}
		$allowed = $this->authorization->authorize_organizer( $request, $actor_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$to_status = sanitize_key( $to_status );
		if ( ! in_array( $to_status, self::REQUEST_TRANSITIONS[ $request['status'] ] ?? array(), true ) ) {
			return new \WP_Error( 'local_support_request_transition_forbidden', __( 'The requested support-request transition is not allowed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return $this->mutate_request(
			$request,
			$expected_version,
			$idempotency_key,
			$actor_id,
			'request_status_changed',
			array( 'status' => $to_status ),
			array(
				'from_status' => $request['status'],
				'to_status'   => $to_status,
			)
		);
	}

	/** Create or replay one artist interest. */
	public function express_interest( int $request_id, int $artist_term_id, string $idempotency_key, int $actor_id ) {
		$request = $this->repository->get_request( $request_id );
		if ( ! is_array( $request ) ) {
			return is_wp_error( $request ) ? $request : $this->not_found();
		}
		if ( 'open' !== $request['status'] ) {
			return new \WP_Error( 'local_support_request_not_open', __( 'This support request is not accepting interest.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$allowed = $this->authorization->authorize_artist( $artist_term_id, $actor_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$attached = $this->authorization->artist_attached_to_event( $request['event_id'], $artist_term_id );
		if ( is_wp_error( $attached ) ) {
			return $attached;
		}
		if ( $attached ) {
			return new \WP_Error( 'local_support_artist_already_attached', __( 'Artists already attached to the event cannot express local-support interest.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$key  = $this->idempotency_key( $idempotency_key );
		$hash = $this->request_hash(
			'express_interest',
			array(
				'request_id'     => $request_id,
				'artist_term_id' => $artist_term_id,
			),
			$actor_id
		);
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$existing = $this->repository->get_interest_for_artist( $request_id, $artist_term_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( is_array( $existing ) ) {
			$replayed = $this->replay( $request, $key, $hash );
			return is_wp_error( $replayed ) ? $replayed : $existing;
		}
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$allowed = $this->authorization->authorize_artist( $artist_term_id, $actor_id );
		if ( true !== $allowed ) {
			return $this->rollback( $allowed );
		}
		$interest = $this->repository->create_interest( $request_id, $artist_term_id, $actor_id );
		if ( is_wp_error( $interest ) ) {
			$rolled = $this->rollback( $interest );
			if ( 'local_support_interest_create_failed' !== $rolled->get_error_code() ) {
				return $rolled;
			}
			$winner = $this->repository->get_interest_for_artist( $request_id, $artist_term_id );
			if ( ! is_array( $winner ) ) {
				return $rolled;
			}
			$replayed = $this->replay( $request, $key, $hash );
			return is_wp_error( $replayed ) ? $replayed : $winner;
		}
		$activity = $this->record(
			$request_id,
			$interest['id'],
			'interest_expressed',
			$actor_id,
			$key,
			$hash,
			1,
			array(
				'artist_term_id' => $artist_term_id,
				'status'         => 'interested',
			)
		);
		if ( is_wp_error( $activity ) ) {
			return $this->rollback( $activity );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		$this->emit( 'interest_expressed', $request_id, $interest['id'], 1 );
		return $interest;
	}

	/** Transition an interest as the artist or organizer, depending on target state. */
	public function transition_interest( int $interest_id, string $to_status, int $expected_version, string $idempotency_key, int $actor_id ) {
		$interest = $this->repository->get_interest( $interest_id );
		if ( ! is_array( $interest ) ) {
			return is_wp_error( $interest ) ? $interest : $this->not_found();
		}
		$request = $this->repository->get_request( $interest['request_id'] );
		if ( ! is_array( $request ) ) {
			return is_wp_error( $request ) ? $request : $this->not_found();
		}
		$to_status = sanitize_key( $to_status );
		if ( ! in_array( $to_status, self::INTEREST_TRANSITIONS[ $interest['status'] ] ?? array(), true ) ) {
			return new \WP_Error( 'local_support_interest_transition_forbidden', __( 'The requested interest transition is not allowed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$artist_owned = 'withdrawn' === $to_status;
		$allowed      = $artist_owned
			? $this->authorization->authorize_artist( $interest['artist_term_id'], $actor_id )
			: $this->authorization->authorize_organizer( $request, $actor_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		if ( ! $artist_owned && ! in_array( $to_status, array( 'shortlisted', 'selected', 'declined' ), true ) ) {
			return new \WP_Error( 'local_support_interest_transition_forbidden', __( 'Only the artist may withdraw interest.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		$changes = array( 'status' => $to_status );
		$payload = array(
			'from_status' => $interest['status'],
			'to_status'   => $to_status,
		);
		if ( $artist_owned && null !== $interest['contact'] ) {
			$changes                            = array_merge(
				$changes,
				array(
					'contact_payload'    => null,
					'consent_fields'     => null,
					'consent_version'    => $interest['consent_version'] + 1,
					'revoked_by_user_id' => $actor_id,
					'revoked_at'         => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$payload['contact_consent_revoked'] = true;
		}
		return $this->mutate_interest(
			$request,
			$interest,
			$expected_version,
			$idempotency_key,
			$actor_id,
			'interest_status_changed',
			$changes,
			$payload,
			$artist_owned
		);
	}

	/** Grant or revoke request-scoped contact disclosure. */
	public function set_contact_consent( int $interest_id, bool $granted, array $contact, array $fields, int $expected_version, string $idempotency_key, int $actor_id ) {
		$interest = $this->repository->get_interest( $interest_id );
		if ( ! is_array( $interest ) ) {
			return is_wp_error( $interest ) ? $interest : $this->not_found();
		}
		$request = $this->repository->get_request( $interest['request_id'] );
		if ( ! is_array( $request ) ) {
			return is_wp_error( $request ) ? $request : $this->not_found();
		}
		$allowed = $this->authorization->authorize_artist( $interest['artist_term_id'], $actor_id );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$consent_version = $interest['consent_version'] + 1;
		$now             = gmdate( 'Y-m-d H:i:s' );
		if ( $granted ) {
			$normalized = $this->normalize_contact( $contact, $fields );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}
			$changes = array(
				'contact_payload'      => wp_json_encode( $normalized['contact'] ),
				'consent_fields'       => wp_json_encode( $normalized['fields'] ),
				'consent_version'      => $consent_version,
				'consented_by_user_id' => $actor_id,
				'consented_at'         => $now,
				'revoked_by_user_id'   => null,
				'revoked_at'           => null,
			);
			$payload = array(
				'granted'         => true,
				'fields'          => $normalized['fields'],
				'consent_version' => $consent_version,
				'consented_at'    => $now,
			);
			$kind    = 'contact_consent_granted';
		} else {
			$changes = array(
				'contact_payload'    => null,
				'consent_fields'     => null,
				'consent_version'    => $consent_version,
				'revoked_by_user_id' => $actor_id,
				'revoked_at'         => $now,
			);
			$payload = array(
				'granted'         => false,
				'fields'          => (array) ( $interest['consent_fields'] ?? array() ),
				'consent_version' => $consent_version,
				'revoked_at'      => $now,
			);
			$kind    = 'contact_consent_revoked';
		}
		return $this->mutate_interest( $request, $interest, $expected_version, $idempotency_key, $actor_id, $kind, $changes, $payload, true );
	}

	/** Return the private organizer shortlist; no uninterested candidates exist here. */
	public function list_interests( int $request_id, int $actor_id, int $limit = 100 ) {
		$request = $this->repository->get_request( $request_id );
		if ( ! is_array( $request ) ) {
			return is_wp_error( $request ) ? $request : $this->not_found();
		}
		$allowed = $this->authorization->authorize_organizer( $request, $actor_id );
		return true === $allowed ? $this->repository->list_interests( $request_id, $limit ) : $allowed;
	}

	/** Public lifecycle validator used by focused domain tests. */
	public static function can_transition_request( string $from, string $to ): bool {
		return in_array( $to, self::REQUEST_TRANSITIONS[ $from ] ?? array(), true );
	}

	/** Public lifecycle validator used by focused domain tests. */
	public static function can_transition_interest( string $from, string $to ): bool {
		return in_array( $to, self::INTEREST_TRANSITIONS[ $from ] ?? array(), true );
	}

	/** Run one request mutation transaction. */
	private function mutate_request( array $request, int $expected_version, string $key, int $actor_id, string $kind, array $changes, array $payload ) {
		$key  = $this->idempotency_key( $key );
		$hash = $this->request_hash( $kind, $changes, $actor_id );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$replay = $this->replay( $request, $key, $hash, false );
		if ( null !== $replay ) {
			return $replay;
		}
		if ( $request['version'] !== $expected_version ) {
			return $this->conflict( $request['version'] );
		}
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->repository->get_request( $request['id'], true );
		if ( ! is_array( $locked ) || $locked['version'] !== $expected_version ) {
			$rolled = $this->rollback( is_array( $locked ) ? $this->conflict( $locked['version'] ) : $this->not_found() );
			return is_array( $locked ) ? $this->concurrent_replay( $request['id'], $key, $hash, $rolled, 'request', $request['id'] ) : $rolled;
		}
		$allowed = $this->authorization->authorize_organizer( $locked, $actor_id );
		if ( true !== $allowed ) {
			return $this->rollback( $allowed );
		}
		$updated = $this->repository->update_request( $locked['id'], $expected_version, $changes );
		$event   = is_wp_error( $updated ) ? $updated : $this->record( $locked['id'], null, $kind, $actor_id, $key, $hash, $updated['version'], $payload );
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		$this->emit( $kind, $locked['id'], null, $updated['version'] );
		return $updated;
	}

	/** Run one interest mutation transaction. */
	private function mutate_interest( array $request, array $interest, int $expected_version, string $key, int $actor_id, string $kind, array $changes, array $payload, bool $artist_owned ) {
		$key  = $this->idempotency_key( $key );
		$hash = $this->request_hash(
			$kind,
			array(
				'interest_id' => $interest['id'],
				'changes'     => $changes,
			),
			$actor_id
		);
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$receipt = $this->repository->find_activity( $request['id'], $key );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		if ( is_array( $receipt ) ) {
			return hash_equals( (string) $receipt['request_hash'], $hash ) ? $interest : $this->idempotency_conflict();
		}
		if ( $interest['version'] !== $expected_version ) {
			return $this->conflict( $interest['version'] );
		}
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->repository->get_interest( $interest['id'], true );
		if ( ! is_array( $locked ) || $locked['version'] !== $expected_version ) {
			$rolled = $this->rollback( is_array( $locked ) ? $this->conflict( $locked['version'] ) : $this->not_found() );
			return is_array( $locked ) ? $this->concurrent_replay( $request['id'], $key, $hash, $rolled, 'interest', $interest['id'] ) : $rolled;
		}
		$allowed = $artist_owned
			? $this->authorization->authorize_artist( $locked['artist_term_id'], $actor_id )
			: $this->authorization->authorize_organizer( $request, $actor_id );
		if ( true !== $allowed ) {
			return $this->rollback( $allowed );
		}
		$updated = $this->repository->update_interest( $locked['id'], $expected_version, $changes );
		$event   = is_wp_error( $updated ) ? $updated : $this->record( $request['id'], $locked['id'], $kind, $actor_id, $key, $hash, $updated['version'], $payload );
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		$this->emit( $kind, $request['id'], $locked['id'], $updated['version'] );
		return $updated;
	}

	/** Normalize disclosed fields without retaining undisclosed contact data. */
	private function normalize_contact( array $contact, array $fields ) {
		$fields = array_values( array_unique( array_map( 'sanitize_key', $fields ) ) );
		if ( empty( $fields ) || array_diff( $fields, array( 'name', 'email', 'phone' ) ) ) {
			return new \WP_Error( 'invalid_local_support_consent_fields', __( 'Consent must name supported contact fields.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$normalized = array();
		foreach ( $fields as $field ) {
			$value = 'email' === $field ? sanitize_email( (string) ( $contact[ $field ] ?? '' ) ) : sanitize_text_field( (string) ( $contact[ $field ] ?? '' ) );
			if ( '' === $value ) {
				return new \WP_Error(
					'invalid_local_support_contact',
					__( 'Every consented contact field requires a value.', 'extrachill-events' ),
					array(
						'status' => 400,
						'field'  => $field,
					)
				);
			}
			$normalized[ $field ] = mb_substr( $value, 0, 'phone' === $field ? 64 : 255 );
		}
		return array(
			'contact' => $normalized,
			'fields'  => $fields,
		);
	}

	/** Return a prior receipt, null when no receipt exists. */
	private function replay( array $request, string $key, string $hash, bool $required = true ) {
		$receipt = $this->repository->find_activity( $request['id'], $key );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		if ( ! is_array( $receipt ) ) {
			return $required ? $this->idempotency_conflict() : null;
		}
		return hash_equals( (string) $receipt['request_hash'], $hash ) ? $request : $this->idempotency_conflict();
	}

	/** Resolve a mutation that lost its row-version race to the same idempotent call. */
	private function concurrent_replay( int $request_id, string $key, string $hash, \WP_Error $fallback, string $aggregate, int $aggregate_id ) {
		$receipt = $this->repository->find_activity( $request_id, $key );
		if ( ! is_array( $receipt ) || ! hash_equals( (string) $receipt['request_hash'], $hash ) ) {
			return is_wp_error( $receipt ) ? $receipt : $fallback;
		}
		return 'request' === $aggregate ? $this->repository->get_request( $aggregate_id ) : $this->repository->get_interest( $aggregate_id );
	}

	/** Append one mutation receipt. */
	private function record( int $request_id, ?int $interest_id, string $kind, int $actor_id, string $key, string $hash, int $version, array $payload ) {
		return $this->repository->append_activity(
			array(
				'request_id'      => $request_id,
				'interest_id'     => $interest_id,
				'kind'            => $kind,
				'actor_user_id'   => $actor_id,
				'idempotency_key' => $key,
				'request_hash'    => $hash,
				'result_version'  => $version,
				'payload'         => array_merge( $payload, array( 'version' => $version ) ),
			)
		);
	}

	/** Emit a privacy-safe internal contract after commit for #422. */
	private function emit( string $kind, int $request_id, ?int $interest_id, int $version ): void {
		do_action(
			'extrachill_events_local_support_changed',
			array(
				'kind'        => $kind,
				'request_id'  => $request_id,
				'interest_id' => $interest_id,
				'version'     => $version,
			)
		);
	}

	/** Normalize bounded idempotency keys. */
	private function idempotency_key( $key ) {
		$key = trim( sanitize_text_field( (string) $key ) );
		return '' === $key || strlen( $key ) > 191
			? new \WP_Error( 'local_support_idempotency_key_required', __( 'A bounded idempotency key is required.', 'extrachill-events' ), array( 'status' => 400 ) )
			: $key;
	}

	/** Fingerprint one actor-bound mutation. */
	private function request_hash( string $operation, array $data, int $actor_id ): string {
		$this->canonicalize( $data );
		return hash_hmac(
			'sha256',
			wp_json_encode(
				array(
					'operation' => $operation,
					'actor_id'  => $actor_id,
					'data'      => $data,
				)
			),
			wp_salt( 'auth' )
		);
	}

	/** Sort associative data recursively for stable hashes. */
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
		return false === $wpdb->query( 'START TRANSACTION' ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
			? new \WP_Error( 'local_support_transaction_start_failed', __( 'The local support transaction could not start.', 'extrachill-events' ) )
			: true;
	}

	private function commit() {
		global $wpdb;
		return false === $wpdb->query( 'COMMIT' ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
			? new \WP_Error( 'local_support_transaction_commit_uncertain', __( 'The local support transaction outcome is uncertain.', 'extrachill-events' ) )
			: true;
	}

	private function rollback( \WP_Error $cause ): \WP_Error {
		global $wpdb;
		if ( false === $wpdb->query( 'ROLLBACK' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
			return new \WP_Error( 'local_support_transaction_rollback_failed', __( 'The local support transaction could not roll back.', 'extrachill-events' ), array( 'cause' => $cause->get_error_code() ) );
		}
		return $cause;
	}

	private function conflict( int $version ): \WP_Error {
		return new \WP_Error(
			'local_support_version_conflict',
			__( 'The local support record changed since it was read.', 'extrachill-events' ),
			array(
				'status'          => 409,
				'current_version' => $version,
			)
		);
	}

	private function idempotency_conflict(): \WP_Error {
		return new \WP_Error( 'local_support_idempotency_conflict', __( 'The idempotency key was already used for a different mutation.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	private function not_found(): \WP_Error {
		return new \WP_Error( 'local_support_not_found', __( 'The local support record was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}
}
