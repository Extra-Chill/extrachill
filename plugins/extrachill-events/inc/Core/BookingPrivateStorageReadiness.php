<?php
/**
 * Private booking storage operational readiness.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

// Repository convention uses PSR-4 class names and concise method comments.
// phpcs:disable WordPress.Files.FileName,Generic.Commenting,Squiz.Commenting

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates repository and operator-owned gates without exposing evidence details. */
final class BookingPrivateStorageReadiness {

	public const EVIDENCE_VERSION = 1;

	private const PROHIBITED_DOCUMENT_CLASSES = array( 'banking', 'ssn_tin', 'tax_identity', 'w9' );

	/** Return a deterministic, non-secret readiness projection. */
	public static function audit( $provider, ?array $evidence = null, ?int $now = null ): array {
		$now      = null === $now ? time() : $now;
		$evidence = null === $evidence ? apply_filters( 'extrachill_events_booking_private_storage_evidence', array() ) : $evidence;
		$checks   = array(
			'provider'        => self::provider_check( $provider ),
			'attestation'     => self::attestation_check( $evidence, $now ),
			'document_policy' => self::document_policy_check( $evidence ),
			'malware_policy'  => self::malware_policy_check( $evidence ),
			'retention'       => self::retention_check( $evidence ),
			'backup_restore'  => self::backup_check( $evidence ),
			'access_denial'   => self::access_check( $evidence ),
			'upload_limits'   => self::limits_check( $evidence ),
			'capacity'        => self::capacity_check( $evidence ),
		);
		$ready    = ! in_array( 'blocked', array_column( $checks, 'status' ), true );

		return array(
			'version' => self::EVIDENCE_VERSION,
			'state'   => $ready ? 'ready' : 'blocked',
			'ready'   => $ready,
			'checks'  => $checks,
		);
	}

	private static function provider_check( $provider ): array {
		if ( $provider instanceof BookingPrivateFileProvider ) {
			return self::passed();
		}
		$known = array(
			'booking_private_storage_owner',
			'booking_private_storage_permissions',
			'booking_private_storage_public',
			'booking_private_storage_unavailable',
			'booking_private_storage_unsafe',
			'booking_private_storage_unwritable',
		);
		$code  = is_wp_error( $provider ) && in_array( $provider->get_error_code(), $known, true ) ? $provider->get_error_code() : 'booking_private_storage_unavailable';
		return self::blocked( $code );
	}

	private static function attestation_check( array $evidence, int $now ): array {
		$attested = self::timestamp( $evidence['attested_at'] ?? '' );
		$expires  = self::timestamp( $evidence['expires_at'] ?? '' );
		if ( self::EVIDENCE_VERSION !== (int) ( $evidence['version'] ?? 0 ) || false === $attested || false === $expires ) {
			return self::blocked( 'booking_private_attestation_missing' );
		}
		if ( $attested > $now || $expires <= $now || $expires <= $attested ) {
			return self::blocked( 'booking_private_attestation_stale' );
		}
		return self::passed();
	}

	private static function document_policy_check( array $evidence ): array {
		$policy     = is_array( $evidence['document_policy'] ?? null ) ? $evidence['document_policy'] : array();
		$allowed    = self::sorted_strings( $policy['allowed_purposes'] ?? array() );
		$expected   = self::sorted_strings( BookingAttachmentPolicy::PURPOSES );
		$prohibited = self::sorted_strings( $policy['prohibited_classes'] ?? array() );
		if ( true !== ( $policy['approved'] ?? false ) || $allowed !== $expected || array_diff( self::PROHIBITED_DOCUMENT_CLASSES, $prohibited ) ) {
			return self::blocked( 'booking_private_document_policy_incomplete' );
		}
		return self::passed();
	}

	private static function malware_policy_check( array $evidence ): array {
		$policy   = is_array( $evidence['malware_policy'] ?? null ) ? $evidence['malware_policy'] : array();
		$required = array_values( array_filter( BookingAttachmentPolicy::allowed_mimes(), array( BookingAttachmentPolicy::class, 'requires_malware_scan' ) ) );
		if ( true !== ( $policy['approved'] ?? false ) || true !== ( $policy['clean_probe_passed'] ?? false ) || true !== ( $policy['rejection_probe_passed'] ?? false ) || self::sorted_strings( $policy['required_mime_types'] ?? array() ) !== self::sorted_strings( $required ) ) {
			return self::blocked( 'booking_private_malware_policy_incomplete' );
		}
		return self::passed();
	}

