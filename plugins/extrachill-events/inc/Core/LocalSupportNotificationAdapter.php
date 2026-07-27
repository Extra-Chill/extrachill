<?php
/**
 * Production local-support notification domain adapter.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Persists notification state in the local-support activity ledger. */
class LocalSupportNotificationAdapter {

	public const WORKSPACE_URL_FILTER = 'extrachill_events_local_support_workspace_url';

	/** @var LocalSupportRepository */
	private $repository;
	/** @var LocalSupportAuthorization */
	private $authorization;
	/** @var VenueMembershipRepository */
	private $memberships;
	/** @var callable|null Authorized workspace resolver test seam. */
	private $workspace;

	public function __construct( ?LocalSupportRepository $repository = null, ?LocalSupportAuthorization $authorization = null, ?VenueMembershipRepository $memberships = null, $workspace = null ) {
		$this->repository    = $repository ? $repository : new LocalSupportRepository();
		$this->authorization = $authorization ? $authorization : new LocalSupportAuthorization();
		$this->memberships   = $memberships ? $memberships : new VenueMembershipRepository();
		$this->workspace     = $workspace;
	}

	/** Hydrate the exact committed domain records represented by one change event. */
	public function notification_source( array $change ) {
		$kind        = sanitize_key( (string) ( $change['kind'] ?? '' ) );
		$request_id  = absint( $change['request_id'] ?? 0 );
		$interest_id = empty( $change['interest_id'] ) ? null : absint( $change['interest_id'] );
		$version     = absint( $change['version'] ?? 0 );
		if ( '' === $kind || $request_id < 1 || $version < 1 ) {
			return new \WP_Error( 'local_support_change_invalid', __( 'The emitted local-support change is invalid.', 'extrachill-events' ) );
		}
		$request  = $this->repository->get_request( $request_id );
		$activity = $this->repository->find_activity_for_change( $request_id, $interest_id, $kind, $version );
		if ( ! is_array( $request ) || ! is_array( $activity ) ) {
			if ( is_wp_error( $request ) || is_wp_error( $activity ) ) {
				return is_wp_error( $request ) ? $request : $activity;
			}
			return new \WP_Error( 'local_support_change_source_missing', __( 'The emitted local-support change has no durable source activity.', 'extrachill-events' ) );
		}
		$interest = null;
		if ( null !== $interest_id ) {
			$interest = $this->repository->get_interest( $interest_id );
			if ( ! is_array( $interest ) || (int) $interest['request_id'] !== $request_id ) {
				return is_wp_error( $interest ) ? $interest : new \WP_Error( 'local_support_change_interest_missing', __( 'The emitted local-support interest could not be resolved.', 'extrachill-events' ) );
			}
		}
		return compact( 'request', 'interest', 'activity' );
	}

	/** Append one idempotent, privacy-safe notification intent. */
	public function append_notification_intent( array $intent ) {
		$existing = $this->repository->find_activity( (int) $intent['request_id'], (string) $intent['idempotency_key'] );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( is_array( $existing ) ) {
			return $this->intent_from_activity( $this->repository->hydrate_activity( $existing ) );
		}
		$source = $this->source_activity_by_id( (int) $intent['source_activity_id'], (int) $intent['request_id'] );
		if ( ! is_array( $source ) ) {
			return is_wp_error( $source ) ? $source : new \WP_Error( 'local_support_notification_source_missing', __( 'The notification intent source activity is unavailable.', 'extrachill-events' ) );
		}
		$hash   = hash( 'sha256', (string) $intent['idempotency_key'] );
		$record = $this->repository->append_activity(
			array(
				'request_id'      => (int) $intent['request_id'],
				'interest_id'     => null,
				'kind'            => 'notification_intent',
				'actor_user_id'   => (int) $source['actor_user_id'],
				'idempotency_key' => (string) $intent['idempotency_key'],
				'request_hash'    => $hash,
				'result_version'  => (int) $source['result_version'],
				'payload'         => $intent,
			)
		);
		if ( is_wp_error( $record ) ) {
			$winner = $this->repository->find_activity( (int) $intent['request_id'], (string) $intent['idempotency_key'] );
			return is_array( $winner ) ? $this->intent_from_activity( $this->repository->hydrate_activity( $winner ) ) : $record;
		}
		return $this->intent_from_activity( $this->repository->hydrate_activity( $record ) );
	}

