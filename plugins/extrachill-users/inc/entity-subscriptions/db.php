<?php
/**
 * Network entity subscription storage.
 *
 * User meta is network-wide but cannot index recipients by entity without
 * scanning serialized values. This table is the small reverse-indexing gap.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_USERS_ENTITY_SUBSCRIPTIONS_SCHEMA_VERSION = '1';

/**
 * Get the network entity subscription table name.
 *
 * @return string
 */
function extrachill_users_entity_subscriptions_table_name(): string {

	global $wpdb;

	return $wpdb->base_prefix . 'ec_entity_subscriptions';
}

/**
 * Install the network entity subscription table.
 *
 * @return void
 */
function extrachill_users_install_entity_subscriptions_table(): void {

	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = extrachill_users_entity_subscriptions_table_name();
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		entity_type varchar(32) NOT NULL,
		taxonomy varchar(32) NOT NULL,
		entity_slug varchar(200) NOT NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY user_entity (user_id, entity_type, taxonomy, entity_slug),
		KEY entity_recipients (entity_type, taxonomy, entity_slug, user_id)
	) {$charset_collate};";

	dbDelta( $sql );
}
