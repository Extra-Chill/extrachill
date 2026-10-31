<?php
/**
 * Private booking file provider resolver.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves an owner-approved provider without falling back to public uploads. */
final class BookingPrivateFileProviders {

	/** Return the registered private provider or fail closed. */
	public static function resolve() {
		$provider = self::resolve_configured();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		$readiness = BookingPrivateStorageReadiness::audit( $provider );
		if ( ! $readiness['ready'] ) {
			return new \WP_Error(
				'booking_private_storage_not_approved',
				__( 'Private booking file storage has not passed operational readiness.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'readiness' => $readiness,
				)
			);
		}
		return $provider;
	}

	/** Return the redacted operational readiness projection. */
	public static function readiness(): array {
		return BookingPrivateStorageReadiness::audit( self::resolve_configured() );
	}

	/** Resolve configured storage before applying the operational gate. */
	private static function resolve_configured() {
		$provider = apply_filters( 'extrachill_events_booking_private_file_provider', null );
		if ( $provider instanceof BookingPrivateFileProvider ) {
			return $provider;
		}
		$local = new LocalBookingPrivateFileProvider();
		return $local->is_ready() ? $local : $local->configuration_error();
	}
}
