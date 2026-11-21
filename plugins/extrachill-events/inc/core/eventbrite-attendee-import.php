<?php
/**
 * Eventbrite attendee import: bring a show's Eventbrite buyers onto the door list.
 *
 * A venue moving to Extra Chill may still have a show on sale at Eventbrite.
 * The host downloads Eventbrite's attendee report (CSV) and uploads it here;
 * each attending ticket becomes an account-less pass on the door list labeled
 * "(Eventbrite)", so the door checks everyone in from one list by name.
 *
 * Nothing is emailed and no email address is stored: the buyers already hold
 * their Eventbrite tickets. Re-importing a newer report only adds new tickets.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\RsvpPassesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Idempotency-key prefix marking passes imported from Eventbrite. */
const EXTRACHILL_EVENTS_EVENTBRITE_KEY_PREFIX = 'eventbrite:';

/** Largest attendee report accepted, in bytes. */
const EXTRACHILL_EVENTS_EVENTBRITE_MAX_BYTES = 2097152;

/**
 * Whether a pass row came from an Eventbrite import.
 *
 * @param array $pass Pass row.
 * @return bool
 */
function extrachill_events_pass_is_eventbrite_import( array $pass ): bool {
	return 0 === strpos( (string) ( $pass['idempotency_key'] ?? '' ), EXTRACHILL_EVENTS_EVENTBRITE_KEY_PREFIX );
}

/**
 * Parse an Eventbrite attendee report into one entry per attending ticket.
 *
 * Header names are matched case-insensitively, so the Attendee Summary and
 * Order reports both work. A name comes from "First Name" + "Last Name" or a
 * single "Name"-style column. Rows whose "Attendee Status" says they are not
 * attending (refunded, cancelled, transferred) are skipped. With an
 * "Attendee #" column each row is one ticket; otherwise "Quantity" tickets.
 *
 * @param string $csv Report contents.
 * @return array|WP_Error { tickets: array{key: string, name: string}[], skipped: int }
 */
