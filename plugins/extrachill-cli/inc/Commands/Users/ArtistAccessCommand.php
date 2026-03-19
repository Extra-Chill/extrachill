<?php
/**
 * Artist Access CLI Command
 *
 * Wraps artist access abilities from extrachill-users.
 *
 * @package ExtraChill\CLI\Commands\Users
 */

namespace ExtraChill\CLI\Commands\Users;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ArtistAccessCommand {

	/**
	 * List pending artist access requests.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users access list
	 *     wp extrachill users access list --format=json
	 *
	 * @when after_wp_load
	 * @subcommand list
	 */
	public function list_requests( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/list-artist-access-requests' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill-users plugin is required (ability not found).' );
		}

		$result = $ability->execute( array() );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$requests = $result['requests'] ?? array();
		if ( empty( $requests ) ) {
			WP_CLI::success( 'No pending artist access requests.' );
			return;
		}

		// Format timestamps for display.
		foreach ( $requests as &$req ) {
			if ( ! empty( $req['requested_at'] ) ) {
				$req['requested_at'] = gmdate( 'Y-m-d H:i', $req['requested_at'] );
			}
		}

		$format = $assoc_args['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $requests, array( 'user_id', 'user_login', 'user_email', 'type', 'requested_at' ) );
	}

	/**
	 * Approve a pending artist access request.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [--type=<type>]
	 * : Access type to grant.
	 * ---
	 * default: artist
	 * options:
	 *   - artist
	 *   - professional
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users access approve maraluso
	 *     wp extrachill users access approve 592 --type=professional
	 *
	 * @when after_wp_load
	 */
	public function approve( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$ability = wp_get_ability( 'extrachill/approve-artist-access' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill-users plugin is required (ability not found).' );
		}

		$type   = $assoc_args['type'] ?? 'artist';
		$result = $ability->execute(
			array(
				'user_id' => $user->ID,
				'type'    => $type,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $result['skipped'] ) ) {
			WP_CLI::warning( "User {$user->user_login} (ID {$user->ID}) already has artist/professional access." );
			return;
		}

		WP_CLI::success( "Approved {$user->user_login} (ID {$user->ID}) as {$type}." );
	}

	/**
	 * Reject a pending artist access request.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users access reject 592
	 *     wp extrachill users access reject spamuser
	 *
	 * @when after_wp_load
	 */
	public function reject( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$ability = wp_get_ability( 'extrachill/reject-artist-access' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill-users plugin is required (ability not found).' );
		}

		$result = $ability->execute(
			array(
				'user_id' => $user->ID,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( "Rejected access request from {$user->user_login} (ID {$user->ID})." );
	}

	/**
	 * Resolve a user by ID, login, or email.
	 *
	 * @param string $identifier User ID, login, or email.
	 * @return \WP_User|null
	 */
	private function resolve_user( string $identifier ): ?\WP_User {
		if ( is_numeric( $identifier ) ) {
			return get_user_by( 'ID', absint( $identifier ) ) ?: null;
		}

		if ( is_email( $identifier ) ) {
			return get_user_by( 'email', $identifier ) ?: null;
		}

		return get_user_by( 'login', $identifier ) ?: null;
	}
}
