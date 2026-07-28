<?php
/**
 * Provider-neutral ticket source, import, and reconciliation contracts.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

// Repository convention uses concise method comments.
// phpcs:disable Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps attribution and reconciliation policy beside immutable settlement evidence. */
class TicketReconciliationService {
	public const DECISIONS           = array( 'admit', 'exclude' );
	private const CSV_HEADER         = array( 'external_report_id', 'period_start', 'period_end', 'tickets_sold', 'tickets_refunded', 'gross_minor', 'fees_minor', 'tax_minor', 'refunds_minor', 'net_minor', 'currency' );
	private const MAX_CSV_ROWS       = 1000;
	private const MAX_CSV_LINE_BYTES = 16384;

	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var BookingAttachmentRepository */
	private $attachments;
	/** @var BookingAttachmentService */
	private $attachment_service;
	/** @var bool */
	private $transaction_active = false;

	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueAuthorization $authorization = null, ?BookingAttachmentRepository $attachments = null, ?BookingAttachmentService $attachment_service = null ) {
		$this->bookings           = $bookings ? $bookings : new BookingRepository();
		$this->activity           = $activity ? $activity : new BookingActivityRepository();
		$this->authorization      = $authorization ? $authorization : new VenueAuthorization();
		$this->attachments        = $attachments ? $attachments : new BookingAttachmentRepository();
		$this->attachment_service = $attachment_service ? $attachment_service : new BookingAttachmentService( $this->attachments, $this->bookings, $this->activity, null, null, $this->authorization );
	}

	/** Register one immutable ticket URL/source identity. */
	public function register_source( array $input, int $actor_id ) {
		$booking = $this->booking( $input['booking_id'] ?? 0 );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$provider = mb_substr( sanitize_key( (string) ( $input['provider'] ?? '' ) ), 0, 64 );
		$key      = TicketSettlementService::opaque_identifier( $input['source_key'] ?? null, 'source_key' );
		$url      = $this->canonical_url( $input['ticket_url'] ?? null );
		if ( '' === $provider || is_wp_error( $key ) || is_wp_error( $url ) || null === $booking['event_id'] ) {
			return is_wp_error( $key ) ? $key : ( is_wp_error( $url ) ? $url : new \WP_Error( 'invalid_ticket_source_identity', __( 'A linked event and valid provider source identity are required.', 'extrachill-events' ), array( 'status' => 400 ) ) );
		}
		$row                 = array(
			'public_id'          => wp_generate_uuid4(),
			'booking_id'         => $booking['id'],
			'event_id'           => $booking['event_id'],
			'venue_term_id'      => $booking['venue_term_id'],
			'provider'           => $provider,
			'source_key'         => $key,
			'source_key_hash'    => hash( 'sha256', $key ),
			'canonical_url'      => $url,
			'url_hash'           => hash( 'sha256', $url ),
			'created_by_user_id' => $actor_id,
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
		);
		$row['request_hash'] = $this->hash( $row, array( 'booking_id', 'event_id', 'venue_term_id', 'provider', 'source_key', 'canonical_url' ) );

		$started = $this->begin_authorized( $booking, $actor_id, VenueAuthorization::ACTION_MANAGE_FINANCES );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking = $this->bookings->get_for_update( $booking['id'] );
		if ( ! is_array( $booking ) || $row['event_id'] !== $booking['event_id'] || $row['venue_term_id'] !== $booking['venue_term_id'] ) {
			return $this->rollback( new \WP_Error( 'ticket_source_booking_changed', __( 'The booking identity changed before the ticket source was recorded.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		$existing = $this->find_source_identity( $booking['id'], $provider, $key, true );
		if ( is_wp_error( $existing ) ) {
			return $this->rollback( $existing );
		}
		if ( is_array( $existing ) ) {
			if ( hash_equals( $existing['request_hash'], $row['request_hash'] ) ) {
				$committed = $this->commit();
				return is_wp_error( $committed ) ? $committed : $this->present_source( $existing );
			}
			return $this->rollback( new \WP_Error( 'ticket_source_idempotency_conflict', __( 'This source identity is already bound to a different ticket URL.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only private source identity.
		if ( false === $wpdb->insert( BookingSchema::ticket_sources_table(), $row ) ) {
			$winner = $this->find_source_identity( $booking['id'], $provider, $key, true );
			if ( is_array( $winner ) && hash_equals( $winner['request_hash'], $row['request_hash'] ) ) {
				$committed = $this->commit();
				return is_wp_error( $committed ) ? $committed : $this->present_source( $winner );
			}
			return $this->rollback( new \WP_Error( 'ticket_source_write_failed', __( 'The ticket source could not be recorded.', 'extrachill-events' ) ) );
		}
		$source = $this->get_source( (int) $wpdb->insert_id, true );
		if ( ! is_array( $source ) ) {
			return $this->rollback( is_wp_error( $source ) ? $source : new \WP_Error( 'ticket_source_write_failed', __( 'The recorded ticket source could not be verified.', 'extrachill-events' ) ) );
		}
		$audit = $this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'ticket_source_recorded',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'external_id'     => (string) $source['id'],
				'idempotency_key' => 'ticket-source:' . $source['id'],
				'payload'         => array(
					'source_id'  => $source['public_id'],
					'provider'   => $source['provider'],
					'source_key' => $source['source_key'],
				),
			)
		);
		if ( is_wp_error( $audit ) ) {
			return $this->rollback( $audit );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $this->present_source( $source );
	}

	/** Return a bounded redacted source list. */
	public function list_sources( int $booking_id, int $actor_id ) {
		$booking = $this->authorized_booking( $booking_id, $actor_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		global $wpdb;
		$table = BookingSchema::ticket_sources_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id ASC LIMIT 200", $booking['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded immutable identities.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'ticket_source_list_failed', __( 'Ticket sources could not be listed.', 'extrachill-events' ) );
		}
		$presented = array();
		foreach ( (array) $rows as $row ) {
			$source = $this->hydrate_source( $row );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			if ( $source['booking_id'] !== $booking['id'] || $source['event_id'] !== $booking['event_id'] || $source['venue_term_id'] !== $booking['venue_term_id'] ) {
				return new \WP_Error(
					'ticket_source_booking_changed',
					__( 'A ticket source no longer matches the booking event and venue identity.', 'extrachill-events' ),
					array(
						'status'    => 409,
						'source_id' => $source['id'],
					)
				);
			}
			$presented[] = $this->present_source( $source );
		}
		return $presented;
	}

	/** Parse one approved private CSV attachment into canonical report inputs. */
	public function csv_report_inputs( array $input, int $actor_id ) {
		$booking = $this->authorized_booking( $input['booking_id'] ?? 0, $actor_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$attachment_id = $this->positive_integer( $input['attachment_id'] ?? null, 'attachment_id' );
		$source_id     = $this->positive_integer( $input['ticket_source_id'] ?? null, 'ticket_source_id' );
		if ( is_wp_error( $attachment_id ) || is_wp_error( $source_id ) ) {
			return is_wp_error( $attachment_id ) ? $attachment_id : $source_id;
		}
		$source = $this->get_source( $source_id );
		if ( ! is_array( $source ) || $source['booking_id'] !== $booking['id'] || $source['event_id'] !== $booking['event_id'] || $source['venue_term_id'] !== $booking['venue_term_id'] ) {
			return new \WP_Error( 'ticket_source_not_found', __( 'The ticket source was not found for this booking.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$attachment = $this->attachments->get_for_booking( $booking['id'], $attachment_id );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		if ( 'active' !== $attachment['state'] || 'text/csv' !== $attachment['mime_type'] || 'other_private_evidence' !== $attachment['purpose'] ) {
			return new \WP_Error( 'sales_csv_attachment_invalid', __( 'CSV sales import requires an active approved private evidence attachment.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$descriptor = $this->attachment_service->download_descriptor( $booking['id'], $attachment_id, $actor_id );
		if ( is_wp_error( $descriptor ) ) {
			return $descriptor;
		}
		$stream = $this->attachment_service->open_download_stream( $booking['id'], $attachment_id, $descriptor['stream_token'], $actor_id, $descriptor['correlation_id'] );
		if ( is_wp_error( $stream ) ) {
			return $stream;
		}
		if ( ! is_resource( $stream ) ) {
			return new \WP_Error( 'sales_csv_stream_invalid', __( 'The private CSV stream could not be opened.', 'extrachill-events' ), array( 'status' => 502 ) );
		}
		$verified = $this->verified_csv_stream( $stream, $attachment );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the approved private stream after bounded parsing.
		$result = $verified;
		if ( is_resource( $verified ) ) {
			$result = $this->parse_csv_stream( $verified, $booking, $source, $attachment );
			fclose( $verified ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the verified bounded copy.
		}
		$outcome = is_wp_error( $result ) ? 'failed' : 'completed';
		$logged  = $this->attachment_service->record_delivery_outcome( $booking['id'], $attachment_id, $descriptor['correlation_id'], $outcome, 'completed' === $outcome ? $attachment['byte_size'] : 0, $actor_id );
		return is_wp_error( $logged ) ? $logged : $result;
	}

	/** Derive deterministic diagnostics and current admission state. */
	public function diagnostics( int $booking_id, int $actor_id ) {
		$booking = $this->authorized_booking( $booking_id, $actor_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		return is_wp_error( $booking ) ? $booking : $this->diagnostics_for_booking( $booking );
	}

	/** Append an optimistic operator decision; prior decisions remain immutable. */
	public function resolve( array $input, int $actor_id ) {
		$booking = $this->booking( $input['booking_id'] ?? 0 );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$report_id = $this->positive_integer( $input['report_id'] ?? null, 'report_id' );
		$expected  = $input['expected_version'] ?? null;
		$decision  = sanitize_key( (string) ( $input['decision'] ?? '' ) );
		$reason    = mb_substr( sanitize_text_field( (string) ( $input['reason'] ?? '' ) ), 0, 1000 );
		$source_id = null;
		if ( array_key_exists( 'ticket_source_id', $input ) && null !== $input['ticket_source_id'] ) {
			$source_id = $this->positive_integer( $input['ticket_source_id'], 'ticket_source_id' );
		}
		if ( is_wp_error( $report_id ) || is_wp_error( $source_id ) || ! is_int( $expected ) || $expected < 0 || ! in_array( $decision, self::DECISIONS, true ) || '' === $reason ) {
			return is_wp_error( $report_id ) ? $report_id : ( is_wp_error( $source_id ) ? $source_id : new \WP_Error( 'invalid_sales_resolution', __( 'A valid versioned reconciliation decision and reason are required.', 'extrachill-events' ), array( 'status' => 400 ) ) );
		}
		$started = $this->begin_authorized( $booking, $actor_id, VenueAuthorization::ACTION_MANAGE_FINANCES );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking    = $this->bookings->get_for_update( $booking['id'] );
		$settlement = is_array( $booking ) ? $this->get_settlement_for_booking( $booking['id'], true ) : null;
		if ( is_wp_error( $settlement ) ) {
			return $this->rollback( $settlement );
		}
		if ( is_array( $settlement ) ) {
			return $this->rollback(
				new \WP_Error(
					'sales_resolution_settlement_frozen',
					__( 'Ticket-sales reconciliation cannot change after settlement is finalized.', 'extrachill-events' ),
					array(
						'status'        => 409,
						'settlement_id' => (int) $settlement['id'],
					)
				)
			);
		}
		$report = $this->get_report_row( $report_id, true );
		if ( ! is_array( $booking ) || ! is_array( $report ) || (int) $report['booking_id'] !== (int) $booking['id'] ) {
			return $this->rollback( new \WP_Error( 'sales_report_not_found', __( 'The sales report was not found for this booking.', 'extrachill-events' ), array( 'status' => 404 ) ) );
		}
		if ( (int) $report['event_id'] !== (int) $booking['event_id'] || (int) $report['venue_term_id'] !== (int) $booking['venue_term_id'] ) {
			return $this->rollback(
				new \WP_Error(
					'sales_report_booking_changed',
					__( 'Ticket-sales evidence no longer matches the booking event and venue identity.', 'extrachill-events' ),
					array(
						'status'    => 409,
						'report_id' => $report_id,
					)
				)
			);
		}
		$latest = $this->latest_resolution( $report_id, true );
		if ( is_wp_error( $latest ) ) {
			return $this->rollback( $latest );
		}
		$current_version = is_array( $latest ) ? $latest['version'] : 0;
		if ( is_array( $latest ) && $current_version === $expected + 1 ) {
			$retry      = array(
				'booking_id'               => $booking['id'],
				'report_id'                => $report_id,
				'venue_term_id'            => $booking['venue_term_id'],
				'version'                  => $expected + 1,
				'decision'                 => $decision,
				'ticket_source_id'         => $source_id,
				'supersedes_resolution_id' => $latest['supersedes_resolution_id'],
				'reason'                   => $reason,
				'created_by_user_id'       => $latest['created_by_user_id'],
				'created_at'               => $latest['created_at'],
			);
			$retry_hash = $this->hash( $retry, array( 'booking_id', 'report_id', 'venue_term_id', 'version', 'decision', 'ticket_source_id', 'supersedes_resolution_id', 'reason', 'created_by_user_id', 'created_at' ) );
			if ( hash_equals( $latest['request_hash'], $retry_hash ) ) {
				$committed = $this->commit();
				return is_wp_error( $committed ) ? $committed : $latest;
			}
		}
		if ( $expected !== $current_version ) {
			return $this->rollback(
				new \WP_Error(
					'sales_resolution_version_conflict',
					__( 'The reconciliation decision changed since it was read.', 'extrachill-events' ),
					array(
						'status'          => 409,
						'current_version' => $current_version,
					)
				)
			);
		}
		if ( null !== $source_id ) {
			$source = $this->get_source( $source_id, true );
			if ( ! is_array( $source ) || $source['booking_id'] !== $booking['id'] || $source['event_id'] !== $booking['event_id'] || $source['venue_term_id'] !== $booking['venue_term_id'] || $source['provider'] !== $report['provider'] ) {
				return $this->rollback( new \WP_Error( 'ticket_source_not_found', __( 'The ticket source was not found for this booking.', 'extrachill-events' ), array( 'status' => 404 ) ) );
			}
		}
		$row                 = array(
			'public_id'                => wp_generate_uuid4(),
			'booking_id'               => $booking['id'],
			'report_id'                => $report_id,
			'venue_term_id'            => $booking['venue_term_id'],
			'version'                  => $current_version + 1,
			'decision'                 => $decision,
			'ticket_source_id'         => $source_id,
			'supersedes_resolution_id' => is_array( $latest ) ? $latest['id'] : null,
			'reason'                   => $reason,
			'created_by_user_id'       => $actor_id,
			'created_at'               => gmdate( 'Y-m-d H:i:s' ),
		);
		$row['request_hash'] = $this->hash( $row, array( 'booking_id', 'report_id', 'venue_term_id', 'version', 'decision', 'ticket_source_id', 'supersedes_resolution_id', 'reason', 'created_by_user_id', 'created_at' ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only operator resolution.
		if ( false === $wpdb->insert( BookingSchema::sales_resolutions_table(), $row ) ) {
			$winner = $this->resolution_version( $report_id, $row['version'], true );
			if ( is_array( $winner ) && hash_equals( $winner['request_hash'], $row['request_hash'] ) ) {
				$committed = $this->commit();
				return is_wp_error( $committed ) ? $committed : $winner;
			}
			return $this->rollback( new \WP_Error( 'sales_resolution_version_conflict', __( 'A different reconciliation decision won this version.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		$resolution = $this->resolution_version( $report_id, $row['version'], true );
		$audit      = is_array( $resolution )
			? $this->activity->append(
				array(
					'booking_id'      => $booking['id'],
					'kind'            => 'ticket_sales_reconciled',
					'actor_type'      => 'user',
					'actor_id'        => $actor_id,
					'external_id'     => (string) $report_id,
					'idempotency_key' => 'sales-resolution:' . $resolution['id'],
					'payload'         => array(
						'report_id' => $report_id,
						'version'   => $resolution['version'],
						'decision'  => $decision,
					),
				)
			)
			: $resolution;
		if ( is_wp_error( $audit ) || ! is_array( $resolution ) ) {
			return $this->rollback( is_wp_error( $audit ) ? $audit : new \WP_Error( 'sales_resolution_write_failed', __( 'The reconciliation decision could not be verified.', 'extrachill-events' ) ) );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $resolution;
	}

	/** Internal settlement boundary: reject unresolved evidence and return admitted reports. */
	public function admitted_reports( array $booking, array $reports ) {
		$diagnostics = $this->derive_diagnostics( $booking, $reports );
		if ( is_wp_error( $diagnostics ) ) {
			return $diagnostics;
		}
		$admitted = array();
		foreach ( $diagnostics['reports'] as $item ) {
			if ( 'unresolved' === $item['state'] ) {
				return new \WP_Error(
					'settlement_reconciliation_required',
					__( 'Ticket-sales evidence must be reconciled before settlement.', 'extrachill-events' ),
					array(
						'status'    => 409,
						'report_id' => $item['report_id'],
						'issues'    => $item['issues'],
					)
				);
			}
			if ( 'admitted' === $item['state'] ) {
				$admitted[] = $reports[ $item['report_index'] ];
			}
		}
		return $admitted;
	}

	/** Return the immutable reconciliation decision bound into settlement evidence. */
	public function settlement_resolution_evidence( int $report_id, bool $lock = false ) {
		$resolution = $this->latest_resolution( $report_id, $lock );
		if ( is_wp_error( $resolution ) || null === $resolution ) {
			return $resolution;
		}
		return array(
			'id'               => $resolution['id'],
			'version'          => $resolution['version'],
			'decision'         => $resolution['decision'],
			'ticket_source_id' => $resolution['ticket_source_id'],
			'request_hash'     => $resolution['request_hash'],
		);
	}

	/** Bind a new observation to current immutable source and authenticated bytes. */
	public function validate_provenance( array $report, array $booking, ?array $csv_certification = null ) {
		$source_id = $report['ticket_source_id'] ?? null;
		$source    = null;
		if ( null !== $source_id ) {
			$source = $this->get_source( (int) $source_id, true );
			if ( ! is_array( $source ) || $source['booking_id'] !== $booking['id'] || $source['event_id'] !== $booking['event_id'] || $source['venue_term_id'] !== $booking['venue_term_id'] || $source['provider'] !== $report['provider'] ) {
				return new \WP_Error( 'invalid_sales_report_source_identity', __( 'The ticket source does not match this booking, event, and provider.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
		}
		$attachment_id = $report['evidence_attachment_id'] ?? null;
		if ( 'csv_certified' === $report['source_type'] ) {
			if ( null === $attachment_id || ! is_array( $source ) || ! is_array( $csv_certification ) ) {
				return new \WP_Error( 'sales_csv_attachment_required', __( 'Certified CSV evidence requires its approved private attachment.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$attachment = $this->attachments->get_for_booking( $booking['id'], (int) $attachment_id );
			if ( is_wp_error( $attachment ) || 'active' !== ( $attachment['state'] ?? '' ) || 'text/csv' !== ( $attachment['mime_type'] ?? '' ) || 'other_private_evidence' !== ( $attachment['purpose'] ?? '' ) ) {
				return is_wp_error( $attachment ) ? $attachment : new \WP_Error( 'sales_csv_attachment_invalid', __( 'Certified CSV evidence requires an active approved private CSV attachment.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$expected = array(
				'ticket_source_id'                 => $source['id'],
				'ticket_source_request_hash'       => $source['request_hash'],
				'evidence_attachment_id'           => $attachment['id'],
				'evidence_attachment_request_hash' => $attachment['request_hash'],
				'evidence_content_hash'            => $attachment['content_hash'],
				'evidence_byte_size'               => $attachment['byte_size'],
			);
			if ( $expected !== $csv_certification ) {
				return new \WP_Error( 'sales_csv_certification_invalid', __( 'Certified CSV evidence no longer matches the authenticated source and attachment bytes.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
		} elseif ( null !== $attachment_id ) {
			return new \WP_Error( 'sales_report_attachment_invalid', __( 'Only certified CSV evidence may bind a private file.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return array(
			'provenance_version'               => 2,
			'ticket_source_request_hash'       => is_array( $source ) ? $source['request_hash'] : null,
			'evidence_attachment_request_hash' => isset( $attachment ) ? $attachment['request_hash'] : null,
			'evidence_content_hash'            => isset( $attachment ) ? $attachment['content_hash'] : null,
			'evidence_byte_size'               => isset( $attachment ) ? $attachment['byte_size'] : null,
		);
	}

	/** Revalidate and project the exact provenance frozen by formula v3. */
	public function settlement_provenance_evidence( array $report, array $booking, ?array $resolution, int $actor_id, bool $lock, bool $authenticate_bytes = true ) {
		$source_id = is_array( $resolution ) && null !== $resolution['ticket_source_id'] ? $resolution['ticket_source_id'] : $report['ticket_source_id'];
		if ( null === $source_id ) {
			return new \WP_Error( 'settlement_source_evidence_missing', __( 'Settlement evidence has no immutable ticket source attribution.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$source = $this->get_source( (int) $source_id, $lock );
		if ( ! is_array( $source ) || $source['booking_id'] !== $booking['id'] || $source['event_id'] !== $booking['event_id'] || $source['venue_term_id'] !== $booking['venue_term_id'] || $source['provider'] !== $report['provider'] ) {
			return new \WP_Error( 'settlement_source_evidence_invalid', __( 'Settlement ticket-source evidence is missing, corrupt, or belongs to another booking.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( 2 <= (int) ( $report['provenance_version'] ?? 1 ) && $report['ticket_source_id'] === $source['id'] && ! hash_equals( (string) $report['ticket_source_request_hash'], $source['request_hash'] ) ) {
			return new \WP_Error( 'settlement_source_evidence_invalid', __( 'Settlement ticket-source evidence no longer matches its observation hash.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		$evidence = array(
			'ticket_source_id'           => $source['id'],
			'ticket_source_request_hash' => $source['request_hash'],
			'attachment'                 => null,
		);
		if ( 'csv_certified' !== $report['source_type'] ) {
			return $evidence;
		}
		if ( 2 > (int) ( $report['provenance_version'] ?? 1 ) ) {
			return new \WP_Error( 'settlement_csv_evidence_legacy', __( 'Legacy CSV observations were not authenticated by the certified importer and cannot enter a new settlement.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$attachment = $this->attachments->get_for_booking( $booking['id'], (int) $report['evidence_attachment_id'] );
		if ( is_wp_error( $attachment ) || 'active' !== ( $attachment['state'] ?? '' ) || 'text/csv' !== ( $attachment['mime_type'] ?? '' ) || 'other_private_evidence' !== ( $attachment['purpose'] ?? '' ) ) {
			return new \WP_Error( 'settlement_csv_evidence_invalid', __( 'Settlement CSV evidence is retired, deleted, purged, or invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		foreach ( array(
			'evidence_attachment_request_hash' => 'request_hash',
			'evidence_content_hash'            => 'content_hash',
			'evidence_byte_size'               => 'byte_size',
		) as $report_field => $attachment_field ) {
			if ( $report[ $report_field ] !== $attachment[ $attachment_field ] ) {
				return new \WP_Error( 'settlement_csv_evidence_invalid', __( 'Settlement CSV attachment metadata no longer matches its observation hash.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
		}
		$verified = $authenticate_bytes ? $this->authenticate_attachment_bytes( $booking, $attachment, $actor_id ) : array(
			'content_hash' => $attachment['content_hash'],
			'byte_size'    => $attachment['byte_size'],
		);
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$evidence['attachment'] = array(
			'id'           => $attachment['id'],
			'request_hash' => $attachment['request_hash'],
			'content_hash' => $verified['content_hash'],
			'byte_size'    => $verified['byte_size'],
		);
		return $evidence;
	}

	/** Authenticate the complete current private object against immutable metadata. */
	private function authenticate_attachment_bytes( array $booking, array $attachment, int $actor_id ) {
		$descriptor = $this->attachment_service->download_descriptor( $booking['id'], $attachment['id'], $actor_id );
		if ( is_wp_error( $descriptor ) ) {
			return new \WP_Error( 'settlement_csv_evidence_invalid', __( 'Settlement CSV bytes are unavailable for authentication.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$stream = $this->attachment_service->open_download_stream( $booking['id'], $attachment['id'], $descriptor['stream_token'], $actor_id, $descriptor['correlation_id'] );
		if ( is_wp_error( $stream ) || ! is_resource( $stream ) ) {
			return new \WP_Error( 'settlement_csv_evidence_invalid', __( 'Settlement CSV bytes are unavailable for authentication.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$verified = $this->verified_csv_stream( $stream, $attachment );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the approved private stream after complete authentication.
		if ( is_resource( $verified ) ) {
			fclose( $verified ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- No parsing is needed during settlement revalidation.
		}
		$outcome = is_wp_error( $verified ) ? 'failed' : 'completed';
		$logged  = $this->attachment_service->record_delivery_outcome( $booking['id'], $attachment['id'], $descriptor['correlation_id'], $outcome, 'completed' === $outcome ? $attachment['byte_size'] : 0, $actor_id );
		if ( is_wp_error( $verified ) || is_wp_error( $logged ) ) {
			return new \WP_Error( 'settlement_csv_evidence_invalid', __( 'Settlement CSV bytes failed complete authentication.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return array(
			'content_hash' => $attachment['content_hash'],
			'byte_size'    => $attachment['byte_size'],
		);
	}

	private function diagnostics_for_booking( array $booking ) {
		global $wpdb;
		$table = BookingSchema::sales_reports_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id ASC LIMIT 1001", $booking['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded reconciliation snapshot.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'sales_report_list_failed', __( 'Ticket-sales evidence could not be read for reconciliation.', 'extrachill-events' ) );
		}
		if ( 1000 < count( (array) $rows ) ) {
			return new \WP_Error( 'sales_reconciliation_too_large', __( 'Ticket-sales evidence exceeds the bounded reconciliation limit.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$reports = array();
		foreach ( (array) $rows as $row ) {
			$reports[] = $this->hydrate_report_row( $row );
		}
		return $this->derive_diagnostics( $booking, $reports );
	}

	private function derive_diagnostics( array $booking, array $reports ) {
		$items = array();
		foreach ( $reports as $index => $report ) {
			if ( is_wp_error( $report ) ) {
				return $report;
			}
			if ( $report['booking_id'] !== $booking['id'] || $report['event_id'] !== $booking['event_id'] || $report['venue_term_id'] !== $booking['venue_term_id'] ) {
				return new \WP_Error(
					'sales_report_booking_changed',
					__( 'Ticket-sales evidence no longer matches the booking event and venue identity.', 'extrachill-events' ),
					array(
						'status'    => 409,
						'report_id' => $report['id'],
					)
				);
			}
			$resolution = $this->latest_resolution( $report['id'] );
			if ( is_wp_error( $resolution ) ) {
				return $resolution;
			}
			$source_id = is_array( $resolution ) && null !== $resolution['ticket_source_id'] ? $resolution['ticket_source_id'] : $report['ticket_source_id'];
			$issues    = array();
			if ( null === $source_id ) {
				$issues[] = 'unattributed';
			}
			if ( 'csv_certified' === $report['source_type'] && null === $report['evidence_attachment_id'] ) {
				$issues[] = 'file_missing';
			}
			$deal_currency = strtoupper( (string) ( $booking['confirmed_deal']['data']['currency'] ?? $booking['deal']['data']['currency'] ?? '' ) );
			if ( '' !== $deal_currency && $deal_currency !== $report['currency'] ) {
				$issues[] = 'currency_mismatch';
			}
			$items[ $index ] = array(
				'report_id'              => $report['id'],
				'report_index'           => $index,
				'ticket_source_id'       => $source_id,
				'reconciliation_version' => is_array( $resolution ) ? $resolution['version'] : 0,
				'decision'               => is_array( $resolution ) ? $resolution['decision'] : null,
				'issues'                 => $issues,
			);
		}
		foreach ( $reports as $left => $report ) {
			foreach ( $reports as $right => $candidate ) {
				if ( $right <= $left || $report['provider'] !== $candidate['provider'] || $report['period_start'] > $candidate['period_end'] || $candidate['period_start'] > $report['period_end'] ) {
					continue;
				}
				$financial = array( 'tickets_sold', 'tickets_refunded', 'gross_minor', 'fees_minor', 'tax_minor', 'refunds_minor', 'net_minor', 'currency' );
				$exact     = $report['period_start'] === $candidate['period_start'] && $report['period_end'] === $candidate['period_end'];
				$same      = true;
				foreach ( $financial as $field ) {
					$same = $same && $report[ $field ] === $candidate[ $field ];
				}
				$issue                       = $exact ? ( $same ? 'duplicate' : 'contradictory' ) : 'overlap';
				$items[ $left ]['issues'][]  = $issue;
				$items[ $right ]['issues'][] = $issue;
			}
		}
		$counts = array(
			'admitted'   => 0,
			'excluded'   => 0,
			'unresolved' => 0,
		);
		foreach ( $items as &$item ) {
			$item['issues'] = array_values( array_unique( $item['issues'] ) );
			if ( 'exclude' === $item['decision'] ) {
				$item['state'] = 'excluded';
			} elseif ( 'admit' === $item['decision'] || empty( $item['issues'] ) ) {
				$item['state'] = 'admitted';
			} else {
				$item['state'] = 'unresolved';
			}
			++$counts[ $item['state'] ];
		}
		unset( $item );
		return array(
			'booking_id'           => $booking['id'],
			'reports'              => array_values( $items ),
			'counts'               => $counts,
			'ready_for_settlement' => 0 === $counts['unresolved'] && 0 < $counts['admitted'],
		);
	}

	private function parse_csv_stream( $stream, array $booking, array $source, array $attachment ) {
		$header = $this->read_csv_record( $stream, 1 );
		if ( is_wp_error( $header ) ) {
			return $header;
		}
		if ( self::CSV_HEADER !== $header ) {
			return new \WP_Error( 'sales_csv_header_invalid', __( 'The CSV header does not match the canonical ticket-sales contract.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$inputs        = array();
		$certification = array(
			'ticket_source_id'                 => $source['id'],
			'ticket_source_request_hash'       => $source['request_hash'],
			'evidence_attachment_id'           => $attachment['id'],
			'evidence_attachment_request_hash' => $attachment['request_hash'],
			'evidence_content_hash'            => $attachment['content_hash'],
			'evidence_byte_size'               => $attachment['byte_size'],
		);
		while ( true ) {
			$values = $this->read_csv_record( $stream, count( $inputs ) + 2 );
			if ( false === $values ) {
				break;
			}
			if ( is_wp_error( $values ) ) {
				return $values;
			}
			if ( array( '' ) === $values ) {
				continue;
			}
			if ( count( $values ) !== count( self::CSV_HEADER ) || self::MAX_CSV_ROWS < count( $inputs ) + 1 ) {
				return new \WP_Error(
					'sales_csv_row_invalid',
					__( 'The CSV contains a malformed row or exceeds the import limit.', 'extrachill-events' ),
					array(
						'status' => 400,
						'row'    => count( $inputs ) + 2,
					)
				);
			}
			$row = array_combine( self::CSV_HEADER, $values );
			foreach ( array( 'tickets_sold', 'tickets_refunded', 'gross_minor', 'fees_minor', 'tax_minor', 'refunds_minor', 'net_minor' ) as $field ) {
				if ( ! preg_match( '/^-?\d+$/', $row[ $field ] ) || ltrim( $row[ $field ], '+' ) !== (string) (int) $row[ $field ] ) {
					return new \WP_Error(
						'sales_csv_integer_invalid',
						__( 'CSV counts and money must use canonical signed integers.', 'extrachill-events' ),
						array(
							'status' => 400,
							'row'    => count( $inputs ) + 2,
							'field'  => $field,
						)
					);
				}
				$row[ $field ] = (int) $row[ $field ];
			}
			$inputs[] = array_merge(
				$row,
				array(
					'booking_id'             => $booking['id'],
					'provider'               => $source['provider'],
					'source_type'            => 'csv_certified',
					'ticket_source_id'       => $source['id'],
					'evidence_attachment_id' => $attachment['id'],
					'source'                 => array(
						'attachment_id' => $attachment['public_id'],
						'row'           => count( $inputs ) + 2,
					),
					'_certified_evidence'    => $certification,
				)
			);
		}
		return empty( $inputs ) ? new \WP_Error( 'sales_csv_empty', __( 'The CSV contains no ticket-sales observations.', 'extrachill-events' ), array( 'status' => 400 ) ) : $inputs;
	}

	/** Copy and authenticate every private byte before parsing any observation. */
	private function verified_csv_stream( $stream, array $attachment ) {
		$verified = fopen( 'php://temp/maxmemory:' . BookingAttachmentPolicy::MAX_BYTES, 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded in-process verification copy.
		if ( false === $verified ) {
			return new \WP_Error( 'sales_csv_stream_invalid', __( 'A bounded CSV verification stream could not be created.', 'extrachill-events' ), array( 'status' => 500 ) );
		}
		$hash  = hash_init( 'sha256' );
		$bytes = 0;
		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads only the approved private stream.
			if ( false === $chunk ) {
				fclose( $verified ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Error-path cleanup.
				return new \WP_Error( 'sales_csv_stream_invalid', __( 'The private CSV stream could not be read completely.', 'extrachill-events' ), array( 'status' => 502 ) );
			}
			$bytes += strlen( $chunk );
			if ( BookingAttachmentPolicy::MAX_BYTES < $bytes || $attachment['byte_size'] < $bytes ) {
				fclose( $verified ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Error-path cleanup.
				return new \WP_Error( 'sales_csv_evidence_mismatch', __( 'The streamed CSV exceeds its immutable attachment evidence.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			hash_update( $hash, $chunk );
			if ( strlen( $chunk ) !== fwrite( $verified, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes only to the bounded verification stream.
				fclose( $verified ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Error-path cleanup.
				return new \WP_Error( 'sales_csv_stream_invalid', __( 'The CSV verification copy could not be completed.', 'extrachill-events' ), array( 'status' => 500 ) );
			}
		}
		if ( $bytes !== $attachment['byte_size'] || ! hash_equals( $attachment['content_hash'], hash_final( $hash ) ) ) {
			fclose( $verified ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Error-path cleanup.
			return new \WP_Error( 'sales_csv_evidence_mismatch', __( 'The streamed CSV does not match its immutable attachment evidence.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		rewind( $verified );
		return $verified;
	}

	/** Read one physical CSV record without allowing multiline or overlong fields. */
	private function read_csv_record( $stream, int $row ) {
		$line = fgets( $stream, self::MAX_CSV_LINE_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- Explicit bounded physical record.
		if ( false === $line ) {
			return false;
		}
		$has_newline = "\n" === substr( $line, -1 );
		if ( self::MAX_CSV_LINE_BYTES < strlen( $line ) || ( ! $has_newline && ! feof( $stream ) ) ) {
			return $this->csv_record_error( 'sales_csv_line_too_long', __( 'A CSV record exceeds the bounded line length.', 'extrachill-events' ), $row );
		}
		$line   = rtrim( $line, "\r\n" );
		$values = str_getcsv( $line );
		if ( false !== strpos( $line, '"' ) && 1 === substr_count( $line, '"' ) % 2 ) {
			return $this->csv_record_error( 'sales_csv_multiline_forbidden', __( 'Multiline or unterminated CSV fields are not supported.', 'extrachill-events' ), $row );
		}
		return $values;
	}

	private function csv_record_error( string $code, string $message, int $row ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array(
				'status' => 400,
				'row'    => $row,
			)
		);
	}

	private function canonical_url( $value ) {
		if ( ! is_string( $value ) || 2048 < strlen( $value ) ) {
			return new \WP_Error( 'invalid_ticket_source_url', __( 'The ticket source URL is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$url   = esc_url_raw( trim( $value ), array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( '' === $url || ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return new \WP_Error( 'invalid_ticket_source_url', __( 'The ticket source URL must be an HTTP or HTTPS URL without credentials or a fragment.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return $url;
	}

	private function present_source( array $source ): array {
		$parts = wp_parse_url( $source['canonical_url'] );
		$port  = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return array(
			'id'            => $source['id'],
			'public_id'     => $source['public_id'],
			'booking_id'    => $source['booking_id'],
			'event_id'      => $source['event_id'],
			'venue_term_id' => $source['venue_term_id'],
			'provider'      => $source['provider'],
			'source_key'    => $source['source_key'],
			'display_url'   => strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] ) . $port,
			'created_at'    => $source['created_at'],
		);
	}

	private function authorized_booking( $booking_id, int $actor_id, string $action ) {
		$booking = $this->booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], $action );
		return true === $allowed ? $booking : $this->denied( $allowed );
	}

	private function booking( $booking_id ) {
		$id = $this->positive_integer( $booking_id, 'booking_id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$booking = $this->bookings->get( $id );
		return is_array( $booking ) ? $booking : ( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ), array( 'status' => 404 ) ) );
	}

	private function begin_authorized( array $booking, int $actor_id, string $action ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Financial attribution aggregate boundary.
			return new \WP_Error( 'sales_reconciliation_transaction_start_failed', __( 'The ticket reconciliation transaction could not start.', 'extrachill-events' ) );
		}
		$this->transaction_active = true;
		$table                    = BookingSchema::memberships_table();
		$members                  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $booking['venue_term_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks exact venue authority.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'sales_reconciliation_authorization_lock_failed', __( 'Venue finance authority could not be locked.', 'extrachill-events' ) ) );
		}
		$allowed = $this->authorization->authorize_locked( $actor_id, $booking['venue_term_id'], $action, (array) $members );
		return true === $allowed ? true : $this->rollback( $this->denied( $allowed ) );
	}

	private function find_source_identity( int $booking_id, string $provider, string $source_key, bool $lock = false ) {
		global $wpdb;
		$table = BookingSchema::ticket_sources_table();
		$query = "SELECT * FROM {$table} WHERE booking_id = %d AND provider = %s AND source_key_hash = %s LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' );
		$row   = $wpdb->get_row( $wpdb->prepare( $query, $booking_id, $provider, hash( 'sha256', $source_key ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stable source lookup.
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'ticket_source_read_failed', __( 'The ticket source could not be read.', 'extrachill-events' ) ) : ( is_array( $row ) ? $this->hydrate_source( $row ) : null );
	}

	private function get_source( int $id, bool $lock = false ) {
		global $wpdb;
		$table = BookingSchema::ticket_sources_table();
		$query = "SELECT * FROM {$table} WHERE id = %d LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' );
		$row   = $wpdb->get_row( $wpdb->prepare( $query, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immutable source lookup.
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'ticket_source_read_failed', __( 'The ticket source could not be read.', 'extrachill-events' ) ) : ( is_array( $row ) ? $this->hydrate_source( $row ) : null );
	}

	private function hydrate_source( array $row ) {
		foreach ( array( 'id', 'booking_id', 'event_id', 'venue_term_id', 'created_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$stored        = strtolower( (string) $row['request_hash'] );
		$identity_hash = strtolower( (string) ( $row['source_key_hash'] ?? '' ) );
		return preg_match( '/^[a-f0-9]{64}$/', $identity_hash ) && hash_equals( $identity_hash, hash( 'sha256', $row['source_key'] ) ) && preg_match( '/^[a-f0-9]{64}$/', $stored ) && hash_equals( $stored, $this->hash( $row, array( 'booking_id', 'event_id', 'venue_term_id', 'provider', 'source_key', 'canonical_url' ) ) )
			? $row
			: new \WP_Error(
				'ticket_source_integrity_failed',
				__( 'Stored ticket source identity failed its immutable content check.', 'extrachill-events' ),
				array(
					'status'    => 409,
					'source_id' => $row['id'],
				)
			);
	}

	private function latest_resolution( int $report_id, bool $lock = false ) {
		global $wpdb;
		$table = BookingSchema::sales_resolutions_table();
		$query = "SELECT * FROM {$table} WHERE report_id = %d ORDER BY version DESC LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' );
		$row   = $wpdb->get_row( $wpdb->prepare( $query, $report_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current append-only decision.
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'sales_resolution_read_failed', __( 'The reconciliation decision could not be read.', 'extrachill-events' ) ) : ( is_array( $row ) ? $this->hydrate_resolution( $row ) : null );
	}

	private function resolution_version( int $report_id, int $version, bool $lock = false ) {
		global $wpdb;
		$table = BookingSchema::sales_resolutions_table();
		$query = "SELECT * FROM {$table} WHERE report_id = %d AND version = %d LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' );
		$row   = $wpdb->get_row( $wpdb->prepare( $query, $report_id, $version ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact immutable decision.
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'sales_resolution_read_failed', __( 'The reconciliation decision could not be read.', 'extrachill-events' ) ) : ( is_array( $row ) ? $this->hydrate_resolution( $row ) : null );
	}

	private function hydrate_resolution( array $row ) {
		foreach ( array( 'id', 'booking_id', 'report_id', 'venue_term_id', 'version', 'created_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		foreach ( array( 'ticket_source_id', 'supersedes_resolution_id' ) as $field ) {
			$row[ $field ] = null === $row[ $field ] ? null : (int) $row[ $field ];
		}
		$stored = strtolower( (string) $row['request_hash'] );
		return preg_match( '/^[a-f0-9]{64}$/', $stored ) && hash_equals( $stored, $this->hash( $row, array( 'booking_id', 'report_id', 'venue_term_id', 'version', 'decision', 'ticket_source_id', 'supersedes_resolution_id', 'reason', 'created_by_user_id', 'created_at' ) ) )
			? $row
			: new \WP_Error(
				'sales_resolution_integrity_failed',
				__( 'Stored reconciliation decision failed its immutable content check.', 'extrachill-events' ),
				array(
					'status'        => 409,
					'resolution_id' => $row['id'],
				)
			);
	}

	private function get_report_row( int $report_id, bool $lock = false ) {
		global $wpdb;
		$table = BookingSchema::sales_reports_table();
		$query = "SELECT * FROM {$table} WHERE id = %d LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' );
		$row   = $wpdb->get_row( $wpdb->prepare( $query, $report_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact report resolution target.
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'sales_report_read_failed', __( 'Ticket-sales evidence could not be read.', 'extrachill-events' ) ) : $row;
	}

	private function get_settlement_for_booking( int $booking_id, bool $lock = false ) {
		global $wpdb;
		$table = BookingSchema::settlements_table();
		$query = "SELECT * FROM {$table} WHERE booking_id = %d LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' );
		$row   = $wpdb->get_row( $wpdb->prepare( $query, $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Frozen settlement boundary.
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'settlement_read_failed', __( 'The booking settlement could not be read.', 'extrachill-events' ) ) : $row;
	}

	private function hydrate_report_row( array $row ) {
		$source = json_decode( (string) ( $row['source_payload'] ?? '' ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $source ) || 1 !== ( $source['version'] ?? null ) || ! array_key_exists( 'data', $source ) ) {
			return new \WP_Error( 'sales_report_source_invalid', __( 'Stored ticket-sales source evidence is malformed.', 'extrachill-events' ) );
		}
		foreach ( array( 'id', 'booking_id', 'event_id', 'venue_term_id', 'tickets_sold', 'tickets_refunded', 'gross_minor', 'fees_minor', 'tax_minor', 'refunds_minor', 'net_minor', 'provenance_version' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		foreach ( array( 'ticket_source_id', 'evidence_attachment_id' ) as $field ) {
			$row[ $field ] = null === ( $row[ $field ] ?? null ) ? null : (int) $row[ $field ];
		}
		$row['evidence_byte_size'] = null === ( $row['evidence_byte_size'] ?? null ) ? null : (int) $row['evidence_byte_size'];
		$row['corrects_report_id'] = null === $row['corrects_report_id'] ? null : (int) $row['corrects_report_id'];
		$row['source']             = $source['data'];
		$stored_hash               = strtolower( (string) ( $row['request_hash'] ?? '' ) );
		$identity_hash             = strtolower( (string) ( $row['external_report_id_hash'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $identity_hash ) || ! hash_equals( $identity_hash, hash( 'sha256', $row['external_report_id'] ) ) || ! preg_match( '/^[a-f0-9]{64}$/', $stored_hash ) || ! hash_equals( $stored_hash, TicketSettlementService::report_request_hash( $row ) ) ) {
			return new \WP_Error(
				'sales_report_integrity_failed',
				__( 'Stored ticket-sales evidence failed its immutable content check.', 'extrachill-events' ),
				array(
					'status'    => 409,
					'report_id' => $row['id'],
				)
			);
		}
		return $row;
	}

	private function hash( array $row, array $fields ): string {
		$payload = array();
		foreach ( $fields as $field ) {
			$payload[ $field ] = $row[ $field ] ?? null;
		}
		return hash( 'sha256', wp_json_encode( $payload ) );
	}

	private function positive_integer( $value, string $field ) {
		return is_int( $value ) && 0 < $value ? $value : new \WP_Error(
			'invalid_ticket_reconciliation_integer',
			__( 'A positive integer is required.', 'extrachill-events' ),
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	private function denied( $result ): \WP_Error {
		return is_wp_error( $result ) ? $result : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	private function commit() {
		global $wpdb;
		$result                   = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Financial attribution aggregate boundary.
		$this->transaction_active = false;
		return false === $result ? new \WP_Error( 'sales_reconciliation_commit_uncertain', __( 'The reconciliation transaction outcome could not be confirmed.', 'extrachill-events' ) ) : true;
	}

	private function rollback( \WP_Error $error ) {
		global $wpdb;
		if ( $this->transaction_active ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Financial attribution rollback.
			$this->transaction_active = false;
		}
		return $error;
	}
}