function extrachill_events_parse_eventbrite_attendees( string $csv ) {
	$csv = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
	$csv = str_replace( array( "\r\n", "\r" ), "\n", (string) $csv );
	if ( '' === trim( $csv ) ) {
		return new WP_Error( 'eventbrite_import_empty', __( 'That file is empty.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory CSV parse.
	if ( false === $handle ) {
		return new WP_Error( 'eventbrite_import_failed', __( 'The file could not be read.', 'extrachill-events' ), array( 'status' => 500 ) );
	}
	fwrite( $handle, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- In-memory CSV parse.
	rewind( $handle );

	$header = fgetcsv( $handle, 0, ',', '"', '' );
	$header = is_array( $header ) ? array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), $header ) : array();
	$column = static function ( array $names ) use ( $header ) {
		foreach ( $names as $name ) {
			$index = array_search( $name, $header, true );
			if ( false !== $index ) {
				return $index;
			}
		}
		return null;
	};

	$first    = $column( array( 'first name', 'attendee first name' ) );
	$last     = $column( array( 'last name', 'attendee last name' ) );
	$full     = $column( array( 'name', 'attendee name', 'full name', 'buyer name' ) );
	$order    = $column( array( 'order #', 'order number', 'order id' ) );
	$attendee = $column( array( 'attendee #', 'attendee id', 'ticket #', 'barcode #', 'barcode' ) );
	$quantity = $column( array( 'quantity', 'qty' ) );
	$status   = $column( array( 'attendee status', 'status' ) );

	if ( null === $first && null === $full ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory CSV parse.
		return new WP_Error( 'eventbrite_import_unrecognized', __( 'That doesn\'t look like an Eventbrite attendee report. In Eventbrite, open the event, go to Reports → Attendee summary, and export it as CSV.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$tickets = array();
	$skipped = 0;
	$line    = 1;
	while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard CSV read loop.
		++$line;
		if ( array( null ) === $row || '' === trim( implode( '', $row ) ) ) {
			continue;
		}
		$cell = static fn( $index ) => null === $index ? '' : trim( (string) ( $row[ $index ] ?? '' ) );

		$name  = null !== $first ? trim( $cell( $first ) . ' ' . $cell( $last ) ) : $cell( $full );
		$name  = trim( sanitize_text_field( $name ) );
		$name  = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 ) : substr( $name, 0, 100 );
		$state = strtolower( $cell( $status ) );
		if ( '' === $name || ( '' !== $state && 1 === preg_match( '/not attending|refund|cancel|transfer|delet|void/', $state ) ) ) {
			++$skipped;
			continue;
		}

		$order_ref = $cell( $order );
		if ( '' !== $cell( $attendee ) ) {
			$tickets[] = array(
				'key'  => 'attendee:' . $cell( $attendee ),
				'name' => $name,
			);
			continue;
		}
		$count = max( 1, min( 50, (int) $cell( $quantity ) ) );
		$base  = '' !== $order_ref ? 'order:' . $order_ref : 'row:' . md5( strtolower( $name ) . '|' . $line );
		for ( $i = 1; $i <= $count; $i++ ) {
			$tickets[] = array(
				'key'  => $base . ':' . $i,
				'name' => $name,
			);
		}
	}
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory CSV parse.

	return array(
		'tickets' => $tickets,
		'skipped' => $skipped,
	);
}

/**
 * Send consent-filtered venue confirmation requests, never door passes.
 * Missing or unfamiliar consent values fail closed. Counts are per CSV row;
 * duplicate eligible emails in the same file receive only one request.
 */
function extrachill_events_import_eventbrite_venue_list( int $event_id, string $csv ) {
	if ( strlen( $csv ) > EXTRACHILL_EVENTS_EVENTBRITE_MAX_BYTES ) {
		return new WP_Error( 'eventbrite_import_too_large', 'That file is too large.', array( 'status' => 413 ) );
	}
	$venues = wp_get_post_terms( $event_id, 'venue' );
	if ( is_wp_error( $venues ) || 1 !== count( $venues ) ) {
		return new WP_Error( 'eventbrite_venue_required', 'The event must have exactly one venue.', array( 'status' => 400 ) );
	}
	$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory CSV.
	fwrite( $handle, (string) preg_replace( '/^\xEF\xBB\xBF/', '', $csv ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- In-memory CSV.
	rewind( $handle );
	$header = fgetcsv( $handle, 0, ',', '"', '' );
	$header = is_array( $header ) ? array_map( static fn( $value ) => strtolower( trim( $value ) ), $header ) : array();
	$email = array_search( 'email', $header, true );
	$consent = array_search( 'wants organizer email', $header, true );
	if ( false === $consent ) {
		$consent = array_search( 'email opt-in', $header, true );
	}
	$unsubscribed = array_search( 'unsubscribed', $header, true );
	if ( false === $email || false === $consent || false === $unsubscribed ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory CSV.
		return new WP_Error( 'eventbrite_consent_columns_required', 'Nobody imported: venue-list import requires Email, Wants Organizer Email (or Email Opt-In), and Unsubscribed columns. Export a report containing explicit consent; attendee reports without these fields cannot be used.', array( 'status' => 400 ) );
	}
	$result = array( 'imported' => 0, 'skipped_no_consent' => 0, 'skipped_unsubscribed' => 0, 'already_subscribed' => 0, 'skipped_duplicate' => 0, 'failed' => 0 );
	$seen = array();
	while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- CSV loop.
		if ( array( null ) === $row ) {
			continue;
		}
		$cell = static fn( $index ) => strtolower( trim( (string) ( $row[ $index ] ?? '' ) ) );
		if ( in_array( $cell( $unsubscribed ), array( 'yes', 'true', '1' ), true ) ) {
			++$result['skipped_unsubscribed'];
			continue;
		}
		if ( ! in_array( $cell( $consent ), array( 'yes', 'true', '1' ), true ) || ! in_array( $cell( $unsubscribed ), array( 'no', 'false', '0' ), true ) ) {
			++$result['skipped_no_consent'];
			continue;
		}
		$address = $cell( $email );
		if ( ! is_email( $address ) ) {
			++$result['failed'];
			continue;
		}
		if ( isset( $seen[ $address ] ) ) {
			++$result['skipped_duplicate'];
			continue;
		}
		$seen[ $address ] = true;
		$user = get_user_by( 'email', $address );
		$definition = extrachill_events_digest_scope( 'venue' );
		$status = $user ? extrachill_users_entity_subscription_status( $user->ID, $definition['entity'], $definition['taxonomy'], $venues[0]->slug ) : array();
		if ( ! is_wp_error( $status ) && ! empty( $status['subscribed'] ) ) {
			++$result['already_subscribed'];
			continue;
		}
		$request = extrachill_events_guest_subscribe_local_scene_digest( $venues[0]->slug, $address, array( 'eventbrite_import' => true ), 'venue' );
		++$result[ is_wp_error( $request ) ? 'failed' : 'imported' ];
	}
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory CSV.
	return $result;
}

/**
 * Import an Eventbrite attendee report onto an event's door list.
 *
 * @param int    $event_id     Event post ID.
 * @param string $csv          Report contents.
 * @param int    $host_user_id Host running the import.
 * @return array|WP_Error { added: int, already_listed: int, skipped: int }
 */
function extrachill_events_import_eventbrite_attendees( int $event_id, string $csv, int $host_user_id, string $mode = 'door_list' ) {
	if ( 'venue_list' === $mode ) {
		return extrachill_events_import_eventbrite_venue_list( $event_id, $csv );
	}
	if ( 'door_list' !== $mode ) {
		return new WP_Error( 'invalid_import_mode', 'Choose door_list or venue_list.', array( 'status' => 400 ) );
	}
	if ( ! extrachill_events_perk_enabled( $event_id ) ) {
		return new WP_Error( 'guest_list_unavailable', __( 'Turn on the guest list and save before importing, so there is a door list to add them to.', 'extrachill-events' ), array( 'status' => 409 ) );
	}
	if ( strlen( $csv ) > EXTRACHILL_EVENTS_EVENTBRITE_MAX_BYTES ) {
		return new WP_Error( 'eventbrite_import_too_large', __( 'That file is too large. Export only this show\'s attendees.', 'extrachill-events' ), array( 'status' => 413 ) );
	}

	$parsed = extrachill_events_parse_eventbrite_attendees( $csv );
	if ( is_wp_error( $parsed ) ) {
		return $parsed;
	}

	$result = array(
		'added'          => 0,
		'already_listed' => 0,
		'skipped'        => (int) $parsed['skipped'],
	);
	foreach ( $parsed['tickets'] as $ticket ) {
		$key    = substr( EXTRACHILL_EVENTS_EVENTBRITE_KEY_PREFIX . $event_id . ':' . $ticket['key'], 0, 191 );
		$status = RsvpPassesTable::import_external_pass( $event_id, $ticket['name'], $key, $host_user_id );
		if ( 'added' === $status ) {
			++$result['added'];
		} elseif ( 'exists' === $status ) {
			++$result['already_listed'];
		} else {
			++$result['skipped'];
		}
	}

	if ( $result['added'] > 0 && function_exists( 'extrachill_cache_purge_post' ) ) {
		extrachill_cache_purge_post( $event_id );
	}

	return $result;
}
