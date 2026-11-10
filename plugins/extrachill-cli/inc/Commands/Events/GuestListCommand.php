<?php
/**
 * Event Guest List CLI Commands
 *
 * Thin adapter over the extrachill-events RSVP perk abilities
 * (`extrachill/set-event-perk`, `extrachill/get-event-perk`) for running a
 * password-gated, capacity-limited guest list (extrachill#433), and the
 * host comp abilities (`extrachill/add-guest-list-name`,
 * `extrachill/remove-guest-list-name`, extrachill#1119), and band /
 * management invites (`extrachill/create-guest-list-party`,
 * `extrachill/list-guest-list-parties`, `extrachill/revoke-guest-list-party`,
 * `extrachill/set-guest-list-party-allotment`, extrachill#1128), and the
 * venue's request queue (`extrachill/list-guest-list-requests`,
 * `extrachill/approve-guest-list-request`,
 * `extrachill/deny-guest-list-request`, extrachill#1129), and a venue's or
 * promoter's permanent owner's list (`extrachill/list-owner-guest-list`,
 * `extrachill/add-owner-guest-list-name`,
 * `extrachill/remove-owner-guest-list-name`, extrachill#1130).
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Configure and check an event's guest list. */
class GuestListCommand {

	/**
	 * Run an Events-owned ability, erroring on failure.
	 *
	 * @param string $ability_name Ability name.
	 * @param array  $input        Input.
	 * @return array
	 */
	private function run( $ability_name, array $input = array() ) {
		$ability = OwnerSiteAbility::get( 'events', 'Events', $ability_name );
		$result  = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Turn on or update an event's guest list.
	 *
	 * Sets the RSVP perk, its access mode, its password, and its capacity in
	 * one call. Omitted options keep their current value. The password is
	 * stored hashed.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * [--text=<text>]
	 * : Attendee-facing perk text, e.g. "Extra Chill guest list". Omit to keep the current text.
	 *
	 * [--mode=<mode>]
	 * : Who gets on the list. host_list: only names hosts add (no password, no public claim, Going grants nothing). password: guests claim with the password. going: marking Going issues the pass. Omit to keep the current mode.
	 * ---
	 * options:
	 *   - host_list
	 *   - password
	 *   - going
	 * ---
	 *
	 * [--password=<password>]
	 * : Guest list password for password mode. Setting one on a going list switches it to password mode.
	 *
	 * [--clear-password]
	 * : Remove the password. A password list becomes a going list; a host list stays a host list.
	 *
	 * [--capacity=<n>]
	 * : Most passes the event will issue. 0 = unlimited.
	 *
	 * [--image-id=<attachment-id>]
	 * : Pass artwork: an image attachment ID shown on the pass next to the QR code. 0 removes it (the pass then shows the event poster, if any).
	 *
	 * [--allotments=<on|off>]
	 * : Cap each invited band or management at its own allotment of spots. Off by default (only --capacity applies).
	 *
	 * [--disable]
	 * : Turn the guest list off.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list set 640348 --text="Extra Chill guest list" --password=spooky --capacity=60 --user=1 --url=events.extrachill.com
	 *     wp extrachill events guest-list set 640348 --mode=host_list --user=1 --url=events.extrachill.com
	 *     wp extrachill events guest-list set 640348 --image-id=812345 --user=1 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function set( $args, $assoc_args ) {
		$input = array(
			'event'   => (string) $args[0],
			'enabled' => ! Utils\get_flag_value( $assoc_args, 'disable', false ),
		);

		if ( isset( $assoc_args['text'] ) ) {
			$input['text'] = (string) $assoc_args['text'];
		}

		if ( isset( $assoc_args['mode'] ) ) {
			$input['mode'] = (string) $assoc_args['mode'];
		}

		if ( Utils\get_flag_value( $assoc_args, 'clear-password', false ) ) {
			$input['clear_password'] = true;
		} elseif ( isset( $assoc_args['password'] ) ) {
			if ( '' === (string) $assoc_args['password'] ) {
				WP_CLI::error( 'The password cannot be empty. Use --clear-password to remove it.' );
			}
			$input['password'] = (string) $assoc_args['password'];
		}

		if ( isset( $assoc_args['capacity'] ) ) {
			if ( ! is_numeric( $assoc_args['capacity'] ) || (int) $assoc_args['capacity'] < 0 ) {
				WP_CLI::error( '--capacity must be 0 or a positive number.' );
			}
			$input['capacity'] = (int) $assoc_args['capacity'];
		}

		if ( isset( $assoc_args['image-id'] ) ) {
			if ( ! is_numeric( $assoc_args['image-id'] ) || (int) $assoc_args['image-id'] < 0 ) {
				WP_CLI::error( '--image-id must be 0 or an attachment ID.' );
			}
			$input['image_id'] = (int) $assoc_args['image-id'];
		}

		if ( isset( $assoc_args['allotments'] ) ) {
			if ( ! in_array( $assoc_args['allotments'], array( 'on', 'off' ), true ) ) {
				WP_CLI::error( '--allotments must be on or off.' );
			}
			$input['allotments_enabled'] = 'on' === $assoc_args['allotments'];
		}

		$this->run( 'extrachill/set-event-perk', $input );
		$this->status( $args, array() );
	}

	/**
	 * Show an event's guest list settings and counts.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list status 640348 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$perk = $this->run( 'extrachill/get-event-perk', array( 'event' => (string) $args[0] ) );

		if ( 'json' === Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $perk, JSON_PRETTY_PRINT ) );
			return;
		}

		$rows = array();
		foreach ( array( 'post_id', 'enabled', 'text', 'mode', 'password_required', 'capacity', 'allotments_enabled', 'claimed', 'checked_in', 'spots_left', 'full', 'image_id', 'image_url', 'pass_image_source', 'pass_image_url' ) as $field ) {
			$value = $perk[ $field ] ?? null;
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			} elseif ( null === $value ) {
				$value = 'capacity' === $field || 'spots_left' === $field ? 'unlimited' : '';
			}
			$rows[] = array(
				'field' => $field,
				'value' => (string) $value,
			);
		}

		Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Put a guest on an event's guest list by name (a comp). No password needed.
	 *
	 * Runs as the --user, who must manage the event. Prints the pass link to
	 * send the guest.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * <name>
	 * : Guest name shown to the door, e.g. "Jake +1".
	 *
	 * [--email=<email>]
	 * : Email the pass to the guest.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list add 640348 "Jake +1" --user=1 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function add( $args, $assoc_args ) {
		$perk  = $this->run( 'extrachill/get-event-perk', array( 'event' => (string) $args[0] ) );
		$input = array(
			'event_id' => (int) ( $perk['post_id'] ?? 0 ),
			'name'     => (string) $args[1],
		);
		if ( isset( $assoc_args['email'] ) ) {
			$input['email'] = (string) $assoc_args['email'];
		}

		$result = $this->run( 'extrachill/add-guest-list-name', $input );

		if ( 'json' === Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		WP_CLI::success( sprintf( '%s is on the list. Code %s. Pass link: %s', $result['name'] ?? '', $result['code'] ?? '', $result['pass_url'] ?? '' ) );
	}

	/**
	 * Take a guest off an event's guest list by pass code. Frees the spot.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * <code>
	 * : The guest's pass code.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list remove 640348 ABCDE-FGH2J-K3LMN --user=1 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function remove( $args, $assoc_args ) {
		unset( $assoc_args );
		$perk = $this->run( 'extrachill/get-event-perk', array( 'event' => (string) $args[0] ) );
		$this->run(
			'extrachill/remove-guest-list-name',
			array(
				'event_id' => (int) ( $perk['post_id'] ?? 0 ),
				'code'     => (string) $args[1],
			)
		);

		WP_CLI::success( 'Guest removed from the list.' );
	}

	/**
	 * Resolve an event reference to its post ID through the perk ability.
	 *
	 * @param string $event Event post ID or slug.
	 * @return int
	 */
	private function event_id( $event ) {
		$perk = $this->run( 'extrachill/get-event-perk', array( 'event' => (string) $event ) );
		return (int) ( $perk['post_id'] ?? 0 );
	}

	/**
	 * Invite a band or artist management to fill guest list spots.
	 *
	 * Emails them a private link where they add guest names with no password
	 * or account. Runs as the --user, who must manage the event. Prints the
	 * link once; it cannot be retrieved later.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * <name>
	 * : Band or management name, shown on the door list, e.g. "Slim S.O.U.L.".
	 *
	 * <email>
	 * : Where to send the invite link.
	 *
	 * [--allotment=<n>]
	 * : Spots for this party. Required when the event has allotments on.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list invite 640348 "Slim S.O.U.L." mgmt@example.com --user=1 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function invite( $args, $assoc_args ) {
		$input = array(
			'event_id' => $this->event_id( $args[0] ),
			'name'     => (string) $args[1],
			'email'    => (string) $args[2],
		);
		if ( isset( $assoc_args['allotment'] ) ) {
			if ( ! is_numeric( $assoc_args['allotment'] ) || (int) $assoc_args['allotment'] < 0 ) {
				WP_CLI::error( '--allotment must be 0 or a positive number.' );
			}
			$input['allotment'] = (int) $assoc_args['allotment'];
		}

		$result = $this->run( 'extrachill/create-guest-list-party', $input );

		if ( 'json' === Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		WP_CLI::success( sprintf( 'Invited %s (party %d)%s. Link: %s', $result['name'] ?? '', (int) ( $result['party_id'] ?? 0 ), empty( $result['emailed'] ) ? ', email NOT sent' : ', email sent', $result['invite_url'] ?? '' ) );
	}

	/**
	 * List the bands and management invited to an event's guest list.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list parties 640348 --user=1 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function parties( $args, $assoc_args ) {
		$result = $this->run( 'extrachill/list-guest-list-parties', array( 'event_id' => $this->event_id( $args[0] ) ) );

		if ( 'json' === Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		WP_CLI::line( sprintf( 'Allotments: %s', empty( $result['allotments_enabled'] ) ? 'off' : 'on' ) );
		$rows = array();
		foreach ( (array) ( $result['parties'] ?? array() ) as $party ) {
			$rows[] = array(
				'party_id'   => (int) $party['party_id'],
				'name'       => (string) $party['name'],
				'email'      => (string) $party['email'],
				'status'     => (string) $party['status'],
				'used'       => (int) $party['used'],
				'allotment'  => null === $party['allotment'] ? '' : (string) $party['allotment'],
				'spots_left' => null === $party['spots_left'] ? '' : (string) $party['spots_left'],
			);
		}
		Utils\format_items( 'table', $rows, array( 'party_id', 'name', 'email', 'status', 'used', 'allotment', 'spots_left' ) );
	}

	/**
	 * Revoke a band or management invite. Their link stops working; guests
	 * they already added stay on the list (remove them with `remove`).
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * <party-id>
	 * : Party ID from `parties`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list revoke-invite 640348 12 --user=1 --url=events.extrachill.com
	 *
	 * @subcommand revoke-invite
	 * @when after_wp_load
	 */
	public function revoke_invite( $args, $assoc_args ) {
		unset( $assoc_args );
		$result = $this->run(
			'extrachill/revoke-guest-list-party',
			array(
				'event_id' => $this->event_id( $args[0] ),
				'party_id' => (int) $args[1],
			)
		);

		WP_CLI::success( sprintf( 'Invite revoked. %d guest(s) they added stay on the list.', (int) ( $result['kept_guests'] ?? 0 ) ) );
	}

	/**
	 * Set how many spots an invited band or management gets.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * <party-id>
	 * : Party ID from `parties`.
	 *
	 * <allotment>
	 * : Spots for this party.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list allot 640348 12 6 --user=1 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function allot( $args, $assoc_args ) {
		unset( $assoc_args );
		if ( ! is_numeric( $args[2] ) || (int) $args[2] < 0 ) {
			WP_CLI::error( '<allotment> must be 0 or a positive number.' );
		}

		$party = $this->run(
			'extrachill/set-guest-list-party-allotment',
			array(
				'event_id'  => $this->event_id( $args[0] ),
				'party_id'  => (int) $args[1],
				'allotment' => (int) $args[2],
			)
		);

		WP_CLI::success( sprintf( '%s: %d of %d spots used.', $party['name'] ?? '', (int) ( $party['used'] ?? 0 ), (int) ( $party['allotment'] ?? 0 ) ) );
	}
	/**
	 * List what is waiting for the venue on an event: uninvited bands
	 * asking to be on the list (party), invited bands asking for more spots
	 * (spots), and lists of names sent for approval (list).
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list requests 640348 --user=1 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function requests( $args, $assoc_args ) {
		$result = $this->run( 'extrachill/list-guest-list-requests', array( 'event_id' => $this->event_id( $args[0] ) ) );

		if ( 'json' === Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$rows = array();
		foreach ( (array) ( $result['requests'] ?? array() ) as $request ) {
			$rows[] = array(
				'party_id'        => (int) $request['party_id'],
				'kind'            => (string) $request['kind'],
				'name'            => (string) $request['name'],
				'email'           => (string) $request['email'],
				'requested_spots' => null === $request['requested_spots'] ? '' : (string) $request['requested_spots'],
				'names'           => implode( ', ', (array) $request['names'] ),
				'note'            => (string) $request['note'],
			);
		}
		if ( empty( $rows ) ) {
			WP_CLI::line( 'No pending requests.' );
			return;
		}
		Utils\format_items( 'table', $rows, array( 'party_id', 'kind', 'name', 'email', 'requested_spots', 'names', 'note' ) );
	}

	/**
	 * Approve a pending request. spots: the band's allotment grows. list:
	 * the submitted names go live. party: the uninvited band becomes an
	 * invited party and is emailed its private link.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * <party-id>
	 * : Party ID from `requests`.
	 *
	 * [--kind=<kind>]
	 * : party, spots, or list. Needed only when the party has more than one request waiting.
	 *
	 * [--spots=<n>]
	 * : Grant this many spots instead of what was asked (spots and party requests).
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list approve 640348 12 --user=1 --url=events.extrachill.com
	 *     wp extrachill events guest-list approve 640348 12 --kind=spots --spots=2 --user=1 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function approve( $args, $assoc_args ) {
		$input = array(
			'event_id' => $this->event_id( $args[0] ),
			'party_id' => (int) $args[1],
			'kind'     => (string) Utils\get_flag_value( $assoc_args, 'kind', '' ),
		);
		$spots = Utils\get_flag_value( $assoc_args, 'spots', null );
		if ( null !== $spots ) {
			if ( ! is_numeric( $spots ) || (int) $spots < 0 ) {
				WP_CLI::error( '--spots must be 0 or a positive number.' );
			}
			$input['spots'] = (int) $spots;
		}

		$result = $this->run( 'extrachill/approve-guest-list-request', $input );

		$party = (array) ( $result['party'] ?? array() );
		$line  = sprintf( 'Approved %s request from %s.', $result['kind'] ?? '', $party['name'] ?? '' );
		if ( ! empty( $result['activated'] ) ) {
			$line .= sprintf( ' %d name(s) now on the list.', (int) $result['activated'] );
		}
		if ( null !== ( $party['allotment'] ?? null ) ) {
			$line .= sprintf( ' %d of %d spots used.', (int) ( $party['used'] ?? 0 ), (int) $party['allotment'] );
		}
		if ( ! empty( $result['invite_url'] ) ) {
			$line .= sprintf( '%s Link: %s', empty( $result['emailed'] ) ? ' Email NOT sent.' : ' Email sent.', $result['invite_url'] );
		}
		if ( ! empty( $result['names_waiting'] ) ) {
			$line .= sprintf( ' %d name(s) did not fit the guest list and are still waiting.', (int) $result['names_waiting'] );
		}
		WP_CLI::success( $line );
	}

	/**
	 * Deny a pending request and email the band. Denied names never scan.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * <party-id>
	 * : Party ID from `requests`.
	 *
	 * [--kind=<kind>]
	 * : party, spots, or list. Needed only when the party has more than one request waiting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list deny 640348 12 --user=1 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function deny( $args, $assoc_args ) {
		$result = $this->run(
			'extrachill/deny-guest-list-request',
			array(
				'event_id' => $this->event_id( $args[0] ),
				'party_id' => (int) $args[1],
				'kind'     => (string) Utils\get_flag_value( $assoc_args, 'kind', '' ),
			)
		);

		WP_CLI::success( sprintf( 'Denied %s request (%d name(s) denied)%s.', $result['kind'] ?? '', (int) ( $result['denied_names'] ?? 0 ), empty( $result['emailed'] ) ? ', email NOT sent' : ', band emailed' ) );
	}

	/**
	 * Resolve the owner of an owner's list: a venue or promoter by term ID or slug.
	 *
	 * @param string $type  venue|promoter.
	 * @param string $owner Term ID or slug.
	 * @return array{owner_type: string, owner_id: int}
	 */
	private function owner( $type, $owner ) {
		if ( ! in_array( $type, array( 'venue', 'promoter' ), true ) ) {
			WP_CLI::error( 'The list owner type must be venue or promoter.' );
		}

		if ( is_numeric( $owner ) ) {
			$owner_id = (int) $owner;
		} else {
			$term     = get_term_by( 'slug', sanitize_title( $owner ), $type );
			$owner_id = $term ? (int) $term->term_id : 0;
			if ( ! $owner_id ) {
				WP_CLI::error( sprintf( 'No %1$s found with slug "%2$s".', $type, $owner ) );
			}
		}

		return array(
			'owner_type' => $type,
			'owner_id'   => $owner_id,
		);
	}

	/**
	 * List a venue's or promoter's permanent owner's list: the people who
	 * get in at every one of their shows, with their pass links.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : venue or promoter.
	 *
	 * <owner>
	 * : Venue or promoter term ID or slug.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list owner-list venue lo-fi-brewing --user=1 --url=events.extrachill.com
	 *
	 * @subcommand owner-list
	 * @when after_wp_load
	 */
	public function owner_list( $args, $assoc_args ) {
		$result = $this->run( 'extrachill/list-owner-guest-list', $this->owner( (string) $args[0], (string) $args[1] ) );

		if ( 'json' === Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		WP_CLI::line( sprintf( '%s: %s', $result['label'] ?? '', $result['owner_name'] ?? '' ) );
		$rows = array();
		foreach ( (array) ( $result['members'] ?? array() ) as $member ) {
			$rows[] = array(
				'member_id' => (int) $member['member_id'],
				'name'      => (string) $member['name'],
				'email'     => (string) $member['email'],
				'code'      => (string) $member['code'],
				'pass_url'  => (string) $member['pass_url'],
			);
		}
		Utils\format_items( 'table', $rows, array( 'member_id', 'name', 'email', 'code', 'pass_url' ) );
	}

	/**
	 * Put someone on a venue's or promoter's permanent owner's list. One
	 * pass works at every published show tagged with that venue or
	 * promoter and never uses a show's guest list spots. Runs as the
	 * --user, who must be on the venue's team or the promoter's members.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : venue or promoter.
	 *
	 * <owner>
	 * : Venue or promoter term ID or slug.
	 *
	 * <name>
	 * : Name shown at the door.
	 *
	 * [--email=<email>]
	 * : Send them the pass.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list owner-add venue lo-fi-brewing "Sam the Engineer" --email=sam@example.com --user=1 --url=events.extrachill.com
	 *
	 * @subcommand owner-add
	 * @when after_wp_load
	 */
	public function owner_add( $args, $assoc_args ) {
		$input         = $this->owner( (string) $args[0], (string) $args[1] );
		$input['name'] = (string) $args[2];
		if ( isset( $assoc_args['email'] ) ) {
			$input['email'] = (string) $assoc_args['email'];
		}

		$result = $this->run( 'extrachill/add-owner-guest-list-name', $input );

		if ( 'json' === Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		WP_CLI::success( sprintf( 'Added %1$s to the %2$s %3$s (person %4$d)%5$s. Pass: %6$s', $result['name'] ?? '', $result['owner_name'] ?? '', strtolower( (string) ( $result['label'] ?? '' ) ), (int) ( $result['member_id'] ?? 0 ), empty( $result['emailed'] ) ? '' : ', pass emailed', $result['pass_url'] ?? '' ) );
	}

	/**
	 * Take someone off a venue's or promoter's owner's list. Their pass
	 * stops working at once.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : venue or promoter.
	 *
	 * <owner>
	 * : Venue or promoter term ID or slug.
	 *
	 * <member-id>
	 * : Person ID from `owner-list`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events guest-list owner-remove venue lo-fi-brewing 12 --user=1 --url=events.extrachill.com
	 *
	 * @subcommand owner-remove
	 * @when after_wp_load
	 */
	public function owner_remove( $args, $assoc_args ) {
		unset( $assoc_args );
		$input              = $this->owner( (string) $args[0], (string) $args[1] );
		$input['member_id'] = (int) $args[2];

		$this->run( 'extrachill/remove-owner-guest-list-name', $input );
		WP_CLI::success( 'Removed. Their pass no longer works.' );
	}
}
