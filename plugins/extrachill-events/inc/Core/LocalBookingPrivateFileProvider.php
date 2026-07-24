<?php
/**
 * Events-owned local private booking byte storage.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores opaque booking objects outside every web-addressable path. */
final class LocalBookingPrivateFileProvider implements BookingPrivateFileProvider {

	private const OBJECT_PATTERN = '/^[a-f0-9]{64}$/';
	private const TOKEN_TTL      = 300;
	private const CLAIM_TTL      = 3600;

	/** Private storage root.
	 *
	 * @var string
	 */
	private $root = '';

	/** Attachment policy.
	 *
	 * @var BookingAttachmentPolicy
	 */
	private $policy;

	/** Fail-closed configuration error.
	 *
	 * @var \WP_Error|null
	 */
	private $configuration_error;
	/** Root device/inode identity pinned at construction.
	 *
	 * @var array|null
	 */
	private $root_identity;
	/** Pinned one-time handoff directory identity.
	 *
	 * @var array|null
	 */
	private $handoffs_identity;

	/**
	 * Validate the configured root and initialize private subdirectories.
	 *
	 * @param string|null                  $root   Explicit test root, or configured production root.
	 * @param BookingAttachmentPolicy|null $policy Attachment policy.
	 */
	public function __construct( ?string $root = null, ?BookingAttachmentPolicy $policy = null ) {
		$this->policy = $policy ? $policy : new BookingAttachmentPolicy();
		$configured   = null !== $root ? $root : ( defined( 'EXTRACHILL_EVENTS_PRIVATE_STORAGE_ROOT' ) ? EXTRACHILL_EVENTS_PRIVATE_STORAGE_ROOT : '' );
		$validated    = $this->validate_root( (string) $configured );
		if ( is_wp_error( $validated ) ) {
			$this->configuration_error = $validated;
			return;
		}
		$this->root          = $validated;
		$this->root_identity = lstat( $validated );
		foreach ( array( $this->objects_directory(), $this->temporary_directory(), $this->handoffs_directory() ) as $directory ) {
			if ( ! $this->ensure_private_directory( $directory ) ) {
				$this->configuration_error = new \WP_Error( 'booking_private_storage_unwritable', __( 'Private booking storage could not initialize secure directories.', 'extrachill-events' ), array( 'status' => 503 ) );
				$this->root                = '';
				return;
			}
		}
		$this->handoffs_identity = lstat( $this->handoffs_directory() );
	}

	/** Return whether this provider is safe to use. */
	public function is_ready(): bool {
		if ( '' === $this->root || null !== $this->configuration_error || ! is_array( $this->root_identity ) ) {
			return false;
		}
		$current     = lstat( $this->root );
		$permissions = fileperms( $this->root );
		$owner       = fileowner( $this->root );
		return is_array( $current )
			&& $current['dev'] === $this->root_identity['dev']
			&& $current['ino'] === $this->root_identity['ino']
			&& ! is_link( $this->root )
			&& false !== $permissions
			&& 0 === ( $permissions & 0022 )
			&& 0 === ( $permissions & 0007 )
			&& function_exists( 'posix_geteuid' )
			&& false !== $owner
			&& (int) posix_geteuid() === (int) $owner;
	}

