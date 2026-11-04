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
 * `extrachill/set-guest-list-party-allotment`, extrachill#1128).
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
	 * Sets the RSVP perk, its password, and its capacity in one call. The
	 * password is stored hashed; omit it to keep the current one.
	 *
	 * ## OPTIONS
	 *
	 * <event>
	 * : Event post ID or slug.
	 *
	 * [--text=<text>]
	 * : Attendee-facing perk text, e.g. "Extra Chill guest list".
	 *
	 * [--password=<password>]
	 * : Guest list password. Only people who know it can claim a pass.
	 *
	 * [--clear-password]
	 * : Remove the password (anyone can claim).
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
		} else {
			$current       = $this->run( 'extrachill/get-event-perk', array( 'event' => (string) $args[0] ) );
			$input['text'] = (string) ( $current['text'] ?? '' );
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
		foreach ( array( 'post_id', 'enabled', 'text', 'password_required', 'capacity', 'allotments_enabled', 'claimed', 'checked_in', 'spots_left', 'full', 'image_id', 'image_url', 'pass_image_source', 'pass_image_url' ) as $field ) {
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
}
