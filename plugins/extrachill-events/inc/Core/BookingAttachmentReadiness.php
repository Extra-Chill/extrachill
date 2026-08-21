<?php
/**
 * Booking attachment runtime readiness.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

// Repository convention uses PSR-4 class names and concise method comments.
// phpcs:disable WordPress.Files.FileName,Generic.Commenting,Squiz.Commenting

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Combines private provider safety with an explicit governance decision. */
final class BookingAttachmentReadiness {

	/**
	 * Governance readiness probe.
	 *
	 * @var callable|null
	 */
	private $governance_ready;

	/**
	 * Accept an optional governance probe for isolated tests or composition.
	 *
	 * @param callable|null $governance_ready Governance readiness probe.
	 */
	public function __construct( $governance_ready = null ) {
		$this->governance_ready = is_callable( $governance_ready ) ? $governance_ready : null;
	}

	/**
	 * Return true only when both independent readiness gates pass.
	 *
	 * @param mixed $provider Resolved private file provider or failure.
	 */
	public function is_ready( $provider ): bool {
		if ( ! $provider instanceof BookingPrivateFileProvider ) {
			return false;
		}
		if ( null === $this->governance_ready ) {
			$readiness = BookingPrivateStorageReadiness::audit( $provider );
			return true === $readiness['ready'];
		}

		return $this->probe_governance();
	}

	/** Return the redacted configured-provider readiness for public projection. */
	public function is_operationally_ready(): bool {
		if ( null === $this->governance_ready ) {
			if ( ! class_exists( BookingPrivateFileProviders::class, false ) ) {
				return false;
			}
			$readiness = BookingPrivateFileProviders::readiness();
			return true === $readiness['ready'];
		}

		return $this->probe_governance();
	}

	/** Run an injected isolated governance probe fail-closed. */
	private function probe_governance(): bool {
		try {
			return true === call_user_func( $this->governance_ready );
		} catch ( \Throwable ) {
			return false;
		}
	}
}
