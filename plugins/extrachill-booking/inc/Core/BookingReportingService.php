<?php
/**
 * Bounded reporting projections over canonical venue-booking records.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

// Repository convention uses PSR-4 class names and concise method comments.
// phpcs:disable WordPress.Files.FileName,Generic.Commenting,Squiz.Commenting

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds private venue reports without introducing another analytics store. */
class BookingReportingService {
	public const MAX_VENUES           = 20;
	public const MAX_RANGE_DAYS       = 366;
	public const MAX_BOOKINGS         = 200;
	public const MAX_FINANCE_BOOKINGS = 25;
	private const MAX_ACTIVITIES      = 5000;
	private const REPORTING_KINDS     = array(
		'inquiry_submitted',
		'status_changed',
		'deal_confirmed',
		'event_conversion_started',
		'event_conversion_failed',
		'event_converted',
		'event_sync_started',
		'event_sync_retryable',
		'event_sync_failed',
		'event_sync_conflict',
		'event_sync_succeeded',
		'event_sync_noop',
		'marketing_operation_submitted',
		'marketing_operation_failed',
		'marketing_operation_approval_failed',
		'marketing_operation_trigger_failed',
		'marketing_operation_denied',
	);

	/** @var VenueAuthorization */
	private $authorization;
	/** @var TicketSettlementService */
	private $commissions;
	/** @var ShowSettlementService */
	private $shows;
	/** @var callable|null */
	private $snapshot_reader;

	public function __construct( ?VenueAuthorization $authorization = null, ?TicketSettlementService $commissions = null, ?ShowSettlementService $shows = null, ?callable $snapshot_reader = null ) {
		$this->authorization   = $authorization ? $authorization : new VenueAuthorization();
		$this->commissions     = $commissions ? $commissions : new TicketSettlementService();
		$this->shows           = $shows ? $shows : new ShowSettlementService();
		$this->snapshot_reader = $snapshot_reader;
	}

	/** Authorize and normalize an operations-report request. */
	public function authorize_operations( array $input, int $actor_id ) {
		return $this->authorize( $input, $actor_id, VenueAuthorization::ACTION_ACCESS_VENUE );
	}

	/** Authorize and normalize a finance-report request. */
	public function authorize_finance( array $input, int $actor_id ) {
		return $this->authorize( $input, $actor_id, VenueAuthorization::ACTION_MANAGE_FINANCES );
	}

	/** Return the canonical booking funnel and operational projection. */
	public function operations( array $input, int $actor_id ) {
		$request = $this->authorize_operations( $input, $actor_id );
		if ( $request instanceof \WP_Error ) {
			return $request;
		}
		$snapshot = $this->snapshot( 'operations', $request, $actor_id );
		if ( $snapshot instanceof \WP_Error ) {
			return $snapshot;
		}
		return $this->project_operations( $request, $snapshot );
	}

	/** Return role-gated commission, show-settlement, and artist-payout totals. */
	public function finance( array $input, int $actor_id ) {
		$request = $this->authorize_finance( $input, $actor_id );
		if ( $request instanceof \WP_Error ) {
			return $request;
		}
		$snapshot = $this->snapshot( 'finance', $request, $actor_id );
		if ( $snapshot instanceof \WP_Error ) {
			return $snapshot;
		}
		return $this->project_finance( $request, $snapshot );
	}

	/** Enforce exact venue membership and an additional administrator gate for aggregation. */
	private function authorize( array $input, int $actor_id, string $action ) {
		$request = $this->normalize_request( $input );
		if ( $request instanceof \WP_Error ) {
			return $request;
		}
		if ( count( $request['venue_term_ids'] ) > 1 && ! $this->authorization->is_administrator( $actor_id ) ) {
			return $this->denied();
		}
		if ( VenueAuthorization::ACTION_MANAGE_FINANCES === $action ) {
			$request['limit'] = min( $request['limit'], self::MAX_FINANCE_BOOKINGS );
		}
		foreach ( $request['venue_term_ids'] as $venue_term_id ) {
			$allowed = $this->authorization->authorize( $actor_id, $venue_term_id, $action );
			if ( true !== $allowed ) {
				return $this->denied();
			}
		}
		return $request;
	}

