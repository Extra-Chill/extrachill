<?php
/**
 * Users Ban CLI Command
 *
 * Wraps user ban abilities from extrachill-users.
 *
 * @package ExtraChill\CLI\Commands\Users
 */

namespace ExtraChill\CLI\Commands\Users;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BanCommand {

	/**
	 * Ban a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [--reason=<reason>]
	 * : Public-facing ban reason.
	 *
	 * [--note=<note>]
	 * : Internal note.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users ban 581 --reason="Link spam"
	 *     wp extrachill users ban top-website-builder --reason="Spam"
	 *
	 * @when after_wp_load
	 */
	public function ban( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$ability = wp_get_ability( 'extrachill/ban-user' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/ban-user ability not available. Is extrachill-users active?' );
		}

		$result = $ability->execute(
			array(
				'user_id'   => (int) $user->ID,
				'reason'    => isset( $assoc_args['reason'] ) ? (string) $assoc_args['reason'] : '',
				'note'      => isset( $assoc_args['note'] ) ? (string) $assoc_args['note'] : '',
				'source'    => 'wp-cli',
				'banned_by' => 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Banned user %d (%s).', (int) $user->ID, $user->user_login ) );
	}

	/**
	 * Unban a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users unban 581
	 *
	 * @when after_wp_load
	 */
	public function unban( $args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$ability = wp_get_ability( 'extrachill/unban-user' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/unban-user ability not available. Is extrachill-users active?' );
		}

		$result = $ability->execute(
			array(
				'user_id' => (int) $user->ID,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Unbanned user %d (%s).', (int) $user->ID, $user->user_login ) );
	}

	/**
	 * Show ban status for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users ban-status 581
	 *
	 * @subcommand ban-status
	 * @when after_wp_load
	 */
	public function ban_status( $args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$ability = wp_get_ability( 'extrachill/get-user-ban-status' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-user-ban-status ability not available. Is extrachill-users active?' );
		}

		$result = $ability->execute(
			array(
				'user_id' => (int) $user->ID,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	}

	private function resolve_user( $identifier ) {
		if ( is_numeric( $identifier ) ) {
			return get_user_by( 'id', (int) $identifier );
		}

		if ( is_email( $identifier ) ) {
			return get_user_by( 'email', $identifier );
		}

		return get_user_by( 'login', $identifier );
	}
}
