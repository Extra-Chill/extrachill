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
	 * Moderate a user with a blocking action.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [--reason-key=<reason-key>]
	 * : Moderation reason key.
	 * ---
	 * default: spam
	 * options:
	 *   - spam
	 *   - abuse
	 *   - impersonation
	 *   - fraud
	 *   - other
	 * ---
	 *
	 * [--reason=<reason>]
	 * : Public-facing moderation reason.
	 *
	 * [--note=<note>]
	 * : Internal note.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill users ban 581 --reason-key=spam --reason="Link spam"
	 *     wp extrachill users ban top-website-builder --reason-key=other --reason="Policy violation"
	 *
	 * @when after_wp_load
	 */
	public function ban( $args, $assoc_args ) {
		$user = $this->resolve_user( $args[0] ?? '' );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$ability = wp_get_ability( 'extrachill/moderate-user' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/moderate-user ability not available. Is extrachill-users active?' );
		}

		$reason_key = isset( $assoc_args['reason-key'] ) ? (string) $assoc_args['reason-key'] : 'spam';
		$state      = isset( $assoc_args['state'] ) ? (string) $assoc_args['state'] : 'banned';

		$result = $ability->execute(
			array(
				'user_id'   => (int) $user->ID,
				'state'     => $state,
				'reason_key'=> $reason_key,
				'reason'    => isset( $assoc_args['reason'] ) ? (string) $assoc_args['reason'] : '',
				'note'      => isset( $assoc_args['note'] ) ? (string) $assoc_args['note'] : '',
				'source'    => 'wp-cli',
				'acted_by'  => 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Applied %s moderation to user %d (%s).', $state, (int) $user->ID, $user->user_login ) );
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

		$ability = wp_get_ability( 'extrachill/clear-user-moderation' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/clear-user-moderation ability not available. Is extrachill-users active?' );
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

		$ability = wp_get_ability( 'extrachill/get-user-moderation-status' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-user-moderation-status ability not available. Is extrachill-users active?' );
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
