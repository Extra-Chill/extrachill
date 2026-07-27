<?php
/**
 * Immutable ticket-sales evidence and booking commission settlements.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

// Repository convention uses concise method comments.
// phpcs:disable Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns validation, authorization, locking, and audit for ticket settlements. */
class TicketSettlementService {
	public const FORMULA_VERSION = 2;
	public const BASES           = array( 'gross_ticket_sales', 'net_ticket_sales' );
	public const SOURCE_TYPES    = array( 'manual', 'csv_certified' );
	public const STATUSES        = array( 'finalized', 'paid', 'void' );

	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var VenueBookingConfig */
	private $config;
	/** @var TicketReconciliationService */
	private $reconciliation;
	/** @var bool */
	private $transaction_active = false;

	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueAuthorization $authorization = null, ?VenueBookingConfig $config = null, ?TicketReconciliationService $reconciliation = null ) {
		$this->bookings       = $bookings ? $bookings : new BookingRepository();
		$this->activity       = $activity ? $activity : new BookingActivityRepository();
		$this->authorization  = $authorization ? $authorization : new VenueAuthorization();
		$this->config         = $config ? $config : new VenueBookingConfig();
		$this->reconciliation = $reconciliation ? $reconciliation : new TicketReconciliationService( $this->bookings, $this->activity, $this->authorization );
	}

	/** Record one immutable observation, returning an exact idempotent retry. */
	public function record_sales( array $input, int $actor_id ) {
		$booking = $this->booking( $input['booking_id'] ?? 0 );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( true !== $allowed ) {
			return $this->denied( $allowed );
		}
		$normalized = $this->normalize_report( $input, $booking, $actor_id );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$started = $this->begin_authorized( $booking, $actor_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking = $this->locked_booking( (int) $booking['id'] );
		if ( is_wp_error( $booking ) ) {
			return $this->rollback( $booking );
		}
		$identity = $this->validate_report_identity( $normalized, $booking );
		if ( is_wp_error( $identity ) ) {
			return $this->rollback( $identity );
		}
		$provenance = $this->reconciliation->validate_provenance( $normalized, $booking );
		if ( is_wp_error( $provenance ) ) {
			return $this->rollback( $provenance );
		}
		$settlement = $this->get_settlement( $booking['id'], true );
		if ( is_wp_error( $settlement ) ) {
			return $this->rollback( $settlement );
		}

		$existing = $this->find_report_by_external( $booking['id'], $normalized['provider'], $normalized['external_report_id'], true );
		if ( is_wp_error( $existing ) ) {
			return $this->rollback( $existing );
		}
		if ( is_array( $existing ) ) {
			if ( hash_equals( $existing['request_hash'], $normalized['request_hash'] ) ) {
				if ( is_array( $settlement ) && ! in_array( $existing['id'], $settlement['included_report_ids'], true ) ) {
					return $this->rollback( $this->settlement_frozen_error( $settlement ) );
				}
				$committed = $this->commit();
				return is_wp_error( $committed ) ? $committed : $existing;
			}
			return $this->rollback( new \WP_Error( 'sales_report_idempotency_conflict', __( 'This provider report ID is already bound to different evidence.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		if ( is_array( $settlement ) ) {
			return $this->rollback( $this->settlement_frozen_error( $settlement ) );
		}
		if ( null !== $normalized['corrects_report_id'] ) {
			$corrected = $this->get_report( $normalized['corrects_report_id'], true );
			if ( ! is_array( $corrected ) || $corrected['booking_id'] !== $booking['id'] || $corrected['currency'] !== $normalized['currency'] || $corrected['provider'] !== $normalized['provider'] || $corrected['ticket_source_id'] !== $normalized['ticket_source_id'] ) {
				return $this->rollback( new \WP_Error( 'invalid_sales_report_correction', __( 'A correction must identify evidence from the same booking, currency, provider, and ticket source.', 'extrachill-events' ), array( 'status' => 400 ) ) );
			}
		}

		global $wpdb;
		$row = $normalized;
		unset( $row['source'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only private financial evidence.
		if ( false === $wpdb->insert( BookingSchema::sales_reports_table(), $row ) ) {
			$existing = $this->find_report_by_external( $booking['id'], $normalized['provider'], $normalized['external_report_id'], true );
			if ( is_array( $existing ) && hash_equals( $existing['request_hash'], $normalized['request_hash'] ) ) {
				$committed = $this->commit();
				return is_wp_error( $committed ) ? $committed : $existing;
			}
			return $this->rollback( new \WP_Error( 'sales_report_write_failed', __( 'The ticket-sales evidence could not be recorded.', 'extrachill-events' ) ) );
		}
		$report = $this->get_report( (int) $wpdb->insert_id, true );
		if ( ! is_array( $report ) ) {
			return $this->rollback( is_wp_error( $report ) ? $report : new \WP_Error( 'sales_report_write_failed', __( 'The recorded ticket-sales evidence could not be verified.', 'extrachill-events' ) ) );
		}
		$audit = $this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'ticket_sales_report_recorded',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'external_id'     => (string) $report['id'],
				'idempotency_key' => 'sales-report:' . $report['id'],
				'payload'         => array(
					'report_id'          => $report['id'],
					'provider'           => $report['provider'],
					'external_report_id' => $report['external_report_id'],
					'currency'           => $report['currency'],
					'corrects_report_id' => $report['corrects_report_id'],
				),
			)
		);
		if ( is_wp_error( $audit ) ) {
			return $this->rollback( $audit );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $report;
	}

	/** List a bounded page of immutable observations after operation-level authorization. */
	public function list_sales( int $booking_id, int $actor_id, int $limit = 100, int $offset = 0 ) {
		$booking = $this->booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( true !== $allowed ) {
			return $this->denied( $allowed );
		}
		global $wpdb;
		$table  = BookingSchema::sales_reports_table();
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", $booking['id'], $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immutable booking evidence page.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'sales_report_list_failed', __( 'Ticket-sales evidence could not be listed.', 'extrachill-events' ) );
		}
		$reports = $this->hydrate_reports( (array) $rows );
		if ( is_wp_error( $reports ) ) {
			return $reports;
		}
		foreach ( $reports as $report ) {
			$identity = $this->validate_report_identity( $report, $booking );
			if ( is_wp_error( $identity ) ) {
				return $identity;
			}
		}
		return $reports;
	}

	/** Calculate a deterministic, non-persisted settlement preview. */
	public function calculate( array $input, int $actor_id ) {
		$booking = $this->booking( $input['booking_id'] ?? 0 );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( true !== $allowed ) {
			return $this->denied( $allowed );
		}
		$terms = $this->settlement_terms( $booking, $input );
		return is_wp_error( $terms ) ? $terms : $this->calculate_from_storage( $booking, $terms, false );
	}

	/** Freeze one settlement from an exact booking version and evidence snapshot. */
	public function finalize( array $input, int $actor_id ) {
		$booking = $this->booking( $input['booking_id'] ?? 0 );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_MANAGE_FINANCES );
		if ( true !== $allowed ) {
			return $this->denied( $allowed );
		}
		$expected_version = $this->positive_integer( $input['expected_booking_version'] ?? null, 'expected_booking_version' );
		$expected_reports = $this->report_ids( $input['expected_report_ids'] ?? null );
		$formula_version  = $input['formula_version'] ?? null;
		$expected_hash    = strtolower( (string) ( $input['expected_evidence_hash'] ?? '' ) );
		$terms            = $this->settlement_terms( $booking, $input, true );
		foreach ( array( $expected_version, $expected_reports, $terms ) as $validated ) {
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
		}
		if ( ! in_array( $formula_version, array( 1, self::FORMULA_VERSION ), true ) || ( self::FORMULA_VERSION === $formula_version && ! preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) ) {
			return new \WP_Error(
				'settlement_formula_version_conflict',
				__( 'The calculated settlement formula version or evidence hash is invalid.', 'extrachill-events' ),
				array(
					'status'                  => 409,
					'current_formula_version' => self::FORMULA_VERSION,
				)
			);
		}
		$started = $this->begin_authorized( $booking, $actor_id, VenueAuthorization::ACTION_MANAGE_FINANCES );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking = $this->locked_booking( $booking['id'] );
		if ( is_wp_error( $booking ) ) {
			return $this->rollback( $booking );
		}
		$existing = $this->get_settlement( $booking['id'], true );
		if ( is_wp_error( $existing ) ) {
			return $this->rollback( $existing );
		}
		if ( is_array( $existing ) ) {
			if ( ! $this->matches_finalization_retry( $existing, $expected_version, $expected_reports, $expected_hash, $formula_version, $terms ) ) {
				return $this->rollback(
					new \WP_Error(
						'settlement_already_finalized',
						__( 'This booking already has a different frozen settlement.', 'extrachill-events' ),
						array(
							'status'        => 409,
							'settlement_id' => $existing['id'],
						)
					)
				);
			}
			$integrity = $this->verify_settlement_evidence( $existing, true );
			if ( is_wp_error( $integrity ) ) {
				return $this->rollback( $integrity );
			}
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $existing;
		}
		if ( self::FORMULA_VERSION !== $formula_version ) {
			return $this->rollback(
				new \WP_Error(
					'settlement_formula_version_conflict',
					__( 'The calculated settlement formula version is no longer current.', 'extrachill-events' ),
					array(
						'status'                  => 409,
						'current_formula_version' => self::FORMULA_VERSION,
					)
				)
			);
		}
		if ( $booking['version'] !== $expected_version ) {
			return $this->rollback(
				new \WP_Error(
					'settlement_booking_version_conflict',
					__( 'The booking changed since settlement calculation.', 'extrachill-events' ),
					array(
						'status'          => 409,
						'current_version' => $booking['version'],
					)
				)
			);
		}
		$calculation = $this->calculate_from_storage( $booking, $terms, true );
		if ( is_wp_error( $calculation ) ) {
			return $this->rollback( $calculation );
		}
		if ( $calculation['included_report_ids'] !== $expected_reports || ! hash_equals( $calculation['evidence_hash'], $expected_hash ) ) {
			return $this->rollback(
				new \WP_Error(
					'settlement_evidence_conflict',
					__( 'Ticket-sales evidence changed since settlement calculation.', 'extrachill-events' ),
					array(
						'status'             => 409,
						'current_report_ids' => $calculation['included_report_ids'],
					)
				)
			);
		}
		global $wpdb;
		$now                   = gmdate( 'Y-m-d H:i:s' );
		$row                   = array(
			'booking_id'           => $booking['id'],
			'event_id'             => $booking['event_id'],
			'venue_term_id'        => $booking['venue_term_id'],
			'status'               => 'finalized',
			'version'              => 1,
			'booking_version'      => $booking['version'],
			'basis'                => $calculation['basis'],
			'basis_points'         => $calculation['basis_points'],
			'currency'             => $calculation['currency'],
			'formula_version'      => self::FORMULA_VERSION,
			'included_report_ids'  => wp_json_encode( $calculation['included_report_ids'] ),
			'evidence_hash'        => $calculation['evidence_hash'],
			'basis_amount_minor'   => $calculation['basis_amount_minor'],
			'adjustment_minor'     => $calculation['adjustment_minor'],
			'amount_due_minor'     => $calculation['amount_due_minor'],
			'finalized_by_user_id' => $actor_id,
			'finalized_at'         => $now,
			'paid_by_user_id'      => null,
			'paid_at'              => null,
			'payment_reference'    => null,
			'voided_by_user_id'    => null,
			'voided_at'            => null,
			'void_reason'          => null,
			'created_at'           => $now,
			'updated_at'           => $now,
		);
		$row['integrity_hash'] = $this->settlement_integrity_hash( $row );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One frozen private settlement per booking.
		if ( false === $wpdb->insert( BookingSchema::settlements_table(), $row ) ) {
			return $this->rollback( new \WP_Error( 'settlement_finalize_failed', __( 'The booking settlement could not be finalized.', 'extrachill-events' ) ) );
		}
		$settlement = $this->get_settlement( $booking['id'], true );
		if ( ! is_array( $settlement ) ) {
			return $this->rollback( is_wp_error( $settlement ) ? $settlement : new \WP_Error( 'settlement_finalize_failed', __( 'The finalized booking settlement could not be verified.', 'extrachill-events' ) ) );
		}
		$audit = $this->append_settlement_audit( $booking['id'], 'booking_settlement_finalized', $actor_id, $settlement );
		if ( is_wp_error( $audit ) ) {
			return $this->rollback( $audit );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $settlement;
	}

	/** Mark a finalized settlement paid with immutable payment audit. */
	public function mark_paid( array $input, int $actor_id ) {
		$reference = $this->required_text( $input['payment_reference'] ?? null, 'payment_reference', 191 );
		if ( is_wp_error( $reference ) ) {
			return $reference;
		}
		return $this->transition_settlement( $input, $actor_id, 'paid', array( 'payment_reference' => $reference ) );
	}

	/** Void a finalized settlement with immutable reason audit. */
	public function void( array $input, int $actor_id ) {
		$reason = $this->required_text( $input['reason'] ?? null, 'reason', 1000 );
		if ( is_wp_error( $reason ) ) {
			return $reason;
		}
		return $this->transition_settlement( $input, $actor_id, 'void', array( 'void_reason' => $reason ) );
	}

	/** Get the one settlement after venue-scoped operation authorization. */
	public function get( int $booking_id, int $actor_id ) {
		$booking = $this->booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		return true === $allowed ? $this->get_settlement( $booking['id'] ) : $this->denied( $allowed );
	}

	/** Round a signed basis-point product to nearest minor unit, ties away from zero. */
	public static function basis_points_amount( int $amount_minor, int $basis_points ) {
		$out_of_range = $basis_points < 0 || $basis_points > 10000;
		if ( ! $out_of_range && $basis_points > 0 ) {
			$limit        = intdiv( PHP_INT_MAX - 5000, $basis_points );
			$out_of_range = $amount_minor > $limit || $amount_minor < -$limit;
		}
		if ( $out_of_range ) {
			return new \WP_Error( 'settlement_amount_out_of_range', __( 'The settlement amount exceeds the supported integer range.', 'extrachill-events' ) );
		}
		$product = $amount_minor * $basis_points;
		$rounded = intdiv( abs( $product ) + 5000, 10000 );
		return $product < 0 ? -$rounded : $rounded;
	}

	private function transition_settlement( array $input, int $actor_id, string $status, array $audit_fields ) {
		$booking = $this->booking( $input['booking_id'] ?? 0 );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$expected_booking    = $this->positive_integer( $input['expected_booking_version'] ?? null, 'expected_booking_version' );
		$expected_settlement = $this->positive_integer( $input['expected_version'] ?? null, 'expected_version' );
		if ( is_wp_error( $expected_booking ) || is_wp_error( $expected_settlement ) ) {
			return is_wp_error( $expected_booking ) ? $expected_booking : $expected_settlement;
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_MANAGE_FINANCES );
		if ( true !== $allowed ) {
			return $this->denied( $allowed );
		}
		$started = $this->begin_authorized( $booking, $actor_id, VenueAuthorization::ACTION_MANAGE_FINANCES );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking = $this->locked_booking( $booking['id'] );
		if ( is_wp_error( $booking ) ) {
			return $this->rollback( $booking );
		}
		if ( $booking['version'] !== $expected_booking ) {
			return $this->rollback(
				new \WP_Error(
					'settlement_booking_version_conflict',
					__( 'The booking changed since the settlement decision was made.', 'extrachill-events' ),
					array(
						'status'          => 409,
						'current_version' => $booking['version'],
					)
				)
			);
		}
		if ( 'paid' === $status && 'completed' !== $booking['status'] ) {
			return $this->rollback(
				new \WP_Error(
					'settlement_payment_booking_status_conflict',
					__( 'A booking must be completed before its settlement can be marked paid.', 'extrachill-events' ),
					array(
						'status'         => 409,
						'booking_status' => $booking['status'],
					)
				)
			);
		}
		$settlement = $this->get_settlement( $booking['id'], true );
		if ( ! is_array( $settlement ) ) {
			return $this->rollback( is_wp_error( $settlement ) ? $settlement : new \WP_Error( 'settlement_not_found', __( 'The booking settlement was not found.', 'extrachill-events' ), array( 'status' => 404 ) ) );
		}
		$integrity = $this->verify_settlement_evidence( $settlement, true );
		if ( is_wp_error( $integrity ) ) {
			return $this->rollback( $integrity );
		}
		if ( $settlement['version'] !== $expected_settlement ) {
			return $this->rollback(
				new \WP_Error(
					'settlement_version_conflict',
					__( 'The booking settlement changed since it was read.', 'extrachill-events' ),
					array(
						'status'          => 409,
						'current_version' => $settlement['version'],
					)
				)
			);
		}
		if ( 'finalized' !== $settlement['status'] ) {
			return $this->rollback(
				new \WP_Error(
					'settlement_status_conflict',
					__( 'Only a finalized settlement may be paid or voided.', 'extrachill-events' ),
					array(
						'status'         => 409,
						'current_status' => $settlement['status'],
					)
				)
			);
		}
		global $wpdb;
		$now     = gmdate( 'Y-m-d H:i:s' );
		$changes = array(
			'status'     => $status,
			'version'    => $expected_settlement + 1,
			'updated_at' => $now,
		);
		if ( 'paid' === $status ) {
			$changes['paid_by_user_id']   = $actor_id;
			$changes['paid_at']           = $now;
			$changes['payment_reference'] = $audit_fields['payment_reference'];
		} else {
			$changes['voided_by_user_id'] = $actor_id;
			$changes['voided_at']         = $now;
			$changes['void_reason']       = $audit_fields['void_reason'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Optimistic terminal settlement transition.
		$updated = $wpdb->update(
			BookingSchema::settlements_table(),
			$changes,
			array(
				'id'      => $settlement['id'],
				'version' => $expected_settlement,
				'status'  => 'finalized',
			)
		);
		if ( 1 !== $updated ) {
			return $this->rollback(
				new \WP_Error(
					'settlement_version_conflict',
					__( 'The booking settlement changed before the audit transition was saved.', 'extrachill-events' ),
					array(
						'status' => 409,
					)
				)
			);
		}
		$settlement = $this->get_settlement( $booking['id'], true );
		$audit      = is_array( $settlement ) ? $this->append_settlement_audit( $booking['id'], 'paid' === $status ? 'booking_settlement_paid' : 'booking_settlement_voided', $actor_id, $settlement ) : $settlement;
		if ( is_wp_error( $audit ) || ! is_array( $settlement ) ) {
			return $this->rollback( is_wp_error( $audit ) ? $audit : new \WP_Error( 'settlement_read_failed', __( 'The transitioned settlement could not be verified.', 'extrachill-events' ) ) );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $settlement;
	}

	private function calculate_from_storage( array $booking, array $terms, bool $lock ) {
		global $wpdb;
		$table = BookingSchema::sales_reports_table();
		$query = "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id ASC";
		if ( $lock ) {
			$query .= ' FOR UPDATE';
		}
		$sql  = $wpdb->prepare( $query, $booking['id'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal lock suffix and trusted current-prefix table.
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete immutable evidence snapshot.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'sales_report_list_failed', __( 'Ticket-sales evidence could not be read for settlement.', 'extrachill-events' ) );
		}
		if ( $lock ) {
			$this->after_evidence_locked( $booking );
		}
		$reports = $this->hydrate_reports( (array) $rows );
		if ( is_wp_error( $reports ) ) {
			return $reports;
		}
		if ( empty( $reports ) ) {
			return new \WP_Error( 'settlement_evidence_required', __( 'At least one ticket-sales report is required for settlement.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$reports = $this->reconciliation->admitted_reports( $booking, $reports );
		if ( is_wp_error( $reports ) ) {
			return $reports;
		}
		$reports = array_values(
			array_filter(
				$reports,
				static function ( array $report ) use ( $terms ): bool {
					return $report['currency'] === $terms['currency'];
				}
			)
		);
		if ( empty( $reports ) ) {
			return new \WP_Error( 'settlement_evidence_required', __( 'At least one reconciled ticket-sales report is required for settlement.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$field        = 'gross_ticket_sales' === $terms['basis'] ? 'gross_minor' : 'net_minor';
		$basis_amount = 0;
		$ids          = array();
		$evidence     = array();
		foreach ( $reports as $report ) {
			if ( ( $report[ $field ] > 0 && $basis_amount > PHP_INT_MAX - $report[ $field ] ) || ( $report[ $field ] < 0 && $basis_amount < PHP_INT_MIN - $report[ $field ] ) ) {
				return new \WP_Error( 'settlement_amount_out_of_range', __( 'The settlement evidence exceeds the supported integer range.', 'extrachill-events' ) );
			}
			$basis_amount += $report[ $field ];
			$ids[]         = $report['id'];
			$resolution    = $this->reconciliation->settlement_resolution_evidence( $report['id'], $lock );
			if ( is_wp_error( $resolution ) ) {
				return $resolution;
			}
			$evidence[] = array(
				'id'           => $report['id'],
				'request_hash' => $report['request_hash'],
				'resolution'   => $resolution,
			);
		}
		$share = self::basis_points_amount( $basis_amount, $terms['basis_points'] );
		if ( is_wp_error( $share ) ) {
			return $share;
		}
		if ( ( $terms['adjustment_minor'] > 0 && $share > PHP_INT_MAX - $terms['adjustment_minor'] ) || ( $terms['adjustment_minor'] < 0 && $share < PHP_INT_MIN - $terms['adjustment_minor'] ) ) {
			return new \WP_Error( 'settlement_amount_out_of_range', __( 'The adjusted settlement exceeds the supported integer range.', 'extrachill-events' ) );
		}
		return array(
			'booking_id'          => $booking['id'],
			'booking_version'     => $booking['version'],
			'event_id'            => $booking['event_id'],
			'venue_term_id'       => $booking['venue_term_id'],
			'basis'               => $terms['basis'],
			'basis_points'        => $terms['basis_points'],
			'currency'            => $terms['currency'],
			'formula_version'     => self::FORMULA_VERSION,
			'included_report_ids' => $ids,
			'evidence_hash'       => $this->evidence_hash( $evidence ),
			'basis_amount_minor'  => $basis_amount,
			'share_amount_minor'  => $share,
			'adjustment_minor'    => $terms['adjustment_minor'],
			'amount_due_minor'    => $share + $terms['adjustment_minor'],
		);
	}

	private function settlement_terms( array $booking, array $input, bool $require_explicit = false ) {
		$document = is_array( $booking['confirmed_deal'] ?? null ) ? ( $booking['confirmed_deal']['data'] ?? array() ) : ( is_array( $booking['deal'] ?? null ) ? ( $booking['deal']['data'] ?? array() ) : array() );
		if ( empty( $document ) ) {
			$config   = $this->config->get( $booking['venue_term_id'] );
			$document = is_array( $config ) ? $config['default_deal'] : array();
			if ( is_wp_error( $config ) ) {
				return $config;
			}
		}
		$basis        = sanitize_key( (string) ( $input['basis'] ?? $document['revenue_share_basis'] ?? '' ) );
		$basis_points = $input['basis_points'] ?? $document['revenue_share_basis_points'] ?? null;
		$currency     = strtoupper( sanitize_text_field( (string) ( $input['currency'] ?? $document['currency'] ?? '' ) ) );
		$adjustment   = $input['adjustment_minor'] ?? 0;
		if ( $require_explicit && ( ! array_key_exists( 'basis', $input ) || ! array_key_exists( 'basis_points', $input ) || ! array_key_exists( 'currency', $input ) || ! array_key_exists( 'adjustment_minor', $input ) ) ) {
			return new \WP_Error( 'settlement_terms_required', __( 'Finalization requires the exact calculated settlement terms.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $basis, self::BASES, true ) ) {
			return new \WP_Error( 'invalid_settlement_basis', __( 'The settlement basis is not supported by ticket-sales evidence.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ! is_int( $basis_points ) || $basis_points < 0 || $basis_points > 10000 ) {
			return new \WP_Error( 'invalid_settlement_basis_points', __( 'Settlement basis points must be an integer between 0 and 10000.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return new \WP_Error( 'invalid_settlement_currency', __( 'Settlement currency must be a three-letter uppercase code.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ! is_int( $adjustment ) ) {
			return new \WP_Error( 'invalid_settlement_adjustment', __( 'Settlement adjustment must use integer minor units.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return array(
			'basis'            => $basis,
			'basis_points'     => $basis_points,
			'currency'         => $currency,
			'adjustment_minor' => $adjustment,
		);
	}

	private function normalize_report( array $input, array $booking, int $actor_id ) {
		if ( null === $booking['event_id'] ) {
			return new \WP_Error( 'sales_report_event_required', __( 'Ticket-sales evidence requires a booking linked to an event.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$provider         = mb_substr( sanitize_key( (string) ( $input['provider'] ?? '' ) ), 0, 64 );
		$external         = $this->required_text( $input['external_report_id'] ?? null, 'external_report_id', 191 );
		$source           = sanitize_key( (string) ( $input['source_type'] ?? '' ) );
		$start            = $this->datetime( $input['period_start'] ?? null, 'period_start' );
		$end              = $this->datetime( $input['period_end'] ?? null, 'period_end' );
		$currency         = strtoupper( sanitize_text_field( (string) ( $input['currency'] ?? '' ) ) );
		$ticket_source_id = null;
		$attachment_id    = null;
		if ( array_key_exists( 'ticket_source_id', $input ) && null !== $input['ticket_source_id'] ) {
			$ticket_source_id = $this->positive_integer( $input['ticket_source_id'], 'ticket_source_id' );
		}
		if ( array_key_exists( 'evidence_attachment_id', $input ) && null !== $input['evidence_attachment_id'] ) {
			$attachment_id = $this->positive_integer( $input['evidence_attachment_id'], 'evidence_attachment_id' );
		}
		$correction = null;
		if ( array_key_exists( 'corrects_report_id', $input ) && null !== $input['corrects_report_id'] ) {
			$correction = $this->positive_integer( $input['corrects_report_id'], 'corrects_report_id' );
		}
		foreach ( array( $external, $start, $end, $correction, $ticket_source_id, $attachment_id ) as $validated ) {
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
		}
		if ( '' === $provider || ! in_array( $source, self::SOURCE_TYPES, true ) || ! preg_match( '/^[A-Z]{3}$/', $currency ) || $end < $start ) {
			return new \WP_Error( 'invalid_sales_report_identity', __( 'Ticket-sales report identity, source, period, or currency is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$integers = array();
		foreach ( array( 'tickets_sold', 'tickets_refunded', 'gross_minor', 'fees_minor', 'tax_minor', 'refunds_minor', 'net_minor' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) || ! is_int( $input[ $field ] ) ) {
				return new \WP_Error(
					'invalid_sales_report_amount',
					__( 'Ticket counts and money must use signed integers.', 'extrachill-events' ),
					array(
						'status' => 400,
						'field'  => $field,
					)
				);
			}
			$integers[ $field ] = $input[ $field ];
		}
		if ( ! array_key_exists( 'source', $input ) || ! is_array( $input['source'] ) || empty( $input['source'] ) ) {
			return new \WP_Error( 'invalid_sales_report_source', __( 'Ticket-sales evidence requires a structured source payload.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$source_payload = wp_json_encode(
			array(
				'version' => 1,
				'data'    => $input['source'],
			)
		);
		if ( false === $source_payload ) {
			return new \WP_Error( 'sales_report_source_encode_failed', __( 'Ticket-sales source evidence could not be encoded.', 'extrachill-events' ), array( 'json_error' => json_last_error_msg() ) );
		}
		if ( 8192 < strlen( $source_payload ) ) {
			return new \WP_Error( 'sales_report_source_too_large', __( 'Ticket-sales source evidence exceeds the bounded provenance limit.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$row                 = array_merge(
			array(
				'booking_id'             => $booking['id'],
				'event_id'               => $booking['event_id'],
				'venue_term_id'          => $booking['venue_term_id'],
				'ticket_source_id'       => $ticket_source_id,
				'evidence_attachment_id' => $attachment_id,
				'provider'               => $provider,
				'external_report_id'     => $external,
				'source_type'            => $source,
				'period_start'           => $start,
				'period_end'             => $end,
				'currency'               => $currency,
				'corrects_report_id'     => $correction,
				'source_payload'         => $source_payload,
				'created_by_user_id'     => $actor_id,
				'created_at'             => gmdate( 'Y-m-d H:i:s' ),
			),
			$integers
		);
		$row['source']       = $input['source'];
		$row['request_hash'] = self::report_request_hash( $row );
		unset( $row['source'] );
		return $row;
	}

	private function validate_report_identity( array $report, array $booking ) {
		return $report['booking_id'] === $booking['id'] && $report['event_id'] === $booking['event_id'] && $report['venue_term_id'] === $booking['venue_term_id']
			? true
			: new \WP_Error( 'sales_report_booking_changed', __( 'The booking identity changed before evidence was recorded.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	private function settlement_frozen_error( array $settlement ): \WP_Error {
		return new \WP_Error(
			'sales_report_settlement_frozen',
			__( 'Ticket-sales evidence cannot change after settlement is finalized.', 'extrachill-events' ),
			array(
				'status'        => 409,
				'settlement_id' => $settlement['id'],
			)
		);
	}

	private function begin_authorized( array $booking, int $actor_id, string $action ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Financial aggregate boundary.
			return new \WP_Error( 'settlement_transaction_start_failed', __( 'The ticket settlement transaction could not start.', 'extrachill-events' ) );
		}
		$this->transaction_active = true;
		$table                    = BookingSchema::memberships_table();
		$members                  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $booking['venue_term_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks exact venue authority.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'settlement_authorization_lock_failed', __( 'Venue finance authority could not be locked.', 'extrachill-events' ) ) );
		}
		$allowed = $this->authorization->authorize_locked( $actor_id, $booking['venue_term_id'], $action, (array) $members );
		return true === $allowed ? true : $this->rollback( $this->denied( $allowed ) );
	}

	private function locked_booking( int $booking_id ) {
		$booking = $this->bookings->get_for_update( $booking_id );
		return is_array( $booking ) ? $booking : ( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ), array( 'status' => 404 ) ) );
	}

	private function booking( $booking_id ) {
		$id = $this->positive_integer( $booking_id, 'booking_id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$booking = $this->bookings->get( $id );
		return is_array( $booking ) ? $booking : ( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ), array( 'status' => 404 ) ) );
	}

	private function find_report_by_external( int $booking_id, string $provider, string $external_id, bool $lock = false ) {
		global $wpdb;
		$table = BookingSchema::sales_reports_table();
		$query = "SELECT * FROM {$table} WHERE booking_id = %d AND provider = %s AND external_report_id = %s LIMIT 1";
		if ( $lock ) {
			$query .= ' FOR UPDATE';
		}
		$row = $wpdb->get_row( $wpdb->prepare( $query, $booking_id, $provider, $external_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Booking-scoped immutable evidence lookup with internal lock suffix.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'sales_report_read_failed', __( 'Ticket-sales evidence could not be read.', 'extrachill-events' ) );
		}
		return is_array( $row ) ? $this->hydrate_report( $row ) : null;
	}

	private function get_report( int $report_id, bool $lock = false ) {
		global $wpdb;
		$table = BookingSchema::sales_reports_table();
		$query = "SELECT * FROM {$table} WHERE id = %d LIMIT 1";
		if ( $lock ) {
			$query .= ' FOR UPDATE';
		}
		$row = $wpdb->get_row( $wpdb->prepare( $query, $report_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact evidence read with internal lock suffix.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'sales_report_read_failed', __( 'Ticket-sales evidence could not be read.', 'extrachill-events' ) );
		}
		return is_array( $row ) ? $this->hydrate_report( $row ) : null;
	}

	private function get_settlement( int $booking_id, bool $lock = false ) {
		global $wpdb;
		$table = BookingSchema::settlements_table();
		$query = "SELECT * FROM {$table} WHERE booking_id = %d LIMIT 1";
		if ( $lock ) {
			$query .= ' FOR UPDATE';
		}
		$row = $wpdb->get_row( $wpdb->prepare( $query, $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One settlement per booking with internal lock suffix.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'settlement_read_failed', __( 'The booking settlement could not be read.', 'extrachill-events' ) );
		}
		return is_array( $row ) ? $this->hydrate_settlement( $row ) : null;
	}

	private function hydrate_reports( array $rows ) {
		$reports = array();
		foreach ( $rows as $row ) {
			$report = $this->hydrate_report( $row );
			if ( is_wp_error( $report ) ) {
				return $report;
			}
			$reports[] = $report;
		}
		return $reports;
	}

	private function hydrate_report( array $row ) {
		$source = json_decode( (string) ( $row['source_payload'] ?? '' ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $source ) || 1 !== ( $source['version'] ?? null ) || ! array_key_exists( 'data', $source ) ) {
			return new \WP_Error( 'sales_report_source_invalid', __( 'Stored ticket-sales source evidence is malformed.', 'extrachill-events' ) );
		}
		foreach ( array( 'id', 'booking_id', 'event_id', 'venue_term_id', 'tickets_sold', 'tickets_refunded', 'gross_minor', 'fees_minor', 'tax_minor', 'refunds_minor', 'net_minor', 'created_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['corrects_report_id']     = null === $row['corrects_report_id'] ? null : (int) $row['corrects_report_id'];
		$row['ticket_source_id']       = null === ( $row['ticket_source_id'] ?? null ) ? null : (int) $row['ticket_source_id'];
		$row['evidence_attachment_id'] = null === ( $row['evidence_attachment_id'] ?? null ) ? null : (int) $row['evidence_attachment_id'];
		$row['source']                 = $source['data'];
		unset( $row['source_payload'] );
		$stored_hash = strtolower( (string) ( $row['request_hash'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $stored_hash ) || ! hash_equals( $stored_hash, self::report_request_hash( $row ) ) ) {
			return new \WP_Error(
				'sales_report_integrity_failed',
				__( 'Stored ticket-sales evidence failed its immutable content check.', 'extrachill-events' ),
				array(
					'status'    => 409,
					'report_id' => $row['id'],
				)
			);
		}
		$row['request_hash'] = $stored_hash;
		$row['source']       = $this->project_source( $row['source'] );
		return $row;
	}

	private function hydrate_settlement( array $row ) {
		$ids = json_decode( (string) ( $row['included_report_ids'] ?? '' ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $ids ) || array_values( array_filter( $ids, 'is_int' ) ) !== $ids ) {
			return new \WP_Error( 'settlement_evidence_invalid', __( 'Stored settlement evidence is malformed.', 'extrachill-events' ) );
		}
		foreach ( array( 'id', 'booking_id', 'event_id', 'venue_term_id', 'version', 'booking_version', 'basis_points', 'formula_version', 'basis_amount_minor', 'adjustment_minor', 'amount_due_minor', 'finalized_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		foreach ( array( 'paid_by_user_id', 'voided_by_user_id' ) as $field ) {
			$row[ $field ] = null === $row[ $field ] ? null : (int) $row[ $field ];
		}
		$row['included_report_ids'] = $ids;
		$stored_hash                = strtolower( (string) ( $row['integrity_hash'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $stored_hash ) || ! hash_equals( $stored_hash, $this->settlement_integrity_hash( $row ) ) ) {
			return new \WP_Error(
				'settlement_integrity_failed',
				__( 'Stored settlement terms failed their immutable financial snapshot check.', 'extrachill-events' ),
				array(
					'status'        => 409,
					'settlement_id' => $row['id'],
				)
			);
		}
		$row['integrity_hash'] = $stored_hash;
		return $row;
	}

	/** Test seam invoked only after production finalization locks its evidence range. */
	protected function after_evidence_locked( array $booking ): void {
		unset( $booking );
	}

	private function matches_finalization_retry( array $settlement, int $booking_version, array $report_ids, string $evidence_hash, int $formula_version, array $terms ): bool {
		return $settlement['booking_version'] === $booking_version
			&& $settlement['included_report_ids'] === $report_ids
			&& $settlement['formula_version'] === $formula_version
			&& ( 1 === $formula_version || hash_equals( $settlement['evidence_hash'], $evidence_hash ) )
			&& $settlement['basis'] === $terms['basis']
			&& $settlement['basis_points'] === $terms['basis_points']
			&& $settlement['currency'] === $terms['currency']
			&& $settlement['adjustment_minor'] === $terms['adjustment_minor'];
	}

	private function verify_settlement_evidence( array $settlement, bool $lock ) {
		$evidence = array();
		foreach ( $settlement['included_report_ids'] as $report_id ) {
			$report = $this->get_report( $report_id, $lock );
			if ( is_wp_error( $report ) ) {
				return $report;
			}
			if ( ! is_array( $report ) || $report['booking_id'] !== $settlement['booking_id'] || $report['currency'] !== $settlement['currency'] ) {
				return new \WP_Error(
					'settlement_evidence_integrity_failed',
					__( 'Frozen settlement evidence is missing or belongs to a different booking or currency.', 'extrachill-events' ),
					array(
						'status'    => 409,
						'report_id' => $report_id,
					)
				);
			}
			$item = array(
				'id'           => $report['id'],
				'request_hash' => $report['request_hash'],
			);
			if ( 2 <= $settlement['formula_version'] ) {
				$resolution = $this->reconciliation->settlement_resolution_evidence( $report['id'], $lock );
				if ( is_wp_error( $resolution ) ) {
					return $resolution;
				}
				$item['resolution'] = $resolution;
			}
			$evidence[] = $item;
		}
		return hash_equals( $settlement['evidence_hash'], $this->evidence_hash( $evidence ) )
			? true
			: new \WP_Error(
				'settlement_evidence_integrity_failed',
				__( 'Frozen settlement evidence no longer matches its canonical content hash.', 'extrachill-events' ),
				array(
					'status'        => 409,
					'settlement_id' => $settlement['id'],
				)
			);
	}

	private function evidence_hash( array $evidence ): string {
		return hash( 'sha256', wp_json_encode( self::canonicalize( $evidence ) ) );
	}

	private function settlement_integrity_hash( array $settlement ): string {
		$ids = $settlement['included_report_ids'] ?? array();
		if ( is_string( $ids ) ) {
			$ids = json_decode( $ids, true );
		}
		$payload = array(
			'booking_id'          => (int) ( $settlement['booking_id'] ?? 0 ),
			'event_id'            => (int) ( $settlement['event_id'] ?? 0 ),
			'venue_term_id'       => (int) ( $settlement['venue_term_id'] ?? 0 ),
			'booking_version'     => (int) ( $settlement['booking_version'] ?? 0 ),
			'basis'               => (string) ( $settlement['basis'] ?? '' ),
			'basis_points'        => (int) ( $settlement['basis_points'] ?? 0 ),
			'currency'            => (string) ( $settlement['currency'] ?? '' ),
			'formula_version'     => (int) ( $settlement['formula_version'] ?? 0 ),
			'included_report_ids' => is_array( $ids ) ? $ids : array(),
			'evidence_hash'       => (string) ( $settlement['evidence_hash'] ?? '' ),
			'basis_amount_minor'  => (int) ( $settlement['basis_amount_minor'] ?? 0 ),
			'adjustment_minor'    => (int) ( $settlement['adjustment_minor'] ?? 0 ),
			'amount_due_minor'    => (int) ( $settlement['amount_due_minor'] ?? 0 ),
		);
		return hash( 'sha256', wp_json_encode( self::canonicalize( $payload ) ) );
	}

	/** Return the canonical immutable report hash shared with diagnostics. */
	public static function report_request_hash( array $report ): string {
		$fields = array( 'booking_id', 'event_id', 'venue_term_id', 'provider', 'external_report_id', 'source_type', 'period_start', 'period_end', 'tickets_sold', 'tickets_refunded', 'gross_minor', 'fees_minor', 'tax_minor', 'refunds_minor', 'net_minor', 'currency', 'corrects_report_id' );
		if ( null !== ( $report['ticket_source_id'] ?? null ) || null !== ( $report['evidence_attachment_id'] ?? null ) ) {
			array_splice( $fields, 3, 0, array( 'ticket_source_id', 'evidence_attachment_id' ) );
		}
		$hashable = array();
		foreach ( $fields as $field ) {
			$hashable[ $field ] = $report[ $field ] ?? null;
		}
		$hashable['source'] = $report['source'] ?? null;
		return hash( 'sha256', wp_json_encode( self::canonicalize( $hashable ) ) );
	}

	/** Return only server-owned provenance fields after integrity verification. */
	private function project_source( $value ): array {
		$projection = array( 'redacted' => true );
		if ( ! is_array( $value ) ) {
			return $projection;
		}
		if ( is_string( $value['attachment_id'] ?? null ) && wp_is_uuid( $value['attachment_id'], 4 ) ) {
			$projection['attachment_id'] = $value['attachment_id'];
		}
		if ( is_int( $value['row'] ?? null ) && 1 < $value['row'] && 1002 >= $value['row'] ) {
			$projection['row'] = $value['row'];
		}
		return $projection;
	}

	private function append_settlement_audit( int $booking_id, string $kind, int $actor_id, array $settlement ) {
		return $this->activity->append(
			array(
				'booking_id'  => $booking_id,
				'kind'        => $kind,
				'actor_type'  => 'user',
				'actor_id'    => $actor_id,
				'external_id' => (string) $settlement['id'],
				'payload'     => array(
					'settlement_id'    => $settlement['id'],
					'status'           => $settlement['status'],
					'version'          => $settlement['version'],
					'amount_due_minor' => $settlement['amount_due_minor'],
					'currency'         => $settlement['currency'],
				),
			)
		);
	}

	private function report_ids( $ids ) {
		if ( ! is_array( $ids ) || empty( $ids ) || count( $ids ) > 10000 ) {
			return new \WP_Error( 'invalid_settlement_report_ids', __( 'Settlement evidence IDs must be a non-empty bounded array.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$normalized = array();
		foreach ( $ids as $id ) {
			$id = $this->positive_integer( $id, 'expected_report_ids' );
			if ( is_wp_error( $id ) || in_array( $id, $normalized, true ) ) {
				return is_wp_error( $id ) ? $id : new \WP_Error( 'invalid_settlement_report_ids', __( 'Settlement evidence IDs must be unique.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$normalized[] = $id;
		}
		sort( $normalized, SORT_NUMERIC );
		return $normalized;
	}

	private function positive_integer( $value, string $field ) {
		return is_int( $value ) && $value > 0 ? $value : new \WP_Error(
			'invalid_settlement_integer',
			__( 'A positive integer is required.', 'extrachill-events' ),
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	private function required_text( $value, string $field, int $length ) {
		$value = mb_substr( sanitize_text_field( (string) $value ), 0, $length );
		return '' !== $value ? $value : new \WP_Error(
			'missing_settlement_field',
			__( 'A required settlement field is missing.', 'extrachill-events' ),
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	private function datetime( $value, string $field ) {
		if ( ! is_string( $value ) ) {
			return new \WP_Error(
				'invalid_sales_report_datetime',
				__( 'Sales report datetimes must use UTC Y-m-d H:i:s format.', 'extrachill-events' ),
				array(
					'status' => 400,
					'field'  => $field,
				)
			);
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value ? $value : new \WP_Error(
			'invalid_sales_report_datetime',
			__( 'Sales report datetimes must use UTC Y-m-d H:i:s format.', 'extrachill-events' ),
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
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

	private function denied( $result ): \WP_Error {
		return is_wp_error( $result ) ? $result : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	private function commit() {
		global $wpdb;
		$result                   = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Financial aggregate boundary.
		$this->transaction_active = false;
		return false === $result ? new \WP_Error( 'settlement_commit_uncertain', __( 'The ticket settlement transaction outcome could not be confirmed.', 'extrachill-events' ) ) : true;
	}

	private function rollback( \WP_Error $error ) {
		global $wpdb;
		if ( $this->transaction_active ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Financial aggregate rollback.
			$this->transaction_active = false;
		}
		return $error;
	}
}
