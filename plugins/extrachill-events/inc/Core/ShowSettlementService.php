<?php
/**
 * Complete immutable show settlements layered over frozen commission settlements.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

// Repository convention uses concise method comments.
// phpcs:disable WordPress.Files.FileName,Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns complete show math, immutable revisions, evidence, and lifecycle audit. */
class ShowSettlementService {
	public const FORMULA_VERSION = 1;
	public const ACTIONS         = array( 'finalized', 'acknowledged', 'disputed', 'corrected', 'paid', 'void' );

	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var TicketSettlementService */
	private $commissions;
	/** @var BookingAttachmentRepository */
	private $attachments;
	/** @var BookingAttachmentService */
	private $attachment_service;
	/** @var LocalSupportAuthorization */
	private $artist_authorization;
	/** @var bool */
	private $transaction_active = false;

	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueAuthorization $authorization = null, ?TicketSettlementService $commissions = null, ?BookingAttachmentRepository $attachments = null, ?BookingAttachmentService $attachment_service = null, ?LocalSupportAuthorization $artist_authorization = null ) {
		$this->bookings             = $bookings ? $bookings : new BookingRepository();
		$this->activity             = $activity ? $activity : new BookingActivityRepository();
		$this->authorization        = $authorization ? $authorization : new VenueAuthorization();
		$this->commissions          = $commissions ? $commissions : new TicketSettlementService( $this->bookings, $this->activity, $this->authorization );
		$this->attachments          = $attachments ? $attachments : new BookingAttachmentRepository();
		$this->attachment_service   = $attachment_service ? $attachment_service : new BookingAttachmentService( $this->attachments, $this->bookings, $this->activity, null, null, $this->authorization );
		$this->artist_authorization = $artist_authorization ? $artist_authorization : new LocalSupportAuthorization( $this->authorization );
	}

	/** Calculate and append the first immutable draft revision. */
	public function draft( array $input, int $actor_id ) {
		return $this->create_revision( $input, $actor_id, false );
	}

	/** Append a replacement draft revision while the current revision is still a draft. */
	public function revise( array $input, int $actor_id ) {
		return $this->create_revision( $input, $actor_id, false, true );
	}

	/** Link a finalized revision to a new immutable correction draft. */
	public function correct( array $input, int $actor_id ) {
		$reason = $this->text( $input['reason'] ?? null, 'reason', 1000 );
		if ( is_wp_error( $reason ) ) {
			return $reason;
		}
		$input['_correction_reason'] = $reason;
		return $this->create_revision( $input, $actor_id, true, true );
	}

	/** Read one revision or the latest revision with derived lifecycle state. */
	public function get( int $booking_id, int $actor_id, ?int $revision = null ) {
		return $this->read( $booking_id, $actor_id, $revision, 10000 );
	}

	/** Read one revision with a strict ticket-evidence scan budget for reporting. */
	public function get_for_reporting( int $booking_id, int $actor_id, int $max_sales_reports = 200 ) {
		return $this->read( $booking_id, $actor_id, null, max( 1, min( 1000, $max_sales_reports ) ) );
	}

	private function read( int $booking_id, int $actor_id, ?int $revision, int $max_sales_reports ) {
		$booking = $this->booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_MANAGE_FINANCES );
		if ( true !== $allowed ) {
			return $this->denied( $allowed );
		}
		$row = null === $revision ? $this->latest_revision( $booking['id'] ) : $this->revision_number( $booking['id'], $revision );
		if ( ! is_array( $row ) ) {
			return is_wp_error( $row ) ? $row : new \WP_Error( 'show_settlement_not_found', __( 'The show settlement was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$verified = $this->verify_revision( $row, $booking, $actor_id, false, false, $max_sales_reports );
		return is_wp_error( $verified ) ? $verified : $this->present( $verified );
	}

	/** Freeze one exact draft revision. */
	public function finalize( array $input, int $actor_id ) {
		return $this->action( $input, $actor_id, 'finalized', array(), array( 'draft' ), true );
	}

	/** Record either direct counterparty attestation or venue-recorded evidence. */
	public function acknowledge( array $input, int $actor_id ) {
		$booking = $this->booking( $input['booking_id'] ?? 0 );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$type = sanitize_key( (string) ( $input['acknowledgement_type'] ?? '' ) );
		if ( ! in_array( $type, array( 'counterparty_verified', 'venue_recorded' ), true ) || null === $booking['artist_term_id'] || null === $booking['artist_profile_id'] ) {
			return new \WP_Error( 'show_settlement_acknowledgement_invalid', __( 'Acknowledgement requires a supported type and canonical booking counterparty.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$authority = 'counterparty_verified' === $type ? 'artist' : 'venue';
		$allowed   = $this->authorize_action( $booking, $actor_id, $authority );
		if ( true !== $allowed ) {
			return $this->denied( $allowed );
		}
		$evidence = array();
		if ( 'venue_recorded' === $type ) {
			$evidence = $this->attachment_evidence( $booking, $input['acknowledgement_evidence_attachment_ids'] ?? null, $actor_id, true );
			if ( is_wp_error( $evidence ) ) {
				return $evidence;
			}
		} elseif ( ! empty( $input['acknowledgement_evidence_attachment_ids'] ) ) {
			return new \WP_Error( 'show_settlement_acknowledgement_invalid', __( 'Direct counterparty acknowledgement must not claim venue-recorded evidence.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return $this->action(
			$input,
			$actor_id,
			'acknowledged',
			array(
				'acknowledgement_type' => $type,
				'counterparty'         => array(
					'type'              => 'artist',
					'artist_term_id'    => $booking['artist_term_id'],
					'artist_profile_id' => $booking['artist_profile_id'],
				),
				'attested_by_user_id'  => 'counterparty_verified' === $type ? $actor_id : null,
				'evidence'             => $evidence,
				'note'                 => $this->optional_text( $input['note'] ?? null, 1000 ),
			),
			array( 'finalized' ),
			false,
			null,
			$authority,
			$evidence
		);
	}

	/** Record a bounded dispute against one frozen revision. */
	public function dispute( array $input, int $actor_id ) {
		$reason = $this->text( $input['reason'] ?? null, 'reason', 1000 );
		return is_wp_error( $reason ) ? $reason : $this->action( $input, $actor_id, 'disputed', array( 'reason' => $reason ), array( 'finalized', 'acknowledged' ), false );
	}

	/** Mark an undisputed revision paid after authenticating immutable payout evidence. */
	public function mark_paid( array $input, int $actor_id ) {
		$reference = $this->text( $input['payment_reference'] ?? null, 'payment_reference', 191 );
		$date      = $this->date( $input['payment_date'] ?? null );
		if ( is_wp_error( $reference ) || is_wp_error( $date ) ) {
			return is_wp_error( $reference ) ? $reference : $date;
		}
		$booking = $this->booking( $input['booking_id'] ?? 0 );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_MANAGE_FINANCES );
		if ( true !== $allowed ) {
			return $this->denied( $allowed );
		}
		$evidence = $this->attachment_evidence( $booking, $input['payout_evidence_attachment_ids'] ?? null, $actor_id, true );
		if ( is_wp_error( $evidence ) ) {
			return $evidence;
		}
		return $this->action(
			$input,
			$actor_id,
			'paid',
			array(
				'payment_reference' => $reference,
				'payment_date'      => $date,
				'payout_evidence'   => $evidence,
			),
			array( 'finalized', 'acknowledged' ),
			true,
			$evidence
		);
	}

	/** Void an unpaid revision with immutable reason audit. */
	public function void( array $input, int $actor_id ) {
		$reason = $this->text( $input['reason'] ?? null, 'reason', 1000 );
		return is_wp_error( $reason ) ? $reason : $this->action( $input, $actor_id, 'void', array( 'reason' => $reason ), array( 'draft', 'finalized', 'acknowledged', 'disputed' ), false );
	}

	/** Pure deterministic formula used by drafts and tests. */
	public static function calculate_amounts( array $terms, int $extra_chill_share_minor ) {
		$fields = array( 'ticket_gross_minor', 'door_gross_minor', 'fees_minor', 'taxes_minor', 'refunds_minor', 'venue_expenses_minor', 'production_expenses_minor', 'artist_guarantee_minor', 'artist_split_basis_points' );
		foreach ( $fields as $field ) {
			if ( ! isset( $terms[ $field ] ) || ! is_int( $terms[ $field ] ) ) {
				return new \WP_Error(
					'invalid_show_settlement_amount',
					__( 'Show settlement money and rates must use integers.', 'extrachill-events' ),
					array(
						'status' => 400,
						'field'  => $field,
					)
				);
			}
		}
		if ( $terms['artist_split_basis_points'] < 0 || $terms['artist_split_basis_points'] > 10000 || $terms['artist_guarantee_minor'] < 0 ) {
			return new \WP_Error( 'invalid_show_settlement_terms', __( 'The artist guarantee or split is outside the supported range.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$adjustment_total = 0;
		foreach ( $terms['adjustments'] ?? array() as $adjustment ) {
			if ( ! is_array( $adjustment ) || ! isset( $adjustment['amount_minor'] ) || ! is_int( $adjustment['amount_minor'] ) ) {
				return new \WP_Error( 'invalid_show_settlement_adjustment', __( 'Every signed adjustment must use integer minor units.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$adjustment_total = self::safe_add( $adjustment_total, $adjustment['amount_minor'] );
			if ( is_wp_error( $adjustment_total ) ) {
				return $adjustment_total;
			}
		}
		$gross = self::safe_add( $terms['ticket_gross_minor'], $terms['door_gross_minor'] );
		if ( is_wp_error( $gross ) ) {
			return $gross;
		}
		$deductions = 0;
		foreach ( array( 'fees_minor', 'taxes_minor', 'refunds_minor', 'venue_expenses_minor', 'production_expenses_minor' ) as $field ) {
			if ( $terms[ $field ] < 0 ) {
				return new \WP_Error(
					'invalid_show_settlement_terms',
					__( 'Show settlement deductions cannot be negative.', 'extrachill-events' ),
					array(
						'status' => 400,
						'field'  => $field,
					)
				);
			}
			$deductions = self::safe_add( $deductions, $terms[ $field ] );
			if ( is_wp_error( $deductions ) ) {
				return $deductions;
			}
		}
		$artist_basis = self::safe_subtract( $gross, $deductions );
		$artist_basis = is_wp_error( $artist_basis ) ? $artist_basis : self::safe_add( $artist_basis, $adjustment_total );
		$artist_basis = is_wp_error( $artist_basis ) ? $artist_basis : self::safe_subtract( $artist_basis, $extra_chill_share_minor );
		if ( is_wp_error( $artist_basis ) ) {
			return $artist_basis;
		}
		$artist_split = TicketSettlementService::basis_points_amount( max( 0, $artist_basis ), $terms['artist_split_basis_points'] );
		if ( is_wp_error( $artist_split ) ) {
			return $artist_split;
		}
		$artist_payout = max( $terms['artist_guarantee_minor'], $artist_split );
		$venue_net     = self::safe_subtract( $artist_basis, $artist_payout );
		if ( is_wp_error( $venue_net ) ) {
			return $venue_net;
		}
		return array(
			'total_gross_minor'            => $gross,
			'total_deductions_minor'       => $deductions,
			'adjustment_total_minor'       => $adjustment_total,
			'extra_chill_share_minor'      => $extra_chill_share_minor,
			'artist_split_basis_minor'     => $artist_basis,
			'artist_split_amount_minor'    => $artist_split,
			'artist_guarantee_minor'       => $terms['artist_guarantee_minor'],
			'artist_payout_minor'          => $artist_payout,
			'venue_net_after_payout_minor' => $venue_net,
		);
	}

	private function create_revision( array $input, int $actor_id, bool $correction, bool $requires_current = false ) {
		$booking = $this->booking( $input['booking_id'] ?? 0 );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_MANAGE_FINANCES );
		if ( true !== $allowed ) {
			return $this->denied( $allowed );
		}
		$key                     = TicketSettlementService::opaque_identifier( $input['idempotency_key'] ?? null, 'idempotency_key' );
		$expected                = $requires_current ? $this->positive_integer( $input['expected_revision_id'] ?? null, 'expected_revision_id' ) : null;
		$expected_action_version = $correction ? $this->positive_integer( $input['expected_version'] ?? null, 'expected_version' ) : null;
		foreach ( array( $key, $expected, $expected_action_version ) as $validated ) {
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
		}
		$terms = $this->terms( $input, $actor_id );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$evidence = $this->attachment_evidence( $booking, $input['door_report_attachment_ids'] ?? array(), $actor_id, 0 !== $terms['door_gross_minor'] );
		if ( is_wp_error( $evidence ) ) {
			return $evidence;
		}
		$started = $this->begin_authorized( $booking, $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking = $this->locked_booking( $booking['id'] );
		if ( is_wp_error( $booking ) ) {
			return $this->rollback( $booking );
		}
		$commission = $this->commission( $booking, $actor_id, (int) $input['commission_settlement_id'], null, $terms['currency'], true );
		if ( is_wp_error( $commission ) ) {
			return $this->rollback( $commission );
		}
		$ticket_gross = $this->ticket_gross( $booking, $commission, $actor_id, true );
		if ( is_wp_error( $ticket_gross ) || $ticket_gross !== $terms['ticket_gross_minor'] ) {
			return $this->rollback(
				is_wp_error( $ticket_gross ) ? $ticket_gross : new \WP_Error(
					'show_settlement_ticket_gross_conflict',
					__( 'Ticket gross does not match the frozen reconciled ticket evidence.', 'extrachill-events' ),
					array(
						'status'                      => 409,
						'expected_ticket_gross_minor' => $ticket_gross,
					)
				)
			);
		}
		$calculation = self::calculate_amounts( $terms, $commission['amount_due_minor'] );
		if ( is_wp_error( $calculation ) ) {
			return $this->rollback( $calculation );
		}
		$request_hash = self::hash(
			array(
				'booking_id'                => $booking['id'],
				'commission_settlement_id'  => $commission['id'],
				'commission_integrity_hash' => $commission['integrity_hash'],
				'terms'                     => $terms,
				'evidence'                  => $evidence,
				'expected_revision_id'      => $expected,
				'expected_version'          => $expected_action_version,
				'correction_reason'         => $input['_correction_reason'] ?? null,
			)
		);
		$retry        = $this->idempotent_revision( $booking['id'], $key, true );
		if ( is_wp_error( $retry ) ) {
			return $this->rollback( $retry );
		}
		if ( is_array( $retry ) ) {
			if ( ! hash_equals( $retry['request_hash'], $request_hash ) ) {
				return $this->rollback( new \WP_Error( 'show_settlement_idempotency_conflict', __( 'This show-settlement idempotency key is bound to different inputs.', 'extrachill-events' ), array( 'status' => 409 ) ) );
			}
			$verified = $this->verify_revision( $retry, $booking, $actor_id, false );
			$commit   = is_wp_error( $verified ) ? $this->rollback( $verified ) : $this->commit();
			return is_wp_error( $commit ) ? $commit : $this->present( $verified );
		}
		$current = $this->latest_revision( $booking['id'], true );
		if ( is_wp_error( $current ) ) {
			return $this->rollback( $current );
		}
		if ( ! $requires_current && is_array( $current ) ) {
			return $this->rollback( new \WP_Error( 'show_settlement_already_exists', __( 'Use a revision operation after the first show-settlement draft.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		if ( $requires_current && ( ! is_array( $current ) || $current['id'] !== $expected ) ) {
			return $this->rollback( new \WP_Error( 'show_settlement_revision_conflict', __( 'The current show-settlement revision changed.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		if ( is_array( $current ) ) {
			$state = $this->state( $current, true );
			if ( is_wp_error( $state ) || ( $correction && $state['version'] !== $expected_action_version ) || ( $correction ? ! in_array( $state['status'], array( 'finalized', 'acknowledged', 'disputed' ), true ) : 'draft' !== $state['status'] ) ) {
				return $this->rollback( is_wp_error( $state ) ? $state : new \WP_Error( 'show_settlement_status_conflict', __( 'The current revision cannot be replaced by this operation.', 'extrachill-events' ), array( 'status' => 409 ) ) );
			}
		}
		$metadata = $this->verify_attachment_snapshot( $booking, $evidence );
		if ( is_wp_error( $metadata ) ) {
			return $this->rollback( $metadata );
		}
		global $wpdb;
		$now                   = gmdate( 'Y-m-d H:i:s' );
		$row                   = array(
			'public_id'                 => wp_generate_uuid4(),
			'booking_id'                => $booking['id'],
			'event_id'                  => $booking['event_id'],
			'venue_term_id'             => $booking['venue_term_id'],
			'revision'                  => is_array( $current ) ? $current['revision'] + 1 : 1,
			'corrects_revision_id'      => $correction ? $current['id'] : null,
			'commission_settlement_id'  => $commission['id'],
			'commission_integrity_hash' => $commission['integrity_hash'],
			'currency'                  => $terms['currency'],
			'formula_version'           => self::FORMULA_VERSION,
			'terms_payload'             => wp_json_encode(
				array(
					'version' => 1,
					'data'    => $terms,
				)
			),
			'evidence_payload'          => wp_json_encode(
				array(
					'version' => 1,
					'data'    => $evidence,
				)
			),
			'calculation_payload'       => wp_json_encode(
				array(
					'version' => 1,
					'data'    => $calculation,
				)
			),
			'request_hash'              => $request_hash,
			'idempotency_key'           => $key,
			'created_by_user_id'        => $actor_id,
			'created_at'                => $now,
		);
		$row['integrity_hash'] = self::hash( self::revision_hashable( $row ) );
		if ( false === $wpdb->insert( BookingSchema::show_settlements_table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only financial revision.
			return $this->rollback( new \WP_Error( 'show_settlement_write_failed', __( 'The show-settlement revision could not be recorded.', 'extrachill-events' ) ) );
		}
		$created = $this->revision_id( (int) $wpdb->insert_id, true );
		if ( ! is_array( $created ) ) {
			return $this->rollback( is_wp_error( $created ) ? $created : new \WP_Error( 'show_settlement_write_failed', __( 'The show-settlement revision could not be verified.', 'extrachill-events' ) ) );
		}
		if ( $correction ) {
			$action = $this->append_action_row(
				$current,
				'corrected',
				$state['version'],
				array(
					'replacement_revision_id' => $created['id'],
					'reason'                  => $input['_correction_reason'],
				),
				'correction:' . $key,
				$actor_id
			);
			if ( is_wp_error( $action ) ) {
				return $this->rollback( $action );
			}
		}
		$audit = $this->append_audit( $created, $correction ? 'show_settlement_corrected' : ( $requires_current ? 'show_settlement_revised' : 'show_settlement_drafted' ), $actor_id );
		if ( is_wp_error( $audit ) ) {
			return $this->rollback( $audit );
		}
		$commit = $this->commit();
		return is_wp_error( $commit ) ? $commit : $this->present( $this->state( $created ) );
	}

	private function action( array $input, int $actor_id, string $action, array $payload, array $allowed_states, bool $authenticate_revision, ?array $authenticated_payout = null, string $authority = 'venue', ?array $authenticated_action_evidence = null ) {
		$booking = $this->booking( $input['booking_id'] ?? 0 );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$allowed = $this->authorize_action( $booking, $actor_id, $authority );
		if ( true !== $allowed ) {
			return $this->denied( $allowed );
		}
		$revision_id      = $this->positive_integer( $input['revision_id'] ?? null, 'revision_id' );
		$expected_version = $this->positive_integer( $input['expected_version'] ?? null, 'expected_version' );
		$key              = TicketSettlementService::opaque_identifier( $input['idempotency_key'] ?? null, 'idempotency_key' );
		foreach ( array( $revision_id, $expected_version, $key ) as $value ) {
			if ( is_wp_error( $value ) ) {
				return $value;
			}
		}
		$request_hash = self::hash(
			array(
				'revision_id'      => $revision_id,
				'expected_version' => $expected_version,
				'action'           => $action,
				'payload'          => $payload,
			)
		);
		$revision     = $this->revision_id( $revision_id );
		if ( ! is_array( $revision ) || $revision['booking_id'] !== $booking['id'] ) {
			return new \WP_Error( 'show_settlement_not_found', __( 'The show settlement was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		if ( $authenticate_revision ) {
			$verified = $this->verify_revision( $revision, $booking, $actor_id, true );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
		}
		$started = $this->begin_authorized( $booking, $actor_id, $authority );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking = $this->locked_booking( $booking['id'] );
		if ( is_wp_error( $booking ) ) {
			return $this->rollback( $booking );
		}
		if ( 'artist' === $authority ) {
			$locked_allowed = $this->authorize_action( $booking, $actor_id, $authority );
			if ( true !== $locked_allowed ) {
				return $this->rollback( $this->denied( $locked_allowed ) );
			}
		}
		$commission = $this->commission( $booking, $actor_id, $revision['commission_settlement_id'], $revision['commission_integrity_hash'], $revision['currency'], true );
		if ( is_wp_error( $commission ) ) {
			return $this->rollback( $commission );
		}
		$retry = $this->idempotent_action( $booking['id'], $key, true );
		if ( is_wp_error( $retry ) ) {
			return $this->rollback( $retry );
		}
		if ( is_array( $retry ) ) {
			if ( ! hash_equals( $retry['request_hash'], $request_hash ) ) {
				return $this->rollback( new \WP_Error( 'show_settlement_idempotency_conflict', __( 'This lifecycle idempotency key is bound to a different request.', 'extrachill-events' ), array( 'status' => 409 ) ) );
			}
			$current = $this->revision_id( $revision_id, true );
			if ( ! is_array( $current ) || $current['booking_id'] !== $booking['id'] ) {
				return $this->rollback( is_wp_error( $current ) ? $current : new \WP_Error( 'show_settlement_not_found', __( 'The show settlement was not found.', 'extrachill-events' ), array( 'status' => 404 ) ) );
			}
			$verified = $this->verify_revision( $current, $booking, $actor_id, false, true );
			$commit   = is_wp_error( $verified ) ? $this->rollback( $verified ) : $this->commit();
			return is_wp_error( $commit ) ? $commit : $this->present( $verified );
		}
		$revision = $this->revision_id( $revision_id, true );
		$latest   = $this->latest_revision( $booking['id'], true );
		if ( ! is_array( $latest ) || ! is_array( $revision ) || $latest['id'] !== $revision['id'] ) {
			return $this->rollback( new \WP_Error( 'show_settlement_revision_conflict', __( 'Only the current show-settlement revision may transition.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		$state = is_array( $revision ) ? $this->state( $revision, true ) : $revision;
		if ( ! is_array( $state ) || $state['version'] !== $expected_version || ! in_array( $state['status'], $allowed_states, true ) ) {
			return $this->rollback(
				is_wp_error( $state ) ? $state : new \WP_Error(
					'show_settlement_version_conflict',
					__( 'The show-settlement lifecycle changed before this transition.', 'extrachill-events' ),
					array(
						'status'          => 409,
						'current_version' => is_array( $state ) ? $state['version'] : null,
					)
				)
			);
		}
		if ( 'paid' === $action && 'completed' !== $booking['status'] ) {
			return $this->rollback( new \WP_Error( 'show_settlement_payment_booking_status_conflict', __( 'The booking must be completed before artist payout is recorded.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		$verified = $this->verify_revision( $revision, $booking, $actor_id, false, true );
		if ( is_wp_error( $verified ) ) {
			return $this->rollback( $verified );
		}
		if ( null !== $authenticated_payout ) {
			$metadata = $this->verify_attachment_snapshot( $booking, $authenticated_payout );
			if ( is_wp_error( $metadata ) ) {
				return $this->rollback( $metadata );
			}
		}
		if ( null !== $authenticated_action_evidence ) {
			$metadata = $this->verify_attachment_snapshot( $booking, $authenticated_action_evidence );
			if ( is_wp_error( $metadata ) ) {
				return $this->rollback( $metadata );
			}
		}
		$written = $this->append_action_row( $revision, $action, $expected_version, $payload, $key, $actor_id, $request_hash );
		if ( is_wp_error( $written ) ) {
			return $this->rollback( $written );
		}
		$audit = $this->append_audit( $revision, 'show_settlement_' . $action, $actor_id );
		if ( is_wp_error( $audit ) ) {
			return $this->rollback( $audit );
		}
		$state  = $this->state( $revision, true );
		$commit = $this->commit();
		return is_wp_error( $commit ) ? $commit : $this->present( $state );
	}

	private function terms( array $input, int $actor_id ) {
		$currency = strtoupper( sanitize_text_field( (string) ( $input['currency'] ?? '' ) ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return new \WP_Error( 'invalid_show_settlement_currency', __( 'Currency must be an uppercase three-letter code.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$terms = array( 'currency' => $currency );
		foreach ( array( 'ticket_gross_minor', 'door_gross_minor', 'fees_minor', 'taxes_minor', 'refunds_minor', 'venue_expenses_minor', 'production_expenses_minor', 'artist_guarantee_minor', 'artist_split_basis_points' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) || ! is_int( $input[ $field ] ) ) {
				return new \WP_Error(
					'invalid_show_settlement_amount',
					__( 'Show settlement money and rates must use integers.', 'extrachill-events' ),
					array(
						'status' => 400,
						'field'  => $field,
					)
				);
			}
			$terms[ $field ] = $input[ $field ];
		}
		$adjustments = $input['adjustments'] ?? array();
		if ( ! is_array( $adjustments ) || 100 < count( $adjustments ) ) {
			return new \WP_Error( 'invalid_show_settlement_adjustment', __( 'Signed adjustments must be a bounded array.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$terms['adjustments'] = array();
		foreach ( $adjustments as $adjustment ) {
			$reason = $this->text( is_array( $adjustment ) ? ( $adjustment['reason'] ?? null ) : null, 'adjustment_reason', 500 );
			if ( is_wp_error( $reason ) || ! is_array( $adjustment ) || ! is_int( $adjustment['amount_minor'] ?? null ) ) {
				return is_wp_error( $reason ) ? $reason : new \WP_Error( 'invalid_show_settlement_adjustment', __( 'Every adjustment requires signed integer minor units and a reason.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$terms['adjustments'][] = array(
				'amount_minor'      => $adjustment['amount_minor'],
				'reason'            => $reason,
				'signed_by_user_id' => $actor_id,
				'signature_hash'    => self::hash( array( $adjustment['amount_minor'], $reason, $actor_id ) ),
			);
		}
		return $terms;
	}

	private function attachment_evidence( array $booking, $ids, int $actor_id, bool $required ) {
		if ( ! is_array( $ids ) || 20 < count( $ids ) || ( $required && empty( $ids ) ) ) {
			return new \WP_Error( 'show_settlement_evidence_required', __( 'Authorized immutable evidence is required for this settlement operation.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$normalized = array();
		foreach ( $ids as $id ) {
			$id = $this->positive_integer( $id, 'attachment_id' );
			if ( is_wp_error( $id ) || in_array( $id, $normalized, true ) ) {
				return is_wp_error( $id ) ? $id : new \WP_Error( 'show_settlement_evidence_invalid', __( 'Evidence attachment IDs must be unique.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$normalized[] = $id;
		}
		sort( $normalized, SORT_NUMERIC );
		$evidence = array();
		foreach ( $normalized as $id ) {
			$attachment = $this->attachments->get_for_booking( $booking['id'], $id );
			if ( is_wp_error( $attachment ) || 'active' !== ( $attachment['state'] ?? '' ) || 'other_private_evidence' !== ( $attachment['purpose'] ?? '' ) ) {
				return new \WP_Error( 'show_settlement_evidence_invalid', __( 'Settlement evidence is unavailable, inactive, or not approved private evidence.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$verified = $this->authenticate_attachment( $booking, $attachment, $actor_id );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			$evidence[] = array(
				'id'           => $attachment['id'],
				'public_id'    => $attachment['public_id'],
				'request_hash' => $attachment['request_hash'],
				'content_hash' => $attachment['content_hash'],
				'byte_size'    => $attachment['byte_size'],
				'mime_type'    => $attachment['mime_type'],
				'purpose'      => $attachment['purpose'],
			);
		}
		return $evidence;
	}

	private function authenticate_attachment( array $booking, array $attachment, int $actor_id ) {
		$descriptor = $this->attachment_service->download_descriptor( $booking['id'], $attachment['id'], $actor_id );
		if ( is_wp_error( $descriptor ) ) {
			return new \WP_Error( 'show_settlement_evidence_invalid', __( 'Settlement evidence bytes are unavailable.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$stream = $this->attachment_service->open_download_stream( $booking['id'], $attachment['id'], $descriptor['stream_token'], $actor_id, $descriptor['correlation_id'] );
		if ( is_wp_error( $stream ) || ! is_resource( $stream ) ) {
			return new \WP_Error( 'show_settlement_evidence_invalid', __( 'Settlement evidence bytes are unavailable.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$hash  = hash_init( 'sha256' );
		$bytes = 0;
		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads only an authorized private stream.
			if ( false === $chunk ) {
				$bytes = -1;
				break;
			}
			$bytes += strlen( $chunk );
			hash_update( $hash, $chunk );
			if ( BookingAttachmentPolicy::MAX_BYTES < $bytes || $attachment['byte_size'] < $bytes ) {
				$bytes = -1;
				break;
			}
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the approved private stream.
		$valid  = $bytes === $attachment['byte_size'] && hash_equals( $attachment['content_hash'], hash_final( $hash ) );
		$logged = $this->attachment_service->record_delivery_outcome( $booking['id'], $attachment['id'], $descriptor['correlation_id'], $valid ? 'completed' : 'failed', $valid ? $bytes : 0, $actor_id );
		return $valid && ! is_wp_error( $logged ) ? true : new \WP_Error( 'show_settlement_evidence_invalid', __( 'Settlement evidence bytes failed authentication.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	private function verify_attachment_snapshot( array $booking, array $snapshot ) {
		foreach ( $snapshot as $expected ) {
			$attachment = $this->attachments->get_for_booking( $booking['id'], (int) $expected['id'] );
			if ( is_wp_error( $attachment ) || 'active' !== ( $attachment['state'] ?? '' ) ) {
				return new \WP_Error( 'show_settlement_evidence_invalid', __( 'Frozen settlement evidence is missing or inactive.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			foreach ( array( 'public_id', 'request_hash', 'content_hash', 'byte_size', 'mime_type', 'purpose' ) as $field ) {
				if ( $attachment[ $field ] !== $expected[ $field ] ) {
					return new \WP_Error( 'show_settlement_evidence_invalid', __( 'Frozen settlement evidence metadata was changed.', 'extrachill-events' ), array( 'status' => 409 ) );
				}
			}
		}
		return true;
	}

	private function verify_revision( array $revision, array $booking, int $actor_id, bool $authenticate_bytes, bool $lock_commission = false, int $max_sales_reports = 10000 ) {
		if ( $revision['booking_id'] !== $booking['id'] || $revision['event_id'] !== $booking['event_id'] || $revision['venue_term_id'] !== $booking['venue_term_id'] || ! hash_equals( $revision['integrity_hash'], self::hash( self::revision_hashable( $revision ) ) ) ) {
			return new \WP_Error( 'show_settlement_integrity_failed', __( 'The immutable show-settlement revision failed integrity verification.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$commission = $this->commission( $booking, $actor_id, $revision['commission_settlement_id'], $revision['commission_integrity_hash'], $revision['currency'], $lock_commission );
		if ( is_wp_error( $commission ) ) {
			return $commission;
		}
		$calculation = self::calculate_amounts( $revision['terms'], $commission['amount_due_minor'] );
		if ( is_wp_error( $calculation ) || $calculation !== $revision['calculation'] ) {
			return new \WP_Error( 'show_settlement_calculation_invalid', __( 'The frozen show-settlement calculation is not reproducible.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$ticket_gross = $this->ticket_gross( $booking, $commission, $actor_id, $lock_commission, $max_sales_reports );
		if ( is_wp_error( $ticket_gross ) || $ticket_gross !== $revision['terms']['ticket_gross_minor'] ) {
			return is_wp_error( $ticket_gross ) ? $ticket_gross : new \WP_Error( 'show_settlement_ticket_gross_conflict', __( 'Frozen ticket gross no longer matches its reconciled ticket evidence.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$metadata = $this->verify_attachment_snapshot( $booking, $revision['evidence'] );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		if ( $authenticate_bytes ) {
			foreach ( $revision['evidence'] as $item ) {
				$attachment = $this->attachments->get_for_booking( $booking['id'], $item['id'] );
				$verified   = is_array( $attachment ) ? $this->authenticate_attachment( $booking, $attachment, $actor_id ) : $attachment;
				if ( true !== $verified ) {
					return is_wp_error( $verified ) ? $verified : new \WP_Error( 'show_settlement_evidence_invalid', __( 'Frozen settlement evidence bytes failed authentication.', 'extrachill-events' ), array( 'status' => 409 ) );
				}
			}
		}
		$state = $this->state( $revision );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		foreach ( $state['actions'] as $action ) {
			if ( isset( $action['payload']['payout_evidence'] ) ) {
				$payout = $this->verify_attachment_snapshot( $booking, $action['payload']['payout_evidence'] );
				if ( is_wp_error( $payout ) ) {
					return $payout;
				}
			}
			if ( isset( $action['payload']['evidence'] ) ) {
				$acknowledgement = $this->verify_attachment_snapshot( $booking, $action['payload']['evidence'] );
				if ( is_wp_error( $acknowledgement ) ) {
					return $acknowledgement;
				}
			}
		}
		return $state;
	}

	private function ticket_gross( array $booking, array $commission, int $actor_id, bool $lock = false, int $max_sales_reports = 10000 ) {
		$by_id     = array();
		$found     = 0;
		$needed    = count( $commission['included_report_ids'] );
		$exhausted = false;
		for ( $offset = 0; $offset < $max_sales_reports && $found < $needed; $offset += 200 ) {
			$page_limit = min( 200, $max_sales_reports - $offset );
			$reports    = $lock ? $this->commissions->included_reports_for_update( $commission ) : $this->commissions->list_sales( $booking['id'], $actor_id, $page_limit, $offset );
			if ( is_wp_error( $reports ) ) {
				return $reports;
			}
			foreach ( $reports as $report ) {
				if ( in_array( $report['id'], $commission['included_report_ids'], true ) ) {
					$by_id[ $report['id'] ] = $report;
					$found                  = count( $by_id );
				}
			}
			if ( count( $reports ) < $page_limit ) {
				$exhausted = true;
				break;
			}
			if ( $lock ) {
				break;
			}
		}
		if ( $found < $needed && ! $lock && ! $exhausted ) {
			return new \WP_Error( 'show_settlement_reporting_limit_exceeded', __( 'The show settlement exceeds the bounded reporting evidence limit.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$gross = 0;
		foreach ( $commission['included_report_ids'] as $report_id ) {
			if ( ! isset( $by_id[ $report_id ] ) || $by_id[ $report_id ]['currency'] !== $commission['currency'] ) {
				return new \WP_Error( 'show_settlement_ticket_evidence_invalid', __( 'Frozen ticket evidence is missing from the complete show settlement.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$gross = self::safe_add( $gross, $by_id[ $report_id ]['gross_minor'] );
			if ( is_wp_error( $gross ) ) {
				return $gross;
			}
		}
		return $gross;
	}

	private function state( array $revision, bool $lock = false ) {
		global $wpdb;
		$table = BookingSchema::show_settlement_actions_table();
		$query = "SELECT * FROM {$table} WHERE show_settlement_id = %d ORDER BY expected_version ASC, id ASC";
		if ( $lock ) {
			$query .= ' FOR UPDATE';
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $revision['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete immutable lifecycle.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'show_settlement_action_read_failed', __( 'The show-settlement lifecycle could not be read.', 'extrachill-events' ) );
		}
		$status  = 'draft';
		$version = 1;
		$actions = array();
		foreach ( (array) $rows as $row ) {
			$row = $this->hydrate_action( $row );
			if ( is_wp_error( $row ) || $row['expected_version'] !== $version || $row['show_settlement_id'] !== $revision['id'] ) {
				return is_wp_error( $row ) ? $row : new \WP_Error( 'show_settlement_action_integrity_failed', __( 'The show-settlement lifecycle chain is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$status = $row['action'];
			++$version;
			$actions[] = $row;
		}
		$revision['status']  = $status;
		$revision['version'] = $version;
		$revision['actions'] = $actions;
		return $revision;
	}

	private function append_action_row( array $revision, string $action, int $expected_version, array $payload, string $key, int $actor_id, ?string $request_hash = null ) {
		global $wpdb;
		$row                   = array(
			'public_id'          => wp_generate_uuid4(),
			'booking_id'         => $revision['booking_id'],
			'venue_term_id'      => $revision['venue_term_id'],
			'show_settlement_id' => $revision['id'],
			'action'             => $action,
			'expected_version'   => $expected_version,
			'payload'            => wp_json_encode(
				array(
					'version' => 1,
					'data'    => $payload,
				)
			),
			'request_hash'       => $request_hash ? $request_hash : self::hash(
				array(
					'revision_id'      => $revision['id'],
					'expected_version' => $expected_version,
					'action'           => $action,
					'payload'          => $payload,
				)
			),
			'idempotency_key'    => $key,
			'actor_user_id'      => $actor_id,
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
		);
		$row['integrity_hash'] = self::hash( self::action_hashable( $row ) );
		return false === $wpdb->insert( BookingSchema::show_settlement_actions_table(), $row ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only lifecycle transition.
			? new \WP_Error( 'show_settlement_action_write_failed', __( 'The show-settlement lifecycle transition could not be recorded.', 'extrachill-events' ) )
			: $row;
	}

	private function hydrate_revision( array $row ) {
		foreach ( array( 'id', 'booking_id', 'event_id', 'venue_term_id', 'revision', 'commission_settlement_id', 'formula_version', 'created_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['corrects_revision_id'] = null === $row['corrects_revision_id'] ? null : (int) $row['corrects_revision_id'];
		foreach ( array(
			'terms_payload'       => 'terms',
			'evidence_payload'    => 'evidence',
			'calculation_payload' => 'calculation',
		) as $field => $target ) {
			$decoded = json_decode( (string) $row[ $field ], true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || 1 !== ( $decoded['version'] ?? null ) || ! is_array( $decoded['data'] ?? null ) ) {
				return new \WP_Error( 'show_settlement_integrity_failed', __( 'Stored show-settlement data is malformed.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$row[ $target ] = $decoded['data'];
			unset( $row[ $field ] );
		}
		return $row;
	}

	private function hydrate_action( array $row ) {
		foreach ( array( 'id', 'booking_id', 'venue_term_id', 'show_settlement_id', 'expected_version', 'actor_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$payload = json_decode( (string) $row['payload'], true );
		if ( JSON_ERROR_NONE !== json_last_error() || 1 !== ( $payload['version'] ?? null ) || ! is_array( $payload['data'] ?? null ) || ! in_array( $row['action'], self::ACTIONS, true ) ) {
			return new \WP_Error( 'show_settlement_action_integrity_failed', __( 'Stored show-settlement lifecycle data is malformed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$row['payload'] = $payload['data'];
		$hash           = self::hash(
			array(
				'revision_id'      => $row['show_settlement_id'],
				'expected_version' => $row['expected_version'],
				'action'           => $row['action'],
				'payload'          => $row['payload'],
			)
		);
		return hash_equals( $row['request_hash'], $hash ) && hash_equals( $row['integrity_hash'], self::hash( self::action_hashable( $row ) ) ) ? $row : new \WP_Error( 'show_settlement_action_integrity_failed', __( 'Stored show-settlement lifecycle data failed integrity verification.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	private function latest_revision( int $booking_id, bool $lock = false ) {
		global $wpdb;
		$table = BookingSchema::show_settlements_table();
		$query = "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY revision DESC LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' );
		$row   = $wpdb->get_row( $wpdb->prepare( $query, $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact latest revision with optional lock.
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'show_settlement_read_failed', __( 'The show settlement could not be read.', 'extrachill-events' ) ) : ( is_array( $row ) ? $this->hydrate_revision( $row ) : null );
	}

	private function revision_id( int $id, bool $lock = false ) {
		global $wpdb;
		$table = BookingSchema::show_settlements_table();
		$query = "SELECT * FROM {$table} WHERE id = %d LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' );
		$row   = $wpdb->get_row( $wpdb->prepare( $query, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact immutable revision.
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'show_settlement_read_failed', __( 'The show settlement could not be read.', 'extrachill-events' ) ) : ( is_array( $row ) ? $this->hydrate_revision( $row ) : null );
	}

	private function revision_number( int $booking_id, int $revision ) {
		global $wpdb;
		$table = BookingSchema::show_settlements_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d AND revision = %d LIMIT 1", $booking_id, $revision ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact immutable revision.
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'show_settlement_read_failed', __( 'The show settlement could not be read.', 'extrachill-events' ) ) : ( is_array( $row ) ? $this->hydrate_revision( $row ) : null );
	}

	private function idempotent_revision( int $booking_id, string $key, bool $lock ) {
		global $wpdb;
		$table = BookingSchema::show_settlements_table();
		$query = "SELECT * FROM {$table} WHERE booking_id = %d AND idempotency_key = %s LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' );
		$row   = $wpdb->get_row( $wpdb->prepare( $query, $booking_id, $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotency lookup.
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'show_settlement_read_failed', __( 'The show settlement could not be read.', 'extrachill-events' ) ) : ( is_array( $row ) ? $this->hydrate_revision( $row ) : null );
	}

	private function idempotent_action( int $booking_id, string $key, bool $lock ) {
		global $wpdb;
		$table = BookingSchema::show_settlement_actions_table();
		$query = "SELECT * FROM {$table} WHERE booking_id = %d AND idempotency_key = %s LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' );
		$row   = $wpdb->get_row( $wpdb->prepare( $query, $booking_id, $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotency lookup.
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'show_settlement_action_read_failed', __( 'The show-settlement lifecycle could not be read.', 'extrachill-events' ) ) : ( is_array( $row ) ? $this->hydrate_action( $row ) : null );
	}

	private function begin_authorized( array $booking, int $actor_id, string $authority = 'venue' ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete financial transaction.
			return new \WP_Error( 'show_settlement_transaction_start_failed', __( 'The show-settlement transaction could not start.', 'extrachill-events' ) );
		}
		$this->transaction_active = true;
		if ( 'artist' === $authority ) {
			$allowed = $this->authorize_action( $booking, $actor_id, $authority );
			return true === $allowed ? true : $this->rollback( $this->denied( $allowed ) );
		}
		$table   = BookingSchema::memberships_table();
		$members = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $booking['venue_term_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks exact venue authority first.
		$allowed = '' === (string) $wpdb->last_error ? $this->authorization->authorize_locked( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_MANAGE_FINANCES, (array) $members ) : false;
		return true === $allowed ? true : $this->rollback( $this->denied( $allowed ) );
	}

	private function authorize_action( array $booking, int $actor_id, string $authority ) {
		if ( 'artist' === $authority ) {
			return null !== $booking['artist_term_id'] ? $this->artist_authorization->authorize_artist( $booking['artist_term_id'], $actor_id ) : false;
		}
		return $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_MANAGE_FINANCES );
	}

	private function commission( array $booking, int $actor_id, int $expected_id, ?string $expected_hash, string $currency, bool $lock ) {
		$commission = $lock ? $this->commissions->get_for_update( $booking['id'] ) : $this->commissions->get( $booking['id'], $actor_id );
		if ( is_wp_error( $commission ) || ! is_array( $commission ) || $commission['id'] !== $expected_id || ! in_array( $commission['status'], array( 'finalized', 'paid' ), true ) || $commission['currency'] !== $currency || ( null !== $expected_hash && ! hash_equals( $commission['integrity_hash'], $expected_hash ) ) ) {
			return new \WP_Error( 'show_settlement_commission_invalid', __( 'The frozen Extra Chill commission is missing, void, mismatched, or failed integrity verification.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return $commission;
	}

	private function booking( $id ) {
		$id = $this->positive_integer( $id, 'booking_id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$booking = $this->bookings->get( $id );
		return is_array( $booking ) && null !== $booking['event_id'] ? $booking : ( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'An event-linked booking was not found.', 'extrachill-events' ), array( 'status' => 404 ) ) );
	}

	private function locked_booking( int $booking_id ) {
		$booking = $this->bookings->get_for_update( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return new \WP_Error( 'show_settlement_booking_lock_failed', __( 'The booking could not be locked for this settlement operation.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return is_array( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	private function append_audit( array $revision, string $kind, int $actor_id ) {
		return $this->activity->append(
			array(
				'booking_id'  => $revision['booking_id'],
				'kind'        => $kind,
				'actor_type'  => 'user',
				'actor_id'    => $actor_id,
				'external_id' => (string) $revision['id'],
				'payload'     => array(
					'show_settlement_id' => $revision['id'],
					'revision'           => $revision['revision'],
					'currency'           => $revision['currency'],
				),
			)
		);
	}

	private function present( array $revision ): array {
		unset( $revision['idempotency_key'], $revision['request_hash'] );
		if ( isset( $revision['actions'] ) ) {
			foreach ( $revision['actions'] as &$action ) {
				unset( $action['idempotency_key'], $action['request_hash'], $action['integrity_hash'] );
				if ( isset( $action['payload']['payment_reference'] ) ) {
					unset( $action['payload']['payment_reference'] );
					$action['payload']['payment_reference_recorded'] = true;
				}
				if ( isset( $action['payload']['payout_evidence'] ) ) {
					$action['payload']['payout_evidence'] = $this->project_evidence( $action['payload']['payout_evidence'] );
				}
				if ( isset( $action['payload']['evidence'] ) ) {
					$action['payload']['evidence'] = $this->project_evidence( $action['payload']['evidence'] );
				}
			}
			unset( $action );
		}
		$revision['evidence'] = $this->project_evidence( $revision['evidence'] );
		return $revision;
	}

	private function project_evidence( array $evidence ): array {
		return array_map(
			static function ( array $item ): array {
				return array(
					'id'        => $item['id'],
					'public_id' => $item['public_id'],
					'mime_type' => $item['mime_type'],
					'byte_size' => $item['byte_size'],
				);
			},
			$evidence
		);
	}

	private static function revision_hashable( array $row ): array {
		return array(
			'public_id'                 => $row['public_id'],
			'booking_id'                => (int) $row['booking_id'],
			'event_id'                  => (int) $row['event_id'],
			'venue_term_id'             => (int) $row['venue_term_id'],
			'revision'                  => (int) $row['revision'],
			'corrects_revision_id'      => null === $row['corrects_revision_id'] ? null : (int) $row['corrects_revision_id'],
			'commission_settlement_id'  => (int) $row['commission_settlement_id'],
			'commission_integrity_hash' => $row['commission_integrity_hash'],
			'currency'                  => $row['currency'],
			'formula_version'           => (int) $row['formula_version'],
			'terms'                     => $row['terms'] ?? json_decode( $row['terms_payload'], true )['data'],
			'evidence'                  => $row['evidence'] ?? json_decode( $row['evidence_payload'], true )['data'],
			'calculation'               => $row['calculation'] ?? json_decode( $row['calculation_payload'], true )['data'],
			'request_hash'              => $row['request_hash'],
			'idempotency_key'           => $row['idempotency_key'],
			'created_by_user_id'        => (int) $row['created_by_user_id'],
			'created_at'                => $row['created_at'],
		);
	}

	private static function action_hashable( array $row ): array {
		$payload = $row['payload'];
		if ( is_string( $payload ) ) {
			$payload = json_decode( $payload, true )['data'];
		}
		return array(
			'public_id'          => $row['public_id'],
			'booking_id'         => (int) $row['booking_id'],
			'venue_term_id'      => (int) $row['venue_term_id'],
			'show_settlement_id' => (int) $row['show_settlement_id'],
			'action'             => $row['action'],
			'expected_version'   => (int) $row['expected_version'],
			'payload'            => $payload,
			'request_hash'       => $row['request_hash'],
			'idempotency_key'    => $row['idempotency_key'],
			'actor_user_id'      => (int) $row['actor_user_id'],
			'created_at'         => $row['created_at'],
		);
	}

	private static function safe_add( int $left, int $right ) {
		if ( ( $right > 0 && $left > PHP_INT_MAX - $right ) || ( $right < 0 && $left < PHP_INT_MIN - $right ) ) {
			return new \WP_Error( 'show_settlement_amount_out_of_range', __( 'The show-settlement calculation exceeds the supported integer range.', 'extrachill-events' ) );
		}
		return $left + $right;
	}

	private static function safe_subtract( int $left, int $right ) {
		if ( ( $right > 0 && $left < PHP_INT_MIN + $right ) || ( $right < 0 && $left > PHP_INT_MAX + $right ) ) {
			return new \WP_Error( 'show_settlement_amount_out_of_range', __( 'The show-settlement calculation exceeds the supported integer range.', 'extrachill-events' ) );
		}
		return $left - $right;
	}

	private static function hash( $value ): string {
		return hash( 'sha256', wp_json_encode( self::canonicalize( $value ) ) );
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}

	private function positive_integer( $value, string $field ) {
		return is_int( $value ) && 0 < $value ? $value : new \WP_Error(
			'invalid_show_settlement_integer',
			__( 'A positive integer is required.', 'extrachill-events' ),
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	private function text( $value, string $field, int $max ) {
		$value = sanitize_text_field( (string) $value );
		return '' !== $value && mb_strlen( $value ) <= $max ? $value : new \WP_Error(
			'invalid_show_settlement_text',
			__( 'A bounded text value is required.', 'extrachill-events' ),
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	private function optional_text( $value, int $max ): ?string {
		$value = sanitize_text_field( (string) $value );
		return '' === $value ? null : mb_substr( $value, 0, $max );
	}

	private function date( $value ) {
		$date = is_string( $value ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) ) : false;
		return false !== $date && $date->format( 'Y-m-d' ) === $value ? $value : new \WP_Error( 'invalid_show_settlement_payment_date', __( 'Payment date must use Y-m-d format.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	private function denied( $result ): \WP_Error {
		return is_wp_error( $result ) ? $result : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to manage this venue settlement.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	private function commit() {
		global $wpdb;
		$result                   = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete financial transaction.
		$this->transaction_active = false;
		return false === $result ? new \WP_Error( 'show_settlement_commit_uncertain', __( 'The show-settlement transaction outcome could not be confirmed.', 'extrachill-events' ) ) : true;
	}

	private function rollback( \WP_Error $error ) {
		global $wpdb;
		if ( $this->transaction_active ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete financial rollback.
			$this->transaction_active = false;
		}
		return $error;
	}
}
