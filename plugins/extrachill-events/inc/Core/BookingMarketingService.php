<?php
/**
 * Approved booking marketing orchestration.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Delegates frozen booking marketing requests to their owning plugins. */
class BookingMarketingService {

	public const SOCIAL_ACTION     = 'datamachine-socials/cross-post';
	public const NEWSLETTER_ACTION = 'extrachill-newsletter/canonical-post-campaign';

	private const TRIGGER      = 'event_converted';
	private const PENDING_KIND = 'extrachill_run_booking_marketing';

	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var VenueBookingConfig */
	private $config;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var bool */
	private static bool $registered = false;

	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueBookingConfig $config = null, ?VenueAuthorization $authorization = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->config        = $config ? $config : new VenueBookingConfig( $this->authorization );
	}

	/** Register conversion and owner-authorization hooks once. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		add_action( 'extrachill_events_booking_event_converted', array( self::class, 'on_event_converted' ), 10, 2 );
		add_action( 'datamachine_pending_action_resolved', array( self::class, 'on_pending_action_resolved' ), 10, 3 );
		add_filter( 'datamachine_socials_delegated_cross_post_authorized', array( self::class, 'authorize_social_operation' ), 10, 2 );
		add_filter( 'extrachill_newsletter_authorize_delegated_campaign', array( self::class, 'authorize_newsletter_operation' ), 10, 3 );
		self::$registered = true;
	}

	/** Trigger recoverable post-conversion work without rolling back the event. */
	public static function on_event_converted( array $conversion, int $actor_id ): void {
		$result = ( new self() )->trigger( (int) ( $conversion['booking_id'] ?? 0 ), $actor_id );
		if ( is_wp_error( $result ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Booking marketing trigger failed',
				array(
					'booking_id' => $conversion['booking_id'] ?? 0,
					'error'      => $result->get_error_code(),
				)
			);
			return;
		}
		foreach ( $result['channels'] ?? array() as $channel => $channel_result ) {
			if ( is_wp_error( $channel_result ) ) {
				do_action(
					'datamachine_log',
					'error',
					'Booking marketing channel trigger failed',
					array(
						'booking_id' => $conversion['booking_id'] ?? 0,
						'channel'    => $channel,
						'error'      => $channel_result->get_error_code(),
					)
				);
			}
		}
	}

	/** Trigger configured channels independently. */
	public function trigger( int $booking_id, int $actor_id ) {
		$context = $this->context( $booking_id, $actor_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$results = array();
		if ( ! empty( $context['config']['enabled'] ) ) {
			foreach ( $context['config']['marketing_triggers'] as $trigger ) {
				if ( self::TRIGGER !== $trigger['event'] ) {
					continue;
				}
				foreach ( $trigger['channels'] as $channel ) {
					$operation = $this->operation( $context, $trigger, $channel );
					if ( is_wp_error( $operation ) ) {
						$results[ $channel['key'] ] = $operation;
						$this->record_trigger_failure( $context['booking'], $trigger['key'], $channel['key'], $actor_id, $operation );
						continue;
					}
					$results[ $channel['key'] ] = 'required' === $channel['approval']
						? $this->stage( $context, $operation, $actor_id )
						: $this->submit( $context, $operation, $actor_id, null );
					if ( is_wp_error( $results[ $channel['key'] ] ) ) {
						$this->record_trigger_failure( $context['booking'], $trigger['key'], $channel['key'], $actor_id, $results[ $channel['key'] ] );
					}
				}
			}
		}
		return array(
			'booking_id' => $booking_id,
			'event_id'   => $context['booking']['event_id'],
			'channels'   => $results,
		);
	}

	/** Apply an accepted approval only when every frozen binding still matches. */
	public function apply( array $input, int $actor_id ) {
		$context = $this->context( absint( $input['booking_id'] ?? 0 ), $actor_id );
		if ( is_wp_error( $context ) ) {
			return $this->apply_error( $context );
		}
		$resolved = $this->configured_channel( $context['config'], (string) ( $input['trigger_key'] ?? '' ), (string) ( $input['channel_key'] ?? '' ) );
		if ( is_wp_error( $resolved ) ) {
			return $this->apply_error( $resolved );
		}
		$operation = $this->operation( $context, $resolved['trigger'], $resolved['channel'] );
		if ( is_wp_error( $operation ) ) {
			return $this->apply_error( $operation );
		}
		if ( ! is_string( $input['binding_hash'] ?? null ) || ! hash_equals( $operation['binding_hash'], $input['binding_hash'] ) ) {
			return $this->stale_error();
		}
		$result = $this->submit( $context, $operation, $actor_id, sanitize_text_field( (string) ( $input['approval_id'] ?? '' ) ) );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}
		if ( $this->is_recoverable_apply_error( $result ) ) {
			return $this->apply_error( $result, $operation['operation_id'] );
		}
		$failure = $this->activity->append(
			array(
				'booking_id'      => $context['booking']['id'],
				'kind'            => 'marketing_operation_approval_failed',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'channel'         => $operation['channel_key'],
				'external_id'     => sanitize_text_field( (string) ( $input['approval_id'] ?? '' ) ),
				'idempotency_key' => $operation['operation_id'] . ':approval-failed',
				'payload'         => array(
					'operation_id' => $operation['operation_id'],
					'action'       => $operation['action'],
					'error_code'   => sanitize_key( $result->get_error_code() ),
				),
			)
		);
		return is_wp_error( $failure ) ? new \WP_Error( 'booking_marketing_approval_failure_persist_failed' ) : $result;
	}

	/** Reconcile, retry, or cancel one previously submitted bounded operation. */
	public function manage( string $verb, int $booking_id, string $operation_ref, int $actor_id ) {
		if ( ! in_array( $verb, array( 'get', 'retry', 'cancel' ), true ) || ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
			return new \WP_Error( 'booking_marketing_operation_invalid', __( 'The marketing operation reference is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$booking = $this->bookings->get( $booking_id );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		if ( 'retry' === $verb ) {
			$context = $this->context( $booking_id, $actor_id );
			if ( is_wp_error( $context ) ) {
				return $context;
			}
		}
		$receipt = $this->activity->find_by_external_id( $booking_id, 'marketing_operation_submitted', $operation_ref );
		if ( ! is_array( $receipt ) ) {
			return is_wp_error( $receipt ) ? $receipt : new \WP_Error( 'booking_marketing_operation_not_found', __( 'The marketing operation was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$action = (string) ( $receipt['payload']['data']['action'] ?? '' );
		$result = $this->execute_ability(
			'datamachine/' . $verb . '-delegated-operation',
			array(
				'action'        => $action,
				'operation_ref' => $operation_ref,
			)
		);
		return $this->record_response( $booking, $receipt['channel'], $action, $result, $actor_id, $receipt['payload']['data']['approval_id'] ?? null, (string) ( $receipt['payload']['data']['operation_id'] ?? '' ), 'marketing_operation_' . $verb . '_rejected' );
	}

	/** Stage one deterministic approval containing hashes and references only. */
	private function stage( array $context, array $operation, int $actor_id ) {
		$existing = $this->frozen_activity( $context['booking']['id'], $operation['operation_id'] );
		if ( is_wp_error( $existing ) ) {
			return $this->recoverable_infrastructure_error( $existing );
		}
		if ( is_array( $existing ) ) {
			$prior = (string) ( $existing['payload']['data']['binding_hash'] ?? '' );
			if ( ! hash_equals( $prior, $operation['binding_hash'] ) ) {
				return new \WP_Error( 'booking_marketing_operation_conflict', __( 'This marketing operation was already approved with different content or policy.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
		}
		$failed = $this->activity->find_by_idempotency( $context['booking']['id'], $operation['operation_id'] . ':approval-failed' );
		if ( is_wp_error( $failed ) ) {
			return $failed;
		}
		if ( is_array( $failed ) ) {
			return new \WP_Error(
				sanitize_key( (string) ( $failed['payload']['data']['error_code'] ?? 'booking_marketing_approval_failed' ) ),
				__( 'The approved marketing operation failed permanently.', 'extrachill-events' ),
				array(
					'status'    => 409,
					'retryable' => false,
				)
			);
		}
		$store = apply_filters( 'wp_agent_pending_action_store', null, array( 'kind' => self::PENDING_KIND ) );
		if ( ! is_object( $store ) || ! is_callable( array( $store, 'get' ) ) || ! is_callable( array( $store, 'store' ) ) || ! class_exists( '\AgentsAPI\AI\Approvals\WP_Agent_Pending_Action' ) ) {
			return new \WP_Error(
				'booking_marketing_approval_unavailable',
				__( 'The approval substrate is unavailable.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}
		$action_id = $this->approval_id( $operation['operation_id'] );
		$stored    = $store->get( $action_id, true );
		if ( is_object( $stored ) ) {
			$stored_input = is_callable( array( $stored, 'get_apply_input' ) ) ? $stored->get_apply_input() : null;
			$stored_kind  = is_callable( array( $stored, 'get_kind' ) ) ? $stored->get_kind() : '';
			$stored_id    = is_callable( array( $stored, 'get_action_id' ) ) ? $stored->get_action_id() : '';
			if ( self::PENDING_KIND !== $stored_kind || $action_id !== $stored_id || ! is_array( $stored_input ) || ( $stored_input['binding_hash'] ?? '' ) !== $operation['binding_hash'] || (int) ( $stored_input['booking_id'] ?? 0 ) !== (int) $context['booking']['id'] || ( $stored_input['trigger_key'] ?? '' ) !== $operation['trigger_key'] || ( $stored_input['channel_key'] ?? '' ) !== $operation['channel_key'] ) {
				return new \WP_Error( 'booking_marketing_approval_conflict', __( 'The stored marketing approval does not match the frozen operation.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
		}
		if ( ! is_object( $stored ) ) {
			try {
				$pending_action = \AgentsAPI\AI\Approvals\WP_Agent_Pending_Action::from_array(
					array(
						'action_id'   => $action_id,
						'kind'        => self::PENDING_KIND,
						'summary'     => sprintf( 'Approve %s marketing for event #%d.', $operation['channel_key'], $context['booking']['event_id'] ),
						'preview'     => $this->binding_receipt( $operation ),
						'apply_input' => array(
							'booking_id'   => $context['booking']['id'],
							'trigger_key'  => $operation['trigger_key'],
							'channel_key'  => $operation['channel_key'],
							'binding_hash' => $operation['binding_hash'],
							'approval_id'  => $action_id,
						),
						'creator'     => 'user:' . $actor_id,
						'agent'       => null,
						'workspace'   => null,
						'status'      => 'pending',
						'created_at'  => gmdate( 'c' ),
						'expires_at'  => gmdate( 'c', time() + WEEK_IN_SECONDS ),
						'metadata'    => array(
							'datamachine' => array(
								'authorization' => array(
									'operation' => 'run_booking_marketing',
									'target'    => array(
										'booking_id'    => $context['booking']['id'],
										'venue_term_id' => $context['booking']['venue_term_id'],
									),
								),
							),
						),
					)
				);
			} catch ( \InvalidArgumentException $exception ) {
				unset( $exception );
				return new \WP_Error(
					'booking_marketing_approval_stage_failed',
					__( 'Marketing approval could not be staged.', 'extrachill-events' ),
					array(
						'status'    => 503,
						'retryable' => true,
					)
				);
			}
			if ( ! $store->store( $pending_action ) ) {
				return new \WP_Error(
					'booking_marketing_approval_stage_failed',
					__( 'Marketing approval could not be staged.', 'extrachill-events' ),
					array(
						'status'    => 503,
						'retryable' => true,
					)
				);
			}
			$stored = $pending_action;
		}
		$activity = $this->freeze( $context['booking'], $operation, $actor_id, $action_id, 'marketing_operation_approval_pending' );
		if ( is_wp_error( $activity ) ) {
			return $activity;
		}
		$status = is_callable( array( $stored, 'get_status' ) ) ? (string) $stored->get_status() : 'pending';
		if ( 'accepted' === $status ) {
			return $this->submit( $context, $operation, $actor_id, $action_id );
		}
		return array(
			'status'      => $status,
			'approval_id' => $action_id,
			'activity_id' => $activity['id'],
		);
	}

	/** Freeze the request, then call only Data Machine's public submit ability. */
	private function submit( array $context, array $operation, int $actor_id, ?string $approval_id ) {
		$frozen = $this->freeze( $context['booking'], $operation, $actor_id, $approval_id, 'marketing_operation_frozen' );
		if ( is_wp_error( $frozen ) ) {
			return $frozen;
		}
		$prior = (string) ( $frozen['payload']['data']['binding_hash'] ?? '' );
		if ( ! hash_equals( $prior, $operation['binding_hash'] ) ) {
			return new \WP_Error( 'booking_marketing_operation_conflict', __( 'This marketing operation is frozen with different content or policy.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$result = $this->execute_ability(
			'datamachine/submit-delegated-operation',
			array(
				'action'       => $operation['action'],
				'operation_id' => $operation['operation_id'],
				'input'        => $operation['input'],
				'timestamp'    => $operation['scheduled_at'],
			)
		);
		return $this->record_response( $context['booking'], $operation['channel_key'], $operation['action'], $result, $actor_id, $approval_id, $operation['operation_id'] );
	}

	/** Persist one immutable hash-only approval/execution binding. */
	private function freeze( array $booking, array $operation, int $actor_id, ?string $approval_id, string $kind ) {
		return $this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => $kind,
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'channel'         => $operation['channel_key'],
				'external_id'     => $approval_id,
				'idempotency_key' => $operation['operation_id'] . ( 'marketing_operation_frozen' === $kind ? ':frozen' : ':approval' ),
				'payload'         => array_merge( $this->binding_receipt( $operation ), array( 'approval_id' => $approval_id ) ),
			)
		);
	}

	/** Record only the opaque operation ref and owner-approved bounded projection. */
	private function record_response( array $booking, string $channel, string $action, $result, int $actor_id, ?string $approval_id, string $operation_id, string $failure_kind = 'marketing_operation_failed' ) {
		if ( is_wp_error( $result ) ) {
			$result = array(
				'success'    => false,
				'error_code' => $result->get_error_code(),
			);
		}
		if ( ! is_array( $result ) || true !== ( $result['success'] ?? false ) || ! preg_match( '/^dop_[a-f0-9]{64}$/', (string) ( $result['operation_ref'] ?? '' ) ) ) {
			$code = sanitize_key( (string) ( $result['error_code'] ?? 'booking_marketing_operation_failed' ) );
			$this->activity->append(
				array(
					'booking_id'      => $booking['id'],
					'kind'            => $failure_kind,
					'actor_type'      => 'user',
					'actor_id'        => $actor_id,
					'channel'         => $channel,
					'idempotency_key' => $failure_kind . ':' . hash( 'sha256', $operation_id . "\0" . $action . "\0" . $channel . "\0" . $code ),
					'payload'         => array(
						'action'     => $action,
						'error_code' => $code,
						'retryable'  => ! empty( $result['retryable'] ),
					),
				)
			);
			return new \WP_Error(
				$code,
				__( 'The delegated marketing operation failed.', 'extrachill-events' ),
				array(
					'status'    => 409,
					'retryable' => ! empty( $result['retryable'] ),
				)
			);
		}
		$operation_ref = (string) $result['operation_ref'];
		$status        = in_array( $result['status'] ?? '', array( 'submitted', 'executing', 'executed', 'no-op', 'failed', 'cancelled', 'retrying' ), true ) ? $result['status'] : 'failed';
		$projection    = $this->sanitize_projection( $action, $result['projection'] ?? array() );
		$receipt       = $this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'marketing_operation_submitted',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'channel'         => $channel,
				'external_id'     => $operation_ref,
				'idempotency_key' => 'marketing-operation:' . hash( 'sha256', $operation_ref . "\0" . $status . "\0" . wp_json_encode( $projection ) ),
				'payload'         => array(
					'action'        => $action,
					'operation_id'  => $operation_id,
					'operation_ref' => $operation_ref,
					'status'        => $status,
					'replayed'      => ! empty( $result['replayed'] ),
					'approval_id'   => $approval_id,
					'projection'    => $projection,
				),
			)
		);
		if ( is_wp_error( $receipt ) ) {
			return new \WP_Error(
				'booking_marketing_receipt_persist_failed',
				__( 'The delegated marketing receipt could not be persisted.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}
		return array(
			'status'        => $status,
			'operation_ref' => $operation_ref,
			'activity_id'   => $receipt['id'],
			'projection'    => $projection,
			'retryable'     => ! empty( $result['retryable'] ),
		);
	}

	/** Build the exact owner input plus frozen policy/content/asset/version hashes. */
	private function operation( array $context, array $trigger, array $channel ) {
		$booking = $context['booking'];
		$event   = $context['event'];
		$url     = get_permalink( $event->ID );
		if ( ! is_string( $url ) || '' === $url ) {
			return new \WP_Error( 'booking_marketing_event_unavailable', __( 'The canonical event URL is unavailable.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( self::SOCIAL_ACTION === $channel['action'] ) {
			$caption    = '' !== $channel['social']['caption'] ? $channel['social']['caption'] : $event->post_title;
			$asset_refs = $this->social_asset_refs( $channel['social']['media_kind'], $channel['social']['asset_refs'] );
			if ( is_wp_error( $asset_refs ) ) {
				return $asset_refs;
			}
			$input = array(
				'post_id'      => (int) $event->ID,
				'source_url'   => $url,
				'caption'      => $caption,
				'content_hash' => hash( 'sha256', $caption ),
				'channels'     => $channel['social']['channels'],
				'media_kind'   => $channel['social']['media_kind'],
				'asset_refs'   => $asset_refs,
			);
		} elseif ( self::NEWSLETTER_ACTION === $channel['action'] ) {
			$input = array(
				'source' => array(
					'site_id' => get_current_blog_id(),
					'post_id' => (int) $event->ID,
				),
				'policy' => $channel['newsletter']['policy'],
			);
		} else {
			return new \WP_Error( 'booking_marketing_action_invalid', __( 'The marketing action is unsupported.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$operation_id = 'booking-marketing:' . hash( 'sha256', $booking['public_id'] . "\0" . $booking['event_id'] . "\0" . $trigger['key'] . "\0" . $channel['key'] );
		$policy_hash  = $this->hash_value(
			array(
				'config_revision' => $context['config']['revision'],
				'trigger'         => $trigger['key'],
				'channel'         => $channel,
			)
		);
		$content_hash = $this->hash_value(
			array(
				'title'       => (string) $event->post_title,
				'content'     => (string) ( $event->post_content ?? '' ),
				'excerpt'     => (string) ( $event->post_excerpt ?? '' ),
				'url'         => $url,
				'owner_input' => $input,
			)
		);
		$assets       = self::SOCIAL_ACTION === $channel['action'] ? $this->asset_identities( $channel['social']['asset_refs'] ) : array();
		if ( is_wp_error( $assets ) ) {
			return $assets;
		}
		$assets_hash   = $this->hash_value( $assets );
		$event_version = $this->hash_value(
			array(
				'id'           => (int) $event->ID,
				'status'       => (string) $event->post_status,
				'modified_gmt' => (string) ( $event->post_modified_gmt ?? '' ),
				'content_hash' => $content_hash,
			)
		);
		$binding       = array(
			'operation_id'    => $operation_id,
			'action'          => $channel['action'],
			'trigger_key'     => $trigger['key'],
			'channel_key'     => $channel['key'],
			'booking_version' => (int) $booking['version'],
			'event_version'   => $event_version,
			'policy_hash'     => $policy_hash,
			'content_hash'    => $content_hash,
			'assets_hash'     => $assets_hash,
		);
		$scheduled_at  = $this->scheduled_at( $booking, $channel );
		if ( is_wp_error( $scheduled_at ) ) {
			return $scheduled_at;
		}
		return array_merge(
			$binding,
			array(
				'binding_hash' => $this->hash_value( $binding ),
				'input'        => $input,
				'scheduled_at' => $scheduled_at,
			)
		);
	}

	/** Authorize Socials from exact current Events authority and frozen binding. */
	public static function authorize_social_operation( bool $allowed, array $context ) {
		unset( $allowed );
		return ( new self() )->authorize_owner( self::SOCIAL_ACTION, $context );
	}

	/** Authorize Newsletter from exact current Events authority and frozen binding. */
	public static function authorize_newsletter_operation( $allowed, array $source, array $context ) {
		unset( $allowed, $source );
		return ( new self() )->authorize_owner( self::NEWSLETTER_ACTION, $context );
	}

	/** Rebuild the owner request and reject stale actor, booking, event, or policy. */
	private function authorize_owner( string $action, array $owner_context ) {
		if ( ( $owner_context['action'] ?? '' ) !== $action || ! is_array( $owner_context['input'] ?? null ) ) {
			return new \WP_Error( 'booking_marketing_owner_forbidden' );
		}
		$event_id = self::SOCIAL_ACTION === $action ? (int) ( $owner_context['input']['post_id'] ?? 0 ) : (int) ( $owner_context['input']['source']['post_id'] ?? 0 );
		$booking  = $this->bookings->get_by_event( $event_id );
		$actor_id = is_int( $owner_context['actor']['user_id'] ?? null ) ? $owner_context['actor']['user_id'] : 0;
		if ( is_wp_error( $booking ) ) {
			return $this->recoverable_infrastructure_error( $booking );
		}
		if ( ! is_array( $booking ) || $actor_id < 1 ) {
			return new \WP_Error( 'booking_marketing_owner_forbidden' );
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( is_wp_error( $allowed ) && $this->is_recoverable_apply_error( $allowed ) ) {
			return $this->recoverable_infrastructure_error( $allowed );
		}
		if ( true !== $allowed ) {
			return new \WP_Error( 'booking_marketing_owner_forbidden' );
		}
		$phase         = (string) ( $owner_context['phase'] ?? '' );
		$frozen        = null;
		$receipt       = null;
		$authorization = null;
		$operation_id  = is_string( $owner_context['operation_id'] ?? null ) ? $owner_context['operation_id'] : '';
		$operation_ref = is_string( $owner_context['operation_ref'] ?? null ) ? $owner_context['operation_ref'] : '';
		if ( '' !== $operation_id ) {
			$frozen = $this->frozen_activity( $booking['id'], $operation_id );
		}
		if ( '' !== $operation_ref ) {
			$authorization = $this->activity->find_by_external_id( $booking['id'], 'marketing_operation_authorized', $operation_ref );
			$receipt       = $this->activity->find_by_external_id( $booking['id'], 'marketing_operation_submitted', $operation_ref );
			if ( is_wp_error( $authorization ) || is_wp_error( $receipt ) ) {
				return $this->recoverable_infrastructure_error( is_wp_error( $authorization ) ? $authorization : $receipt );
			}
			if ( '' === $operation_id && ! is_array( $frozen ) && is_array( $receipt ) ) {
				$frozen = $this->activity->find_by_idempotency( $booking['id'], (string) ( $receipt['payload']['data']['operation_id'] ?? '' ) . ':frozen' );
			}
			if ( '' === $operation_id && ! is_array( $frozen ) && is_array( $authorization ) ) {
				$frozen = $this->activity->find_by_idempotency( $booking['id'], (string) ( $authorization['payload']['data']['operation_id'] ?? '' ) . ':frozen' );
			}
		}
		if ( is_wp_error( $frozen ) ) {
			return $this->recoverable_infrastructure_error( $frozen );
		}
		$receipt_operation_id = is_array( $receipt ) ? (string) ( $receipt['payload']['data']['operation_id'] ?? '' ) : '';
		$frozen_operation_id  = is_array( $frozen ) ? (string) ( $frozen['payload']['data']['operation_id'] ?? '' ) : '';
		$identifiers_match    = '' !== $operation_id && hash_equals( $operation_id, $receipt_operation_id ) && hash_equals( $operation_id, $frozen_operation_id );
		if ( in_array( $phase, array( 'reconcile', 'cancel' ), true ) ) {
			return $identifiers_match && ( $receipt['payload']['data']['action'] ?? '' ) === $action && (int) $booking['event_id'] === $event_id
				? true
				: new \WP_Error( 'booking_marketing_owner_forbidden' );
		}
		$context = $this->context( $booking['id'], $actor_id );
		if ( is_wp_error( $context ) ) {
			return $this->recoverable_infrastructure_error( $context );
		}
		if ( is_array( $frozen ) ) {
			$data     = $frozen['payload']['data'];
			$resolved = $this->configured_channel( $context['config'], (string) ( $data['trigger_key'] ?? '' ), (string) ( $data['channel_key'] ?? '' ) );
			if ( is_wp_error( $resolved ) ) {
				return $this->stale_error();
			}
			$current = $this->operation( $context, $resolved['trigger'], $resolved['channel'] );
		} else {
			return new \WP_Error( 'booking_marketing_binding_missing' );
		}
		if ( is_wp_error( $current ) ) {
			return $this->is_recoverable_apply_error( $current ) ? $this->recoverable_infrastructure_error( $current ) : $this->stale_error();
		}
		$data        = $frozen['payload']['data'];
		$owner_input = $owner_context['input'];
		if ( self::SOCIAL_ACTION === $action ) {
			$owner_input = array_intersect_key( $owner_input, $current['input'] );
		}
		if ( $action !== $current['action'] || $current['input'] !== $owner_input || ! hash_equals( (string) ( $data['binding_hash'] ?? '' ), $current['binding_hash'] ) ) {
			return $this->stale_error();
		}
		if ( 'submit' === $phase ) {
			if ( ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) || ! hash_equals( $current['operation_id'], $operation_id ) ) {
				return new \WP_Error( 'booking_marketing_owner_forbidden' );
			}
			$authorization = $this->activity->append(
				array(
					'booking_id'      => $booking['id'],
					'kind'            => 'marketing_operation_authorized',
					'actor_type'      => 'user',
					'actor_id'        => $actor_id,
					'channel'         => $current['channel_key'],
					'external_id'     => $operation_ref,
					'idempotency_key' => $operation_id . ':authorized',
					'payload'         => $this->binding_receipt( $current ),
				)
			);
			if ( is_wp_error( $authorization ) ) {
				return new \WP_Error(
					'booking_marketing_authorization_persist_failed',
					__( 'The delegated marketing authorization could not be persisted.', 'extrachill-events' ),
					array(
						'status'    => 503,
						'retryable' => true,
					)
				);
			}
			return is_array( $authorization )
				&& hash_equals( $operation_ref, (string) ( $authorization['external_id'] ?? '' ) )
				&& hash_equals( $operation_id, (string) ( $authorization['payload']['data']['operation_id'] ?? '' ) )
				&& hash_equals( $current['binding_hash'], (string) ( $authorization['payload']['data']['binding_hash'] ?? '' ) )
				? true
				: new \WP_Error( 'booking_marketing_owner_forbidden' );
		}
		if ( in_array( $phase, array( 'effect', 'execute', 'retry' ), true ) ) {
			$authorized_operation_id = is_array( $authorization ) ? (string) ( $authorization['payload']['data']['operation_id'] ?? '' ) : '';
			if ( ! is_array( $authorization ) || ! hash_equals( $data['operation_id'], $authorized_operation_id ) || ! hash_equals( (string) $authorization['external_id'], $operation_ref ) ) {
				return new \WP_Error( 'booking_marketing_owner_forbidden' );
			}
		}
		return true;
	}

	/** Resolve and authorize canonical booking, event, and venue policy. */
	private function context( int $booking_id, int $actor_id ) {
		$booking = $this->bookings->get( $booking_id );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		$event = null === $booking['event_id'] ? null : get_post( $booking['event_id'] );
		$type  = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
		if ( 'confirmed' !== $booking['status'] || ! $event || ( $event->post_type ?? null ) !== $type || 'publish' !== ( $event->post_status ?? null ) ) {
			return new \WP_Error( 'booking_marketing_event_unavailable', __( 'Marketing requires a confirmed booking linked to a published canonical event.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$config = $this->config->get( $booking['venue_term_id'] );
		return is_wp_error( $config ) ? $config : array(
			'booking' => $booking,
			'event'   => $event,
			'config'  => $config,
		);
	}

	/** Resolve one exact configured channel. */
	private function configured_channel( array $config, string $trigger_key, string $channel_key ) {
		foreach ( $config['marketing_triggers'] as $trigger ) {
			if ( $trigger_key !== $trigger['key'] || self::TRIGGER !== $trigger['event'] ) {
				continue;
			}
			foreach ( $trigger['channels'] as $channel ) {
				if ( $channel_key === $channel['key'] ) {
					return array(
						'trigger' => $trigger,
						'channel' => $channel,
					);
				}
			}
		}
		return new \WP_Error( 'booking_marketing_channel_unavailable', __( 'The configured marketing channel is no longer available.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	private function execute_ability( string $name, array $input ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( ! is_object( $ability ) || ! is_callable( array( $ability, 'execute' ) ) ) {
			return array(
				'success'    => false,
				'error_code' => 'booking_marketing_operation_unavailable',
				'retryable'  => true,
			);
		}
		return $ability->execute( $input );
	}

	/** Keep only fields explicitly projected by the two owner contracts. */
	private function sanitize_projection( string $action, $projection ): array {
		$projection = is_array( $projection ) ? $projection : array();
		if ( self::SOCIAL_ACTION === $action ) {
			$result = array(
				'classification' => in_array( $projection['classification'] ?? '', array( 'success', 'partial', 'failure', 'no_op', 'cancelled' ), true ) ? $projection['classification'] : null,
				'effect_count'   => max( 0, (int) ( $projection['effect_count'] ?? 0 ) ),
				'share_refs'     => array(),
				'error_codes'    => array(),
			);
			foreach ( array_slice( is_array( $projection['share_refs'] ?? null ) ? $projection['share_refs'] : array(), 0, 20 ) as $ref ) {
				if ( is_array( $ref ) && is_string( $ref['channel'] ?? null ) && is_string( $ref['platform_post_id'] ?? null ) ) {
					$result['share_refs'][] = array(
						'channel'          => sanitize_key( $ref['channel'] ),
						'platform_post_id' => mb_substr( sanitize_text_field( $ref['platform_post_id'] ), 0, 191 ),
					);
				}
			}
			foreach ( array_slice( is_array( $projection['error_codes'] ?? null ) ? $projection['error_codes'] : array(), 0, 20 ) as $error ) {
				if ( is_array( $error ) ) {
					$result['error_codes'][] = array(
						'channel' => sanitize_key( (string) ( $error['channel'] ?? '' ) ),
						'code'    => sanitize_key( (string) ( $error['code'] ?? '' ) ),
					);
				}
			}
			return array_filter( $result, static fn( $value ) => null !== $value );
		}
		if ( self::NEWSLETTER_ACTION === $action ) {
			$record = is_array( $projection['record'] ?? null ) ? $projection['record'] : null;
			return array_filter(
				array(
					'classification' => in_array( $projection['classification'] ?? '', array( 'submitted', 'executing', 'retrying', 'executed', 'no-op', 'failed', 'cancelled' ), true ) ? $projection['classification'] : null,
					'effect_count'   => isset( $projection['effect_count'] ) ? max( 0, (int) $projection['effect_count'] ) : null,
					'record'         => $record ? array(
						'newsletter_post_id' => max( 0, (int) ( $record['newsletter_post_id'] ?? 0 ) ),
						'campaign_id'        => mb_substr( sanitize_text_field( (string) ( $record['campaign_id'] ?? '' ) ), 0, 191 ),
					) : null,
					'error_code'     => isset( $projection['error_code'] ) ? sanitize_key( (string) $projection['error_code'] ) : null,
				),
				static fn( $value ) => null !== $value
			);
		}
		return array();
	}

	private function binding_receipt( array $operation ): array {
		return array_intersect_key( $operation, array_flip( array( 'operation_id', 'action', 'trigger_key', 'channel_key', 'booking_version', 'event_version', 'policy_hash', 'content_hash', 'assets_hash', 'binding_hash', 'scheduled_at' ) ) );
	}

	private function frozen_activity( int $booking_id, string $operation_id ) {
		$frozen = $this->activity->find_by_idempotency( $booking_id, $operation_id . ':frozen' );
		return is_array( $frozen ) || is_wp_error( $frozen ) ? $frozen : $this->activity->find_by_idempotency( $booking_id, $operation_id . ':approval' );
	}

	/** Convert only known pre-effect infrastructure failures into pending recovery. */
	private function apply_error( \WP_Error $error, string $operation_id = '' ) {
		if ( ! $this->is_recoverable_apply_error( $error ) ) {
			return $error;
		}
		$result = array(
			'status'    => 'recovery-pending',
			'retryable' => true,
		);
		if ( '' !== $operation_id ) {
			$result['operation_id'] = $operation_id;
		}
		return $result;
	}

	/** Identify bounded failures that occur before a delegated effect is trusted. */
	private function is_recoverable_apply_error( \WP_Error $error ): bool {
		$data = $error->get_error_data();
		if ( is_array( $data ) && true === ( $data['retryable'] ?? false ) ) {
			return true;
		}
		return in_array(
			$error->get_error_code(),
			array(
				'booking_read_failed',
				'venue_membership_schema_unavailable',
				'venue_membership_read_failed',
				'booking_activity_booking_read_failed',
				'booking_activity_read_failed',
				'booking_activity_write_failed',
				'booking_event_conversion_state_read_failed',
				'booking_marketing_authorization_persist_failed',
				'booking_marketing_receipt_persist_failed',
			),
			true
		);
	}

	/** Add bounded retry metadata when an owner callback must carry the failure. */
	private function recoverable_infrastructure_error( \WP_Error $error ): \WP_Error {
		if ( ! $this->is_recoverable_apply_error( $error ) ) {
			return $error;
		}
		$data              = is_array( $error->get_error_data() ) ? $error->get_error_data() : array();
		$data['status']    = (int) ( $data['status'] ?? 503 );
		$data['retryable'] = true;
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	private function scheduled_at( array $booking, array $channel ) {
		$state = $this->activity->event_conversion_state( $booking['id'], $booking['public_id'] );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$time = is_array( $state ) && ! empty( $state['completed']['occurred_at'] )
			? strtotime( $state['completed']['occurred_at'] . ' UTC' )
			: strtotime( (string) $booking['updated_at'] . ' UTC' );
		return max( 1, (int) $time + (int) $channel['delay_seconds'] );
	}

	/** Resolve immutable public attachment identity for approval and effect checks. */
	private function asset_identities( array $asset_refs ) {
		$identities = array();
		foreach ( $asset_refs as $asset_ref ) {
			$attachment = get_post( $asset_ref );
			$url        = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $asset_ref ) : false;
			$mime       = function_exists( 'get_post_mime_type' ) ? get_post_mime_type( $asset_ref ) : false;
			$file       = function_exists( 'get_attached_file' ) ? get_attached_file( $asset_ref, true ) : false;
			$file_hash  = is_string( $file ) && is_readable( $file ) ? hash_file( 'sha256', $file ) : false;
			if ( ! $attachment || 'attachment' !== ( $attachment->post_type ?? '' ) || ! is_string( $url ) || '' === $url || ! is_string( $mime ) || '' === $mime || ! is_string( $file_hash ) ) {
				return new \WP_Error( 'booking_marketing_asset_invalid', __( 'A marketing attachment is unavailable or invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$identities[] = array(
				'id'           => (int) $asset_ref,
				'url_hash'     => hash( 'sha256', $url ),
				'file_hash'    => $file_hash,
				'mime'         => $mime,
				'modified_gmt' => (string) ( $attachment->post_modified_gmt ?? '' ),
			);
		}
		return $identities;
	}

	/** Map ordered venue attachment IDs onto the concrete Socials v2 role contract. */
	private function social_asset_refs( string $media_kind, array $asset_ids ) {
		$refs = array();
		foreach ( $asset_ids as $index => $attachment_id ) {
			$mime = get_post_mime_type( $attachment_id );
			if ( 'reel' === $media_kind ) {
				$role = 0 === $index ? 'video' : 'cover';
			} elseif ( 'story' === $media_kind ) {
				$role = is_string( $mime ) && str_starts_with( $mime, 'video/' ) ? 'video' : 'image';
			} else {
				$role = 'image';
			}
			if ( ! is_string( $mime ) || ( 'video' === $role && ! str_starts_with( $mime, 'video/' ) ) || ( 'video' !== $role && ! str_starts_with( $mime, 'image/' ) ) ) {
				return new \WP_Error( 'booking_marketing_asset_kind_invalid', __( 'A marketing attachment does not match its deterministic Socials role.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$refs[] = array(
				'attachment_id' => (int) $attachment_id,
				'role'          => $role,
			);
		}
		return $refs;
	}

	/** Persist one bounded trigger failure for automatic recovery visibility. */
	private function record_trigger_failure( array $booking, string $trigger, string $channel, int $actor_id, \WP_Error $error ): void {
		$this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'marketing_operation_trigger_failed',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'channel'         => $channel,
				'idempotency_key' => 'marketing-trigger-failed:' . hash( 'sha256', $booking['public_id'] . "\0" . $trigger . "\0" . $channel . "\0" . $error->get_error_code() ),
				'payload'         => array(
					'trigger'    => $trigger,
					'channel'    => $channel,
					'error_code' => sanitize_key( $error->get_error_code() ),
				),
			)
		);
	}

	private function approval_id( string $operation_id ): string {
		$hash = substr( hash( 'sha256', $operation_id . ':approval' ), 0, 32 );
		return sprintf( 'act_%s-%s-%s-%s-%s', substr( $hash, 0, 8 ), substr( $hash, 8, 4 ), substr( $hash, 12, 4 ), substr( $hash, 16, 4 ), substr( $hash, 20, 12 ) );
	}

	private function hash_value( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( $this->canonicalize( $value ) ) );
	}

	private function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize( $item );
		}
		return $value;
	}

	private function stale_error(): \WP_Error {
		return new \WP_Error( 'booking_marketing_binding_stale', __( 'The booking, event, content, assets, or marketing policy changed after approval.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	/** Record rejected approvals without storing their free-form reason. */
	public static function on_pending_action_resolved( $action, $decision, string $resolver ): void {
		unset( $resolver );
		if ( ! is_object( $action ) || ! is_callable( array( $action, 'get_kind' ) ) || self::PENDING_KIND !== $action->get_kind() || ! is_object( $decision ) || ! is_callable( array( $decision, 'is_rejected' ) ) || ! $decision->is_rejected() ) {
			return;
		}
		$input = $action->get_apply_input();
		if ( ! is_array( $input ) ) {
			return;
		}
		( new BookingActivityRepository() )->append(
			array(
				'booking_id'      => absint( $input['booking_id'] ?? 0 ),
				'kind'            => 'marketing_operation_denied',
				'actor_type'      => 'user',
				'actor_id'        => get_current_user_id(),
				'channel'         => sanitize_key( (string) ( $input['channel_key'] ?? '' ) ),
				'external_id'     => $action->get_action_id(),
				'idempotency_key' => 'marketing-denied:' . hash( 'sha256', $action->get_action_id() ),
				'payload'         => array( 'approval_id' => $action->get_action_id() ),
			)
		);
	}
}
