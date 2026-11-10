<?php
/** Network team storage schema. @package ExtraChill\Users */
defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_TEAMS_SCHEMA_VERSION = '2';

/** Install the network team stores. */
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

	$claims = $wpdb->base_prefix . 'ec_team_claims';
	$invites = $wpdb->base_prefix . 'ec_team_invitations';
	$claims_sql = "CREATE TABLE {$claims} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			team_type VARCHAR(16) NOT NULL,
			team_id BIGINT UNSIGNED NOT NULL,
			claimant_user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			reviewed_by_user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			resolved_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY team_claimant (team_type, team_id, claimant_user_id),
			KEY status_created (team_type, status, created_at),
			KEY team_status (team_type, team_id, status),
			KEY claimant_status_team (claimant_user_id, status, team_type, team_id)
		) ENGINE=InnoDB {$charset};";

	$invites_sql = "CREATE TABLE {$invites} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			team_type VARCHAR(16) NOT NULL,
			team_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			is_owner TINYINT UNSIGNED NOT NULL DEFAULT '0',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			token_hash CHAR(64) NOT NULL,
			email_hash CHAR(64) NOT NULL,
			account_created TINYINT UNSIGNED NOT NULL DEFAULT '0',
			delivery_id CHAR(36) NOT NULL,
			delivery_status VARCHAR(20) NOT NULL DEFAULT 'queued',
			delivery_attempts BIGINT UNSIGNED NOT NULL DEFAULT '0',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			invited_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			resolved_at DATETIME NULL,
			delivered_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY delivery_id (delivery_id),
			UNIQUE KEY team_user (team_type, team_id, user_id),
			KEY team_status (team_type, team_id, status),
			KEY status_expiration (team_type, status, expires_at),
			KEY user_status_team (user_id, status, team_type, team_id)
		) ENGINE=InnoDB {$charset};";

	$error = false;
	foreach ( array( $sql, $claims_sql, $invites_sql ) as $statement ) {
		dbDelta( $statement );
		$error = $error || '' !== (string) $wpdb->last_error;
	}
	if ( ! $error ) {
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
