<?php
/**
 * Bulk guest list adds (extrachill#1246).
 *
 * The host door view takes a pasted list or a CSV/TXT upload, one name per
 * line. Parsing happens here, server-side; each surviving name goes through
 * extrachill_events_add_guest_list_name(), the same path as a single host
 * add, so capacity, the lock, and the door list behave the same.
 *
 * Exposed as the extrachill/add-guest-list-names ability (host-only).
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Most names one paste or upload may add. */
const EXTRACHILL_EVENTS_GUEST_LIST_BULK_MAX = 500;

/** Largest upload the door view accepts, in bytes. */
const EXTRACHILL_EVENTS_GUEST_LIST_BULK_MAX_BYTES = 1048576;

/**
 * Split pasted or uploaded text into guest names.
 *
 * One name per line. Blank lines and surrounding whitespace are dropped,
 * and a UTF-8 byte-order mark is stripped. In CSV format each line's first
 * cell is the name (quoted cells may hold commas), and a leading "Name"
 * header row is skipped. Plain text keeps the whole line, so "Smith, Jane"
 * stays one name.
 *
 * @param string $text   Raw text.
 * @param string $format 'csv' or 'text'.
 * @return string[] Names in input order (not yet deduplicated).
 */
function extrachill_events_parse_guest_list_names( string $text, string $format = 'text' ): array {
	$text  = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );
	$lines = preg_split( '/\r\n|\r|\n/', $text );
	$names = array();

	foreach ( (array) $lines as $index => $line ) {
		$line = (string) $line;
		if ( 'csv' === $format ) {
			$cells = str_getcsv( $line, ',', '"', '\\' );
			$line  = (string) ( $cells[0] ?? '' );
		}

		$name = trim( (string) preg_replace( '/\s+/u', ' ', $line ) );
		if ( '' === $name ) {
			continue;
		}

		if ( 'csv' === $format && 0 === $index && in_array( strtolower( $name ), array( 'name', 'names', 'guest', 'guest name', 'full name' ), true ) ) {
			continue;
		}

		$names[] = $name;
	}

	return $names;
}

/**
 * Comparison key for deduplicating names.
 *
 * @param string $name Guest name.
 * @return string
 */
function extrachill_events_guest_list_name_key( string $name ): string {
	if ( function_exists( 'extrachill_events_normalize_guest_name' ) ) {
		return extrachill_events_normalize_guest_name( $name );
	}

	$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
	return trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
}

/**
 * Put many names on the guest list at once.
 *
 * Duplicates within the batch, and names already on the list (an active
 * or checked-in account-less pass with the same name, compared case- and
 * accent-insensitively), are skipped. Once the list fills, the rest are
 * reported as not added.
 *
 * @param int    $event_id     Event post ID.
 * @param string $text         Pasted or uploaded text.
 * @param string $format       'csv' or 'text'.
 * @param int    $host_user_id Host adding the names.
 * @return array|WP_Error `{ added, already_listed, not_added, names }` or an error before any add.
 */
function extrachill_events_add_guest_list_names( int $event_id, string $text, string $format, int $host_user_id ) {
	$names = extrachill_events_parse_guest_list_names( $text, 'csv' === $format ? 'csv' : 'text' );
	if ( ! $names ) {
		return new WP_Error( 'guest_names_required', __( 'Add at least one name, one per line.', 'extrachill-events' ), array( 'status' => 400 ) );
	}
	if ( count( $names ) > EXTRACHILL_EVENTS_GUEST_LIST_BULK_MAX ) {
		/* translators: %d: most names per upload. */
		return new WP_Error( 'guest_names_too_many', sprintf( __( 'Add up to %d names at a time.', 'extrachill-events' ), EXTRACHILL_EVENTS_GUEST_LIST_BULK_MAX ), array( 'status' => 400 ) );
	}

	$seen = array();
	if ( class_exists( '\\ExtraChillEvents\\Core\\RsvpPassesTable' ) ) {
		foreach ( \ExtraChillEvents\Core\RsvpPassesTable::list_guest_passes( $event_id, 1000 ) as $pass ) {
			if ( in_array( $pass['status'] ?? '', array( 'active', 'redeemed' ), true ) && '' !== (string) ( $pass['guest_name'] ?? '' ) ) {
				$seen[ extrachill_events_guest_list_name_key( (string) $pass['guest_name'] ) ] = true;
			}
		}
	}

	$added          = array();
	$already_listed = 0;
	$not_added      = 0;
	foreach ( $names as $name ) {
		$key = extrachill_events_guest_list_name_key( $name );
		if ( isset( $seen[ $key ] ) ) {
			++$already_listed;
			continue;
		}

		$pass = extrachill_events_add_guest_list_name( $event_id, $name, '', $host_user_id );
		if ( is_wp_error( $pass ) ) {
			// The first add surfaces a setup problem (perk off, past show)
			// instead of a silent zero.
			if ( ! $added && ! $not_added && 'guest_list_full' !== $pass->get_error_code() ) {
				return $pass;
			}
			++$not_added;
			continue;
		}

		$seen[ $key ] = true;
		$added[]      = (string) $pass['name'];
	}

	return array(
		'added'          => count( $added ),
		'already_listed' => $already_listed,
		'not_added'      => $not_added,
		'names'          => $added,
	);
}
