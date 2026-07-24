<?php
/**
 * Booking attachment vocabulary and validation policy.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps booking-specific file policy out of the generic storage owner. */
final class BookingAttachmentPolicy {

	public const MAX_BYTES = 20971520;
	public const PURPOSES  = array( 'promo_image', 'epk', 'press_release', 'stage_plot', 'technical_rider', 'hospitality_rider', 'insurance', 'contract', 'other_private_evidence' );
	public const STATES    = array( 'active', 'replaced', 'deleted', 'purged' );
	public const UPLOADERS = array( 'anonymous', 'user', 'email', 'system' );

	private const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'pdf'          => 'application/pdf',
		'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'csv'          => 'text/csv',
		'txt'          => 'text/plain',
	);

	/** Return the effective application limit bounded by the platform limit. */
	public static function max_bytes(): int {
		$platform_limit = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : self::MAX_BYTES;
		return max( 1, min( self::MAX_BYTES, $platform_limit ) );
	}

	/** Return the domain MIME allowlist keyed by extensions. */
	public static function allowed_mimes(): array {
		return self::MIMES;
	}

	/**
	 * Require an explicit scanner verdict for active document formats.
	 *
	 * @param string $mime_type Detected MIME type.
	 */
	public static function requires_malware_scan( string $mime_type ): bool {
		return in_array(
			$mime_type,
			array(
				'application/pdf',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			),
			true
		);
	}

	/**
	 * Validate trusted metadata returned by the private storage provider.
	 *
	 * @param array  $metadata Trusted object metadata.
	 * @param string $purpose  Booking-domain purpose.
	 */
	public function validate( array $metadata, string $purpose ) {
		if ( ! in_array( $purpose, self::PURPOSES, true ) ) {
			return new \WP_Error( 'invalid_booking_attachment_purpose', __( 'The attachment purpose is not supported.', 'extrachill-events' ) );
		}

		$filename = sanitize_file_name( (string) ( $metadata['filename'] ?? '' ) );
		if ( '' === $filename || (string) ( $metadata['filename'] ?? '' ) !== $filename || basename( $filename ) !== $filename || 255 < strlen( $filename ) ) {
			return new \WP_Error( 'invalid_booking_attachment_filename', __( 'The attachment filename is unsafe.', 'extrachill-events' ) );
		}

		$size      = $metadata['byte_size'] ?? null;
		$max_bytes = self::max_bytes();
		if ( ! is_int( $size ) || 1 > $size || $max_bytes < $size ) {
			return new \WP_Error( 'invalid_booking_attachment_size', __( 'The attachment size is outside the allowed range.', 'extrachill-events' ), array( 'max_bytes' => $max_bytes ) );
		}

		$filetype = wp_check_filetype( $filename, self::MIMES );
		$mime     = (string) ( $metadata['mime_type'] ?? '' );
		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) || $mime !== $filetype['type'] ) {
			return new \WP_Error( 'invalid_booking_attachment_type', __( 'The attachment extension and detected content type do not agree.', 'extrachill-events' ) );
		}

		$hash = strtolower( (string) ( $metadata['content_hash'] ?? '' ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return new \WP_Error( 'invalid_booking_attachment_hash', __( 'The attachment requires a valid SHA-256 content hash.', 'extrachill-events' ) );
		}

		return array(
			'original_filename' => $filename,
			'mime_type'         => $mime,
			'byte_size'         => $size,
			'content_hash'      => $hash,
		);
	}

	/**
	 * Tax-identity forms require a separately approved secure vault and policy.
	 *
	 * @param string $filename Safe original filename.
	 */
	public function is_default_denied_filename( string $filename ): bool {
		return 1 === preg_match( '/(?:^|[-_.\s])(?:w[-_.\s]?9|tax|tin|ein|ssn)(?:[-_.\s]|$)/i', $filename );
	}

	/**
	 * Preserve audit-sensitive documents after a booking becomes contractual.
	 *
	 * @param array $attachment Attachment metadata.
	 * @param array $booking    Booking record.
	 */
	public function requires_audit_retention( array $attachment, array $booking ): bool {
		return in_array( $booking['status'], array( 'confirmed', 'completed' ), true )
			&& ! in_array( $attachment['purpose'], array( 'promo_image', 'epk', 'press_release' ), true );
	}
}
