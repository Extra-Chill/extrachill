<?php
/**
 * Private vendor request persistence.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provides bounded persistence for vendor requests and applications. */
class VendorRequestRepository {

	public function create_request( array $data ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'public_id'           => wp_generate_uuid4(),
			'event_id'            => (int) $data['event_id'],
			'venue_term_id'       => (int) $data['venue_term_id'],
			'coordinator_user_id' => (int) $data['coordinator_user_id'],
			'status'              => 'open',
			'policy'              => wp_json_encode( $data['policy'] ),
			'version'             => 1,
			'created_at'          => $now,
			'updated_at'          => $now,
		);
		if ( false === $row['policy'] || false === $wpdb->insert( VendorRequestSchema::requests_table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private operational write.
			return new \WP_Error( 'vendor_request_create_failed', __( 'The vendor request could not be created.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return $this->get_request( (int) $wpdb->insert_id );
	}

	public function get_request( int $id, bool $for_update = false ) {
		global $wpdb;
		$table = VendorRequestSchema::requests_table();
		$lock  = $for_update ? ' FOR UPDATE' : '';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1{$lock}", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted table and fixed lock clause.
		return $this->row_result( $row, 'vendor_request_read_failed', array( $this, 'hydrate_request' ) );
	}

	public function get_request_by_event( int $event_id ) {
		global $wpdb;
		$table = VendorRequestSchema::requests_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d LIMIT 1", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact indexed read.
		return $this->row_result( $row, 'vendor_request_read_failed', array( $this, 'hydrate_request' ) );
	}

	public function get_request_by_public_id( string $public_id ) {
		global $wpdb;
		$table = VendorRequestSchema::requests_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s LIMIT 1", $public_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact opaque identifier read.
		return $this->row_result( $row, 'vendor_request_read_failed', array( $this, 'hydrate_request' ) );
	}

	public function create_application( array $data ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'public_id'         => wp_generate_uuid4(),
			'request_id'        => (int) $data['request_id'],
			'status'            => 'submitted',
			'version'           => 1,
			'business_name'     => $data['business_name'],
			'category'          => $data['category'],
			'website_url'       => $data['website_url'],
			'footprint'         => $data['footprint'],
			'power_needs'       => $data['power_needs'],
			'insurance_notes'   => $data['insurance_notes'],
			'message'           => $data['message'],
			'contact_payload'   => wp_json_encode( $data['contact'] ),
			'consent_version'   => 1,
			'consented_at'      => $now,
			'revoked_at'        => null,
			'private_notes'     => null,
			'access_token_hash' => $data['access_token_hash'],
			'idempotency_key'   => $data['idempotency_key'],
			'request_hash'      => $data['request_hash'],
			'submitter_user_id' => $data['submitter_user_id'],
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		if ( false === $row['contact_payload'] || false === $wpdb->insert( VendorRequestSchema::applications_table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private operational write.
			return new \WP_Error( 'vendor_application_create_failed', __( 'The vendor application could not be recorded.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return $this->get_application( (int) $wpdb->insert_id );
	}

	public function get_application( int $id, bool $for_update = false ) {
		global $wpdb;
		$table = VendorRequestSchema::applications_table();
		$lock  = $for_update ? ' FOR UPDATE' : '';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1{$lock}", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted table and fixed lock clause.
		return $this->row_result( $row, 'vendor_application_read_failed', array( $this, 'hydrate_application' ) );
	}

	public function find_application_retry( int $request_id, string $key ) {
		global $wpdb;
		$table = VendorRequestSchema::applications_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d AND idempotency_key = %s LIMIT 1", $request_id, $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact retry read.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'vendor_application_read_failed', __( 'The private vendor record could not be read.', 'extrachill-events' ) );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}
		$hash                        = (string) $row['request_hash'];
		$application                 = $this->hydrate_application( $row );
		$application['_replay_hash'] = $hash;
		return $application;
	}

	public function list_applications( int $request_id, int $limit = 100 ) {
		global $wpdb;
		$table = VendorRequestSchema::applications_table();
		$limit = max( 1, min( 100, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d ORDER BY created_at ASC, id ASC LIMIT %d", $request_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded private read.
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'vendor_application_list_failed', __( 'Vendor applications could not be read.', 'extrachill-events' ) ) : array_map( array( $this, 'hydrate_application' ), (array) $rows );
	}

	public function get_application_by_public_id( string $public_id, bool $for_update = false ) {
		global $wpdb;
		$table = VendorRequestSchema::applications_table();
		$lock  = $for_update ? ' FOR UPDATE' : '';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s LIMIT 1{$lock}", $public_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact opaque identifier read.
		return $this->row_result( $row, 'vendor_application_read_failed', array( $this, 'hydrate_application' ) );
	}

	public function verify_application_token( string $public_id, string $token ): bool {
		global $wpdb;
		$table = VendorRequestSchema::applications_table();
		$hash  = $wpdb->get_var( $wpdb->prepare( "SELECT access_token_hash FROM {$table} WHERE public_id = %s LIMIT 1", $public_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact opaque-token authorization read.
		$sent  = hash_hmac( 'sha256', trim( $token ), wp_salt( 'auth' ) );
		return is_string( $hash ) && hash_equals( $hash, $sent );
	}

	public function update_request( int $id, int $expected_version, array $changes ) {
		return $this->conditional_update( VendorRequestSchema::requests_table(), $id, $expected_version, $changes, true );
	}

	public function update_application( int $id, int $expected_version, array $changes ) {
		return $this->conditional_update( VendorRequestSchema::applications_table(), $id, $expected_version, $changes, false );
	}

	public function append_activity( array $data ) {
		global $wpdb;
		$payload = wp_json_encode(
			array(
				'version' => 1,
				'data'    => $data['payload'] ?? array(),
			)
		);
		$row     = array(
			'request_id'      => (int) $data['request_id'],
			'application_id'  => empty( $data['application_id'] ) ? null : (int) $data['application_id'],
			'kind'            => sanitize_key( $data['kind'] ),
			'actor_user_id'   => empty( $data['actor_user_id'] ) ? null : (int) $data['actor_user_id'],
			'idempotency_key' => $data['idempotency_key'],
			'request_hash'    => $data['request_hash'],
			'result_version'  => (int) $data['result_version'],
			'payload'         => $payload,
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( false === $payload || false === $wpdb->insert( VendorRequestSchema::activity_table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only private audit write.
			return new \WP_Error( 'vendor_request_activity_write_failed', __( 'Vendor request activity could not be recorded.', 'extrachill-events' ) );
		}
		$row['id'] = (int) $wpdb->insert_id;
		return $row;
	}

	public function find_activity( int $request_id, string $key ) {
		global $wpdb;
		$table = VendorRequestSchema::activity_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d AND idempotency_key = %s LIMIT 1", $request_id, $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact receipt read.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'vendor_request_activity_read_failed', __( 'Vendor request activity could not be read.', 'extrachill-events' ) );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}
		$payload        = json_decode( (string) $row['payload'], true );
		$row['payload'] = (array) ( $payload['data'] ?? array() );
		return $row;
	}

	public function hydrate_request( array $row ): array {
		foreach ( array( 'id', 'event_id', 'venue_term_id', 'coordinator_user_id', 'version' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['policy'] = (array) json_decode( (string) $row['policy'], true );
		return $row;
	}

	public function hydrate_application( array $row ): array {
		foreach ( array( 'id', 'request_id', 'version', 'consent_version' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['submitter_user_id'] = null === $row['submitter_user_id'] ? null : (int) $row['submitter_user_id'];
		$row['contact']           = null === $row['revoked_at'] ? (array) json_decode( (string) $row['contact_payload'], true ) : null;
		unset( $row['contact_payload'], $row['access_token_hash'], $row['idempotency_key'], $row['request_hash'] );
		return $row;
	}

	private function conditional_update( string $table, int $id, int $expected_version, array $changes, bool $request ) {
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
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Optimistic private write.
		if ( 1 !== $updated ) {
			return new \WP_Error( 'vendor_request_version_conflict', __( 'The vendor request changed since it was read.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return $request ? $this->get_request( $id ) : $this->get_application( $id );
	}

	private function row_result( $row, string $code, callable $hydrate ) {
		global $wpdb;
		return '' !== (string) $wpdb->last_error ? new \WP_Error( $code, __( 'The private vendor record could not be read.', 'extrachill-events' ) ) : ( is_array( $row ) ? call_user_func( $hydrate, $row ) : null );
	}
}
