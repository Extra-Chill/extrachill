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

	/** @var bool Whether this service owns an active transaction. */
	private $transaction_active = false;

	/** @var object Opaque authorization-scope owner token. */
	private $transaction_token;

	/** @var \WP_Error|null Token claim failure for a reused authorization instance. */
	private $transaction_owner_error;

	public function __construct( ?LocalSupportRepository $repository = null, ?LocalSupportAuthorization $authorization = null ) {
		$this->repository    = $repository ? $repository : new LocalSupportRepository();
		$this->authorization = $authorization ? $authorization : new LocalSupportAuthorization();
		$this->transaction_token = new \stdClass();
		$claimed = $this->authorization->claim_transaction_owner( $this->transaction_token );
		$this->transaction_owner_error = $claimed instanceof \WP_Error ? $claimed : null;
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

		$created = $this->transaction(
			function ( object $scope ) use ( $request, $actor_id, $key, $hash ) {
				$allowed = $this->authorization->authorize_organizer_locked( $request, $actor_id, $scope );
				if ( true !== $allowed ) {
					return $allowed;
				}
				$created = $this->repository->create_request( array_merge( $request, array( 'actor_id' => $actor_id ) ) );
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				$activity = $this->record( $created['id'], null, 'request_opened', $actor_id, $key, $hash, 1, array( 'status' => 'open' ) );
				return is_wp_error( $activity ) ? $activity : $created;
			}
		);
		if ( is_wp_error( $created ) ) {
			if ( 'local_support_request_create_failed' !== $created->get_error_code() ) {
				return $created;
			}
			$winner = $this->repository->get_request_by_event( $event_id );
			return is_array( $winner ) ? $this->replay( $winner, $key, $hash ) : $created;
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
		$interest = $this->transaction(
			function ( object $scope ) use ( $request_id, $artist_term_id, $actor_id, $key, $hash ) {
				$locked_request = $this->repository->get_request( $request_id, true );
				if ( ! is_array( $locked_request ) || 'open' !== $locked_request['status'] ) {
					return is_array( $locked_request ) ? new \WP_Error( 'local_support_request_not_open', __( 'This support request is not accepting interest.', 'extrachill-events' ), array( 'status' => 409 ) ) : $this->not_found();
				}
				$attached = $this->authorization->artist_attached_to_event_locked( $locked_request['event_id'], $artist_term_id, $scope );
				if ( is_wp_error( $attached ) ) {
					return $attached;
				}
				if ( $attached ) {
					return new \WP_Error( 'local_support_artist_already_attached', __( 'Artists already attached to the event cannot express local-support interest.', 'extrachill-events' ), array( 'status' => 409 ) );
				}
				$allowed = $this->authorization->authorize_artist_locked( $artist_term_id, $actor_id, $scope );
				if ( true !== $allowed ) {
					return $allowed;
				}
				$interest = $this->repository->create_interest( $request_id, $artist_term_id, $actor_id );
				if ( is_wp_error( $interest ) ) {
					return $interest;
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
				return is_wp_error( $activity ) ? $activity : $interest;
			}
		);
		if ( is_wp_error( $interest ) ) {
			if ( 'local_support_interest_create_failed' !== $interest->get_error_code() ) {
				return $interest;
			}
			$winner = $this->repository->get_interest_for_artist( $request_id, $artist_term_id );
			if ( ! is_array( $winner ) ) {
				return $interest;
			}
			$replayed = $this->replay( $request, $key, $hash );
			return is_wp_error( $replayed ) ? $replayed : $winner;
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
		$updated = $this->transaction(
			function ( object $scope ) use ( $request, $expected_version, $actor_id, $changes, $kind, $key, $hash, $payload ) {
				$locked = $this->repository->get_request( $request['id'], true );
				if ( ! is_array( $locked ) || $locked['version'] !== $expected_version ) {
					return is_array( $locked ) ? $this->conflict( $locked['version'] ) : $this->not_found();
				}
				$allowed = $this->authorization->authorize_organizer_locked( $locked, $actor_id, $scope );
				if ( true !== $allowed ) {
					return $allowed;
				}
				$updated = $this->repository->update_request( $locked['id'], $expected_version, $changes );
				$event   = is_wp_error( $updated ) ? $updated : $this->record( $locked['id'], null, $kind, $actor_id, $key, $hash, $updated['version'], $payload );
				return is_wp_error( $event ) ? $event : $updated;
			}
		);
		if ( is_wp_error( $updated ) ) {
			return 'local_support_version_conflict' === $updated->get_error_code() ? $this->concurrent_replay( $request['id'], $key, $hash, $updated, 'request', $request['id'] ) : $updated;
		}
		$this->emit( $kind, $request['id'], null, $updated['version'] );
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
		$updated = $this->transaction(
			function ( object $scope ) use ( $request, $interest, $expected_version, $artist_owned, $actor_id, $changes, $kind, $key, $hash, $payload ) {
				$locked_request = $this->repository->get_request( $request['id'], true );
				if ( ! is_array( $locked_request ) ) {
					return $this->not_found();
				}
				$locked = $this->repository->get_interest( $interest['id'], true );
				if ( ! is_array( $locked ) || $locked['version'] !== $expected_version ) {
					return is_array( $locked ) ? $this->conflict( $locked['version'] ) : $this->not_found();
				}
				$allowed = $artist_owned
					? $this->authorization->authorize_artist_locked( $locked['artist_term_id'], $actor_id, $scope )
					: $this->authorization->authorize_organizer_locked( $locked_request, $actor_id, $scope );
				if ( true !== $allowed ) {
					return $allowed;
				}
				$updated = $this->repository->update_interest( $locked['id'], $expected_version, $changes );
				$event   = is_wp_error( $updated ) ? $updated : $this->record( $request['id'], $locked['id'], $kind, $actor_id, $key, $hash, $updated['version'], $payload );
				return is_wp_error( $event ) ? $event : $updated;
			}
		);
		if ( is_wp_error( $updated ) ) {
			return 'local_support_version_conflict' === $updated->get_error_code() ? $this->concurrent_replay( $request['id'], $key, $hash, $updated, 'interest', $interest['id'] ) : $updated;
		}
		$this->emit( $kind, $request['id'], $interest['id'], $updated['version'] );
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

	/** Execute one service-owned transaction and always close its lock scope. */
	private function transaction( callable $callback ) {
		if ( is_wp_error( $this->transaction_owner_error ) ) {
			return $this->transaction_owner_error;
		}
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		try {
			$scope = $this->authorization->open_transaction_scope( $this->transaction_token );
		} catch ( \Throwable $throwable ) {
			if ( $this->transaction_active ) {
				$this->rollback_transaction( new \WP_Error( 'local_support_transaction_interrupted', __( 'The local support transaction was interrupted.', 'extrachill-events' ) ) );
			}
			throw $throwable;
		}
		if ( is_wp_error( $scope ) ) {
			return $this->rollback_transaction( $scope );
		}

		$result    = null;
		$committed = false;
		try {
			$result = call_user_func( $callback, $scope );
			if ( is_wp_error( $result ) ) {
				$result = $this->rollback_transaction( $result );
			} else {
				$commit = $this->commit_transaction();
				if ( is_wp_error( $commit ) ) {
					$result = $commit;
				} else {
					$committed = true;
				}
			}
		} catch ( \Throwable $throwable ) {
			if ( $this->transaction_active ) {
				$this->rollback_transaction( new \WP_Error( 'local_support_transaction_interrupted', __( 'The local support transaction was interrupted.', 'extrachill-events' ) ) );
			}
			throw $throwable;
		} finally {
			try {
				$released = $this->authorization->close_transaction_scope( $scope );
				if ( is_wp_error( $released ) ) {
					do_action(
						'extrachill_events_local_support_authority_release_failed',
						array(
							'code'      => $released->get_error_code(),
							'committed' => $committed,
						)
					);
				}
			} catch ( \Throwable $release_throwable ) {
				do_action(
					'extrachill_events_local_support_authority_release_failed',
					array(
						'code'      => 'local_support_artist_authority_release_threw',
						'committed' => $committed,
					)
				);
			}
		}
		return $result;
	}

	/** Start only when this connection is not already in a transaction. */
	private function begin() {
		global $wpdb;
		if ( $this->transaction_active ) {
			return new \WP_Error( 'local_support_nested_transaction_forbidden', __( 'Local support cannot start a nested transaction.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$previous = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		try {
			$boundary = $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Portable MySQL/MariaDB active-transaction rejection and required next-key isolation.
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$boundary = false;
		} finally {
			if ( method_exists( $wpdb, 'suppress_errors' ) ) {
				$wpdb->suppress_errors( $previous );
			}
		}
		if ( false === $boundary ) {
			return new \WP_Error( 'local_support_transaction_boundary_forbidden', __( 'Local support requires an isolated transaction boundary.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
			return new \WP_Error( 'local_support_transaction_start_failed', __( 'The local support transaction could not start.', 'extrachill-events' ) );
		}
		$this->transaction_active = true;
		return true;
	}

	/** Commit without attempting rollback after an uncertain successful command. */
	private function commit_transaction() {
		global $wpdb;
		try {
			$result = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$result = false;
		}
		if ( false !== $result ) {
			$this->transaction_active = false;
			return true;
		}
		return $this->quarantine_uncertain_commit();
	}

	/** Roll back an active service transaction while preserving its cause. */
	private function rollback_transaction( \WP_Error $cause ): \WP_Error {
		global $wpdb;
		try {
			$result = $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			$result = false;
		}
		if ( false !== $result ) {
			$this->transaction_active = false;
			return $cause;
		}
		return $this->quarantine_transaction( $cause );
	}

	/** Close a connection whose transaction could not be safely terminated. */
	private function quarantine_transaction( \WP_Error $cause ): \WP_Error {
		global $wpdb;
		$closed = false;
		try {
			$closed = method_exists( $wpdb, 'close' ) && true === $wpdb->close();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
		if ( property_exists( $wpdb, 'ready' ) ) {
			$wpdb->ready = false;
		}
		$this->transaction_active = false;
		return new \WP_Error(
			'local_support_transaction_rollback_failed',
			__( 'The local support transaction could not roll back and its connection was retired.', 'extrachill-events' ),
			array(
				'cause'                  => $cause->get_error_code(),
				'connection_quarantined' => true,
				'disconnect_confirmed'   => $closed,
			)
		);
	}

	/** Retire a connection when failed commit state cannot be observed safely. */
	private function quarantine_uncertain_commit(): \WP_Error {
		global $wpdb;
		$closed = false;
		try {
			$closed = method_exists( $wpdb, 'close' ) && true === $wpdb->close();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
		if ( property_exists( $wpdb, 'ready' ) ) {
			$wpdb->ready = false;
		}
		$this->transaction_active = false;
		return new \WP_Error(
			'local_support_transaction_commit_uncertain',
			__( 'The local support transaction outcome is uncertain and its connection was retired.', 'extrachill-events' ),
			array(
				'connection_quarantined' => true,
				'disconnect_confirmed'   => $closed,
			)
		);
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
