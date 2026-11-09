<?php
/** Network team storage schema. @package ExtraChill\Users */
defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_TEAMS_SCHEMA_VERSION = '1';

/** Install the network membership store. */
function extrachill_users_install_teams_table(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = $wpdb->base_prefix . 'ec_team_members';
	$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			team_type VARCHAR(16) NOT NULL,
			team_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			is_owner TINYINT UNSIGNED NOT NULL DEFAULT '0',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			created_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			revoked_by_user_id BIGINT UNSIGNED NULL,
			revoked_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY team_user (team_type, team_id, user_id),
			KEY user_status_team (user_id, status, team_type, team_id),
			KEY team_status_owner (team_type, team_id, status, is_owner)
		) ENGINE=InnoDB {$charset};";

	dbDelta( $sql );
	if ( '' === (string) $wpdb->last_error ) {
		update_site_option( 'extrachill_users_teams_schema_version', EXTRACHILL_USERS_TEAMS_SCHEMA_VERSION );
	}
}

/** Upgrade network storage before consumers boot. */
function extrachill_users_maybe_install_teams_table(): void {
	if ( EXTRACHILL_USERS_TEAMS_SCHEMA_VERSION !== (string) get_site_option( 'extrachill_users_teams_schema_version', '' ) ) {
		extrachill_users_install_teams_table();
	}
}
add_action( 'plugins_loaded', 'extrachill_users_maybe_install_teams_table', 1 );
