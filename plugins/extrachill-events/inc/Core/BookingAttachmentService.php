<?php
/**
 * Transactional booking attachment lifecycle.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates private object claims, metadata, authorization inputs, and audit activity. */
class BookingAttachmentService {

	/** Attachment metadata repository.
	 *
	 * @var BookingAttachmentRepository
	 */
	private $attachments;
	/** Booking repository.
	 *
	 * @var BookingRepository
	 */
	private $bookings;
	/** Activity repository.
	 *
	 * @var BookingActivityRepository
	 */
	private $activity;
	/** Attachment policy.
	 *
	 * @var BookingAttachmentPolicy
	 */
	private $policy;
	/** Private provider or setup error.
	 *
	 * @var BookingPrivateFileProvider|\WP_Error
	 */
	private $provider;
	/** Exact venue authorization policy.
	 *
	 * @var VenueAuthorization
	 */
	private $authorization;
	/**
	 * Build the booking attachment aggregate.
	 *
	 * @param BookingAttachmentRepository|null $attachments Attachment repository.
	 * @param BookingRepository|null           $bookings    Booking repository.
	 * @param BookingActivityRepository|null   $activity    Activity repository.
	 * @param BookingAttachmentPolicy|null     $policy      Attachment policy.
	 * @param mixed                            $provider      Private provider.
	 * @param VenueAuthorization|null          $authorization Exact venue authorization.
	 */
	public function __construct( ?BookingAttachmentRepository $attachments = null, ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?BookingAttachmentPolicy $policy = null, $provider = null, ?VenueAuthorization $authorization = null ) {
		$this->attachments   = $attachments ? $attachments : new BookingAttachmentRepository();
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->policy        = $policy ? $policy : new BookingAttachmentPolicy();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$resolved            = null !== $provider ? $provider : BookingPrivateFileProviders::resolve();
		$this->provider      = $resolved instanceof BookingPrivateFileProvider || is_wp_error( $resolved )
			? $resolved
			: new \WP_Error( 'booking_private_storage_invalid_provider', __( 'The private booking file provider is invalid.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	/**
	 * Attach one previously admitted opaque private object.
	 *
	 * @param array $input Attachment input.
	 */
	public function attach( array $input ) {
		if ( $this->connection_is_quarantined() ) {
			return $this->connection_quarantined_error();
		}
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}
		$booking = $this->booking( (int) ( $input['booking_id'] ?? 0 ) );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$normalized = $this->normalize_input( $input, $booking );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$claim_key = $this->claim_key( $booking['id'], $normalized['idempotency_key'] );
		$claimed   = false;
		$result    = $this->authorized_reference_transaction(
			$booking,
			$normalized['uploader_user_id'],
			$normalized['storage_reference'],
			function () use ( $booking, $normalized, $claim_key, &$claimed ) {
				return $this->attach_locked( $booking, $normalized, $claim_key, $claimed );
			}
		);
		return is_wp_error( $result ) && $claimed
			? $this->compensate_claim( $booking, $normalized['uploader_user_id'], $normalized['storage_reference'], $claim_key, $result )
			: $result;
	}

	/**
	 * Claim and persist one reference while its cross-store lock is held.
	 *
	 * @param array  $booking    Current booking.
	 * @param array  $normalized Normalized attachment input.
	 * @param string $claim_key  Site-scoped claim key.
	 * @param bool   $claimed    Whether the provider claim was persisted.
	 */
	private function attach_locked( array $booking, array $normalized, string $claim_key, bool &$claimed ) {
		$metadata = $this->provider->claim( $normalized['storage_reference'], $claim_key, $normalized['purpose'] );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		$claimed = true;
		if ( ! is_array( $metadata ) ) {
			return new \WP_Error( 'booking_private_storage_invalid_response', __( 'Private storage returned invalid attachment metadata.', 'extrachill-events' ), array( 'status' => 502 ) );
		}
		$validated = $this->policy->validate( $metadata, $normalized['purpose'] );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( BookingAttachmentPolicy::requires_malware_scan( $validated['mime_type'] ) && 'clean' !== ( $metadata['scan_status'] ?? '' ) ) {
			return new \WP_Error( 'booking_private_scan_required', __( 'This document type requires an approved malware scanner before attachment.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( $this->policy->is_default_denied_filename( $validated['original_filename'] ) ) {
			return new \WP_Error( 'booking_tax_document_denied', __( 'Tax identity documents require an approved secure vault and are not accepted here.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$data                 = array_merge( $normalized, $validated );
		$data['request_hash'] = $this->request_hash( $data );
		$reuse                = $this->validate_reuse( $data, $booking );
		if ( is_wp_error( $reuse ) ) {
			return $reuse;
		}
		$attachment = $this->attachments->create( $data );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		if ( 'active' !== $attachment['state'] ) {
			return new \WP_Error( 'booking_attachment_inactive', __( 'The attachment is no longer active.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$activity = $this->activity->append(
			array(
				'booking_id'      => $attachment['booking_id'],
				'kind'            => 'attachment_added',
				'actor_type'      => $attachment['uploader_type'],
				'actor_id'        => $attachment['uploader_user_id'],
				'idempotency_key' => 'attachment-added:' . $attachment['idempotency_key'],
				'payload'         => $this->audit_payload( $attachment ),
			)
		);
		return is_wp_error( $activity ) ? $activity : $attachment;
	}

	/**
	 * Replace an active attachment while preserving the prior audit reference.
	 *
	 * @param array $input Replacement input.
	 */
	public function replace( array $input ) {
		if ( $this->connection_is_quarantined() ) {
			return $this->connection_quarantined_error();
		}
		$prior = $this->attachments->get_for_booking( (int) ( $input['booking_id'] ?? 0 ), (int) ( $input['attachment_id'] ?? 0 ) );
		if ( is_wp_error( $prior ) ) {
			return $prior;
		}
		if ( 'active' !== $prior['state'] ) {
			return new \WP_Error( 'booking_attachment_inactive', __( 'Only active attachments can be replaced.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$input['purpose']                = $input['purpose'] ?? $prior['purpose'];
		$input['replaces_attachment_id'] = $prior['id'];
		$replacement                     = $this->attach( $input );
		if ( is_wp_error( $replacement ) || $replacement['id'] === $prior['id'] ) {
			return $replacement;
		}
		$booking = $this->booking( $prior['booking_id'] );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$retired = $this->authorized_reference_transaction(
			$booking,
			(int) $replacement['uploader_user_id'],
			$prior['storage_reference'],
			function () use ( $prior, $replacement ) {
				$updated = $this->attachments->retire( $prior['id'], 'replaced' );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				$activity = $this->activity->append(
					array(
						'booking_id'      => $prior['booking_id'],
						'kind'            => 'attachment_replaced',
						'actor_type'      => $replacement['uploader_type'],
						'actor_id'        => $replacement['uploader_user_id'],
						'idempotency_key' => 'attachment-replaced:' . $replacement['id'],
						'payload'         => array(
							'attachment_id'             => $prior['public_id'],
							'replacement_attachment_id' => $replacement['public_id'],
						),
					)
				);
				return is_wp_error( $activity ) ? $activity : $updated;
			}
		);
		if ( is_wp_error( $retired ) ) {
			$abandoned = $this->authorized_reference_transaction(
				$booking,
				(int) $replacement['uploader_user_id'],
				$replacement['storage_reference'],
				function () use ( $replacement ) {
					return $this->attachments->retire( $replacement['id'], 'abandoned' );
				}
			);
			return is_wp_error( $abandoned )
				? new \WP_Error(
					'booking_attachment_replacement_compensation_failed',
					__( 'Attachment replacement failed and its new reference requires operator recovery.', 'extrachill-events' ),
					array(
						'cause'        => $retired->get_error_code(),
						'compensation' => $abandoned->get_error_code(),
					)
				)
				: $retired;
		}
		return $replacement;
	}

	/**
	 * Logically delete a booking reference; physical cleanup remains retention-gated.
	 *
	 * @param int $booking_id    Booking ID.
	 * @param int $attachment_id Attachment ID.
	 * @param int $actor_id      Acting user ID.
	 */
	public function delete( int $booking_id, int $attachment_id, int $actor_id ) {
		if ( $this->connection_is_quarantined() ) {
			return $this->connection_quarantined_error();
		}
		$attachment = $this->attachments->get_for_booking( $booking_id, $attachment_id );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		$booking = $this->booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		return $this->authorized_reference_transaction(
			$booking,
			$actor_id,
			$attachment['storage_reference'],
			function () use ( $attachment, $attachment_id, $booking_id, $actor_id ) {
				$retired = $this->attachments->retire( $attachment_id, 'deleted' );
				if ( is_wp_error( $retired ) ) {
					return $retired;
				}
				$activity = $this->activity->append(
					array(
						'booking_id'      => $booking_id,
						'kind'            => 'attachment_deleted',
						'actor_type'      => 'user',
						'actor_id'        => $actor_id,
						'idempotency_key' => 'attachment-deleted:' . $attachment_id,
						'payload'         => $this->audit_payload( $retired ),
					)
				);
				return is_wp_error( $activity ) ? $activity : $retired;
			}
		);
	}

	/**
	 * Purge retired bytes only when every reference is inactive and no audit hold applies.
	 *
	 * @param array $policy Explicit retention days and legal-hold callback.
	 */
	public function cleanup( array $policy = array() ) {
		if ( $this->connection_is_quarantined() ) {
			return $this->connection_quarantined_error();
		}
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}
		$retention_days = absint( $policy['retention_days'] ?? 0 );
		$legal_hold     = $policy['legal_hold_callback'] ?? null;
		$actor_id       = absint( $policy['actor_id'] ?? get_current_user_id() );
		if ( $retention_days < 1 || ! is_callable( $legal_hold ) || $actor_id < 1 ) {
			return new \WP_Error( 'booking_attachment_cleanup_policy_required', __( 'Destructive cleanup requires an explicit approved retention and legal-hold policy.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$candidates = $this->attachments->list_retired_before( $cutoff );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		$purged  = 0;
		$visited = array();
		foreach ( $candidates as $candidate ) {
			$reference = $candidate['storage_reference'];
			if ( isset( $visited[ $reference ] ) ) {
				continue;
			}
			$visited[ $reference ] = true;
			$references            = $this->attachments->list_by_storage_reference( $reference );
			if ( is_wp_error( $references ) ) {
				return $references;
			}
			$bookings = $this->bookings_for_references( $references );
			if ( is_wp_error( $bookings ) ) {
				return $bookings;
			}
			$prepared = $this->authorized_reference_transaction_many(
				$bookings,
				$actor_id,
				$reference,
				function () use ( $reference, $legal_hold, $bookings ) {
					$locked = $this->attachments->list_by_storage_reference( $reference, true );
					if ( is_wp_error( $locked ) || empty( $locked ) ) {
						return is_wp_error( $locked ) ? $locked : new \WP_Error( 'booking_attachment_cleanup_uncertain', __( 'Cleanup found no authoritative attachment references.', 'extrachill-events' ) );
					}
					if ( ! $this->same_booking_set( $locked, $bookings ) ) {
						return new \WP_Error( 'booking_attachment_cleanup_retry_required', __( 'Attachment references changed while cleanup was acquiring locks.', 'extrachill-events' ) );
					}
					foreach ( $locked as $attachment ) {
						$booking = $bookings[ $attachment['booking_id'] ];
						$held    = $legal_hold( $attachment, $booking );
						if ( is_wp_error( $held ) ) {
							return $held;
						}
						if ( 'active' === $attachment['state'] || false !== $held || $this->policy->requires_audit_retention( $attachment, $booking ) ) {
							return false;
						}
					}
					$states = array();
					foreach ( $locked as $attachment ) {
						$states[ $attachment['id'] ] = $attachment['state'];
						$marked                      = $this->attachments->retire( $attachment['id'], 'purging' );
						if ( is_wp_error( $marked ) ) {
							return $marked;
						}
					}
					return $states;
				}
			);
			if ( false === $prepared ) {
				continue;
			}
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$references = $this->attachments->list_by_storage_reference( $reference );
			$bookings   = is_wp_error( $references ) ? $references : $this->bookings_for_references( $references );
			if ( is_wp_error( $bookings ) ) {
				return $bookings;
			}
			$result = $this->authorized_reference_transaction_many(
				$bookings,
				$actor_id,
				$reference,
				function () use ( $reference, $bookings, $legal_hold, $prepared ) {
					$locked = $this->attachments->list_by_storage_reference( $reference, true );
					if ( is_wp_error( $locked ) || empty( $locked ) || ! $this->same_booking_set( $locked, $bookings ) ) {
						$cancelled = $this->cancel_purging( $prepared );
						if ( is_wp_error( $cancelled ) ) {
							return $cancelled;
						}
						return array(
							'cancelled' => true,
							'error'     => is_wp_error( $locked ) ? $locked->get_error_code() : 'booking_attachment_cleanup_retry_required',
						);
					}
					foreach ( $locked as $attachment ) {
						if ( 'purging' !== $attachment['state'] ) {
							$cancelled = $this->cancel_purging( $prepared );
							return is_wp_error( $cancelled ) ? $cancelled : array(
								'cancelled' => true,
								'error'     => 'booking_attachment_cleanup_uncertain',
							);
						}
						$current_booking = $this->bookings->get( $attachment['booking_id'] );
						if ( is_wp_error( $current_booking ) || ! is_array( $current_booking ) ) {
							$cancelled = $this->cancel_purging( $prepared );
							return is_wp_error( $cancelled ) ? $cancelled : array(
								'cancelled' => true,
								'error'     => is_wp_error( $current_booking ) ? $current_booking->get_error_code() : 'booking_attachment_reference_booking_missing',
							);
						}
						$held = $legal_hold( $attachment, $current_booking );
						if ( is_wp_error( $held ) ) {
							$cancelled = $this->cancel_purging( $prepared );
							return is_wp_error( $cancelled ) ? $cancelled : array(
								'cancelled' => true,
								'error'     => $held->get_error_code(),
							);
						}
						if ( false !== $held || $this->policy->requires_audit_retention( $attachment, $current_booking ) ) {
							$cancelled = $this->cancel_purging( $prepared );
							return is_wp_error( $cancelled ) ? $cancelled : array(
								'cancelled' => true,
								'error'     => null,
							);
						}
					}
					$retired = $this->provider->retire( $reference );
					if ( true !== $retired ) {
						return is_wp_error( $retired ) ? $retired : new \WP_Error( 'booking_private_retirement_uncertain', __( 'Private object retirement could not be confirmed.', 'extrachill-events' ) );
					}
					foreach ( $locked as $attachment ) {
						$marked = $this->attachments->retire( $attachment['id'], 'purged' );
						if ( is_wp_error( $marked ) ) {
							return $marked;
						}
					}
					return count( $locked );
				}
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( is_array( $result ) && ! empty( $result['cancelled'] ) ) {
				if ( ! empty( $result['error'] ) ) {
					return new \WP_Error( $result['error'], __( 'Attachment cleanup was cancelled before byte retirement.', 'extrachill-events' ) );
				}
				continue;
			}
			$purged += (int) $result;
		}
		return array(
			'purged' => $purged,
			'cutoff' => $cutoff,
		);
	}

	/**
	 * Inspect and optionally repair crash states without deleting bytes.
	 *
	 * @param array $policy Actor, minimum age, and explicit repair flag.
	 */
	public function reconcile( array $policy = array() ) {
		if ( $this->connection_is_quarantined() ) {
			return $this->connection_quarantined_error();
		}
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}
		$actor_id           = absint( $policy['actor_id'] ?? get_current_user_id() );
		$minimum_age        = absint( $policy['minimum_age'] ?? 0 );
		$repair             = true === ( $policy['repair'] ?? false );
		$continuation       = is_array( $policy['continuation'] ?? null ) ? $policy['continuation'] : array();
		$claim_cursor       = isset( $continuation['claims'] ) && is_string( $continuation['claims'] ) ? $continuation['claims'] : null;
		$replacement_cursor = absint( $continuation['replacements'] ?? 0 );
		if ( $actor_id < 1 || $minimum_age < HOUR_IN_SECONDS ) {
			return new \WP_Error( 'booking_attachment_reconciliation_policy_required', __( 'Reconciliation requires an explicit actor and minimum crash-age policy.', 'extrachill-events' ) );
		}
		$inspection = $this->provider->inspect_claims( $claim_cursor );
		if ( is_wp_error( $inspection ) ) {
			return $inspection;
		}
		$claims             = is_array( $inspection['claims'] ?? null ) ? $inspection['claims'] : array();
		$claim_truncated    = true === ( $inspection['truncated'] ?? false );
		$claim_continuation = $claim_truncated && is_string( $inspection['continuation'] ?? null ) ? $inspection['continuation'] : null;
		$cutoff             = time() - $minimum_age;
		$orphan_claims      = array();
		$repaired_claims    = 0;
		$uncertain_claims   = absint( $inspection['uncertain'] ?? 0 );
		foreach ( $claims as $claim ) {
			$updated = strtotime( (string) ( $claim['updated_at'] ?? '' ) );
			if ( 'active' !== ( $claim['state'] ?? '' ) || false === $updated || $updated >= $cutoff ) {
				continue;
			}
			$booking_id = $this->booking_id_from_claim_key( (string) ( $claim['claim_key'] ?? '' ) );
			if ( $booking_id < 1 ) {
				++$uncertain_claims;
				continue;
			}
			$booking = $this->booking( $booking_id );
			if ( is_wp_error( $booking ) ) {
				++$uncertain_claims;
				continue;
			}
			$references = $this->attachments->list_by_storage_reference( (string) $claim['storage_reference'] );
			if ( is_wp_error( $references ) ) {
				++$uncertain_claims;
				continue;
			}
			$referenced = false;
			foreach ( $references as $attachment ) {
				if ( hash_equals( (string) $claim['claim_key'], $this->claim_key( $attachment['booking_id'], $attachment['idempotency_key'] ) ) ) {
					$referenced = true;
					break;
				}
			}
			if ( $referenced ) {
				continue;
			}
			$result = $this->authorized_reference_transaction(
				$booking,
				$actor_id,
				(string) $claim['storage_reference'],
				function () use ( $claim, $repair ) {
					$current = $this->attachments->list_by_storage_reference( (string) $claim['storage_reference'], true );
					if ( is_wp_error( $current ) ) {
						return $current;
					}
					foreach ( $current as $attachment ) {
						if ( hash_equals( (string) $claim['claim_key'], $this->claim_key( $attachment['booking_id'], $attachment['idempotency_key'] ) ) ) {
							return new \WP_Error( 'booking_attachment_reconciliation_changed', __( 'The claim gained a database reference during reconciliation.', 'extrachill-events' ) );
						}
					}
					return $repair ? $this->provider->release_claim( (string) $claim['storage_reference'], (string) $claim['claim_key'] ) : true;
				}
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$orphan_claims[] = array(
				'fingerprint' => $this->reference_fingerprint( (string) $claim['storage_reference'] ),
				'state'       => 'active_unreferenced',
			);
			if ( $repair ) {
				++$repaired_claims;
			}
		}

		$replacement_cutoff = gmdate( 'Y-m-d H:i:s', $cutoff );
		$replacement_page   = $this->attachments->list_active_replacements_before( $replacement_cutoff, $replacement_cursor );
		if ( is_wp_error( $replacement_page ) ) {
			return $replacement_page;
		}
		$replacements             = is_array( $replacement_page['items'] ?? null ) ? $replacement_page['items'] : array();
		$replacement_truncated    = true === ( $replacement_page['truncated'] ?? false );
		$replacement_continuation = $replacement_truncated ? absint( $replacement_page['continuation'] ?? 0 ) : null;
		$incomplete               = array();
		$repaired_replacements    = 0;
		$uncertain_replacements   = 0;
		foreach ( $replacements as $replacement ) {
			$prior = $this->attachments->get( (int) $replacement['replaces_attachment_id'] );
			if ( is_wp_error( $prior ) ) {
				++$uncertain_replacements;
				continue;
			}
			if ( ! is_array( $prior ) ) {
				++$uncertain_replacements;
				continue;
			}
			if ( 'active' !== $prior['state'] || $prior['booking_id'] !== $replacement['booking_id'] ) {
				continue;
			}
			$booking = $this->booking( $replacement['booking_id'] );
			if ( is_wp_error( $booking ) ) {
				++$uncertain_replacements;
				continue;
			}
			$result = $this->authorized_reference_transaction(
				$booking,
				$actor_id,
				$prior['storage_reference'],
				function () use ( $prior, $replacement, $actor_id, $repair ) {
					$current_prior = $this->attachments->get_for_booking( $prior['booking_id'], $prior['id'] );
					$current_new   = $this->attachments->get_for_booking( $replacement['booking_id'], $replacement['id'] );
					if ( is_wp_error( $current_prior ) || is_wp_error( $current_new ) || 'active' !== $current_prior['state'] || 'active' !== $current_new['state'] ) {
						return new \WP_Error( 'booking_attachment_reconciliation_changed', __( 'The replacement changed during reconciliation.', 'extrachill-events' ) );
					}
					if ( ! $repair ) {
						return true;
					}
					$retired = $this->attachments->retire( $prior['id'], 'replaced' );
					if ( is_wp_error( $retired ) ) {
						return $retired;
					}
					return $this->activity->append(
						array(
							'booking_id'      => $prior['booking_id'],
							'kind'            => 'attachment_replaced',
							'actor_type'      => 'user',
							'actor_id'        => $actor_id,
							'idempotency_key' => 'attachment-reconciled:' . $replacement['id'],
							'payload'         => array(
								'attachment_id' => $prior['public_id'],
								'replacement_attachment_id' => $replacement['public_id'],
							),
						)
					);
				}
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$incomplete[] = array(
				'attachment_id' => $replacement['public_id'],
				'state'         => 'prior_active',
			);
			if ( $repair ) {
				++$repaired_replacements;
			}
		}
		return array(
			'orphan_claims'           => $orphan_claims,
			'incomplete_replacements' => $incomplete,
			'repaired_claims'         => $repaired_claims,
			'repaired_replacements'   => $repaired_replacements,
			'uncertain_claims'        => $uncertain_claims,
			'uncertain_replacements'  => $uncertain_replacements,
			'truncated'               => array(
				'claims'       => $claim_truncated,
				'replacements' => $replacement_truncated,
			),
			'continuation'            => array(
				'claims'       => $claim_continuation,
				'replacements' => $replacement_continuation,
			),
		);
	}

	/**
	 * Resolve an authorized stream token without returning storage references or paths.
	 *
	 * @param int $booking_id    Booking ID.
	 * @param int $attachment_id Attachment ID.
	 * @param int $actor_id      Authorized user ID.
	 */
	public function download_descriptor( int $booking_id, int $attachment_id, ?int $actor_id = null ) {
		if ( $this->connection_is_quarantined() ) {
			return $this->connection_quarantined_error();
		}
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}
		$attachment = $this->attachments->get_for_booking( $booking_id, $attachment_id );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		if ( 'active' !== $attachment['state'] ) {
			return new \WP_Error( 'booking_attachment_inactive', __( 'The attachment is no longer downloadable.', 'extrachill-events' ), array( 'status' => 410 ) );
		}
		$actor_id = null === $actor_id ? get_current_user_id() : $actor_id;
		$booking  = $this->booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$descriptor = $this->authorized_reference_transaction(
			$booking,
			$actor_id,
			$attachment['storage_reference'],
			function () use ( $attachment, $booking, $actor_id ) {
				$current = $this->attachments->get_for_booking( $booking['id'], $attachment['id'] );
				if ( is_wp_error( $current ) || 'active' !== $current['state'] ) {
					return is_wp_error( $current ) ? $current : new \WP_Error( 'booking_attachment_inactive', __( 'The attachment is no longer downloadable.', 'extrachill-events' ), array( 'status' => 410 ) );
				}
				$claim_key  = $this->claim_key( $booking['id'], $current['idempotency_key'] );
				$descriptor = $this->provider->download_descriptor( $current['storage_reference'], $current['public_id'], $actor_id, $current['purpose'], $claim_key );
				if ( is_wp_error( $descriptor ) ) {
					return $descriptor;
				}
				$activity = $this->activity->append(
					array(
						'booking_id' => $booking['id'],
						'kind'       => 'attachment_downloaded',
						'actor_type' => 'user',
						'actor_id'   => $actor_id,
						'payload'    => $this->audit_payload( $current ),
					)
				);
				return is_wp_error( $activity ) ? $activity : $descriptor;
			}
		);
		if ( is_wp_error( $descriptor ) ) {
			return $descriptor;
		}
		if ( ! is_array( $descriptor ) || empty( $descriptor['stream_token'] ) || ! is_string( $descriptor['stream_token'] ) || empty( $descriptor['expires_at'] ) || ! is_string( $descriptor['expires_at'] ) ) {
			return new \WP_Error( 'booking_private_storage_invalid_response', __( 'Private storage did not return a secure stream handoff.', 'extrachill-events' ), array( 'status' => 502 ) );
		}
		return array(
			'stream_token' => $descriptor['stream_token'],
			'expires_at'   => $descriptor['expires_at'],
			'filename'     => $attachment['original_filename'],
			'mime_type'    => $attachment['mime_type'],
		);
	}

	/**
	 * Reauthorize and consume an opaque one-time download handoff.
	 *
	 * @param int    $booking_id    Booking ID.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $stream_token  Opaque handoff.
	 * @param int    $actor_id      Current user ID.
	 */
	public function open_download_stream( int $booking_id, int $attachment_id, string $stream_token, int $actor_id ) {
		if ( $this->connection_is_quarantined() ) {
			return $this->connection_quarantined_error();
		}
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}
		$booking = $this->booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$opened     = null;
		$attachment = $this->attachments->get_for_booking( $booking_id, $attachment_id );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		$result = $this->authorized_reference_transaction(
			$booking,
			$actor_id,
			$attachment['storage_reference'],
			function () use ( $booking, $attachment_id, $stream_token, $actor_id, &$opened ) {
				$attachment = $this->attachments->get_for_booking( $booking['id'], $attachment_id );
				if ( is_wp_error( $attachment ) || 'active' !== $attachment['state'] ) {
					return is_wp_error( $attachment ) ? $attachment : new \WP_Error( 'booking_attachment_inactive', __( 'The attachment is no longer downloadable.', 'extrachill-events' ), array( 'status' => 410 ) );
				}
				$opened = $this->provider->open_stream( $stream_token, $attachment['public_id'], $actor_id, $attachment['purpose'] );
				return $opened;
			}
		);
		if ( is_wp_error( $result ) && is_resource( $opened ) ) {
			fclose( $opened );
		}
		return $result;
	}

	/**
	 * Resolve one booking.
	 *
	 * @param int $booking_id Booking ID.
	 */
	private function booking( int $booking_id ) {
		$booking = $this->bookings->get( $booking_id );
		return is_wp_error( $booking ) || is_array( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	/**
	 * Normalize attribution and identity against the stored booking.
	 *
	 * @param array $input   Attachment input.
	 * @param array $booking Stored booking.
	 */
	private function normalize_input( array $input, array $booking ) {
		$type = sanitize_key( (string) ( $input['uploader_type'] ?? '' ) );
		if ( ! in_array( $type, BookingAttachmentPolicy::UPLOADERS, true ) ) {
			return new \WP_Error( 'invalid_booking_attachment_uploader', __( 'The attachment uploader attribution is invalid.', 'extrachill-events' ) );
		}
		$user_id = empty( $input['uploader_user_id'] ) ? null : absint( $input['uploader_user_id'] );
		if ( ( 'user' === $type ) !== ( null !== $user_id ) ) {
			return new \WP_Error( 'invalid_booking_attachment_uploader', __( 'Authenticated uploads require a user identity and anonymous uploads must not claim one.', 'extrachill-events' ) );
		}
		$term_id    = empty( $input['artist_term_id'] ) ? $booking['artist_term_id'] : absint( $input['artist_term_id'] );
		$profile_id = empty( $input['artist_profile_id'] ) ? $booking['artist_profile_id'] : absint( $input['artist_profile_id'] );
		if ( $term_id !== $booking['artist_term_id'] || $profile_id !== $booking['artist_profile_id'] ) {
			return new \WP_Error( 'booking_attachment_artist_mismatch', __( 'Attachment artist identity must match the booking.', 'extrachill-events' ) );
		}
		$reference = sanitize_text_field( (string) ( $input['storage_reference'] ?? '' ) );
		$key       = sanitize_text_field( (string) ( $input['idempotency_key'] ?? '' ) );
		if ( 1 !== preg_match( '/^[A-Za-z0-9:_-]{24,191}$/', $reference ) || '' === $key || strlen( $key ) > 191 ) {
			return new \WP_Error( 'invalid_booking_attachment_reference', __( 'Opaque storage and idempotency references are required.', 'extrachill-events' ) );
		}
		return array(
			'booking_id'             => $booking['id'],
			'uploader_type'          => $type,
			'uploader_user_id'       => $user_id,
			'uploader_reference'     => empty( $input['uploader_reference'] ) ? null : mb_substr( sanitize_text_field( $input['uploader_reference'] ), 0, 191 ),
			'artist_term_id'         => $term_id,
			'artist_profile_id'      => $profile_id,
			'purpose'                => sanitize_key( (string) ( $input['purpose'] ?? '' ) ),
			'storage_reference'      => $reference,
			'idempotency_key'        => $key,
			'replaces_attachment_id' => empty( $input['replaces_attachment_id'] ) ? null : absint( $input['replaces_attachment_id'] ),
		);
	}

	/**
	 * Enforce authenticated, same-artist reuse.
	 *
	 * @param array $input   Normalized input.
	 * @param array $booking Stored booking.
	 */
	private function validate_reuse( array $input, array $booking ) {
		$references = $this->attachments->list_by_storage_reference( $input['storage_reference'] );
		if ( is_wp_error( $references ) ) {
			return $references;
		}
		$references = array_values(
			array_filter(
				$references,
				static function ( array $reference ) use ( $input, $booking ): bool {
					return $reference['booking_id'] !== $booking['id'] || $reference['idempotency_key'] !== $input['idempotency_key'];
				}
			)
		);
		if ( empty( $references ) ) {
			return true;
		}
		if ( null === $booking['artist_term_id'] && null === $booking['artist_profile_id'] ) {
			return new \WP_Error( 'booking_attachment_artist_unresolved', __( 'Attachment reuse requires a canonical artist identity.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		return new \WP_Error( 'booking_attachment_reuse_forbidden', __( 'Cross-booking reuse is disabled until canonical artist authority can be proven.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/**
	 * Build an exact idempotency fingerprint.
	 *
	 * @param array $data Normalized attachment data.
	 */
	private function request_hash( array $data ): string {
		unset( $data['request_hash'] );
		ksort( $data );
		return hash_hmac( 'sha256', wp_json_encode( $data ), wp_salt( 'auth' ) );
	}

	/**
	 * Build activity data without private references or tokens.
	 *
	 * @param array $attachment Attachment metadata.
	 */
	private function audit_payload( array $attachment ): array {
		return array(
			'attachment_id' => $attachment['public_id'],
			'purpose'       => $attachment['purpose'],
			'filename'      => $attachment['original_filename'],
			'state'         => $attachment['state'],
		);
	}

	/**
	 * Acquire the final lock in the global membership -> booking -> reference order.
	 *
	 * @param string $reference Opaque storage reference.
	 */
	private function acquire_reference_lock( string $reference ) {
		global $wpdb;
		$name            = $this->reference_lock_name( $reference );
		$uncertain_locks = $this->uncertain_reference_locks();
		if ( isset( $uncertain_locks[ $name ] ) ) {
			return new \WP_Error(
				'booking_attachment_reference_lock_uncertain',
				__( 'This connection has an unresolved private attachment lock.', 'extrachill-events' ),
				array(
					'lock_uncertain' => true,
					'lock_name'      => $name,
					'recovery'       => 'disconnect_and_reconcile',
				)
			);
		}
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-store object serialization.
		if ( '' !== (string) $wpdb->last_error || 1 !== (int) $acquired ) {
			return new \WP_Error( 'booking_attachment_reference_lock_failed', __( 'The private attachment reference could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return $name;
	}

	/**
	 * Release one acquired site-scoped reference lock.
	 *
	 * @param string $name Acquired MySQL lock name.
	 */
	private function release_reference_lock( string $name ) {
		global $wpdb;
		try {
			$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases cross-store object serialization.
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'booking_attachment_reference_unlock_failed', __( 'The private attachment reference lock release could not be confirmed.', 'extrachill-events' ), array( 'exception' => get_class( $throwable ) ) );
		}
		return 1 === (int) $released && '' === (string) $wpdb->last_error
			? true
			: new \WP_Error( 'booking_attachment_reference_unlock_failed', __( 'The private attachment reference lock release could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
	}

	/**
	 * Execute one authorized mutation in the global lock order.
	 *
	 * @param array    $booking   Current booking.
	 * @param int|null $actor_id  Acting user, or null for trusted admission.
	 * @param string   $reference Opaque storage reference.
	 * @param callable $callback  Transaction body after all locks.
	 */
	private function authorized_reference_transaction( array $booking, ?int $actor_id, string $reference, callable $callback ) {
		return $this->authorized_reference_transaction_many( array( $booking['id'] => $booking ), $actor_id, $reference, $callback );
	}

	/**
	 * Execute one multi-booking operation in the same global lock order.
	 *
	 * @param array    $bookings  Current bookings keyed by ID.
	 * @param int|null $actor_id  Acting user or trusted admission.
	 * @param string   $reference Opaque storage reference.
	 * @param callable $callback  Transaction body after all locks.
	 */
	private function authorized_reference_transaction_many( array $bookings, ?int $actor_id, string $reference, callable $callback ) {
		if ( $this->connection_is_quarantined() ) {
			return $this->connection_quarantined_error();
		}
		$lock_name = null;
		$result    = $this->transaction(
			function () use ( $bookings, $actor_id, $reference, $callback, &$lock_name ) {
				$authorized = $this->lock_and_authorize_many( $bookings, $actor_id );
				if ( is_wp_error( $authorized ) ) {
					return $authorized;
				}
				$lock_name = $this->reference_lock_name( $reference );
				$acquired  = $this->acquire_reference_lock( $reference );
				if ( is_wp_error( $acquired ) ) {
					$lock_name = null;
					return $acquired;
				}
				return $callback();
			}
		);
		if ( is_string( $lock_name ) ) {
			$error_data = is_wp_error( $result ) ? (array) $result->get_error_data() : array();
			if ( true === ( $error_data['connection_quarantined'] ?? false ) ) {
				$GLOBALS['extrachill_events_booking_reference_lock_uncertainty'][ $lock_name ] = true;
				return $result;
			}
			$released = $this->release_reference_lock( $lock_name );
			if ( is_wp_error( $released ) ) {
				$GLOBALS['extrachill_events_booking_reference_lock_uncertainty'][ $lock_name ] = true;
				return $this->quarantine_connection(
					new \WP_Error(
						'booking_attachment_reference_unlock_uncertain',
						is_wp_error( $result ) ? __( 'The attachment operation failed and its connection lock release is uncertain.', 'extrachill-events' ) : __( 'The attachment operation committed but its connection lock release is uncertain.', 'extrachill-events' ),
						array(
							'committed'      => ! is_wp_error( $result ),
							'lock_uncertain' => true,
							'lock_name'      => $lock_name,
							'cause'          => is_wp_error( $result ) ? $result->get_error_code() : null,
						)
					),
					false
				);
			}
		}
		return $result;
	}

	/**
	 * Lock the current booking and venue membership range, then reauthorize.
	 *
	 * @param array    $booking  Previously resolved booking.
	 * @param int|null $actor_id Acting user, or null for a trusted admission adapter.
	 */
	private function lock_and_authorize( array $booking, ?int $actor_id ) {
		return $this->lock_and_authorize_many( array( $booking['id'] => $booking ), $actor_id );
	}

	/**
	 * Lock all authority ranges first, then all bookings, in numeric order.
	 *
	 * @param array    $bookings Current bookings keyed by ID.
	 * @param int|null $actor_id Acting user or trusted admission.
	 */
	private function lock_and_authorize_many( array $bookings, ?int $actor_id ) {
		global $wpdb;
		$venues             = array();
		$locked_memberships = array();
		foreach ( $bookings as $booking ) {
			$venues[ (int) $booking['venue_term_id'] ] = true;
		}
		$venue_ids = array_keys( $venues );
		sort( $venue_ids, SORT_NUMERIC );
		$memberships = BookingSchema::memberships_table();
		foreach ( $venue_ids as $venue_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$memberships} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $venue_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Global order locks authority ranges first.
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'booking_authorization_lock_failed', __( 'Venue booking authority could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
			}
			$locked_memberships[ $venue_id ] = (array) $rows;
		}
		ksort( $bookings, SORT_NUMERIC );
		$bookings_table = BookingSchema::bookings_table();
		foreach ( $bookings as $booking ) {
			$locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bookings_table} WHERE id = %d FOR UPDATE", $booking['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Global order locks bookings after all authority ranges.
			if ( '' !== (string) $wpdb->last_error || ! is_array( $locked ) || (int) $locked['venue_term_id'] !== (int) $booking['venue_term_id'] ) {
				return new \WP_Error( 'booking_attachment_booking_lock_failed', __( 'The booking could not be locked for attachment authorization.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
			}
		}
		if ( null === $actor_id ) {
			return true;
		}
		foreach ( $venue_ids as $venue_id ) {
			$allowed = $this->authorization->authorize_locked( $actor_id, $venue_id, VenueAuthorization::ACTION_ACCESS_VENUE, $locked_memberships[ $venue_id ] );
			if ( true !== $allowed ) {
				return is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
			}
		}
		return true;
	}

	/**
	 * Resolve every booking referenced by one object without guessing on errors.
	 *
	 * @param array $references Attachment references.
	 */
	private function bookings_for_references( array $references ) {
		$bookings = array();
		foreach ( $references as $attachment ) {
			$booking = $this->bookings->get( $attachment['booking_id'] );
			if ( is_wp_error( $booking ) || ! is_array( $booking ) ) {
				return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_attachment_reference_booking_missing', __( 'An attachment reference has no authoritative booking.', 'extrachill-events' ) );
			}
			$bookings[ $booking['id'] ] = $booking;
		}
		return $bookings;
	}

	/**
	 * Confirm locked references still match the booking rows acquired earlier.
	 *
	 * @param array $references Attachment references.
	 * @param array $bookings   Locked bookings keyed by ID.
	 */
	private function same_booking_set( array $references, array $bookings ): bool {
		$ids = array();
		foreach ( $references as $attachment ) {
			$ids[ $attachment['booking_id'] ] = true;
		}
		$reference_ids = array_keys( $ids );
		$booking_ids   = array_keys( $bookings );
		sort( $reference_ids, SORT_NUMERIC );
		sort( $booking_ids, SORT_NUMERIC );
		return $reference_ids === $booking_ids;
	}

	/**
	 * Restore phase-one purging rows before retaining bytes.
	 *
	 * @param array $states Prior states keyed by attachment ID.
	 */
	private function cancel_purging( array $states ) {
		foreach ( $states as $attachment_id => $state ) {
			$restored = $this->attachments->cancel_purge( (int) $attachment_id, (string) $state );
			if ( is_wp_error( $restored ) ) {
				return $restored;
			}
		}
		return true;
	}

	/**
	 * Preserve an explicit recovery marker and surface failed compensation.
	 *
	 * @param array     $booking   Current booking.
	 * @param int|null  $actor_id  Acting user or trusted admission.
	 * @param string    $reference Opaque storage reference.
	 * @param string    $claim_key Exact claim key.
	 * @param \WP_Error $cause     Original failure.
	 */
	private function compensate_claim( array $booking, ?int $actor_id, string $reference, string $claim_key, \WP_Error $cause ) {
		$error_data = $cause->get_error_data();
		if ( 'booking_attachment_transaction_commit_uncertain' === $cause->get_error_code() || true === ( $error_data['lock_uncertain'] ?? false ) ) {
			return $cause;
		}
		$released = $this->authorized_reference_transaction(
			$booking,
			$actor_id,
			$reference,
			function () use ( $reference, $claim_key ) {
				return $this->provider->release_claim( $reference, $claim_key );
			}
		);
		return true === $released
			? $cause
			: new \WP_Error(
				'booking_attachment_claim_compensation_failed',
				__( 'The attachment failed and its private claim requires operator recovery.', 'extrachill-events' ),
				array(
					'cause'        => $cause->get_error_code(),
					'compensation' => is_wp_error( $released ) ? $released->get_error_code() : 'unconfirmed',
				)
			);
	}

	/** Return operator-visible request-local advisory lock uncertainty. */
	public function reference_lock_uncertainty(): array {
		$uncertain_locks = $this->uncertain_reference_locks();
		return array(
			'count'                  => count( $uncertain_locks ),
			'lock_names'             => array_keys( $uncertain_locks ),
			'connection_quarantined' => $this->connection_is_quarantined(),
			'recovery'               => empty( $uncertain_locks ) ? null : 'abort_request_and_reconcile',
		);
	}

	/** Return request-wide lock quarantine shared by all service instances. */
	private function uncertain_reference_locks(): array {
		return is_array( $GLOBALS['extrachill_events_booking_reference_lock_uncertainty'] ?? null )
			? $GLOBALS['extrachill_events_booking_reference_lock_uncertainty']
			: array();
	}

	/**
	 * Return a blog/table-scoped claim identity.
	 *
	 * @param int    $booking_id     Booking ID.
	 * @param string $idempotency_key Request idempotency key.
	 */
	private function claim_key( int $booking_id, string $idempotency_key ): string {
		return 'site:' . get_current_blog_id() . ':table:' . BookingSchema::attachments_table() . ':booking:' . $booking_id . ':request:' . hash( 'sha256', $idempotency_key );
	}

	/**
	 * Return a blog/table-scoped, MySQL-length-safe advisory lock name.
	 *
	 * @param string $reference Opaque storage reference.
	 */
	private function reference_lock_name( string $reference ): string {
		$scope = get_current_blog_id() . ':' . BookingSchema::attachments_table() . ':' . $reference;
		return 'ec_booking_file_' . substr( hash( 'sha256', $scope ), 0, 40 );
	}

	/**
	 * Resolve only this blog/table's booking ID from an internal claim key.
	 *
	 * @param string $claim_key Internal provider claim key.
	 */
	private function booking_id_from_claim_key( string $claim_key ): int {
		$prefix = 'site:' . get_current_blog_id() . ':table:' . BookingSchema::attachments_table() . ':booking:';
		if ( 0 !== strpos( $claim_key, $prefix ) || 1 !== preg_match( '/^' . preg_quote( $prefix, '/' ) . '(\d+):request:[a-f0-9]{64}$/', $claim_key, $match ) ) {
			return 0;
		}
		return absint( $match[1] );
	}

	/**
	 * Return a non-reversible operator correlation value, never the reference.
	 *
	 * @param string $reference Opaque storage reference.
	 */
	private function reference_fingerprint( string $reference ): string {
		return substr( hash_hmac( 'sha256', $reference, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Run one metadata and activity mutation atomically.
	 *
	 * @param callable $callback Transaction body.
	 */
	private function transaction( callable $callback ) {
		global $wpdb;
		if ( $this->connection_is_quarantined() ) {
			return $this->connection_quarantined_error();
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Coordinates private metadata and audit writes.
			$started = $wpdb->query( 'START TRANSACTION' );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'booking_attachment_transaction_exception', __( 'The attachment transaction failed unexpectedly.', 'extrachill-events' ), array( 'exception' => get_class( $throwable ) ) );
		}
		if ( false === $started ) {
			return new \WP_Error( 'booking_attachment_transaction_failed', __( 'The attachment transaction could not start.', 'extrachill-events' ) );
		}
		try {
			$result = $callback();
		} catch ( \Throwable $throwable ) {
			return $this->rollback_transaction( new \WP_Error( 'booking_attachment_transaction_exception', __( 'The attachment transaction failed unexpectedly.', 'extrachill-events' ), array( 'exception' => get_class( $throwable ) ) ) );
		}
		if ( is_wp_error( $result ) ) {
			return $this->rollback_transaction( $result );
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits private metadata and audit writes.
			$committed = $wpdb->query( 'COMMIT' );
		} catch ( \Throwable $throwable ) {
			$committed    = false;
			$commit_error = get_class( $throwable );
		}
		if ( false === $committed ) {
			$commit_error = $commit_error ?? $wpdb->last_error;
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Ensures failed commit attempts do not retain row locks.
				$rolled_back = $wpdb->query( 'ROLLBACK' );
			} catch ( \Throwable $throwable ) {
				$rolled_back = false;
			}
			$error = new \WP_Error(
				'booking_attachment_transaction_commit_uncertain',
				__( 'The attachment transaction outcome could not be confirmed.', 'extrachill-events' ),
				array(
					'database_error'     => $commit_error,
					'rollback_confirmed' => false !== $rolled_back,
				)
			);
			return false === $rolled_back ? $this->quarantine_connection( $error ) : $error;
		}
		return $result;
	}

	/**
	 * Roll back a failed transaction without allowing SQL throwables to escape.
	 *
	 * @param \WP_Error $cause Original transaction failure.
	 */
	private function rollback_transaction( \WP_Error $cause ) {
		global $wpdb;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exception-safe rollback.
			$rolled_back = $wpdb->query( 'ROLLBACK' );
		} catch ( \Throwable $throwable ) {
			$rolled_back = false;
		}
		if ( false !== $rolled_back ) {
			return $cause;
		}
		return $this->quarantine_connection(
			new \WP_Error(
				'booking_attachment_transaction_rollback_failed',
				__( 'The attachment transaction could not be rolled back.', 'extrachill-events' ),
				array(
					'cause'          => $cause->get_error_code(),
					'database_error' => $wpdb->last_error,
				)
			)
		);
	}

	/**
	 * Prevent any further query on a connection with unknown transaction state.
	 *
	 * @param \WP_Error $cause              Failure that made connection reuse unsafe.
	 * @param bool      $rollback_uncertain Whether transaction rollback is also uncertain.
	 */
	private function quarantine_connection( \WP_Error $cause, bool $rollback_uncertain = true ): \WP_Error {
		global $wpdb;
		$GLOBALS['extrachill_events_booking_database_connection_quarantined'] = true;
		$closed = false;
		try {
			$closed = method_exists( $wpdb, 'close' ) && true === $wpdb->close();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
		if ( property_exists( $wpdb, 'ready' ) ) {
			$wpdb->ready = false;
		}
		$data = (array) $cause->get_error_data();
		if ( $rollback_uncertain ) {
			$data['rollback_uncertain'] = true;
		}
		$data['connection_quarantined'] = true;
		$data['disconnect_confirmed']   = $closed;
		$data['lock_uncertain']         = true;
		$data['recovery']               = 'abort_request_and_reconcile';
		return new \WP_Error( $cause->get_error_code(), $cause->get_error_message(), $data );
	}

	/** Whether this request has retired its database connection after uncertainty. */
	private function connection_is_quarantined(): bool {
		return true === ( $GLOBALS['extrachill_events_booking_database_connection_quarantined'] ?? false );
	}

	/** Return the stable fail-closed response for a retired request connection. */
	private function connection_quarantined_error(): \WP_Error {
		return new \WP_Error(
			'booking_attachment_database_connection_quarantined',
			__( 'The database connection is unavailable after an uncertain attachment operation.', 'extrachill-events' ),
			array(
				'connection_quarantined' => true,
				'recovery'               => 'abort_request_and_reconcile',
			)
		);
	}
}
