<?php
/**
 * Booking privacy, retention, and bounded operational diagnostics.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

// Repository convention uses PSR-4 class names and concise method comments.
// phpcs:disable WordPress.Files.FileName,Generic.Commenting,Squiz.Commenting

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Integrates booking records with WordPress privacy and venue operator tools. */
class BookingPrivacyService {
	public const BATCH_SIZE = 25;
	public const MAX_LIMIT  = 100;

	/** @var VenueAuthorization */
	private $authorization;
	/** @var callable|null */
	private $identity_resolver;
	/** @var callable|null */
	private $reader;
	/** @var callable|null */
	private $redactor;
	/** @var callable|null */
	private $diagnostics_reader;

	public function __construct( ?VenueAuthorization $authorization = null, ?callable $identity_resolver = null, ?callable $reader = null, ?callable $redactor = null, ?callable $diagnostics_reader = null ) {
		$this->authorization      = $authorization ? $authorization : new VenueAuthorization();
		$this->identity_resolver  = $identity_resolver;
		$this->reader             = $reader;
		$this->redactor           = $redactor;
		$this->diagnostics_reader = $diagnostics_reader;
	}

	/** Register directly with WordPress Core's privacy framework. */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
		add_action( 'admin_init', array( self::class, 'add_privacy_policy_content' ) );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters['extrachill-events-bookings'] = array(
			'exporter_friendly_name' => __( 'Extra Chill Venue Bookings', 'extrachill-events' ),
			'callback'               => array( self::class, 'export_personal_data' ),
		);
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers['extrachill-events-bookings'] = array(
			'eraser_friendly_name' => __( 'Extra Chill Venue Bookings', 'extrachill-events' ),
			'callback'             => array( self::class, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/** Core privacy exporter callback. */
	public static function export_personal_data( string $email_address, int $page = 1 ): array {
		return ( new self() )->export( $email_address, $page );
	}

	/** Core privacy eraser callback. */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		return ( new self() )->erase( $email_address, $page );
	}

	/** Publish generic suggested text while leaving approval and wording to site policy. */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$policy = self::retention_policy();
		$text   = sprintf(
			'<p>%s</p><p>%s</p>',
			esc_html__( 'Venue booking inquiries contain contact details, requested dates, intake answers, and correspondence. Verified privacy requests can export or anonymize those fields.', 'extrachill-events' ),
			esc_html( sprintf( /* translators: 1: rejected inquiry days, 2: active inquiry days, 3: confirmed booking days. */ __( 'Code defaults anonymize rejected or withdrawn inquiries after %1$d days, active inquiries after %2$d days, and confirmed or completed booking contact data after %3$d days. Financial, delivery, and audit facts may be retained longer where policy or law requires. Venue policy owners must review and override these defaults as appropriate.', 'extrachill-events' ), $policy['categories']['rejected']['contact_intake_days'], $policy['categories']['active']['contact_intake_days'], $policy['categories']['confirmed']['contact_intake_days'] ) )
		);
		wp_add_privacy_policy_content( __( 'Extra Chill Venue Bookings', 'extrachill-events' ), wp_kses_post( $text ) );
	}

	/** Return code-owned defaults with one explicit legal/policy override seam. */
	public static function retention_policy(): array {
		$policy   = array(
			'version'                   => 1,
			'statuses'                  => array(
				'submitted'    => 'active',
				'needs_info'   => 'active',
				'under_review' => 'active',
				'negotiating'  => 'active',
				'held'         => 'active',
				'declined'     => 'rejected',
				'withdrawn'    => 'rejected',
				'confirmed'    => 'confirmed',
				'cancelled'    => 'confirmed',
				'completed'    => 'confirmed',
			),
			'categories'                => array(
				'rejected'  => array(
					'contact_intake_days' => 180,
					'correspondence_days' => 365,
					'booking_record_days' => 730,
				),
				'active'    => array(
					'contact_intake_days' => 730,
					'correspondence_days' => 730,
					'booking_record_days' => 2555,
				),
				'confirmed' => array(
					'contact_intake_days' => 730,
					'correspondence_days' => 1095,
					'booking_record_days' => 2555,
				),
			),
			'notification_receipt_days' => 365,
			'operational_audit_days'    => 2555,
			'financial_audit_days'      => 2555,
			'attachments'               => 'deferred_to_issue_336',
		);
		$filtered = apply_filters( 'extrachill_events_booking_retention_policy', $policy );
		return self::valid_policy( $filtered ) ? $filtered : $policy;
	}

