<?php
/**
 * Private local support persistence.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provides bounded persistence for local support aggregates. */
class LocalSupportRepository {

	/** Create one request. Database uniqueness owns concurrent event admission. */
	public function create_request( array $data ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'public_id'          => wp_generate_uuid4(),
			'event_id'           => (int) $data['event_id'],
			'venue_term_id'      => (int) $data['venue_term_id'],
			'booking_id'         => empty( $data['booking_id'] ) ? null : (int) $data['booking_id'],
			'organizer_type'     => (string) $data['organizer_type'],
			'organizer_id'       => (int) $data['organizer_id'],
			'status'             => 'open',
			'version'            => 1,
			'created_by_user_id' => (int) $data['actor_id'],
			'created_at'         => $now,
			'updated_at'         => $now,
		);
		if ( false === $wpdb->insert( LocalSupportSchema::requests_table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private operational write.
			return new \WP_Error( 'local_support_request_create_failed', __( 'The support request could not be created.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return $this->get_request( (int) $wpdb->insert_id );
	}

	/** Get one request by numeric ID. */
	public function get_request( int $id, bool $for_update = false ) {
		global $wpdb;
		$table = LocalSupportSchema::requests_table();
		$lock  = $for_update ? ' FOR UPDATE' : '';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1{$lock}", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted table and fixed lock clause.
		return $this->row_result( $row, 'local_support_request_read_failed', array( $this, 'hydrate_request' ) );
	}

	/** Get the sole request for a canonical event. */
	public function get_request_by_event( int $event_id ) {
		global $wpdb;
		$table = LocalSupportSchema::requests_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d LIMIT 1", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact indexed read.
		return $this->row_result( $row, 'local_support_request_read_failed', array( $this, 'hydrate_request' ) );
	}

	/** Create one artist interest. */
	public function create_interest( int $request_id, int $artist_term_id, int $actor_id ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'public_id'            => wp_generate_uuid4(),
			'request_id'           => $request_id,
			'artist_term_id'       => $artist_term_id,
			'status'               => 'interested',
			'version'              => 1,
			'contact_payload'      => null,
			'consent_fields'       => null,
			'consent_version'      => 0,
			'consented_by_user_id' => null,
			'consented_at'         => null,
			'revoked_by_user_id'   => null,
			'revoked_at'           => null,
			'created_by_user_id'   => $actor_id,
			'created_at'           => $now,
			'updated_at'           => $now,
		);
		if ( false === $wpdb->insert( LocalSupportSchema::interests_table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private operational write.
			return new \WP_Error( 'local_support_interest_create_failed', __( 'Artist interest could not be recorded.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return $this->get_interest( (int) $wpdb->insert_id );
	}

	/** Get one interest. */
	public function get_interest( int $id, bool $for_update = false ) {
		global $wpdb;
		$table = LocalSupportSchema::interests_table();
		$lock  = $for_update ? ' FOR UPDATE' : '';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1{$lock}", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted table and fixed lock clause.
		return $this->row_result( $row, 'local_support_interest_read_failed', array( $this, 'hydrate_interest' ) );
	}

	/** Get one artist's interest in one request. */
	public function get_interest_for_artist( int $request_id, int $artist_term_id ) {
		global $wpdb;
		$table = LocalSupportSchema::interests_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d AND artist_term_id = %d LIMIT 1", $request_id, $artist_term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact unique read.
		return $this->row_result( $row, 'local_support_interest_read_failed', array( $this, 'hydrate_interest' ) );
	}

	/** List bounded interests for an organizer shortlist. */
	public function list_interests( int $request_id, int $limit = 100 ) {
		global $wpdb;
		$table = LocalSupportSchema::interests_table();
		$limit = max( 1, min( 100, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d ORDER BY created_at ASC, id ASC LIMIT %d", $request_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded private read.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_interest_list_failed', __( 'Local support interests could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return array_map( array( $this, 'hydrate_interest' ), (array) $rows );
	}

	/** Apply a versioned request update. */
	public function update_request( int $id, int $expected_version, array $changes ) {
		return $this->conditional_update( LocalSupportSchema::requests_table(), $id, $expected_version, $changes, 'request' );
	}

	/** Apply a versioned interest update. */
	public function update_interest( int $id, int $expected_version, array $changes ) {
		return $this->conditional_update( LocalSupportSchema::interests_table(), $id, $expected_version, $changes, 'interest' );
	}

	/** Append one immutable activity marker. */
	public function append_activity( array $data ) {
		global $wpdb;
		$payload = wp_json_encode(
			array(
				'version' => 1,
				'data'    => $data['payload'] ?? array(),
			)
		);
		if ( false === $payload ) {
			return new \WP_Error( 'local_support_activity_encode_failed', __( 'Local support activity could not be encoded.', 'extrachill-events' ) );
		}
		$row = array(
			'request_id'      => (int) $data['request_id'],
			'interest_id'     => empty( $data['interest_id'] ) ? null : (int) $data['interest_id'],
			'kind'            => sanitize_key( (string) $data['kind'] ),
			'actor_user_id'   => (int) $data['actor_user_id'],
			'idempotency_key' => (string) $data['idempotency_key'],
			'request_hash'    => (string) $data['request_hash'],
			'result_version'  => (int) $data['result_version'],
			'payload'         => $payload,
			'created_at'      => isset( $data['created_at'] ) ? (string) $data['created_at'] : gmdate( 'Y-m-d H:i:s' ),
		);
		if ( false === $wpdb->insert( LocalSupportSchema::activity_table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only private audit write.
			return new \WP_Error( 'local_support_activity_write_failed', __( 'Local support activity could not be recorded.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		$row['id'] = (int) $wpdb->insert_id;
		return $row;
	}

	/** Find a prior mutation receipt. */
	public function find_activity( int $request_id, string $idempotency_key ) {
		global $wpdb;
		$table = LocalSupportSchema::activity_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d AND idempotency_key = %s LIMIT 1", $request_id, $idempotency_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact idempotency receipt read.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_activity_read_failed', __( 'Local support activity could not be read.', 'extrachill-events' ) );
		}
		return is_array( $row ) ? $row : null;
	}

	/** Read one immutable activity by ID and optional request owner. */
	public function get_activity( int $activity_id, ?int $request_id = null ) {
		global $wpdb;
		$table  = LocalSupportSchema::activity_table();
		$where  = null === $request_id ? '' : ' AND request_id = %d';
		$values = null === $request_id ? array( $activity_id ) : array( $activity_id, $request_id );
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d{$where} LIMIT 1", $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed optional owner clause with prepared IDs.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_activity_read_failed', __( 'Local support activity could not be read.', 'extrachill-events' ) );
		}
		return is_array( $row ) ? $this->hydrate_activity( $row ) : null;
	}

	/** Resolve the exact durable activity represented by an emitted change. */
	public function find_activity_for_change( int $request_id, ?int $interest_id, string $kind, int $version ) {
		global $wpdb;
		$table  = LocalSupportSchema::activity_table();
		$where  = null === $interest_id ? 'interest_id IS NULL' : 'interest_id = %d';
		$values = null === $interest_id
			? array( $request_id, $kind, $version )
			: array( $request_id, $interest_id, $kind, $version );
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d AND {$where} AND kind = %s AND result_version = %d ORDER BY id DESC LIMIT 1", $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed clause with prepared change identity; the optional interest placeholder determines the runtime count.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_activity_read_failed', __( 'Local support activity could not be read.', 'extrachill-events' ) );
		}
		return is_array( $row ) ? $this->hydrate_activity( $row ) : null;
	}

	/** List committed notification source activities not yet durably processed. */
	public function pending_notification_sources( int $limit = 50 ) {
		global $wpdb;
		$table = LocalSupportSchema::activity_table();
		$limit = max( 1, min( 100, $limit ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reuses the trusted current-prefix activity table in the bounded subquery.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source.* FROM {$table} source
				WHERE source.kind IN ('request_opened', 'interest_expressed', 'interest_status_changed', 'contact_consent_granted', 'contact_consent_revoked')
				AND NOT EXISTS (
					SELECT 1 FROM {$table} marker
					WHERE marker.request_id = source.request_id
					AND marker.kind = 'notification_source_processed'
					AND marker.request_hash = source.request_hash
				)
				ORDER BY source.id ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded recovery query over immutable source activity.
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_notification_sources_read_failed', __( 'Local support notification source activities could not be read.', 'extrachill-events' ) );
		}
		return array_map( array( $this, 'hydrate_activity' ), (array) $rows );
	}

	/** List bounded due notification intents with no terminal activity. */
	public function pending_notification_intents( int $limit = 50 ) {
		global $wpdb;
		$table = LocalSupportSchema::activity_table();
		$limit = max( 1, min( 100, $limit ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reuses the trusted current-prefix activity table in bounded subqueries.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT intent.* FROM {$table} intent
				WHERE intent.kind = 'notification_intent'
				AND NOT EXISTS (
					SELECT 1 FROM {$table} terminal
					WHERE terminal.request_id = intent.request_id
					AND terminal.request_hash = intent.request_hash
					AND terminal.kind = 'notification_terminal'
				)
				AND NOT EXISTS (
					SELECT 1 FROM {$table} deferred
					WHERE deferred.request_id = intent.request_id
					AND deferred.request_hash = intent.request_hash
					AND deferred.kind = 'notification_attempt'
					AND deferred.created_at > UTC_TIMESTAMP()
				)
				ORDER BY intent.id ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded due outbox query over private activity.
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_notification_intents_read_failed', __( 'Local support notification intents could not be read.', 'extrachill-events' ) );
		}
		return array_map( array( $this, 'hydrate_activity' ), (array) $rows );
	}

	/** Return one terminal notification activity by its intent correlation hash. */
	public function notification_terminal( int $request_id, string $request_hash ) {
		global $wpdb;
		$table = LocalSupportSchema::activity_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d AND request_hash = %s AND kind = 'notification_terminal' ORDER BY id DESC LIMIT 1", $request_id, $request_hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact terminal correlation read.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_notification_terminal_read_failed', __( 'Local support notification terminal state could not be read.', 'extrachill-events' ) );
		}
		return is_array( $row ) ? $this->hydrate_activity( $row ) : null;
	}

	/** Count delivery attempts for one durable notification intent. */
	public function notification_attempt_count( int $request_id, string $request_hash ) {
		global $wpdb;
		$table = LocalSupportSchema::activity_table();
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE request_id = %d AND request_hash = %s AND kind = 'notification_attempt'", $request_id, $request_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact attempt count.
		return '' === (string) $wpdb->last_error
			? (int) $count
			: new \WP_Error( 'local_support_notification_attempts_read_failed', __( 'Local support notification attempts could not be read.', 'extrachill-events' ) );
	}

	/** Hydrate request scalar types. */
	public function hydrate_request( array $row ): array {
		foreach ( array( 'id', 'event_id', 'venue_term_id', 'organizer_id', 'version', 'created_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['booking_id'] = null === $row['booking_id'] ? null : (int) $row['booking_id'];
		return $row;
	}

	/** Hydrate interest and reveal contact only while consent is active. */
	public function hydrate_interest( array $row ): array {
		$stored_fields = $row['consent_fields'];
		foreach ( array( 'id', 'request_id', 'artist_term_id', 'version', 'consent_version', 'created_by_user_id' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		foreach ( array( 'consented_by_user_id', 'revoked_by_user_id' ) as $field ) {
			$row[ $field ] = null === $row[ $field ] ? null : (int) $row[ $field ];
		}
		$row['contact']        = null;
		$row['consent_fields'] = null;
		if ( null !== $row['contact_payload'] && null === $row['revoked_at'] ) {
			$row['contact']        = json_decode( $row['contact_payload'], true );
			$row['consent_fields'] = json_decode( (string) $stored_fields, true );
		}
		unset( $row['contact_payload'] );
		return $row;
	}

	/** Hydrate activity scalar and versioned payload fields. */
	public function hydrate_activity( array $row ): array {
		foreach ( array( 'id', 'request_id', 'actor_user_id', 'result_version' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['interest_id'] = null === $row['interest_id'] ? null : (int) $row['interest_id'];
		$payload            = json_decode( (string) $row['payload'], true );
		$row['payload']     = is_array( $payload ) && is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
		return $row;
	}

	/** Execute one optimistic update. */
	private function conditional_update( string $table, int $id, int $expected_version, array $changes, string $kind ) {
		global $wpdb;
		$changes['version']    = $expected_version + 1;
		$changes['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$updated               = $wpdb->update(
			$table,
			$changes,
			array(
				'id'      => $id,
				'version' => $expected_version,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Optimistic private aggregate write.
		if ( 1 !== $updated ) {
			return new \WP_Error( 'local_support_version_conflict', __( 'The local support record changed since it was read.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return 'request' === $kind ? $this->get_request( $id ) : $this->get_interest( $id );
	}

	/** Normalize a single-row read. */
	private function row_result( $row, string $error_code, callable $hydrate ) {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( $error_code, __( 'The local support record could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? call_user_func( $hydrate, $row ) : null;
	}
}
