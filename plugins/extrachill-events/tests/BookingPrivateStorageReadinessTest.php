<?php
/**
 * Private booking storage operational readiness tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingAttachmentPolicy;
use ExtraChillEvents\Core\BookingInquiryAdmissionService;
use ExtraChillEvents\Core\BookingPrivateFileProviders;
use ExtraChillEvents\Core\BookingPrivateStorageReadiness;
use PHPUnit\Framework\TestCase;

// Repository tests use PSR-4 filenames and concise method names as descriptions.
// phpcs:disable WordPress.Files.FileName,Generic.Commenting,Squiz.Commenting

require_once __DIR__ . '/Support/BookingTestHarness.php';

/** Covers every fail-closed operational gate with synthetic evidence. */
final class BookingPrivateStorageReadinessTest extends TestCase {

	private const NOW = 1787313600;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test']['max_upload_size'] = BookingAttachmentPolicy::MAX_BYTES;
	}

	protected function tearDown(): void {
		$GLOBALS['ec_test_filters']['extrachill_events_booking_private_file_provider']    = array();
		$GLOBALS['ec_test_filters']['extrachill_events_booking_private_storage_evidence'] = array();
	}

	public function test_healthy_evidence_is_ready_and_redacted(): void {
		$evidence                 = $this->healthy_evidence();
		$evidence['private_path'] = '/srv/private/booking-files';
		$evidence['credential']   = 'secret-value';
		$result                   = BookingPrivateStorageReadiness::audit( new BookingTestPrivateFileProvider(), $evidence, self::NOW );

		$this->assertTrue( $result['ready'] );
		$this->assertSame( 'ready', $result['state'] );
		$this->assertNotContains( 'blocked', array_column( $result['checks'], 'status' ) );
		$this->assertStringNotContainsString( '/srv/private', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( 'secret-value', wp_json_encode( $result ) );
	}

	/** @dataProvider providerFailureProvider */
	public function test_provider_path_and_permission_failures_remain_blocked_and_safe( string $code ): void {
		$result = BookingPrivateStorageReadiness::audit( new WP_Error( $code, '/private/path' ), $this->healthy_evidence(), self::NOW );

		$this->assertFalse( $result['ready'] );
		$this->assertSame( $code, $result['checks']['provider']['code'] );
		$this->assertStringNotContainsString( '/private/path', wp_json_encode( $result ) );
	}

	public function providerFailureProvider(): array {
		return array(
			'absent path'       => array( 'booking_private_storage_unavailable' ),
			'web-root path'     => array( 'booking_private_storage_public' ),
			'wrong owner'       => array( 'booking_private_storage_owner' ),
			'wrong permissions' => array( 'booking_private_storage_permissions' ),
			'unsafe path'       => array( 'booking_private_storage_unsafe' ),
		);
	}

	public function test_missing_and_stale_attestations_fail_closed(): void {
		$missing = BookingPrivateStorageReadiness::audit( new BookingTestPrivateFileProvider(), array(), self::NOW );
		$this->assertSame( 'booking_private_attestation_missing', $missing['checks']['attestation']['code'] );

		$stale               = $this->healthy_evidence();
		$stale['expires_at'] = '2026-08-20T00:00:00Z';
		$result              = BookingPrivateStorageReadiness::audit( new BookingTestPrivateFileProvider(), $stale, self::NOW );
		$this->assertSame( 'booking_private_attestation_stale', $result['checks']['attestation']['code'] );

		$invalid                = $this->healthy_evidence();
		$invalid['attested_at'] = 'tomorrow';
		$result                 = BookingPrivateStorageReadiness::audit( new BookingTestPrivateFileProvider(), $invalid, self::NOW );
		$this->assertSame( 'booking_private_attestation_missing', $result['checks']['attestation']['code'] );
	}

	public function test_effective_wordpress_limit_cannot_be_overridden_by_attestation(): void {
		$GLOBALS['ec_artist_test']['max_upload_size'] = BookingAttachmentPolicy::MAX_BYTES - 1;
		$result                                       = BookingPrivateStorageReadiness::audit( new BookingTestPrivateFileProvider(), $this->healthy_evidence(), self::NOW );

		$this->assertFalse( $result['ready'] );
		$this->assertSame( 'booking_private_upload_limits_mismatch', $result['checks']['upload_limits']['code'] );
	}

	/** @dataProvider incompleteEvidenceProvider */
	public function test_each_policy_and_probe_gate_blocks_activation( string $section, string $field, $value, string $check, string $code ): void {
		$evidence                       = $this->healthy_evidence();
		$evidence[ $section ][ $field ] = $value;
		$result                         = BookingPrivateStorageReadiness::audit( new BookingTestPrivateFileProvider(), $evidence, self::NOW );

		$this->assertFalse( $result['ready'] );
		$this->assertSame( $code, $result['checks'][ $check ]['code'] );
	}

	public function incompleteEvidenceProvider(): array {
		return array(
			'prohibited documents' => array( 'document_policy', 'prohibited_classes', array( 'w9', 'ssn_tin', 'tax_identity' ), 'document_policy', 'booking_private_document_policy_incomplete' ),
			'malware rejection'    => array( 'malware_policy', 'rejection_probe_passed', false, 'malware_policy', 'booking_private_malware_policy_incomplete' ),
			'retention holds'      => array( 'retention_policy', 'legal_hold_probe_passed', false, 'retention', 'booking_private_retention_policy_incomplete' ),
			'backup restore'       => array( 'backup_restore', 'restore_test_passed', false, 'backup_restore', 'booking_private_backup_restore_unproven' ),
			'static denial'        => array( 'access_denial', 'static_probe_passed', false, 'access_denial', 'booking_private_access_denial_unproven' ),
			'application denial'   => array( 'access_denial', 'application_probe_passed', false, 'access_denial', 'booking_private_access_denial_unproven' ),
			'aggregate mismatch'   => array( 'upload_limits', 'ingress_bytes', BookingInquiryAdmissionService::MAX_AGGREGATE_BYTES - 1, 'upload_limits', 'booking_private_upload_limits_mismatch' ),
			'per-file mismatch'    => array( 'upload_limits', 'php_upload_bytes', BookingAttachmentPolicy::MAX_BYTES - 1, 'upload_limits', 'booking_private_upload_limits_mismatch' ),
			'capacity pressure'    => array( 'capacity', 'pressure_probe_passed', false, 'capacity', 'booking_private_capacity_policy_incomplete' ),
		);
	}

	public function test_resolver_requires_complete_evidence_before_returning_provider(): void {
		$provider = new BookingTestPrivateFileProvider();
		add_filter( 'extrachill_events_booking_private_file_provider', static fn() => $provider );
		$this->assertSame( 'booking_private_storage_not_approved', BookingPrivateFileProviders::resolve()->get_error_code() );

		$evidence = $this->healthy_evidence();
		add_filter( 'extrachill_events_booking_private_storage_evidence', static fn() => $evidence );
		$this->assertSame( $provider, BookingPrivateFileProviders::resolve() );
	}

	private function healthy_evidence(): array {
		return array(
			'version'          => 1,
			'attested_at'      => '2026-08-21T00:00:00Z',
			'expires_at'       => '2026-11-21T00:00:00Z',
			'document_policy'  => array(
				'approved'           => true,
				'allowed_purposes'   => BookingAttachmentPolicy::PURPOSES,
				'prohibited_classes' => array( 'w9', 'ssn_tin', 'tax_identity', 'banking' ),
			),
			'malware_policy'   => array(
				'approved'               => true,
				'required_mime_types'    => array( 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ),
				'clean_probe_passed'     => true,
				'rejection_probe_passed' => true,
			),
			'retention_policy' => array(
				'version'                           => 1,
				'approved'                          => true,
				'legal_hold_statuses'               => array( 'confirmed', 'completed' ),
				'deletion_authority_approved'       => true,
				'orphan_handling_approved'          => true,
				'privacy_request_behavior_approved' => true,
				'legal_hold_probe_passed'           => true,
			),
			'backup_restore'   => array(
				'encrypted'                       => true,
				'path_covered'                    => true,
				'retention_documented'            => true,
				'deletion_propagation_documented' => true,
				'restore_test_passed'             => true,
				'restore_tested_at'               => '2026-08-20T00:00:00Z',
			),
			'access_denial'    => array(
				'environment'              => 'staging',
				'static_probe_passed'      => true,
				'application_probe_passed' => true,
				'tested_at'                => '2026-08-20T00:00:00Z',
			),
			'upload_limits'    => array(
				'ingress_bytes'     => BookingInquiryAdmissionService::MAX_AGGREGATE_BYTES,
				'php_post_bytes'    => BookingInquiryAdmissionService::MAX_AGGREGATE_BYTES,
				'php_upload_bytes'  => BookingAttachmentPolicy::MAX_BYTES,
				'wordpress_bytes'   => BookingAttachmentPolicy::MAX_BYTES,
				'application_bytes' => BookingAttachmentPolicy::MAX_BYTES,
			),
			'capacity'         => array(
				'monitoring_enabled'    => true,
				'pressure_behavior'     => 'fail_closed',
				'pressure_probe_passed' => true,
			),
		);
	}
}