	/** Validate a half-open UTC period and bounded scope. */
	private function normalize_request( array $input ) {
		$venues = array_values( array_unique( array_map( 'absint', is_array( $input['venue_term_ids'] ?? null ) ? $input['venue_term_ids'] : array() ) ) );
		if ( empty( $venues ) || count( $venues ) > self::MAX_VENUES || in_array( 0, $venues, true ) ) {
			return new \WP_Error( 'invalid_booking_report_venues', __( 'One to twenty unique venue identifiers are required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$from = $this->datetime( $input['from'] ?? null );
		$to   = $this->datetime( $input['to'] ?? null );
		if ( $from instanceof \WP_Error || $to instanceof \WP_Error ) {
			return $from instanceof \WP_Error ? $from : $to;
		}
		$seconds = $to->getTimestamp() - $from->getTimestamp();
		if ( $seconds <= 0 || $seconds > self::MAX_RANGE_DAYS * DAY_IN_SECONDS ) {
			return new \WP_Error( 'invalid_booking_report_range', __( 'The report period must be positive and no longer than 366 days.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return array(
			'venue_term_ids' => $venues,
			'from'           => $from->format( 'Y-m-d H:i:s' ),
			'to'             => $to->format( 'Y-m-d H:i:s' ),
			'limit'          => max( 1, min( self::MAX_BOOKINGS, absint( $input['limit'] ?? 100 ) ) ),
		);
	}

	private function datetime( $value ) {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $value, new \DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d H:i:s' ) === $value
			? $date
			: new \WP_Error( 'invalid_booking_report_datetime', __( 'Report timestamps must use UTC Y-m-d H:i:s format.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	private function snapshot( string $kind, array $request, int $actor_id ) {
		if ( $this->snapshot_reader ) {
			return call_user_func( $this->snapshot_reader, $kind, $request, $actor_id );
		}
		return 'finance' === $kind ? $this->read_finance_snapshot( $request, $actor_id ) : $this->read_operations_snapshot( $request );
	}

	/** Read only bounded, non-contact operational columns from canonical tables. */
	private function read_operations_snapshot( array $request, bool $finance = false ) {
		global $wpdb;
		$venues       = implode( ', ', array_map( 'intval', $request['venue_term_ids'] ) );
		$bookings     = BookingSchema::bookings_table();
		$limit        = $request['limit'] + 1;
		$date_column  = $finance ? 'performance_start_at' : 'created_at';
		$booking_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, public_id, venue_term_id, status, event_id, created_at, updated_at FROM {$bookings} WHERE venue_term_id IN ({$venues}) AND status <> 'admission_pending' AND {$date_column} >= %s AND {$date_column} < %s ORDER BY {$date_column} ASC, id ASC LIMIT %d", $request['from'], $request['to'], $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal fixed date column, table name, and integer venue list; bounded report snapshot.
		if ( ! empty( $wpdb->last_error ) || ! is_array( $booking_rows ) ) {
			return new \WP_Error( 'booking_report_read_failed', __( 'The booking report could not be read.', 'extrachill-events' ) );
		}
		$truncated    = count( $booking_rows ) > $request['limit'];
		$booking_rows = array_slice( $booking_rows, 0, $request['limit'] );
		foreach ( $booking_rows as &$booking ) {
			foreach ( array( 'id', 'venue_term_id' ) as $field ) {
				$booking[ $field ] = (int) $booking[ $field ];
			}
			$booking['event_id'] = null === $booking['event_id'] ? null : (int) $booking['event_id'];
		}
		unset( $booking );
		$snapshot = array(
			'bookings'             => $booking_rows,
			'activities'           => array(),
			'holds'                => array(),
			'sales_reports'        => array(),
			'sales_resolutions'    => array(),
			'truncated'            => $truncated,
			'activities_truncated' => false,
			'evidence_truncated'   => false,
			'database_now'         => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( empty( $booking_rows ) ) {
			return $snapshot;
		}
		$ids               = array_column( $booking_rows, 'id' );
		$id_placeholders   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$kind_placeholders = implode( ', ', array_fill( 0, count( self::REPORTING_KINDS ), '%s' ) );
		$activity          = BookingSchema::activity_table();
		$activity_query    = $wpdb->prepare( "SELECT id, booking_id, kind, channel, external_id, payload, occurred_at FROM {$activity} WHERE booking_id IN ({$id_placeholders}) AND kind IN ({$kind_placeholders}) ORDER BY occurred_at ASC, id ASC LIMIT %d", array_merge( $ids, self::REPORTING_KINDS, array( self::MAX_ACTIVITIES + 1 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table and bounded placeholder counts; all values prepared.
		$activity_rows     = $wpdb->get_results( $activity_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query prepared above.
		if ( ! empty( $wpdb->last_error ) || ! is_array( $activity_rows ) ) {
			return new \WP_Error( 'booking_report_activity_read_failed', __( 'Booking report activity could not be read.', 'extrachill-events' ) );
		}
		$snapshot['activities_truncated'] = count( $activity_rows ) > self::MAX_ACTIVITIES;
		$activity_rows                    = array_slice( $activity_rows, 0, self::MAX_ACTIVITIES );
		$repository                       = new BookingActivityRepository();
		foreach ( $activity_rows as $row ) {
			$row['actor_id'] = null;
			$item            = $repository->hydrate( $row );
			if ( $item instanceof \WP_Error ) {
				return $item;
			}
			$snapshot['activities'][] = $item;
		}
		$holds_table = BookingSchema::holds_table();
		$holds_query = $wpdb->prepare( "SELECT booking_id, status, expires_at FROM {$holds_table} WHERE booking_id IN ({$id_placeholders}) ORDER BY id ASC LIMIT 1001", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Internal table and bounded placeholder count; all IDs prepared.
		$hold_rows   = $wpdb->get_results( $holds_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query prepared above.
		if ( ! empty( $wpdb->last_error ) || ! is_array( $hold_rows ) ) {
			return new \WP_Error( 'booking_report_evidence_read_failed', __( 'Booking hold evidence could not be read.', 'extrachill-events' ) );
		}
		$snapshot['holds'] = $hold_rows;
		$reports_table     = BookingSchema::sales_reports_table();
		$reports_query     = $wpdb->prepare( "SELECT id, booking_id, corrects_report_id FROM {$reports_table} WHERE booking_id IN ({$id_placeholders}) ORDER BY id ASC LIMIT 1001", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Internal table and bounded placeholder count; all IDs prepared.
		$report_rows       = $wpdb->get_results( $reports_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query prepared above.
		if ( ! empty( $wpdb->last_error ) || ! is_array( $report_rows ) ) {
			return new \WP_Error( 'booking_report_evidence_read_failed', __( 'Booking ticket evidence could not be read.', 'extrachill-events' ) );
		}
		$snapshot['sales_reports'] = $report_rows;
		$resolutions_table         = BookingSchema::sales_resolutions_table();
		$resolutions_query         = $wpdb->prepare( "SELECT report_id, decision, version FROM {$resolutions_table} WHERE booking_id IN ({$id_placeholders}) ORDER BY report_id ASC, version ASC LIMIT 1001", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Internal table and bounded placeholder count; all IDs prepared.
		$resolution_rows           = $wpdb->get_results( $resolutions_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query prepared above.
		if ( ! empty( $wpdb->last_error ) || ! is_array( $resolution_rows ) ) {
			return new \WP_Error( 'booking_report_evidence_read_failed', __( 'Booking ticket evidence could not be read.', 'extrachill-events' ) );
		}
		$snapshot['sales_resolutions'] = $resolution_rows;
		foreach ( array( 'holds', 'sales_reports', 'sales_resolutions' ) as $key ) {
			if ( count( $snapshot[ $key ] ) > 1000 ) {
				$snapshot['evidence_truncated'] = true;
				$snapshot[ $key ]               = array_slice( $snapshot[ $key ], 0, 1000 );
			}
		}
		return $snapshot;
	}

	/** Verify finance records through their owning services before aggregation. */
	private function read_finance_snapshot( array $request, int $actor_id ) {
		$operations = $this->read_operations_snapshot( $request, true );
		if ( $operations instanceof \WP_Error ) {
			return $operations;
		}
		$operations['commissions']                    = array();
		$operations['shows']                          = array();
		$operations['finance_verification_truncated'] = false;
		foreach ( $operations['bookings'] as $booking ) {
			$commission = $this->commissions->get( $booking['id'], $actor_id );
			if ( $commission instanceof \WP_Error ) {
				return $commission;
			}
			if ( is_array( $commission ) ) {
				$operations['commissions'][] = $commission;
			}
			$show = $this->shows->get_for_reporting( $booking['id'], $actor_id, 200 );
			if ( $show instanceof \WP_Error && 'show_settlement_reporting_limit_exceeded' === $show->get_error_code() ) {
				$operations['finance_verification_truncated'] = true;
				continue;
			}
			if ( $show instanceof \WP_Error && 'show_settlement_not_found' !== $show->get_error_code() ) {
				return $show;
			}
			if ( is_array( $show ) ) {
				$operations['shows'][] = $show;
			}
		}
		return $operations;
	}

	private function project_operations( array $request, array $snapshot ): array {
		$bookings      = array_values( $snapshot['bookings'] ?? array() );
		$status_counts = array_fill_keys( BookingRepository::STATUSES, 0 );
		$booking_refs  = array();
		foreach ( $bookings as $booking ) {
			$status = (string) ( $booking['status'] ?? '' );
			if ( isset( $status_counts[ $status ] ) ) {
				++$status_counts[ $status ];
			}
			$booking_refs[ (int) $booking['id'] ] = (string) $booking['public_id'];
		}
		$stage_entries      = array_fill_keys( BookingRepository::STATUSES, 0 );
		$submitted_at       = array();
		$confirmation_times = array();
		$decline_note_count = 0;
		$event_states       = array_fill_keys( array( 'none', 'pending', 'completed', 'failed', 'retryable', 'conflict', 'no_change', 'succeeded' ), 0 );
		$latest_event_state = array();
		$marketing_latest   = array();
		$marketing_failures = 0;
		foreach ( (array) ( $snapshot['activities'] ?? array() ) as $activity ) {
			$id       = (int) ( $activity['booking_id'] ?? 0 );
			$kind     = (string) ( $activity['kind'] ?? '' );
			$data     = is_array( $activity['payload']['data'] ?? null ) ? $activity['payload']['data'] : array();
			$occurred = (string) ( $activity['occurred_at'] ?? '' );
			if ( 'inquiry_submitted' === $kind ) {
				++$stage_entries['submitted'];
				$submitted_at[ $id ] = $occurred;
			} elseif ( 'status_changed' === $kind && isset( $stage_entries[ $data['to_status'] ?? '' ] ) ) {
				++$stage_entries[ $data['to_status'] ];
				$decline_note_count += 'declined' === $data['to_status'] && '' !== trim( (string) ( $data['note'] ?? '' ) ) ? 1 : 0;
			} elseif ( 'deal_confirmed' === $kind ) {
				++$stage_entries['confirmed'];
				if ( isset( $submitted_at[ $id ] ) ) {
					$confirmation_times[] = max( 0, strtotime( $occurred . ' UTC' ) - strtotime( $submitted_at[ $id ] . ' UTC' ) );
				}
			}
			$state = $this->event_state( $kind );
			if ( null !== $state ) {
				$latest_event_state[ $id ] = $state;
			}
			if ( 'marketing_operation_submitted' === $kind && ! empty( $activity['external_id'] ) ) {
				$marketing_latest[ (string) $activity['external_id'] ] = array(
					'booking_ref'    => $booking_refs[ $id ] ?? '',
					'channel'        => (string) ( $activity['channel'] ?? '' ),
					'operation_ref'  => (string) $activity['external_id'],
					'status'         => (string) ( $data['status'] ?? 'failed' ),
					'classification' => $data['projection']['classification'] ?? null,
					'effect_count'   => max( 0, (int) ( $data['projection']['effect_count'] ?? 0 ) ),
					'outcome_refs'   => $this->marketing_refs( $data['projection'] ?? array() ),
				);
			} elseif ( 0 === strpos( $kind, 'marketing_operation_' ) && false !== strpos( $kind, 'failed' ) ) {
				++$marketing_failures;
			}
		}
		foreach ( $bookings as $booking ) {
			$state = $latest_event_state[ (int) $booking['id'] ] ?? ( null === $booking['event_id'] ? 'none' : 'completed' );
			++$event_states[ $state ];
		}
		$holds = array_fill_keys( array( 'active', 'released', 'expired', 'converted' ), 0 );
		$now   = (string) ( $snapshot['database_now'] ?? gmdate( 'Y-m-d H:i:s' ) );
		foreach ( (array) ( $snapshot['holds'] ?? array() ) as $hold ) {
			$status = (string) ( $hold['status'] ?? '' );
			if ( 'active' === $status && (string) ( $hold['expires_at'] ?? '' ) <= $now ) {
				$status = 'expired';
			}
			if ( isset( $holds[ $status ] ) ) {
				++$holds[ $status ];
			}
		}
		$reports     = (array) ( $snapshot['sales_reports'] ?? array() );
		$resolutions = array();
		foreach ( (array) ( $snapshot['sales_resolutions'] ?? array() ) as $resolution ) {
			$resolutions[ (int) $resolution['report_id'] ] = (string) $resolution['decision'];
		}
		$decision_counts = array(
			'admit'                   => 0,
			'exclude'                 => 0,
			'not_explicitly_resolved' => 0,
		);
		foreach ( $reports as $report ) {
			$decision = $resolutions[ (int) $report['id'] ] ?? 'not_explicitly_resolved';
			++$decision_counts[ $decision ];
		}
		$confirmation = $this->duration_summary( $confirmation_times );
		return array(
			'scope'        => $this->scope( $request, 'inquiry_created_at', (string) ( $snapshot['database_now'] ?? gmdate( 'Y-m-d H:i:s' ) ) ),
			'state'        => empty( $bookings ) ? 'empty' : ( ! empty( $snapshot['truncated'] ) || ! empty( $snapshot['activities_truncated'] ) || ! empty( $snapshot['evidence_truncated'] ) ? 'incomplete' : 'complete' ),
			'bounded'      => array(
				'booking_limit'        => $request['limit'],
				'booking_count'        => count( $bookings ),
				'truncated'            => ! empty( $snapshot['truncated'] ),
				'activity_limit'       => self::MAX_ACTIVITIES,
				'activities_truncated' => ! empty( $snapshot['activities_truncated'] ),
				'evidence_limit'       => 1000,
				'evidence_truncated'   => ! empty( $snapshot['evidence_truncated'] ),
			),
			'availability' => array(
				'inquiry_source'       => 'not_recorded',
				'decline_reason_codes' => 'not_recorded',
				'first_response_time'  => 'not_recorded',
				'time_to_confirmation' => 0 < $confirmation['sample_size'] ? 'available' : 'no_observations',
				'marketing_outcomes'   => empty( $marketing_latest ) ? 'no_observations' : 'available',
			),
			'funnel'       => array(
				'inquiries'                    => count( $bookings ),
				'current_stage_counts'         => $status_counts,
				'stage_entry_counts'           => $stage_entries,
				'conversion_basis_points'      => $this->conversion_rates( $stage_entries, count( $bookings ) ),
				'declines'                     => array(
					'count'                  => $stage_entries['declined'],
					'note_recorded_count'    => $decline_note_count,
					'reason_codes_available' => false,
				),
				'first_response_seconds'       => null,
				'time_to_confirmation_seconds' => $confirmation,
			),
			'operations'   => array(
				'holds'           => $holds,
				'confirmed_shows' => $stage_entries['confirmed'],
				'event_states'    => $event_states,
				'ticket_evidence' => array(
					'reports_recorded'           => count( $reports ),
					'corrections_recorded'       => count( array_filter( $reports, static fn( array $row ): bool => ! empty( $row['corrects_report_id'] ) ) ),
					'latest_explicit_decisions'  => $decision_counts,
					'diagnostic_state_available' => false,
				),
			),
			'marketing'    => array(
				'operations'    => array_values( $marketing_latest ),
				'failure_count' => $marketing_failures,
			),
		);
	}

	private function project_finance( array $request, array $snapshot ): array {
		$bookings            = array_values( $snapshot['bookings'] ?? array() );
		$commissions         = array_values( $snapshot['commissions'] ?? array() );
		$shows               = array_values( $snapshot['shows'] ?? array() );
		$commission_statuses = array_fill_keys( array( 'finalized', 'paid', 'void' ), 0 );
		$show_statuses       = array_fill_keys( array_merge( array( 'draft' ), ShowSettlementService::ACTIONS ), 0 );
		$currencies          = array();
		foreach ( $commissions as $commission ) {
			$status = (string) $commission['status'];
			++$commission_statuses[ $status ];
			$currency                = (string) $commission['currency'];
			$currencies[ $currency ] = $currencies[ $currency ] ?? $this->money_totals();
			if ( in_array( $status, array( 'finalized', 'paid' ), true ) ) {
				$currencies[ $currency ]['extra_chill_share_due_minor'] += (int) $commission['amount_due_minor'];
			}
			if ( 'paid' === $status ) {
				$currencies[ $currency ]['extra_chill_share_paid_minor'] += (int) $commission['amount_due_minor'];
			}
		}
		$has_correction = false;
		foreach ( $shows as $show ) {
			$status = (string) $show['status'];
			++$show_statuses[ $status ];
			$currency                = (string) $show['currency'];
			$currencies[ $currency ] = $currencies[ $currency ] ?? $this->money_totals();
			$calculation             = (array) ( $show['calculation'] ?? array() );
			if ( in_array( $status, array( 'finalized', 'acknowledged', 'paid' ), true ) ) {
				$currencies[ $currency ]['artist_payout_committed_minor'] += (int) ( $calculation['artist_payout_minor'] ?? 0 );
				$currencies[ $currency ]['venue_net_after_payout_minor']  += (int) ( $calculation['venue_net_after_payout_minor'] ?? 0 );
			}
			if ( 'paid' === $status ) {
				$currencies[ $currency ]['artist_payout_paid_minor'] += (int) ( $calculation['artist_payout_minor'] ?? 0 );
			}
			$has_correction = $has_correction || null !== ( $show['corrects_revision_id'] ?? null );
		}
		$frozen = ! empty( $shows )
			&& count( $shows ) === count( $bookings )
			&& count( $commissions ) === count( $bookings )
			&& empty( $snapshot['truncated'] )
			&& empty( $snapshot['finance_verification_truncated'] )
			&& count( $shows ) === count( array_filter( $shows, static fn( array $show ): bool => in_array( $show['status'], array( 'finalized', 'acknowledged', 'paid' ), true ) ) );
		$state  = empty( $bookings ) ? 'empty' : ( $frozen ? ( $has_correction ? 'corrected' : 'finalized' ) : 'incomplete' );
		return array(
			'scope'                    => $this->scope( $request, 'performance_start_at', (string) ( $snapshot['database_now'] ?? gmdate( 'Y-m-d H:i:s' ) ) ),
			'state'                    => $state,
			'bounded'                  => array(
				'booking_limit'                        => $request['limit'],
				'booking_count'                        => count( $bookings ),
				'truncated'                            => ! empty( $snapshot['truncated'] ),
				'financial_evidence_limit_per_booking' => 200,
				'financial_verification_truncated'     => ! empty( $snapshot['finance_verification_truncated'] ),
			),
			'coverage'                 => array(
				'bookings'                       => count( $bookings ),
				'commission_settlements'         => count( $commissions ),
				'show_settlements'               => count( $shows ),
				'missing_commission_settlements' => count( $bookings ) - count( $commissions ),
				'missing_show_settlements'       => count( $bookings ) - count( $shows ),
			),
			'commission_statuses'      => $commission_statuses,
			'show_settlement_statuses' => $show_statuses,
			'currencies'               => $currencies,
		);
	}

	private function event_state( string $kind ): ?string {
		$map = array(
			'event_conversion_started' => 'pending',
			'event_conversion_failed'  => 'failed',
			'event_converted'          => 'completed',
			'event_sync_started'       => 'pending',
			'event_sync_retryable'     => 'retryable',
			'event_sync_failed'        => 'failed',
			'event_sync_conflict'      => 'conflict',
			'event_sync_succeeded'     => 'succeeded',
			'event_sync_noop'          => 'no_change',
		);
		return $map[ $kind ] ?? null;
	}

	private function marketing_refs( array $projection ): array {
		$refs = array();
		foreach ( array_slice( (array) ( $projection['share_refs'] ?? array() ), 0, 20 ) as $ref ) {
			if ( is_array( $ref ) && ! empty( $ref['channel'] ) && ! empty( $ref['platform_post_id'] ) ) {
				$refs[] = array(
					'type'    => 'social',
					'channel' => (string) $ref['channel'],
					'id'      => (string) $ref['platform_post_id'],
				);
			}
		}
		$record = $projection['record'] ?? null;
		if ( is_array( $record ) && ! empty( $record['campaign_id'] ) ) {
			$refs[] = array(
				'type'    => 'newsletter',
				'channel' => 'newsletter',
				'id'      => (string) $record['campaign_id'],
			);
		}
		return $refs;
	}

	private function conversion_rates( array $entries, int $inquiries ): array {
		$rates = array();
		foreach ( $entries as $stage => $count ) {
			$rates[ $stage ] = $inquiries > 0 ? (int) round( min( $count, $inquiries ) * 10000 / $inquiries ) : null;
		}
		return $rates;
	}

	private function duration_summary( array $values ): array {
		return empty( $values ) ? array(
			'sample_size' => 0,
			'minimum'     => null,
			'maximum'     => null,
			'average'     => null,
		) : array(
			'sample_size' => count( $values ),
			'minimum'     => min( $values ),
			'maximum'     => max( $values ),
			'average'     => (int) round( array_sum( $values ) / count( $values ) ),
		);
	}

	private function money_totals(): array {
		return array(
			'extra_chill_share_due_minor'   => 0,
			'extra_chill_share_paid_minor'  => 0,
			'artist_payout_committed_minor' => 0,
			'artist_payout_paid_minor'      => 0,
			'venue_net_after_payout_minor'  => 0,
		);
	}

	private function scope( array $request, string $basis, string $outcomes_as_of ): array {
		return array(
			'venue_term_ids' => $request['venue_term_ids'],
			'from'           => $request['from'],
			'to'             => $request['to'],
			'timezone'       => 'UTC',
			'interval'       => 'half_open',
			'basis'          => $basis,
			'outcomes_as_of' => $outcomes_as_of,
		);
	}

	private function denied(): \WP_Error {
		return new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
