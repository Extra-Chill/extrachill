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
		$provider = apply_filters( 'extrachill_events_booking_private_file_provider', null );
		if ( $provider instanceof BookingPrivateFileProvider ) {
			return $provider;
		}
		$local = new LocalBookingPrivateFileProvider();
		return $local->is_ready() ? $local : $local->configuration_error();
	}
}