	/** Export one bounded page from both booking and correspondence streams. */
	public function export( string $email_address, int $page = 1 ): array {
		$identity = $this->identity( $email_address );
		if ( null === $identity ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * self::BATCH_SIZE;
		$rows   = $this->read( 'bookings', $identity, $offset, self::BATCH_SIZE );
		$comms  = $this->read( 'communications', $identity, $offset, self::BATCH_SIZE );
		if ( $rows instanceof \WP_Error || $comms instanceof \WP_Error ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = $this->booking_export_item( $row );
		}
		foreach ( $comms as $row ) {
			$item = $this->communication_export_item( $row );
			if ( null !== $item ) {
				$data[] = $item;
			}
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < self::BATCH_SIZE && count( $comms ) < self::BATCH_SIZE,
		);
	}

	/** Anonymize a bounded batch; deletion offsets are intentionally not used. */
	public function erase( string $email_address, int $page = 1 ): array {
		unset( $page );
		$identity = $this->identity( $email_address );
		if ( null === $identity ) {
			return $this->erasure_result( false, false, array(), true );
		}
		$rows = $this->read( 'erasable', $identity, 0, self::BATCH_SIZE );
		if ( $rows instanceof \WP_Error ) {
			return $this->erasure_result( false, true, array( __( 'Booking records could not be anonymized.', 'extrachill-events' ) ), true );
		}
		$removed  = false;
		$retained = false;
		foreach ( $rows as $row ) {
			$result = $this->redact( $row, 'verified_privacy_request', 0 );
			if ( $result instanceof \WP_Error ) {
				return $this->erasure_result( $removed, true, array( __( 'A booking record could not be anonymized.', 'extrachill-events' ) ), true );
			}
			$removed  = $removed || ! empty( $result['changed'] );
			$retained = $retained || ! empty( $result['retained_evidence'] );
		}
		$messages = $retained ? array( __( 'Non-personal financial, delivery, and audit evidence was retained.', 'extrachill-events' ) ) : array();
		return $this->erasure_result( $removed, $retained, $messages, count( $rows ) < self::BATCH_SIZE );
	}

	/** Run an authorized diagnostic or dry-run-first retention pass. */
	public function operate( array $input, int $actor_id ) {
		$request = $this->authorize_operation( $input, $actor_id );
		if ( $request instanceof \WP_Error ) {
			return $request;
		}
		if ( 'diagnose' === $request['operation'] ) {
			$result = $this->diagnostics_reader ? call_user_func( $this->diagnostics_reader, $request ) : $this->read_diagnostics( $request );
			return is_array( $result ) ? array(
				'scope'       => $this->safe_scope( $request ),
				'diagnostics' => $result,
			) : $result;
		}

		$policy                      = self::retention_policy();
		$category                    = $policy['statuses'][ $request['status'] ];
		$days                        = (int) $policy['categories'][ $category ]['contact_intake_days'];
		$cutoff                      = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$request['retention_cutoff'] = min( $request['before'], $cutoff );
		$rows                        = $this->read( 'cleanup', $request, 0, $request['limit'] );
		if ( $rows instanceof \WP_Error ) {
			return $rows;
		}
		$items = array();
		foreach ( $rows as $row ) {
			$changed = false;
			if ( $request['apply'] ) {
				$result = $this->redact( $row, 'retention_policy', $actor_id );
				if ( $result instanceof \WP_Error ) {
					return $result;
				}
				$changed = ! empty( $result['changed'] );
			}
			$items[] = array(
				'booking_ref' => (string) $row['public_id'],
				'status'      => (string) $row['status'],
				'category'    => $category,
				'action'      => $request['apply'] ? ( $changed ? 'anonymized' : 'already_anonymized' ) : 'would_anonymize',
			);
		}
		$last = end( $rows );
		return array(
			'scope'         => $this->safe_scope( $request ),
			'applied'       => $request['apply'],
			'items'         => $items,
			'next_after_id' => count( $rows ) === $request['limit'] && is_array( $last ) ? (int) $last['id'] : null,
			'done'          => count( $rows ) < $request['limit'],
		);
	}

	/** Validate scope and exact venue authority without executing a read or mutation. */
	public function authorize_operation( array $input, int $actor_id ) {
		$request = $this->normalize_operation( $input );
		if ( $request instanceof \WP_Error ) {
			return $request;
		}
		$allowed = $this->authorization->authorize( $actor_id, $request['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		return true === $allowed ? $request : ( $allowed instanceof \WP_Error ? $allowed : $this->forbidden() );
	}

	/** Exact identity rule: account-owned rows use user ID; anonymous rows use verified email. */
	public static function row_matches_identity( array $row, array $identity ): bool {
		$submitter = isset( $row['submitter_user_id'] ) ? (int) $row['submitter_user_id'] : 0;
		if ( $submitter > 0 ) {
			return $identity['user_id'] > 0 && $submitter === $identity['user_id'];
		}
		return '' !== (string) ( $row['contact_email'] ?? '' ) && strtolower( (string) $row['contact_email'] ) === $identity['email'];
	}

	private function identity( string $email_address ): ?array {
		$email = strtolower( sanitize_email( $email_address ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}
		$user_id = 0;
		if ( $this->identity_resolver ) {
			$user_id = absint( call_user_func( $this->identity_resolver, $email ) );
		} else {
			$user    = get_user_by( 'email', $email );
			$user_id = $user ? (int) $user->ID : 0;
		}
		return array(
			'email'   => $email,
			'user_id' => $user_id,
		);
	}

	private function read( string $stream, array $scope, int $offset, int $limit ) {
		return $this->reader ? call_user_func( $this->reader, $stream, $scope, $offset, $limit ) : $this->read_rows( $stream, $scope, $offset, $limit );
	}

	private function redact( array $row, string $reason, int $actor_id ) {
		return $this->redactor ? call_user_func( $this->redactor, $row, $reason, $actor_id ) : $this->redact_booking( $row, $reason, $actor_id );
	}

	/** Read bounded privacy or cleanup rows without exposing them to operator output. */
	private function read_rows( string $stream, array $scope, int $offset, int $limit ) {
		global $wpdb;
		$bookings = BookingSchema::bookings_table();
		$limit    = max( 1, min( self::MAX_LIMIT, $limit ) );
		if ( 'cleanup' === $stream ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$bookings} WHERE venue_term_id = %d AND status = %s AND id > %d AND updated_at < %s AND (contact_name IS NOT NULL OR contact_email IS NOT NULL OR contact_phone IS NOT NULL OR submitter_user_id IS NOT NULL) ORDER BY id ASC LIMIT %d", $scope['venue_term_id'], $scope['status'], $scope['after_id'], $scope['retention_cutoff'], $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized bounded retention scan.
			return '' === (string) $wpdb->last_error && is_array( $rows ) ? $rows : new \WP_Error( 'booking_privacy_read_failed', __( 'Booking retention candidates could not be read.', 'extrachill-events' ) );
		}

		$where  = "b.status <> 'admission_pending' AND (b.submitter_user_id = %d OR (b.submitter_user_id IS NULL AND LOWER(b.contact_email) = %s))";
		$values = array( (int) $scope['user_id'], $scope['email'] );
		if ( 0 === (int) $scope['user_id'] ) {
			$where  = "b.status <> 'admission_pending' AND (b.submitter_user_id IS NULL AND LOWER(b.contact_email) = %s)";
			$values = array( $scope['email'] );
		}
		if ( 'communications' === $stream ) {
			$activity = BookingSchema::activity_table();
			$sql      = "SELECT a.*, b.public_id AS booking_public_id FROM {$activity} a INNER JOIN {$bookings} b ON b.id = a.booking_id WHERE {$where} AND a.is_communication = 1 ORDER BY a.id ASC LIMIT %d OFFSET %d";
		} else {
			$sql = "SELECT b.* FROM {$bookings} b WHERE {$where}";
			if ( 'erasable' === $stream ) {
				$sql .= ' AND (b.contact_name IS NOT NULL OR b.contact_email IS NOT NULL OR b.contact_phone IS NOT NULL OR b.submitter_user_id IS NOT NULL) ORDER BY b.id ASC LIMIT %d';
			} else {
				$sql .= ' ORDER BY b.id ASC LIMIT %d OFFSET %d';
			}
		}
		$values[] = $limit;
		if ( 'erasable' !== $stream ) {
			$values[] = max( 0, $offset );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal table and clauses; all values are prepared.
		return '' === (string) $wpdb->last_error && is_array( $rows ) ? $rows : new \WP_Error( 'booking_privacy_read_failed', __( 'Booking privacy data could not be read.', 'extrachill-events' ) );
	}

	/** Redact personal fields transactionally while preserving referential and audit facts. */
	private function redact_booking( array $candidate, string $reason, int $actor_id ) {
		global $wpdb;
		$bookings = BookingSchema::bookings_table();
		$activity = BookingSchema::activity_table();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic privacy mutation.
			return new \WP_Error( 'booking_privacy_transaction_failed', __( 'Booking anonymization could not start.', 'extrachill-events' ) );
		}
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bookings} WHERE id = %d AND venue_term_id = %d LIMIT 1 FOR UPDATE", (int) $candidate['id'], (int) $candidate['venue_term_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact aggregate lock.
		if ( ! is_array( $current ) || '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Failed privacy mutation rollback.
			return new \WP_Error( 'booking_privacy_conflict', __( 'The booking could not be locked for anonymization.', 'extrachill-events' ) );
		}
		$changed = null !== $current['contact_name'] || null !== $current['contact_email'] || null !== $current['contact_phone'] || null !== $current['submitter_user_id'];
		if ( $changed ) {
			$marker = wp_json_encode(
				array(
					'version' => 1,
					'data'    => array( 'privacy_redacted' => true ),
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact versioned privacy redaction.
			$updated = $wpdb->update(
				$bookings,
				array(
					'submitter_user_id'      => null,
					'contact_name'           => null,
					'contact_email'          => null,
					'contact_phone'          => null,
					'intake_payload'         => $marker,
					'production_payload'     => $marker,
					'deal_payload'           => $this->redact_deal_payload( $current['deal_payload'] ),
					'confirmed_deal_payload' => $this->redact_deal_payload( $current['confirmed_deal_payload'] ),
					'version'                => (int) $current['version'] + 1,
					'updated_at'             => gmdate( 'Y-m-d H:i:s' ),
				),
				array(
					'id'      => (int) $current['id'],
					'version' => (int) $current['version'],
				)
			);
			if ( 1 !== $updated ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Failed privacy mutation rollback.
				return new \WP_Error( 'booking_privacy_conflict', __( 'The booking changed before anonymization completed.', 'extrachill-events' ) );
			}
		}

		$communications = $wpdb->get_results( $wpdb->prepare( "SELECT id, payload FROM {$activity} WHERE booking_id = %d AND is_communication = 1 ORDER BY id ASC FOR UPDATE", (int) $current['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks exact correspondence ledger.
		// Runtime wpdb can report an error independently of the test double's declared default.
		// @phpstan-ignore notIdentical.alwaysFalse
		if ( ! is_array( $communications ) || '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Failed privacy mutation rollback.
			return new \WP_Error( 'booking_privacy_activity_read_failed', __( 'Booking correspondence could not be anonymized.', 'extrachill-events' ) );
		}
		foreach ( $communications as $communication ) {
			$payload = json_decode( (string) $communication['payload'], true );
			$data    = is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
			if ( ! empty( $data['privacy_redacted'] ) ) {
				continue;
			}
			$preserved = array( 'privacy_redacted' => true );
			foreach ( array( 'template', 'template_version', 'request_hash', 'booking_version', 'source_activity_id', 'from_name', 'mail_site_id', 'intent_id', 'action_id', 'attempt', 'retryable', 'reason', 'error_code' ) as $key ) {
				if ( array_key_exists( $key, $data ) ) {
					$preserved[ $key ] = $data[ $key ];
				}
			}
			$encoded = wp_json_encode(
				array(
					'version' => 1,
					'data'    => $preserved,
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact immutable-marker payload redaction.
			if ( false === $encoded || false === $wpdb->update(
				$activity,
				array( 'payload' => $encoded ),
				array(
					'id'         => (int) $communication['id'],
					'booking_id' => (int) $current['id'],
				)
			) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Failed privacy mutation rollback.
				return new \WP_Error( 'booking_privacy_activity_write_failed', __( 'Booking correspondence could not be anonymized.', 'extrachill-events' ) );
			}
			$changed = true;
		}
		if ( $changed ) {
			$audit = ( new BookingActivityRepository() )->append(
				array(
					'booking_id'      => (int) $current['id'],
					'kind'            => 'privacy_redaction_applied',
					'actor_type'      => $actor_id > 0 ? 'user' : 'system',
					'actor_id'        => $actor_id > 0 ? $actor_id : null,
					'idempotency_key' => 'privacy-redaction:' . $reason . ':' . (int) $current['version'],
					'payload'         => array(
						'reason'                    => $reason,
						'personal_fields_removed'   => true,
						'financial_audit_preserved' => true,
					),
				)
			);
			if ( $audit instanceof \WP_Error ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Failed privacy mutation rollback.
				return $audit;
			}
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic privacy mutation commit.
			return new \WP_Error( 'booking_privacy_commit_uncertain', __( 'The booking anonymization outcome is uncertain.', 'extrachill-events' ) );
		}
		return array(
			'changed'           => $changed,
			'retained_evidence' => $this->has_financial_evidence( (int) $current['id'] ) || ! empty( $communications ),
		);
	}

	/** Return only non-sensitive references and failure classes. */
	private function read_diagnostics( array $request ) {
		global $wpdb;
		$bookings       = BookingSchema::bookings_table();
		$activity       = BookingSchema::activity_table();
		$holds          = BookingSchema::holds_table();
		$states         = BookingSchema::communication_state_table();
		$limit          = $request['limit'];
		$venue          = $request['venue_term_id'];
		$before         = $request['before'];
		$status         = $request['status'];
		$status_sql     = null === $status ? '' : $wpdb->prepare( ' AND b.status = %s', $status );
		$stale_holds    = $wpdb->get_results( $wpdb->prepare( "SELECT b.public_id AS booking_ref, h.id AS hold_id, h.expires_at FROM {$holds} h INNER JOIN {$bookings} b ON b.id = h.booking_id WHERE b.venue_term_id = %d AND b.status <> 'admission_pending'{$status_sql} AND h.status = 'active' AND h.expires_at <= %s ORDER BY h.id ASC LIMIT %d", $venue, $before, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized bounded non-sensitive diagnostic.
		$communications = $wpdb->get_results( $wpdb->prepare( "SELECT b.public_id AS booking_ref, s.intent_id, s.status, s.updated_at FROM {$states} s INNER JOIN {$bookings} b ON b.id = s.booking_id WHERE b.venue_term_id = %d AND b.status <> 'admission_pending'{$status_sql} AND s.status IN ('failed', 'queued', 'reconciliation_required') AND s.updated_at <= %s ORDER BY s.intent_id ASC LIMIT %d", $venue, $before, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized bounded non-sensitive diagnostic.
		$handoffs       = $wpdb->get_results( $wpdb->prepare( "SELECT b.public_id AS booking_ref, start.kind, start.id AS activity_id, start.occurred_at FROM {$activity} start INNER JOIN {$bookings} b ON b.id = start.booking_id WHERE b.venue_term_id = %d AND b.status <> 'admission_pending'{$status_sql} AND start.kind IN ('event_conversion_started', 'event_sync_started') AND start.occurred_at <= %s AND NOT EXISTS (SELECT 1 FROM {$activity} terminal WHERE terminal.booking_id = start.booking_id AND terminal.id > start.id AND ((start.kind = 'event_conversion_started' AND terminal.kind IN ('event_conversion_failed', 'event_converted')) OR (start.kind = 'event_sync_started' AND terminal.external_id = CAST(start.id AS CHAR) AND terminal.kind IN ('event_sync_succeeded', 'event_sync_noop', 'event_sync_conflict', 'event_sync_failed')))) ORDER BY start.id ASC LIMIT %d", $venue, $before, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized bounded non-sensitive diagnostic.
		$overdue        = array();
		if ( null !== $status ) {
			$policy   = self::retention_policy();
			$category = $policy['statuses'][ $status ];
			$cutoff   = min( $before, gmdate( 'Y-m-d H:i:s', time() - ( (int) $policy['categories'][ $category ]['contact_intake_days'] * DAY_IN_SECONDS ) ) );
			$overdue  = $wpdb->get_results( $wpdb->prepare( "SELECT public_id AS booking_ref, status, updated_at FROM {$bookings} WHERE venue_term_id = %d AND status = %s AND updated_at < %s AND (contact_name IS NOT NULL OR contact_email IS NOT NULL OR contact_phone IS NOT NULL OR submitter_user_id IS NOT NULL) ORDER BY id ASC LIMIT %d", $venue, $status, $cutoff, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorized bounded non-sensitive diagnostic.
		}
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_diagnostics_read_failed', __( 'Booking diagnostics could not be read.', 'extrachill-events' ) );
		}
		return array(
			'stale_holds'                    => $stale_holds,
			'correspondence_automation'      => $communications,
			'stuck_event_handoffs'           => $handoffs,
			'overdue_retained_inquiries'     => $overdue,
			'private_attachment_diagnostics' => 'deferred_to_issue_336',
		);
	}

	private function normalize_operation( array $input ) {
		$operation = (string) ( $input['operation'] ?? 'diagnose' );
		$venue     = absint( $input['venue_term_id'] ?? 0 );
		$status    = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : null;
		$before    = (string) ( $input['before'] ?? '' );
		$date      = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $before, new \DateTimeZone( 'UTC' ) );
		if ( ! in_array( $operation, array( 'diagnose', 'cleanup' ), true ) || $venue < 1 || ! $date || $date->format( 'Y-m-d H:i:s' ) !== $before || ( null !== $status && ! in_array( $status, BookingRepository::STATUSES, true ) ) || ( 'cleanup' === $operation && null === $status ) ) {
			return new \WP_Error( 'invalid_booking_privacy_operation', __( 'A valid operation, venue, UTC boundary, and cleanup status are required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return array(
			'operation'     => $operation,
			'venue_term_id' => $venue,
			'status'        => $status,
			'before'        => $before,
			'after_id'      => max( 0, absint( $input['after_id'] ?? 0 ) ),
			'limit'         => max( 1, min( self::MAX_LIMIT, absint( $input['limit'] ?? self::BATCH_SIZE ) ) ),
			'apply'         => true === ( $input['apply'] ?? false ),
		);
	}

	private function safe_scope( array $request ): array {
		return array_intersect_key( $request, array_flip( array( 'operation', 'venue_term_id', 'status', 'before', 'after_id', 'limit', 'retention_cutoff' ) ) );
	}

	private function booking_export_item( array $row ): array {
		$fields = array();
		foreach ( array(
			'artist_name'        => __( 'Artist', 'extrachill-events' ),
			'contact_name'       => __( 'Contact name', 'extrachill-events' ),
			'contact_email'      => __( 'Contact email', 'extrachill-events' ),
			'contact_phone'      => __( 'Contact phone', 'extrachill-events' ),
			'requested_start_at' => __( 'Requested start', 'extrachill-events' ),
			'requested_end_at'   => __( 'Requested end', 'extrachill-events' ),
			'status'             => __( 'Status', 'extrachill-events' ),
			'created_at'         => __( 'Submitted at', 'extrachill-events' ),
		) as $key => $label ) {
			if ( null !== ( $row[ $key ] ?? null ) && '' !== (string) $row[ $key ] ) {
				$fields[] = array(
					'name'  => $label,
					'value' => (string) $row[ $key ],
				);
			}
		}
		$intake = json_decode( (string) ( $row['intake_payload'] ?? '' ), true );
		if ( is_array( $intake['data'] ?? null ) && empty( $intake['data']['privacy_redacted'] ) ) {
			$fields[] = array(
				'name'  => __( 'Intake answers', 'extrachill-events' ),
				'value' => wp_json_encode( $intake['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			);
		}
		return array(
			'group_id'          => 'extrachill-events-bookings',
			'group_label'       => __( 'Venue Bookings', 'extrachill-events' ),
			'group_description' => __( 'Private venue booking inquiries associated with the verified email or account.', 'extrachill-events' ),
			'item_id'           => 'booking-' . (string) $row['public_id'],
			'data'              => $fields,
		);
	}

	private function communication_export_item( array $row ): ?array {
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$data    = is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
		if ( ! empty( $data['privacy_redacted'] ) ) {
			return null;
		}
		$fields = array(
			array(
				'name'  => __( 'Type', 'extrachill-events' ),
				'value' => (string) ( $row['kind'] ?? '' ),
			),
			array(
				'name'  => __( 'Recorded at', 'extrachill-events' ),
				'value' => (string) ( $row['occurred_at'] ?? '' ),
			),
		);
		foreach ( array(
			'recipient' => __( 'Recipient', 'extrachill-events' ),
			'subject'   => __( 'Subject', 'extrachill-events' ),
			'message'   => __( 'Message', 'extrachill-events' ),
			'body'      => __( 'Message', 'extrachill-events' ),
			'reply_to'  => __( 'Reply to', 'extrachill-events' ),
		) as $key => $label ) {
			if ( isset( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				$fields[] = array(
					'name'  => $label,
					'value' => (string) $data[ $key ],
				);
			}
		}
		return array(
			'group_id'    => 'extrachill-events-booking-correspondence',
			'group_label' => __( 'Venue Booking Correspondence', 'extrachill-events' ),
			'item_id'     => 'booking-communication-' . (int) $row['id'],
			'data'        => $fields,
		);
	}

	private function has_financial_evidence( int $booking_id ): bool {
		global $wpdb;
		foreach ( array( BookingSchema::sales_reports_table(), BookingSchema::sales_resolutions_table(), BookingSchema::settlements_table(), BookingSchema::show_settlements_table(), BookingSchema::show_settlement_actions_table() ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE booking_id = %d LIMIT 1", $booking_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact retained-evidence check.
				return true;
			}
		}
		return false;
	}

	/** Preserve structured deal facts while removing free-form contractual content. */
	private function redact_deal_payload( $payload ) {
		if ( null === $payload ) {
			return null;
		}
		$decoded = json_decode( (string) $payload, true );
		if ( ! is_array( $decoded['data'] ?? null ) ) {
			return wp_json_encode(
				array(
					'version' => 1,
					'data'    => array( 'privacy_redacted' => true ),
				)
			);
		}
		$decoded['data']['additional_terms'] = '[privacy redacted]';
		$decoded['data']['privacy_redacted'] = true;
		return wp_json_encode( $decoded );
	}

	private function erasure_result( bool $removed, bool $retained, array $messages, bool $done ): array {
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	private function forbidden(): \WP_Error {
		return new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	private static function valid_policy( $policy ): bool {
		if ( ! is_array( $policy ) || ! isset( $policy['statuses'], $policy['categories'] ) ) {
			return false;
		}
		foreach ( BookingRepository::STATUSES as $status ) {
			$category = $policy['statuses'][ $status ] ?? '';
			if ( ! isset( $policy['categories'][ $category ] ) ) {
				return false;
			}
			foreach ( array( 'contact_intake_days', 'correspondence_days', 'booking_record_days' ) as $field ) {
				if ( ! is_int( $policy['categories'][ $category ][ $field ] ?? null ) || $policy['categories'][ $category ][ $field ] < 1 ) {
					return false;
				}
			}
		}
		return true;
	}
}