	private static function retention_check( array $evidence ): array {
		$policy = is_array( $evidence['retention_policy'] ?? null ) ? $evidence['retention_policy'] : array();
		$holds  = self::sorted_strings( $policy['legal_hold_statuses'] ?? array() );
		if ( true !== ( $policy['approved'] ?? false ) || 1 !== (int) ( $policy['version'] ?? 0 ) || array( 'completed', 'confirmed' ) !== $holds || true !== ( $policy['deletion_authority_approved'] ?? false ) || true !== ( $policy['orphan_handling_approved'] ?? false ) || true !== ( $policy['privacy_request_behavior_approved'] ?? false ) || true !== ( $policy['legal_hold_probe_passed'] ?? false ) ) {
			return self::blocked( 'booking_private_retention_policy_incomplete' );
		}
		return self::passed();
	}

	private static function backup_check( array $evidence ): array {
		$backup = is_array( $evidence['backup_restore'] ?? null ) ? $evidence['backup_restore'] : array();
		if ( true !== ( $backup['encrypted'] ?? false ) || true !== ( $backup['path_covered'] ?? false ) || true !== ( $backup['retention_documented'] ?? false ) || true !== ( $backup['deletion_propagation_documented'] ?? false ) || true !== ( $backup['restore_test_passed'] ?? false ) || false === self::timestamp( $backup['restore_tested_at'] ?? '' ) ) {
			return self::blocked( 'booking_private_backup_restore_unproven' );
		}
		return self::passed();
	}

	private static function access_check( array $evidence ): array {
		$access = is_array( $evidence['access_denial'] ?? null ) ? $evidence['access_denial'] : array();
		if ( 'staging' !== ( $access['environment'] ?? '' ) || true !== ( $access['static_probe_passed'] ?? false ) || true !== ( $access['application_probe_passed'] ?? false ) || false === self::timestamp( $access['tested_at'] ?? '' ) ) {
			return self::blocked( 'booking_private_access_denial_unproven' );
		}
		return self::passed();
	}

	private static function limits_check( array $evidence ): array {
		$limits             = is_array( $evidence['upload_limits'] ?? null ) ? $evidence['upload_limits'] : array();
		$per_file_required  = BookingAttachmentPolicy::MAX_BYTES;
		$aggregate_required = BookingInquiryAdmissionService::MAX_AGGREGATE_BYTES;
		foreach ( array( 'ingress_bytes', 'php_post_bytes' ) as $key ) {
			if ( (int) ( $limits[ $key ] ?? 0 ) < $aggregate_required ) {
				return self::blocked( 'booking_private_upload_limits_mismatch' );
			}
		}
		foreach ( array( 'php_upload_bytes', 'wordpress_bytes', 'application_bytes' ) as $key ) {
			if ( (int) ( $limits[ $key ] ?? 0 ) < $per_file_required ) {
				return self::blocked( 'booking_private_upload_limits_mismatch' );
			}
		}
		return BookingAttachmentPolicy::max_bytes() >= $per_file_required ? self::passed() : self::blocked( 'booking_private_upload_limits_mismatch' );
	}

	private static function capacity_check( array $evidence ): array {
		$capacity = is_array( $evidence['capacity'] ?? null ) ? $evidence['capacity'] : array();
		if ( true !== ( $capacity['monitoring_enabled'] ?? false ) || 'fail_closed' !== ( $capacity['pressure_behavior'] ?? '' ) || true !== ( $capacity['pressure_probe_passed'] ?? false ) ) {
			return self::blocked( 'booking_private_capacity_policy_incomplete' );
		}
		return self::passed();
	}

	private static function timestamp( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d\TH:i:s\Z' ) === $value ? $date->getTimestamp() : false;
	}

	private static function sorted_strings( $values ): array {
		if ( ! is_array( $values ) || count( $values ) !== count( array_filter( $values, 'is_string' ) ) ) {
			return array();
		}
		$values = array_values( array_unique( $values ) );
		sort( $values, SORT_STRING );
		return $values;
	}

	private static function passed(): array {
		return array(
			'status' => 'pass',
			'code'   => 'ready',
		);
	}

	private static function blocked( string $code ): array {
		return array(
			'status' => 'blocked',
			'code'   => $code,
		);
	}
}