	/** Return a bounded page of due durable intents. */
	public function pending_notification_intents( int $limit ) {
		$rows = $this->repository->pending_notification_intents( $limit );
		return is_wp_error( $rows ) ? $rows : array_map( array( $this, 'intent_from_activity' ), $rows );
	}

	/** Return a bounded page of committed changes missing a processed marker. */
	public function pending_notification_sources( int $limit ) {
		return $this->repository->pending_notification_sources( $limit );
	}

	/** Mark one source as fully translated into zero or more durable intents. */
	public function mark_notification_source_processed( array $activity ) {
		$key      = 'local-support-notification-source:' . hash_hmac( 'sha256', (string) $activity['id'], wp_salt( 'auth' ) );
		$existing = $this->repository->find_activity( (int) $activity['request_id'], $key );
		if ( is_wp_error( $existing ) || is_array( $existing ) ) {
			return $existing;
		}
		$record = $this->repository->append_activity(
			array(
				'request_id'      => (int) $activity['request_id'],
				'interest_id'     => empty( $activity['interest_id'] ) ? null : (int) $activity['interest_id'],
				'kind'            => 'notification_source_processed',
				'actor_user_id'   => (int) $activity['actor_user_id'],
				'idempotency_key' => $key,
				'request_hash'    => (string) $activity['request_hash'],
				'result_version'  => (int) $activity['result_version'],
				'payload'         => array( 'source_activity_id' => (int) $activity['id'] ),
			)
		);
		if ( is_wp_error( $record ) ) {
			$winner = $this->repository->find_activity( (int) $activity['request_id'], $key );
			return is_array( $winner ) ? $winner : $record;
		}
		return $record;
	}

	/** Return terminal delivery state for one intent. */
	public function notification_terminal( array $intent ) {
		$row = $this->repository->notification_terminal( (int) $intent['request_id'], (string) $intent['_request_hash'] );
		return is_wp_error( $row ) || ! is_array( $row ) ? $row : $row['payload'];
	}

	/** Count prior delivery attempts for one intent. */
	public function notification_attempt_count( array $intent ) {
		return $this->repository->notification_attempt_count( (int) $intent['request_id'], (string) $intent['_request_hash'] );
	}

	/** Append one deferred retry marker. */
	public function record_notification_attempt( array $intent, int $attempt, string $due_at, string $error_code ) {
		$record = $this->repository->append_activity(
			array(
				'request_id'      => (int) $intent['request_id'],
				'interest_id'     => null,
				'kind'            => 'notification_attempt',
				'actor_user_id'   => (int) $intent['_actor_user_id'],
				'idempotency_key' => sprintf( 'local-support-notification-attempt:%s:%d', $intent['_request_hash'], $attempt ),
				'request_hash'    => (string) $intent['_request_hash'],
				'result_version'  => (int) $intent['_result_version'],
				'payload'         => array(
					'status'     => 'retrying',
					'attempt'    => $attempt,
					'due_at'     => $due_at,
					'error_code' => sanitize_key( $error_code ),
				),
				'created_at'      => $due_at,
			)
		);
		return is_wp_error( $record ) ? $record : $this->repository->hydrate_activity( $record )['payload'];
	}

	/** Append one terminal receipt marker. */
	public function record_notification_terminal( array $intent, string $status, array $details ) {
		$record = $this->repository->append_activity(
			array(
				'request_id'      => (int) $intent['request_id'],
				'interest_id'     => null,
				'kind'            => 'notification_terminal',
				'actor_user_id'   => (int) $intent['_actor_user_id'],
				'idempotency_key' => 'local-support-notification-terminal:' . $intent['_request_hash'],
				'request_hash'    => (string) $intent['_request_hash'],
				'result_version'  => (int) $intent['_result_version'],
				'payload'         => array(
					'status'  => sanitize_key( $status ),
					'details' => $details,
				),
			)
		);
		if ( is_wp_error( $record ) ) {
			$existing = $this->repository->notification_terminal( (int) $intent['request_id'], (string) $intent['_request_hash'] );
			return is_array( $existing ) ? $existing['payload'] : $record;
		}
		return $this->repository->hydrate_activity( $record )['payload'];
	}

