<?php
/**
 * Network entity subscription service.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/db.php';

/**
 * Ensure the network table exists after upgrades.
 *
 * @return void
 */
function extrachill_users_maybe_install_entity_subscriptions_table(): void {

	if ( EXTRACHILL_USERS_ENTITY_SUBSCRIPTIONS_SCHEMA_VERSION === (string) get_site_option( 'extrachill_users_entity_subscriptions_schema_version', '' ) ) {
		return;
	}

	extrachill_users_install_entity_subscriptions_table();
	update_site_option( 'extrachill_users_entity_subscriptions_schema_version', EXTRACHILL_USERS_ENTITY_SUBSCRIPTIONS_SCHEMA_VERSION );
}
add_action( 'init', 'extrachill_users_maybe_install_entity_subscriptions_table' );

/**
 * Normalize and validate an entity identity.
 *
 * The default map defines the network's canonical entity/taxonomy pairs. A
 * feature may extend it without changing this generic persistence layer.
 *
 * @param string $entity_type Entity type.
 * @param string $taxonomy Taxonomy.
 * @param string $slug Entity slug.
 * @return array|WP_Error
 */
function extrachill_users_normalize_entity_subscription( $entity_type, $taxonomy, $slug ) {

	$entity_type = sanitize_key( $entity_type );
	$taxonomy    = sanitize_key( $taxonomy );
	$slug        = sanitize_title( $slug );
	$entities    = apply_filters(
		'extrachill_users_entity_subscription_entities',
		array(
			'festival' => 'festival',
			'artist'   => 'artist',
			'venue'    => 'venue',
			'location' => 'location',
		)
	);

	if ( '' === $entity_type || '' === $taxonomy || '' === $slug || ! isset( $entities[ $entity_type ] ) || $taxonomy !== $entities[ $entity_type ] ) {
		return new WP_Error( 'invalid_entity_subscription', __( 'A supported entity type, taxonomy, and slug are required.', 'extrachill-users' ), array( 'status' => 400 ) );
	}

	return array(
		'entity_type' => $entity_type,
		'taxonomy'    => $taxonomy,
		'slug'        => $slug,
	);
}

/**
 * Subscribe a user to a canonical entity.
 *
 * @param int   $user_id User ID.
 * @param string $entity_type Entity type.
 * @param string $taxonomy Taxonomy.
 * @param string $slug Entity slug.
 * @return array|WP_Error
 */
function extrachill_users_subscribe_to_entity( $user_id, $entity_type, $taxonomy, $slug ) {

	global $wpdb;

	$user_id = absint( $user_id );
	$entity  = extrachill_users_normalize_entity_subscription( $entity_type, $taxonomy, $slug );
	if ( is_wp_error( $entity ) ) {
		return $entity;
	}
	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		return new WP_Error( 'user_not_found', __( 'User not found.', 'extrachill-users' ), array( 'status' => 404 ) );
	}

	$table = extrachill_users_entity_subscriptions_table_name();
	$wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$table} (user_id, entity_type, taxonomy, entity_slug, created_at) VALUES (%d, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
			$user_id,
			$entity['entity_type'],
			$entity['taxonomy'],
			$entity['slug'],
			current_time( 'mysql', true )
		)
	);

	return array_merge( $entity, array( 'subscribed' => true ) );
}

/**
 * Unsubscribe a user from a canonical entity.
 *
 * @param int $user_id User ID.
 * @param string $entity_type Entity type.
 * @param string $taxonomy Taxonomy.
 * @param string $slug Entity slug.
 * @return array|WP_Error
 */
function extrachill_users_unsubscribe_from_entity( $user_id, $entity_type, $taxonomy, $slug ) {

	global $wpdb;

	$user_id = absint( $user_id );
	$entity  = extrachill_users_normalize_entity_subscription( $entity_type, $taxonomy, $slug );
	if ( is_wp_error( $entity ) ) {
		return $entity;
	}
	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		return new WP_Error( 'user_not_found', __( 'User not found.', 'extrachill-users' ), array( 'status' => 404 ) );
	}

	$table = extrachill_users_entity_subscriptions_table_name();
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE user_id = %d AND entity_type = %s AND taxonomy = %s AND entity_slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
			$user_id,
			$entity['entity_type'],
			$entity['taxonomy'],
			$entity['slug']
		)
	);

	return array_merge( $entity, array( 'subscribed' => false ) );
}

/**
 * Get one user's subscription status.
 *
 * @param int $user_id User ID.
 * @param string $entity_type Entity type.
 * @param string $taxonomy Taxonomy.
 * @param string $slug Entity slug.
 * @return array|WP_Error
 */
function extrachill_users_entity_subscription_status( $user_id, $entity_type, $taxonomy, $slug ) {

	global $wpdb;

	$user_id = absint( $user_id );
	$entity  = extrachill_users_normalize_entity_subscription( $entity_type, $taxonomy, $slug );
	if ( is_wp_error( $entity ) ) {
		return $entity;
	}

	$table      = extrachill_users_entity_subscriptions_table_name();
	$subscribed = $user_id && (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE user_id = %d AND entity_type = %s AND taxonomy = %s AND entity_slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
			$user_id,
			$entity['entity_type'],
			$entity['taxonomy'],
			$entity['slug']
		)
	);

	return array_merge( $entity, array( 'subscribed' => $subscribed ) );
}

/**
 * Resolve recipients for an authorized producer without exposing an audience.
 *
 * Producers must opt in through the authorization filter with their own stable
 * identifier. The result is intentionally only a de-duplicated ID list.
 *
 * @param string $producer Producer identifier.
 * @param string $entity_type Entity type.
 * @param string $taxonomy Taxonomy.
 * @param string $slug Entity slug.
 * @param string $delivery Delivery channel: notification or email.
 * @return int[]|WP_Error
 */
function extrachill_users_entity_subscription_recipients( $producer, $entity_type, $taxonomy, $slug, $delivery = 'notification' ) {

	global $wpdb;

	$producer = sanitize_key( $producer );
	$delivery = sanitize_key( $delivery );
	$entity   = extrachill_users_normalize_entity_subscription( $entity_type, $taxonomy, $slug );
	if ( is_wp_error( $entity ) ) {
		return $entity;
	}
	if ( '' === $producer || ! apply_filters( 'extrachill_users_entity_subscription_producer_authorized', false, $producer, $entity, $delivery ) ) {
		return new WP_Error( 'entity_subscription_producer_forbidden', __( 'This producer is not authorized to resolve entity subscription recipients.', 'extrachill-users' ), array( 'status' => 403 ) );
	}
	if ( ! in_array( $delivery, array( 'notification', 'email' ), true ) ) {
		return new WP_Error( 'invalid_entity_subscription_delivery', __( 'Unsupported notification delivery.', 'extrachill-users' ), array( 'status' => 400 ) );
	}

	$table = extrachill_users_entity_subscriptions_table_name();
	$ids   = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT user_id FROM {$table} WHERE entity_type = %s AND taxonomy = %s AND entity_slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
			$entity['entity_type'],
			$entity['taxonomy'],
			$entity['slug']
		)
	);
	$ids   = array_values( array_unique( array_map( 'absint', $ids ) ) );

	if ( 'email' === $delivery && function_exists( 'ec_users_notification_emails_enabled' ) ) {
		$ids = array_values( array_filter( $ids, 'ec_users_notification_emails_enabled' ) );
	}

	return $ids;
}
