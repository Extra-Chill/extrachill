<?php
/** Isolated door contract fixture. @package ExtraChillEvents\Tests */

// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax,Universal.Namespaces.OneDeclarationPerFile,Universal.Namespaces.DisallowDeclarationWithoutName,Universal.Files.SeparateFunctionsFromOO -- Isolated fixture combines a namespaced repository double and global WP shims.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- WP and repository doubles retain production signatures.

namespace ExtraChillEvents\Core {
	/** In-memory pass repository double for ability routing. */
	class RsvpPassesTable {
		const STATUS_ACTIVE         = 'active';
		public static array $passes = array();
		public static function list_for_event( $event ): array {
			return array( 7 => self::$passes['RSVP'] );
		}
		public static function find_by_code( $code ) {
			return self::$passes[ $code ] ?? null; }
		public static function is_owner_pass( $pass ): bool {
			return ! empty( $pass['owner_type'] ); }
		public static function redeem_for_event( $pass, $event, $actor ): array {
			if ( self::is_owner_pass( $pass ) ) {
				throw new \RuntimeException( 'Owner pass must use per-event check-in.' );
			}
			$already                                      = 'redeemed' === self::$passes[ $pass['code'] ]['status'];
			self::$passes[ $pass['code'] ]['status']      = 'redeemed';
			self::$passes[ $pass['code'] ]['redeemed_at'] = '2026-06-01 20:00:00';
			return array(
				'already_redeemed' => $already,
				'redeemed_at'      => '2026-06-01 20:00:00',
			);
		}
	}
}

namespace {
	require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
	require dirname( __DIR__ ) . '/bootstrap.php';
	use ExtraChillEvents\Core\RsvpPassesTable;
	use ExtraChillEvents\Abilities\RsvpPassAbilities;

	function get_current_user_id() {
		return $GLOBALS['door_user']; }
	function get_post( $id ) {
		return (object) array( 'post_type' => 'data_machine_events' ); }
	function user_can( $id, $cap, ...$args ) {
		return ( 1 === $id && 'edit_post' === $cap ) || ( 2 === $id && 'ec_access_promoter' === $cap );
	}
	function wp_get_post_terms( $id, $taxonomy ) {
		return 'promoter' === $taxonomy ? array( (object) array( 'term_id' => 10 ) ) : array(); }
	function wp_register_ability( $name, $args ) {
		$GLOBALS['door_abilities'][ $name ] = $args; }
	function extrachill_events_perk_enabled( $id ) {
		return true; }
	function extrachill_events_get_perk_text( $id ) {
		return 'Entry'; }
	function extrachill_events_get_guest_list_counts( $id ) {
		return array(); }
	function ec_users_get_event_attendees_full( $id ) {
		return array(
			array(
				'user_id'      => 7,
				'display_name' => 'RSVP',
				'marked_at'    => '',
			),
			array(
				'user_id'      => 8,
				'display_name' => 'No pass',
				'marked_at'    => '',
			),
		);
	}
	function mysql2date( $format, $date ) {
		return $date; }
	function get_option( $key ) {
		return 'Y-m-d'; }
	function extrachill_events_door_list_guest_rows( $event, $enabled ): array {
		$rows = array();
		foreach ( RsvpPassesTable::$passes as $code => $pass ) {
			if ( 'RSVP' === $code ) {
				continue; }
			$rows[] = array(
				'pass_id'       => $pass['id'],
				'code'          => $code,
				'guest_name'    => $code,
				'label'         => $code,
				'source'        => $code,
				'source_kind'   => strtolower( $code ),
				'owner_list'    => ! empty( $pass['owner_type'] ),
				'status'        => $pass['status'],
				'checked_in_at' => $pass['redeemed_at'] ?? '',
			);
		}
		return $rows;
	}
	function extrachill_events_owner_list_check_in( $event, $pass, $actor ): array {
		$already = isset( $GLOBALS['owner_checkins'][ $event ][ $pass['id'] ] );
		$GLOBALS['owner_checkins'][ $event ][ $pass['id'] ] = '2026-06-01 20:00:00';
		return array(
			'already_redeemed' => $already,
			'redeemed_at'      => $GLOBALS['owner_checkins'][ $event ][ $pass['id'] ],
		);
	}
	function verify( $condition, $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI test diagnostic, not HTML.
		}
	}

	$root = dirname( __DIR__, 2 );
	require $root . '/inc/core/event-management-authority.php';
	require $root . '/inc/core/rsvp-pass-integration.php';
	require $root . '/inc/core/guest-list-door.php';
	require $root . '/inc/Abilities/RsvpPassAbilities.php';
	$ability = new RsvpPassAbilities();
	$ability->register();
	$definition = $GLOBALS['door_abilities']['extrachill/get-event-door-list'];
	verify( $definition['meta']['show_in_rest'] && $definition['meta']['annotations']['readonly'], 'REST readonly metadata' );
	foreach ( array( 1, 2, 3 ) as $user ) {
		$GLOBALS['door_user'] = $user;
		$result               = call_user_func( $definition['permission_callback'], array( 'event_id' => 100 ) );
		verify( 3 === $user ? is_wp_error( $result ) && 403 === $result->get_error_data()['status'] : true === $result, 'Manager, door staff, stranger permissions' );
	}
	$GLOBALS['door_user'] = 2;
	foreach ( array( 'RSVP', 'TICKET', 'HOST', 'BAND', 'EVENTBRITE', 'VENUE', 'PROMOTER' ) as $index => $code ) {
		RsvpPassesTable::$passes[ $code ] = array(
			'id'         => $index + 1,
			'code'       => $code,
			'status'     => 'active',
			'owner_type' => in_array( $code, array( 'VENUE', 'PROMOTER' ), true ) ? strtolower( $code ) : '',
		);
	}
	$result = $ability->get_event_door_list( array( 'event_id' => 100 ) );
	$web    = extrachill_events_guest_list_door_rows( extrachill_events_get_door_list_rows( 100 )['door_rows'] );
	verify( $web === $result['rows'] && 7 === $result['total'] && 0 === $result['checked_in'], 'Web parity and counts' );
	verify( array( 'attendee', 'guest', 'owner_list' ) === array_values( array_unique( array_column( $web, 'kind' ) ) ), 'All row kinds' );
	foreach ( $result['rows'] as $row ) {
		foreach ( array( 'pass_id', 'kind', 'label', 'source', 'source_kind', 'ticket_type', 'status', 'checked_in', 'checked_in_at', 'code' ) as $key ) {
			verify( array_key_exists( $key, $row ), 'Missing field ' . $key );
		}
		$input  = array(
			'event_id' => 100,
			'code'     => strtolower( $row['code'] ),
		);
		$first  = $ability->redeem_event_pass( $input );
		$second = $ability->redeem_event_pass( $input );
		verify( ! $first['already_redeemed'] && $second['already_redeemed'] && $first['redeemed_at'] === $second['redeemed_at'], 'Double tap ' . $row['kind'] );
	}
	verify( 5 === $ability->get_event_door_list( array( 'event_id' => 100 ) )['checked_in'], 'Updated event pass count' );
	echo 'door contract passed';
}
