<?php
/**
 * Private byte-storage boundary for booking attachments.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Domain-facing contract implemented only by an approved private store. */
interface BookingPrivateFileProvider {

	/**
	 * Stage validated bytes and return an opaque immutable reference.
	 *
	 * @param string $source_path Source file path.
	 * @param string $filename    Original filename.
	 * @param string $purpose     Booking attachment purpose.
	 */
	public function stage( string $source_path, string $filename, string $purpose );

	/**
	 * Idempotently claim an object and return trusted metadata.
	 *
	 * @param string $storage_reference Opaque object reference.
	 * @param string $claim_key         Consumer claim key.
	 * @param string $purpose           Booking attachment purpose.
	 */
	public function claim( string $storage_reference, string $claim_key, string $purpose = '' );

	/**
	 * Release a failed metadata claim without deleting another consumer's claim.
	 *
	 * @param string $storage_reference Opaque object reference.
	 * @param string $claim_key         Consumer claim key.
	 */
	public function release_claim( string $storage_reference, string $claim_key );

	/**
	 * Inspect claim lifecycle for explicit domain reconciliation.
	 *
	 * This internal result may contain opaque references and must never be exposed.
	 *
	 * @param string|null $cursor Opaque exclusive keyset cursor from a prior inspection.
	 */
	public function inspect_claims( ?string $cursor = null );

	/**
	 * Return an authorized stream handoff without a public URL or filesystem path.
	 *
	 * @param string $storage_reference    Opaque object reference.
	 * @param string $attachment_public_id Authorized attachment identity.
	 * @param int    $actor_id             Authorized user identity.
	 * @param string $purpose              Authorized download purpose.
	 * @param string $claim_key            Exact active attachment claim.
	 */
	public function download_descriptor( string $storage_reference, string $attachment_public_id, int $actor_id, string $purpose, string $claim_key );

	/**
	 * Open a previously authorized internal stream token.
	 *
	 * @param string $stream_token         Opaque one-time handoff.
	 * @param string $attachment_public_id Authorized attachment identity.
	 * @param int    $actor_id             Currently authorized user identity.
	 * @param string $purpose              Authorized download purpose.
	 */
	public function open_stream( string $stream_token, string $attachment_public_id, int $actor_id, string $purpose );

	/**
	 * Permanently retire one exact private object.
	 *
	 * @param string $storage_reference Opaque object reference.
	 */
	public function retire( string $storage_reference );
}