	/** Resolve currently authorized users for the stored organizer identity. */
	public function organizer_recipient_ids( int $request_id ) {
		$request = $this->repository->get_request( $request_id );
		if ( ! is_array( $request ) ) {
			return is_wp_error( $request ) ? $request : new \WP_Error( 'local_support_request_missing', __( 'The local-support request could not be resolved.', 'extrachill-events' ) );
		}
		if ( 'venue' === $request['organizer_type'] ) {
			$members = $this->memberships->list_for_venue(
				(int) $request['venue_term_id'],
				array(
					'status' => VenueAuthorization::STATUS_ACTIVE,
					'limit'  => 100,
				)
			);
			if ( is_wp_error( $members ) ) {
				return $members;
			}
			$candidates = array_column( $members, 'user_id' );
		} elseif ( 'artist' === $request['organizer_type'] ) {
			$candidates = $this->artist_manager_ids( (int) $request['organizer_id'] );
			if ( is_wp_error( $candidates ) ) {
				return $candidates;
			}
		} else {
			return new \WP_Error( 'local_support_organizer_invalid', __( 'The local-support organizer identity is invalid.', 'extrachill-events' ) );
		}

		$recipients = array();
		foreach ( array_unique( array_map( 'absint', $candidates ) ) as $user_id ) {
			if ( $user_id > 0 && get_userdata( $user_id ) && true === $this->authorization->authorize_organizer( $request, $user_id ) ) {
				$recipients[] = $user_id;
			}
		}
		return $recipients;
	}

	/** Resolve a future private workspace route only after current authorization. */
	public function workspace_url( int $request_id, int $recipient_id, array $intent ) {
		$request = $this->repository->get_request( $request_id );
		if ( ! is_array( $request ) ) {
			return is_wp_error( $request ) ? $request : new \WP_Error( 'local_support_request_missing', __( 'The local-support request could not be resolved.', 'extrachill-events' ) );
		}
		$allowed = 'organizer_interest_changed' === ( $intent['kind'] ?? '' )
			? $this->authorization->authorize_organizer( $request, $recipient_id )
			: ( get_userdata( $recipient_id ) ? true : new \WP_Error( 'local_support_workspace_forbidden', __( 'The local-support workspace is not authorized.', 'extrachill-events' ) ) );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'local_support_workspace_forbidden', __( 'The local-support workspace is not authorized.', 'extrachill-events' ) );
		}
		$url = $this->workspace
			? call_user_func( $this->workspace, $request, $recipient_id )
			: apply_filters( self::WORKSPACE_URL_FILTER, null, $request, $recipient_id );
		return is_string( $url ) && '' !== $url
			? $url
			: new \WP_Error( 'local_support_workspace_unavailable', __( 'A private local-support workspace route is not available yet.', 'extrachill-events' ) );
	}

	/** Convert one activity row back to the service's strict intent shape. */
	private function intent_from_activity( array $activity ): array {
		$intent                        = (array) $activity['payload'];
		$intent['_request_hash']       = (string) $activity['request_hash'];
		$intent['_actor_user_id']      = (int) $activity['actor_user_id'];
		$intent['_result_version']     = (int) $activity['result_version'];
		$intent['_intent_activity_id'] = (int) $activity['id'];
		return $intent;
	}

	/** Read one source activity by immutable ID and request. */
	private function source_activity_by_id( int $activity_id, int $request_id ) {
		return $this->repository->get_activity( $activity_id, $request_id );
	}

	/** Resolve reciprocal Artist Platform managers for a canonical artist term. */
	private function artist_manager_ids( int $artist_term_id ) {
		$main_blog_id   = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'main' ) ) : 0;
		$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'artist' ) ) : 0;
		if ( $main_blog_id < 1 || $artist_blog_id < 1 || ! function_exists( 'extrachill_artist_platform_get_local_support_manager_ids' ) ) {
			return new \WP_Error( 'local_support_artist_managers_unavailable', __( 'Validated Artist Platform manager recipients are unavailable.', 'extrachill-events' ) );
		}
		switch_to_blog( $main_blog_id );
		try {
			$profile_id = absint( get_term_meta( $artist_term_id, '_artist_profile_id', true ) );
		} finally {
			restore_current_blog();
		}
		if ( $profile_id < 1 ) {
			return new \WP_Error( 'local_support_artist_binding_invalid', __( 'The organizer artist profile binding is invalid.', 'extrachill-events' ) );
		}
		switch_to_blog( $artist_blog_id );
		try {
			return extrachill_artist_platform_get_local_support_manager_ids( $profile_id );
		} finally {
			restore_current_blog();
		}
	}
}
