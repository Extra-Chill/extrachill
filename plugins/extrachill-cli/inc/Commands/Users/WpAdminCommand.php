<?php
/**
 * Explicit wp-admin grant operator surface.
 *
 * @package ExtraChill\CLI\Commands\Users
 */

namespace ExtraChill\CLI\Commands\Users;

use WP_CLI;

/** Manage explicit per-user grants; administrators need no grant. */
class WpAdminCommand {
	/**
	 * Grant, revoke, or list network-wide wp-admin grants.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : grant, revoke, or list.
	 *
	 * [<user>]
	 * : User ID, login, or email. Required for grant/revoke.
	 *
	 * @when after_wp_load
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function __invoke( $args ) {
		if ( ! function_exists( 'ec_grant_wp_admin' ) ) {
			WP_CLI::error( 'extrachill-users is required.' );
		}
		$action = $args[0];
		if ( ! in_array( $action, array( 'grant', 'revoke', 'list' ), true ) ) {
			WP_CLI::error( 'Expected grant, revoke, or list.' );
		}
		$user = null;
		if ( isset( $args[1] ) ) {
			$found = get_user_by( is_numeric( $args[1] ) ? 'id' : ( is_email( $args[1] ) ? 'email' : 'login' ), $args[1] );
			if ( ! $found instanceof \WP_User ) {
				WP_CLI::error( 'User not found.' );
				return;
			}
			$user = $found;
		}
		if ( 'list' === $action ) {
			$rows = array();
			foreach ( $user ? array( $user ) : get_users( array( 'blog_id' => 0 ) ) as $candidate ) {
				if ( ec_user_has_wp_admin_grant( $candidate->ID ) ) {
					$rows[] = array(
						'ID'         => $candidate->ID,
						'user_login' => $candidate->user_login,
					);
				}
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'user_login' ) );
			return;
		}
		if ( ! $user instanceof \WP_User ) {
			WP_CLI::error( 'A user is required.' );
			return;
		}
		$changed = 'grant' === $action ? ec_grant_wp_admin( $user->ID ) : ec_revoke_wp_admin( $user->ID );
		WP_CLI::success( sprintf( 'Updated %d site(s).', count( $changed ) ) );
	}
}