	/** Return the fail-closed configuration result. */
	public function configuration_error() {
		return $this->configuration_error ? $this->configuration_error : new \WP_Error( 'booking_private_storage_unavailable', __( 'Private booking file storage is not configured.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	/**
	 * Stage validated bytes and return an opaque immutable reference.
	 *
	 * @param string $source_path Source file path.
	 * @param string $filename    Original filename.
	 * @param string $purpose     Booking attachment purpose.
	 */
	public function stage( string $source_path, string $filename, string $purpose ) {
		if ( ! $this->is_ready() ) {
			return $this->configuration_error();
		}
		if ( '' === $source_path || is_link( $source_path ) || ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
			return new \WP_Error( 'booking_private_source_invalid', __( 'The private attachment source is invalid.', 'extrachill-events' ) );
		}

		$safe_filename = sanitize_file_name( $filename );
		if ( '' === $safe_filename || $safe_filename !== $filename || basename( $safe_filename ) !== $safe_filename ) {
			return new \WP_Error( 'invalid_booking_attachment_filename', __( 'The attachment filename is unsafe.', 'extrachill-events' ) );
		}
		if ( $this->policy->is_default_denied_filename( $safe_filename ) ) {
			return new \WP_Error( 'booking_tax_document_denied', __( 'Tax identity documents require an approved secure vault and are not accepted here.', 'extrachill-events' ) );
		}

		$temporary = tempnam( $this->temporary_directory(), 'stage-' );
		if ( false === $temporary || ! $this->is_contained( $temporary ) ) {
			return new \WP_Error( 'booking_private_stage_failed', __( 'The private attachment could not be staged.', 'extrachill-events' ) );
		}

		try {
			if ( ! copy( $source_path, $temporary ) || ! chmod( $temporary, 0600 ) ) {
				return new \WP_Error( 'booking_private_stage_failed', __( 'The private attachment could not be copied securely.', 'extrachill-events' ) );
			}
			clearstatcache( true, $temporary );
			$size = filesize( $temporary );
			$hash = hash_file( 'sha256', $temporary );
			$mime = $this->detect_mime( $temporary, $safe_filename );
			if ( false === $size || false === $hash || '' === $mime ) {
				return new \WP_Error( 'booking_private_metadata_failed', __( 'Private attachment metadata could not be derived.', 'extrachill-events' ) );
			}

			$metadata = $this->policy->validate(
				array(
					'filename'     => $safe_filename,
					'mime_type'    => $mime,
					'byte_size'    => (int) $size,
					'content_hash' => $hash,
				),
				$purpose
			);
			if ( is_wp_error( $metadata ) ) {
				return $metadata;
			}

			$scan_status = 'not_required';
			if ( BookingAttachmentPolicy::requires_malware_scan( $metadata['mime_type'] ) ) {
				$scan = apply_filters( 'extrachill_events_booking_private_file_scan', null, $temporary, $metadata, $purpose );
				if ( is_wp_error( $scan ) ) {
					return $scan;
				}
				if ( false === $scan ) {
					return new \WP_Error( 'booking_private_scan_rejected', __( 'The private document failed malware scanning.', 'extrachill-events' ) );
				}
				if ( true !== $scan ) {
					return new \WP_Error( 'booking_private_scan_required', __( 'This document type requires an approved malware scanner before storage.', 'extrachill-events' ), array( 'status' => 503 ) );
				}
				$scan_status = 'clean';
			}

			$reference = bin2hex( random_bytes( 32 ) );
			$directory = $this->object_directory( $reference );
			if ( ! $this->ensure_private_directory( $directory ) ) {
				return new \WP_Error( 'booking_private_stage_failed', __( 'The private object directory could not be initialized.', 'extrachill-events' ) );
			}
			$blob_path     = $this->blob_path( $reference );
			$metadata_path = $this->metadata_path( $reference );
			if ( file_exists( $blob_path ) || file_exists( $metadata_path ) || ! rename( $temporary, $blob_path ) ) {
				return new \WP_Error( 'booking_private_finalize_failed', __( 'The private attachment could not be finalized atomically.', 'extrachill-events' ) );
			}
			$temporary = '';
			if ( ! chmod( $blob_path, 0600 ) ) {
				unlink( $blob_path );
				return new \WP_Error( 'booking_private_finalize_failed', __( 'The private attachment could not be secured.', 'extrachill-events' ) );
			}
			$record = array(
				'version'      => 1,
				'object_id'    => $reference,
				'filename'     => $metadata['original_filename'],
				'mime_type'    => $metadata['mime_type'],
				'byte_size'    => $metadata['byte_size'],
				'content_hash' => $metadata['content_hash'],
				'purpose'      => $purpose,
				'scan_status'  => $scan_status,
				'claims'       => array(),
				'state'        => 'ready',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			);
			if ( ! $this->write_metadata_atomic( $metadata_path, $record ) ) {
				unlink( $blob_path );
				return new \WP_Error( 'booking_private_finalize_failed', __( 'The private attachment metadata could not be finalized atomically.', 'extrachill-events' ) );
			}
			return $reference;
		} finally {
			if ( '' !== $temporary && file_exists( $temporary ) ) {
				unlink( $temporary );
			}
		}
	}

	/**
	 * Idempotently claim an object and return server-derived metadata.
	 *
	 * @param string $storage_reference Opaque object reference.
	 * @param string $claim_key         Consumer claim key.
	 * @param string $purpose           Booking attachment purpose.
	 */
	public function claim( string $storage_reference, string $claim_key, string $purpose = '' ) {
		if ( ! $this->is_ready() ) {
			return $this->configuration_error();
		}
		if ( ! $this->valid_reference( $storage_reference ) || '' === $claim_key || 191 < strlen( $claim_key ) ) {
			return new \WP_Error( 'booking_private_reference_invalid', __( 'The private object reference or claim is invalid.', 'extrachill-events' ) );
		}
		return $this->with_object_lock(
			$storage_reference,
			function () use ( $storage_reference, $claim_key, $purpose ) {
				$record = $this->read_record( $storage_reference );
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				if ( '' !== $purpose && $purpose !== $record['purpose'] ) {
					return new \WP_Error( 'booking_private_purpose_mismatch', __( 'The private object was admitted for a different purpose.', 'extrachill-events' ) );
				}
				if ( 'ready' !== $record['state'] ) {
					return new \WP_Error( 'booking_private_object_retiring', __( 'The private object is being retired and cannot accept claims.', 'extrachill-events' ), array( 'status' => 409 ) );
				}
				$record['claims'][ $claim_key ] = array(
					'state'      => 'active',
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				);
				if ( ! $this->write_metadata_atomic( $this->metadata_path( $storage_reference ), $record ) ) {
					return new \WP_Error( 'booking_private_claim_failed', __( 'The private object claim could not be persisted.', 'extrachill-events' ) );
				}
				return $this->public_metadata( $record );
			}
		);
	}

	/**
	 * Release one failed claim while preserving other consumers.
	 *
	 * @param string $storage_reference Opaque object reference.
	 * @param string $claim_key         Consumer claim key.
	 */
	public function release_claim( string $storage_reference, string $claim_key ) {
		if ( ! $this->is_ready() || ! $this->valid_reference( $storage_reference ) ) {
			return new \WP_Error( 'booking_private_reference_invalid', __( 'The private object reference is invalid.', 'extrachill-events' ) );
		}
		return $this->with_object_lock(
			$storage_reference,
			function () use ( $storage_reference, $claim_key ) {
				$record = $this->read_record( $storage_reference );
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				if ( ! isset( $record['claims'][ $claim_key ] ) ) {
					return true;
				}
				$record['claims'][ $claim_key ] = array(
					'state'      => 'abandoned',
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				);
				return $this->write_metadata_atomic( $this->metadata_path( $storage_reference ), $record )
					? true
					: new \WP_Error( 'booking_private_claim_compensation_failed', __( 'The failed private object claim could not be marked for recovery.', 'extrachill-events' ) );
			}
		);
	}

	/**
	 * Return one stable page of active claims for explicit reconciliation.
	 *
	 * @param string|null $cursor Opaque exclusive keyset cursor.
	 */
	public function inspect_claims( ?string $cursor = null ) {
		if ( ! $this->is_ready() ) {
			return $this->configuration_error();
		}
		if ( null !== $cursor && 1 !== preg_match( '/^[a-f0-9]{64}$/', $cursor ) ) {
			return new \WP_Error( 'booking_private_claim_cursor_invalid', __( 'The private claim continuation is invalid.', 'extrachill-events' ) );
		}
		$candidates = array();
		$uncertain  = 0;
		$iterator   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->objects_directory(), \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() || '.json' !== substr( $file->getFilename(), -5 ) ) {
				continue;
			}
			$reference = substr( $file->getFilename(), 0, -5 );
			if ( ! $this->valid_reference( $reference ) ) {
				continue;
			}
			$record = $this->read_record( $reference );
			if ( is_wp_error( $record ) ) {
				$recoverable = $this->read_record_allow_missing_blob( $reference );
				if ( is_array( $recoverable ) && 'retiring' === ( $recoverable['state'] ?? '' ) ) {
					continue;
				}
			}
			if ( is_wp_error( $record ) ) {
				++$uncertain;
				continue;
			}
			foreach ( $record['claims'] as $claim_key => $claim ) {
				if ( 'active' !== ( $claim['state'] ?? '' ) ) {
					continue;
				}
				$key = hash( 'sha256', $reference . "\0" . (string) $claim_key );
				if ( null !== $cursor && strcmp( $key, $cursor ) <= 0 ) {
					continue;
				}
				$candidates[] = array(
					'cursor'            => $key,
					'storage_reference' => $reference,
					'claim_key'         => (string) $claim_key,
					'state'             => (string) ( $claim['state'] ?? '' ),
					'updated_at'        => (string) ( $claim['updated_at'] ?? '' ),
				);
			}
		}
		usort(
			$candidates,
			static function ( array $left, array $right ): int {
				return strcmp( $left['cursor'], $right['cursor'] );
			}
		);
		$truncated    = count( $candidates ) > 250;
		$claims       = array_slice( $candidates, 0, 250 );
		$continuation = $truncated && ! empty( $claims ) ? $claims[ count( $claims ) - 1 ]['cursor'] : null;
		foreach ( $claims as &$claim ) {
			unset( $claim['cursor'] );
		}
		unset( $claim );
		return array(
			'claims'       => $claims,
			'uncertain'    => $uncertain,
			'truncated'    => $truncated,
			'continuation' => $continuation,
		);
	}

	/**
	 * Return a short-lived opaque one-time stream handoff.
	 *
	 * @param string $storage_reference    Opaque object reference.
	 * @param string $attachment_public_id Authorized attachment identity.
	 * @param int    $actor_id             Authorized user identity.
	 * @param string $purpose              Authorized purpose.
	 * @param string $claim_key            Exact active claim.
	 */
	public function download_descriptor( string $storage_reference, string $attachment_public_id, int $actor_id, string $purpose, string $claim_key ) {
		if ( ! $this->handoffs_directory_is_safe() ) {
			return new \WP_Error( 'booking_private_handoff_directory_unsafe', __( 'The private handoff directory changed unexpectedly.', 'extrachill-events' ) );
		}
		return $this->with_object_lock(
			$storage_reference,
			function () use ( $storage_reference, $attachment_public_id, $actor_id, $purpose, $claim_key ) {
				$record = $this->read_record( $storage_reference );
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				if ( 'ready' !== $record['state'] || 'active' !== ( $record['claims'][ $claim_key ]['state'] ?? '' ) || $purpose !== $record['purpose'] || '' === $attachment_public_id || $actor_id < 1 ) {
					return new \WP_Error( 'booking_private_handoff_forbidden', __( 'The private object is not available for this attachment.', 'extrachill-events' ), array( 'status' => 403 ) );
				}
				$token   = bin2hex( random_bytes( 32 ) );
				$expires = time() + self::TOKEN_TTL;
				$handoff = array(
					'version'              => 1,
					'object_id'            => $storage_reference,
					'attachment_public_id' => $attachment_public_id,
					'actor_id'             => $actor_id,
					'purpose'              => $purpose,
					'claim_key'            => $claim_key,
					'expires'              => $expires,
				);
				if ( ! $this->write_metadata_atomic( $this->handoff_path( $token ), $handoff ) ) {
					return new \WP_Error( 'booking_private_handoff_failed', __( 'The private stream handoff could not be persisted.', 'extrachill-events' ) );
				}
				return array(
					'stream_token' => $token,
					'expires_at'   => gmdate( 'c', $expires ),
				);
			}
		);
	}

	/**
	 * Consume one opaque handoff and open its exact immutable bytes.
	 *
	 * @param string $stream_token         Opaque one-time handoff.
	 * @param string $attachment_public_id Authorized attachment identity.
	 * @param int    $actor_id             Currently authorized user.
	 * @param string $purpose              Authorized purpose.
	 */
	public function open_stream( string $stream_token, string $attachment_public_id, int $actor_id, string $purpose ) {
		if ( ! $this->valid_reference( $stream_token ) ) {
			return new \WP_Error( 'booking_private_stream_invalid', __( 'The private stream token is invalid.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		$handoff_path = $this->handoff_path( $stream_token );
		$consuming    = $handoff_path . '.consuming';
		if ( ! $this->handoffs_directory_is_safe() || ! $this->is_contained( $handoff_path ) || is_link( $handoff_path ) || ! is_file( $handoff_path ) || ! rename( $handoff_path, $consuming ) ) {
			return new \WP_Error( 'booking_private_stream_invalid', __( 'The private stream token is invalid.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		if ( ! $this->handoffs_directory_is_safe() || is_link( $consuming ) || ! is_file( $consuming ) ) {
			return new \WP_Error( 'booking_private_handoff_directory_unsafe', __( 'The private handoff directory changed unexpectedly.', 'extrachill-events' ) );
		}
		$contents = file_get_contents( $consuming );
		$decoded  = false !== $contents ? json_decode( $contents, true ) : null;
		if ( ! $this->handoffs_directory_is_safe() || ! unlink( $consuming ) ) {
			return new \WP_Error( 'booking_private_handoff_consume_failed', __( 'The private stream handoff could not be consumed safely.', 'extrachill-events' ) );
		}
		if ( ! is_array( $decoded ) || time() >= (int) ( $decoded['expires'] ?? 0 ) || ! hash_equals( (string) ( $decoded['attachment_public_id'] ?? '' ), $attachment_public_id ) || (int) ( $decoded['actor_id'] ?? 0 ) !== $actor_id || ! hash_equals( (string) ( $decoded['purpose'] ?? '' ), $purpose ) ) {
			return new \WP_Error( 'booking_private_stream_expired', __( 'The private stream token is invalid or expired.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		$reference = (string) ( $decoded['object_id'] ?? '' );
		return $this->with_object_lock(
			$reference,
			function () use ( $reference, $decoded ) {
				$record = $this->read_record( $reference );
				if ( is_wp_error( $record ) ) {
					return $record;
				}
				if ( 'ready' !== $record['state'] || 'active' !== ( $record['claims'][ (string) ( $decoded['claim_key'] ?? '' ) ]['state'] ?? '' ) ) {
					return new \WP_Error( 'booking_private_stream_revoked', __( 'The private stream handoff has been revoked.', 'extrachill-events' ), array( 'status' => 403 ) );
				}
				return $this->open_verified_blob( $reference, $record );
			}
		);
	}

	/**
	 * Permanently and idempotently retire one exact object.
	 *
	 * @param string $storage_reference Opaque object reference.
	 */
	public function retire( string $storage_reference ) {
		if ( ! $this->is_ready() || ! $this->valid_reference( $storage_reference ) ) {
			return new \WP_Error( 'booking_private_reference_invalid', __( 'The private object reference is invalid.', 'extrachill-events' ) );
		}
		return $this->with_object_lock(
			$storage_reference,
			function () use ( $storage_reference ) {
				$blob     = $this->blob_path( $storage_reference );
				$metadata = $this->metadata_path( $storage_reference );
				if ( file_exists( $metadata ) ) {
					$record = $this->read_record_allow_missing_blob( $storage_reference );
					if ( is_wp_error( $record ) ) {
						return $record;
					}
					$record['state'] = 'retiring';
					if ( ! $this->write_metadata_atomic( $metadata, $record ) ) {
						return new \WP_Error( 'booking_private_retirement_tombstone_failed', __( 'Private object retirement could not be made recoverable.', 'extrachill-events' ) );
					}
				}
				if ( file_exists( $blob ) && ! unlink( $blob ) ) {
					return new \WP_Error( 'booking_private_retirement_partial', __( 'Private object retirement remains incomplete and recoverable.', 'extrachill-events' ) );
				}
				if ( file_exists( $metadata ) && ! unlink( $metadata ) ) {
					return new \WP_Error( 'booking_private_retirement_partial', __( 'Private object retirement remains incomplete and recoverable.', 'extrachill-events' ) );
				}
				return true;
			}
		);
	}

	/**
	 * Remove interrupted temporary files and unreferenced provisional blobs.
	 *
	 * This method is intentionally not scheduled until issue #336 approves
	 * retention, backups, and operational ownership.
	 *
	 * @param array $policy Explicit minimum age and legal-hold callback.
	 */
	public function cleanup_provisional( array $policy = array() ) {
		if ( ! $this->is_ready() ) {
			return $this->configuration_error();
		}
		$minimum_age = absint( $policy['minimum_age'] ?? 0 );
		$legal_hold  = $policy['legal_hold_callback'] ?? null;
		if ( $minimum_age < self::CLAIM_TTL || ! is_callable( $legal_hold ) ) {
			return new \WP_Error( 'booking_private_provisional_cleanup_policy_required', __( 'Provisional cleanup requires an explicit approved retention and legal-hold policy.', 'extrachill-events' ) );
		}
		$cutoff   = time() - max( 0, $minimum_age );
		$deleted  = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			$path = $file->getPathname();
			if ( ! $file->isFile() || $file->isLink() || $file->getMTime() > $cutoff || ! $this->is_contained( $path ) ) {
				continue;
			}
			$is_temporary      = 0 === strpos( $path, $this->temporary_directory() . DIRECTORY_SEPARATOR );
			$is_metadata_temp  = 0 === strpos( $file->getFilename(), '.metadata-' );
			$is_handoff        = 0 === strpos( $path, $this->handoffs_directory() . DIRECTORY_SEPARATOR );
			$is_orphan_blob    = '.blob' === substr( $path, -5 ) && ! file_exists( substr( $path, 0, -5 ) . '.json' );
			$is_orphan_sidecar = '.json' === substr( $path, -5 ) && ! file_exists( substr( $path, 0, -5 ) . '.blob' );
			if ( '.json' === substr( $path, -5 ) && ! $is_orphan_sidecar ) {
				$reference = substr( $file->getFilename(), 0, -5 );
				if ( $this->valid_reference( $reference ) ) {
					$unclaimed = $this->cleanup_unclaimed_object( $reference, $cutoff, $legal_hold );
					if ( is_wp_error( $unclaimed ) ) {
						return $unclaimed;
					}
					if ( is_int( $unclaimed ) ) {
						$deleted += $unclaimed;
					}
					continue;
				}
			}
			$held = $legal_hold( $is_handoff ? 'handoff' : 'provisional', gmdate( 'c', $file->getMTime() ) );
			if ( is_wp_error( $held ) ) {
				return $held;
			}
			if ( false === $held && ( $is_temporary || $is_handoff || $is_metadata_temp || $is_orphan_blob || $is_orphan_sidecar ) && file_exists( $path ) && unlink( $path ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Delete a complete unclaimed object under its claim lock.
	 *
	 * @param string   $reference  Object reference.
	 * @param int      $cutoff     Maximum modification timestamp.
	 * @param callable $legal_hold Approved legal-hold callback.
	 */
	private function cleanup_unclaimed_object( string $reference, int $cutoff, callable $legal_hold ) {
		return $this->with_object_lock(
			$reference,
			function () use ( $reference, $cutoff, $legal_hold ) {
				$record = $this->read_record( $reference );
				if ( is_wp_error( $record ) || $this->has_live_claims( $record, $cutoff ) || filemtime( $this->metadata_path( $reference ) ) > $cutoff ) {
					return 0;
				}
				$held = $legal_hold( 'object', $this->public_metadata( $record ) );
				if ( is_wp_error( $held ) ) {
					return $held;
				}
				if ( false !== $held ) {
					return 0;
				}
				$deleted = 0;
				foreach ( array( $this->blob_path( $reference ), $this->metadata_path( $reference ) ) as $path ) {
					if ( file_exists( $path ) && unlink( $path ) ) {
						++$deleted;
					}
				}
				return $deleted;
			}
		);
	}

	/**
	 * Validate the explicit non-public storage root.
	 *
	 * @param string $configured Configured root.
	 */
	private function validate_root( string $configured ) {
		$configured = rtrim( str_replace( '\\', '/', trim( $configured ) ), '/' );
		if ( '' === $configured ) {
			return new \WP_Error( 'booking_private_storage_unavailable', __( 'Private booking file storage requires explicit configuration.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( '/' !== substr( $configured, 0, 1 ) || ! is_dir( $configured ) || is_link( $configured ) ) {
			return new \WP_Error( 'booking_private_storage_unsafe', __( 'The configured private storage root is missing or unsafe.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$real = realpath( $configured );
		if ( false === $real || str_replace( '\\', '/', $real ) !== $configured || ! is_writable( $real ) ) {
			return new \WP_Error( 'booking_private_storage_unsafe', __( 'The configured private storage root is unwritable or resolves through a symlink.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$permissions = fileperms( $real );
		$owner       = fileowner( $real );
		if ( ! function_exists( 'posix_geteuid' ) || false === $owner || (int) posix_geteuid() !== (int) $owner ) {
			return new \WP_Error( 'booking_private_storage_owner', __( 'The private storage root must be owned by the current process user.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( false === $permissions || 0 !== ( $permissions & 0022 ) || 0 !== ( $permissions & 0007 ) ) {
			return new \WP_Error( 'booking_private_storage_permissions', __( 'The private storage root must not be writable by a group or accessible by other system users.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		$blocked = array(
			defined( 'ABSPATH' ) ? ABSPATH : '',
			isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : '',
			'/mnt/extrachill-workspace',
			'/var/lib/datamachine/workspace',
		);
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads   = wp_upload_dir();
			$blocked[] = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';
		}
		foreach ( $blocked as $path ) {
			$blocked_real = '' !== $path && is_dir( $path ) ? realpath( $path ) : false;
			if ( false !== $blocked_real && ( $this->path_contains( $blocked_real, $real ) || $this->path_contains( $real, $blocked_real ) ) ) {
				return new \WP_Error( 'booking_private_storage_public', __( 'Private booking storage must be isolated from web and coding workspace paths.', 'extrachill-events' ), array( 'status' => 503 ) );
			}
		}
		return $real;
	}

	/**
	 * Ensure one provider-owned directory is private and contained.
	 *
	 * @param string $directory Directory path.
	 */
	private function ensure_private_directory( string $directory ): bool {
		$existing = $directory;
		while ( ! file_exists( $existing ) && dirname( $existing ) !== $existing ) {
			$existing = dirname( $existing );
		}
		$existing_real = realpath( $existing );
		if ( is_link( $existing ) || false === $existing_real || ! $this->is_contained( $existing_real ) ) {
			return false;
		}
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) ) {
			return false;
		}
		$real = realpath( $directory );
		return false !== $real && ! is_link( $directory ) && $this->is_contained( $real ) && chmod( $real, 0700 ) && is_writable( $real );
	}

	/**
	 * Detect MIME from bytes, using WordPress's extension/content agreement when available.
	 *
	 * @param string $path     Staged byte path.
	 * @param string $filename Safe original filename.
	 */
	private function detect_mime( string $path, string $filename ): string {
		if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
			$checked = wp_check_filetype_and_ext( $path, $filename, BookingAttachmentPolicy::allowed_mimes() );
			if ( ! empty( $checked['type'] ) ) {
				return (string) $checked['type'];
			}
		}
		if ( ! extension_loaded( 'fileinfo' ) ) {
			return '';
		}
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = false !== $finfo ? finfo_file( $finfo, $path ) : false;
		if ( false !== $finfo && PHP_VERSION_ID < 80100 ) {
			finfo_close( $finfo );
		}
		return is_string( $mime ) ? $mime : '';
	}

	/**
	 * Read and validate one metadata sidecar and its blob.
	 *
	 * @param string $reference Object reference.
	 */
	private function read_record( string $reference ) {
		if ( ! $this->is_ready() || ! $this->valid_reference( $reference ) ) {
			return new \WP_Error( 'booking_private_reference_invalid', __( 'The private object reference is invalid.', 'extrachill-events' ) );
		}
		$metadata_candidate = $this->metadata_path( $reference );
		$blob_candidate     = $this->blob_path( $reference );
		$metadata_path      = realpath( $metadata_candidate );
		$blob_path          = realpath( $blob_candidate );
		if ( false === $metadata_path || false === $blob_path || ! $this->is_contained( $metadata_path ) || ! $this->is_contained( $blob_path ) || is_link( $metadata_candidate ) || is_link( $blob_candidate ) || ! is_file( $metadata_path ) || ! is_file( $blob_path ) ) {
			return new \WP_Error( 'booking_private_object_missing', __( 'The private object was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$contents = file_get_contents( $metadata_path );
		$record   = false !== $contents ? json_decode( $contents, true ) : null;
		if ( ! is_array( $record ) || 1 !== (int) ( $record['version'] ?? 0 ) || ( $record['object_id'] ?? '' ) !== $reference || ! is_array( $record['claims'] ?? null ) || ! in_array( ( $record['state'] ?? '' ), array( 'ready', 'retiring' ), true ) ) {
			return new \WP_Error( 'booking_private_metadata_invalid', __( 'The private object metadata is invalid.', 'extrachill-events' ) );
		}
		$size = filesize( $blob_path );
		$hash = hash_file( 'sha256', $blob_path );
		if ( false === $size || false === $hash || (int) $record['byte_size'] !== (int) $size || ! hash_equals( (string) $record['content_hash'], $hash ) ) {
			return new \WP_Error( 'booking_private_object_corrupt', __( 'The private object does not match its server-derived metadata.', 'extrachill-events' ) );
		}
		$filename  = (string) ( $record['filename'] ?? '' );
		$mime_type = (string) ( $record['mime_type'] ?? '' );
		$filetype  = wp_check_filetype( $filename, BookingAttachmentPolicy::allowed_mimes() );
		$scan      = (string) ( $record['scan_status'] ?? '' );
		if (
			'' === $filename || sanitize_file_name( $filename ) !== $filename || basename( $filename ) !== $filename
			|| ! in_array( ( $record['purpose'] ?? '' ), BookingAttachmentPolicy::PURPOSES, true )
			|| empty( $filetype['type'] ) || $filetype['type'] !== $mime_type
			|| ! in_array( $scan, array( 'clean', 'not_required' ), true )
			|| ( BookingAttachmentPolicy::requires_malware_scan( $mime_type ) && 'clean' !== $scan )
		) {
			return new \WP_Error( 'booking_private_metadata_invalid', __( 'The private object metadata is invalid.', 'extrachill-events' ) );
		}
		return $record;
	}

	/**
	 * Read recoverable retirement metadata even after the blob is gone.
	 *
	 * @param string $reference Object reference.
	 */
	private function read_record_allow_missing_blob( string $reference ) {
		if ( file_exists( $this->blob_path( $reference ) ) ) {
			return $this->read_record( $reference );
		}
		$metadata = $this->metadata_path( $reference );
		if ( is_link( $metadata ) || ! is_file( $metadata ) || ! $this->is_contained( (string) realpath( $metadata ) ) ) {
			return new \WP_Error( 'booking_private_object_missing', __( 'The private object was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$contents = file_get_contents( $metadata );
		$record   = false !== $contents ? json_decode( $contents, true ) : null;
		return is_array( $record ) && ( $record['object_id'] ?? '' ) === $reference && 'retiring' === ( $record['state'] ?? '' )
			? $record
			: new \WP_Error( 'booking_private_metadata_invalid', __( 'The private object metadata is invalid.', 'extrachill-events' ) );
	}

	/**
	 * Open a blob and verify that the opened inode is still the validated path.
	 *
	 * @param string $reference Object reference.
	 * @param array  $record    Trusted metadata.
	 */
	private function open_verified_blob( string $reference, array $record ) {
		$candidate = $this->blob_path( $reference );
		$real      = realpath( $candidate );
		if ( false === $real || is_link( $candidate ) || ! $this->is_contained( $real ) ) {
			return new \WP_Error( 'booking_private_object_missing', __( 'The private object was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$stream = fopen( $candidate, 'rb' );
		if ( false === $stream ) {
			return new \WP_Error( 'booking_private_stream_failed', __( 'The private object could not be opened.', 'extrachill-events' ) );
		}
		$opened  = fstat( $stream );
		$current = lstat( $candidate );
		$hash    = hash_init( 'sha256' );
		$hashed  = hash_update_stream( $hash, $stream );
		$digest  = hash_final( $hash );
		if ( false === $opened || false === $current || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino'] || 0100000 !== ( $opened['mode'] & 0170000 ) || false === $hashed || ! hash_equals( (string) $record['content_hash'], $digest ) ) {
			fclose( $stream );
			return new \WP_Error( 'booking_private_object_corrupt', __( 'The private object changed before it could be opened safely.', 'extrachill-events' ) );
		}
		rewind( $stream );
		return $stream;
	}

	/**
	 * Return whether an object has an active or not-yet-expired abandoned claim.
	 *
	 * @param array $record Stored object record.
	 * @param int   $cutoff Operator-supplied cleanup cutoff.
	 */
	private function has_live_claims( array $record, int $cutoff ): bool {
		foreach ( $record['claims'] as $claim ) {
			if ( ! is_array( $claim ) || 'active' === ( $claim['state'] ?? '' ) ) {
				return true;
			}
			$updated = strtotime( (string) ( $claim['updated_at'] ?? '' ) );
			if ( false === $updated || $updated > min( $cutoff, time() - self::CLAIM_TTL ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Persist metadata through a same-directory temporary file and rename.
	 *
	 * @param string $path   Metadata path.
	 * @param array  $record Metadata record.
	 */
	private function write_metadata_atomic( string $path, array $record ): bool {
		$is_handoff = dirname( $path ) === $this->handoffs_directory();
		if ( ( $is_handoff && ! $this->handoffs_directory_is_safe() ) || ! $this->is_contained( $path ) || ! $this->ensure_private_directory( dirname( $path ) ) ) {
			return false;
		}
		$temporary = tempnam( dirname( $path ), '.metadata-' );
		if ( false === $temporary || ! $this->is_contained( $temporary ) ) {
			return false;
		}
		$encoded = wp_json_encode( $record );
		$written = false !== $encoded && false !== file_put_contents( $temporary, $encoded, LOCK_EX ) && chmod( $temporary, 0600 );
		if ( $written && ( ( ! $is_handoff || $this->handoffs_directory_is_safe() ) && rename( $temporary, $path ) ) ) {
			return true;
		}
		if ( ! $written && file_exists( $temporary ) ) {
			unlink( $temporary );
		}
		if ( file_exists( $temporary ) ) {
			unlink( $temporary );
		}
		return false;
	}

	/**
	 * Execute a metadata mutation under an object-specific lock.
	 *
	 * @param string   $reference Object reference.
	 * @param callable $callback  Locked operation.
	 */
	private function with_object_lock( string $reference, callable $callback ) {
		$directory = $this->object_directory( $reference );
		if ( ! $this->ensure_private_directory( $directory ) ) {
			return new \WP_Error( 'booking_private_lock_failed', __( 'The private object could not be locked.', 'extrachill-events' ) );
		}
		$lock_path = $directory . '/' . $reference . '.lock';
		$lock      = fopen( $lock_path, 'c' );
		if ( false === $lock || ! chmod( $lock_path, 0600 ) || ! flock( $lock, LOCK_EX ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return new \WP_Error( 'booking_private_lock_failed', __( 'The private object could not be locked.', 'extrachill-events' ) );
		}
		try {
			return $callback();
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * Return metadata safe for the booking service.
	 *
	 * @param array $record Stored metadata.
	 */
	private function public_metadata( array $record ): array {
		return array(
			'filename'     => $record['filename'],
			'mime_type'    => $record['mime_type'],
			'byte_size'    => (int) $record['byte_size'],
			'content_hash' => $record['content_hash'],
			'scan_status'  => $record['scan_status'],
		);
	}

	/** Return the object storage directory. */
	private function objects_directory(): string {
		return $this->root . '/objects';
	}

	/** Return the provisional storage directory. */
	private function temporary_directory(): string {
		return $this->root . '/.tmp';
	}

	/** Return the one-time handoff directory. */
	private function handoffs_directory(): string {
		return $this->root . '/.handoffs';
	}

	/**
	 * Return one opaque handoff sidecar path.
	 *
	 * @param string $token Opaque handoff token.
	 */
	private function handoff_path( string $token ): string {
		return $this->handoffs_directory() . '/' . hash( 'sha256', $token ) . '.json';
	}

	/** Revalidate the pinned handoff parent immediately before mutation. */
	private function handoffs_directory_is_safe(): bool {
		if ( ! $this->is_ready() || ! is_array( $this->handoffs_identity ) ) {
			return false;
		}
		$directory   = $this->handoffs_directory();
		$current     = lstat( $directory );
		$real        = realpath( $directory );
		$permissions = fileperms( $directory );
		$owner       = fileowner( $directory );
		return is_array( $current )
			&& false !== $real
			&& $real === $directory
			&& ! is_link( $directory )
			&& $current['dev'] === $this->handoffs_identity['dev']
			&& $current['ino'] === $this->handoffs_identity['ino']
			&& false !== $permissions
			&& 0 === ( $permissions & 0022 )
			&& 0 === ( $permissions & 0007 )
			&& function_exists( 'posix_geteuid' )
			&& false !== $owner
			&& (int) posix_geteuid() === (int) $owner;
	}

	/**
	 * Return one sharded object directory.
	 *
	 * @param string $reference Object reference.
	 */
	private function object_directory( string $reference ): string {
		return $this->objects_directory() . '/' . substr( $reference, 0, 2 ) . '/' . substr( $reference, 2, 2 );
	}

	/**
	 * Return one private blob path.
	 *
	 * @param string $reference Object reference.
	 */
	private function blob_path( string $reference ): string {
		return $this->object_directory( $reference ) . '/' . $reference . '.blob';
	}

	/**
	 * Return one private metadata path.
	 *
	 * @param string $reference Object reference.
	 */
	private function metadata_path( string $reference ): string {
		return $this->object_directory( $reference ) . '/' . $reference . '.json';
	}

	/**
	 * Validate an opaque object reference.
	 *
	 * @param string $reference Object reference.
	 */
	private function valid_reference( string $reference ): bool {
		return 1 === preg_match( self::OBJECT_PATTERN, $reference );
	}

	/**
	 * Check lexical containment beneath the validated root.
	 *
	 * @param string $path Candidate path.
	 */
	private function is_contained( string $path ): bool {
		return '' !== $this->root && $this->path_contains( $this->root, $path );
	}

	/**
	 * Check whether one normalized path contains another.
	 *
	 * @param string $root_path Parent path.
	 * @param string $child     Child path.
	 */
	private function path_contains( string $root_path, string $child ): bool {
		$root_path = rtrim( str_replace( '\\', '/', $root_path ), '/' );
		$child     = str_replace( '\\', '/', $child );
		return $root_path === $child || 0 === strpos( $child, $root_path . '/' );
	}
}
