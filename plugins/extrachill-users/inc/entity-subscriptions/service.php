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

	$entity_type       = sanitize_key( $entity_type );
	$taxonomy          = sanitize_key( $taxonomy );
	$slug              = sanitize_title( $slug );
	$entities          = apply_filters(
		'extrachill_users_entity_subscription_entities',
		array(
			'festival' => 'festival',
			'artist'   => 'artist',
			'venue'    => 'venue',
			'location' => 'location',
		)
	);
	$definition        = $entities[ $entity_type ] ?? null;
	$expected_taxonomy = is_array( $definition ) ? sanitize_key( $definition['taxonomy'] ?? '' ) : sanitize_key( $definition );

	if ( '' === $entity_type || '' === $taxonomy || '' === $slug || '' === $expected_taxonomy || $taxonomy !== $expected_taxonomy ) {
		return new WP_Error( 'invalid_entity_subscription', __( 'A supported entity type, taxonomy, and slug are required.', 'extrachill-users' ), array( 'status' => 400 ) );
	}

	return array(
		'entity_type' => $entity_type,
		'taxonomy'    => $taxonomy,
		'slug'        => $slug,
	);
}

/**
 * Determine whether an identity uses the account notification-email preference.
 *
 * Feature-owned purpose identities may opt out without the generic layer
 * knowing which product registered them.
 *
 * @param string $entity_type Entity type.
 * @return bool
 */
function extrachill_users_entity_subscription_uses_notification_email_preference( $entity_type ): bool {
	$entity_type = sanitize_key( $entity_type );
	$entities    = apply_filters(
		'extrachill_users_entity_subscription_entities',
		array(
			'festival' => 'festival',
			'artist'   => 'artist',
			'venue'    => 'venue',
			'location' => 'location',
		)
	);
	$definition  = $entities[ $entity_type ] ?? null;

	return ! is_array( $definition ) || ! array_key_exists( 'uses_notification_email_preference', $definition ) || (bool) $definition['uses_notification_email_preference'];
}

/**
 * Subscribe a user to a canonical entity.
 *
 * @param int    $user_id User ID.
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

	$table    = extrachill_users_entity_subscriptions_table_name();
	$inserted = $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$table} (user_id, entity_type, taxonomy, entity_slug, created_at) VALUES (%d, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
			$user_id,
			$entity['entity_type'],
			$entity['taxonomy'],
			$entity['slug'],
			current_time( 'mysql', true )
		)
	);
	if ( false === $inserted ) {
		return new WP_Error( 'entity_subscription_insert_failed', __( 'The subscription could not be saved.', 'extrachill-users' ), array( 'status' => 500 ) );
	}

	return array_merge( $entity, array( 'subscribed' => true ) );
}

/**
 * Unsubscribe a user from a canonical entity.
 *
 * @param int    $user_id User ID.
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

	$table   = extrachill_users_entity_subscriptions_table_name();
	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE user_id = %d AND entity_type = %s AND taxonomy = %s AND entity_slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
			$user_id,
			$entity['entity_type'],
			$entity['taxonomy'],
			$entity['slug']
		)
	);
	if ( false === $deleted ) {
		return new WP_Error( 'entity_subscription_delete_failed', __( 'The subscription could not be removed.', 'extrachill-users' ), array( 'status' => 500 ) );
	}

	return array_merge( $entity, array( 'subscribed' => false ) );
}

/**
 * Get one user's subscription status.
 *
 * @param int    $user_id User ID.
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
 * List one user's canonical entity subscription identities.
 *
 * @param int $user_id User ID.
 * @param int $page Page number.
 * @param int $per_page Results per page, capped at 100.
 * @return array|WP_Error
 */
function extrachill_users_list_entity_subscriptions( $user_id, $page = 1, $per_page = 50 ) {
	global $wpdb;

	$user_id  = absint( $user_id );
	$page     = max( 1, absint( $page ) );
	$per_page = max( 1, min( 100, absint( $per_page ) ) );
	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		return new WP_Error( 'user_not_found', __( 'User not found.', 'extrachill-users' ), array( 'status' => 404 ) );
	}

	$table  = extrachill_users_entity_subscriptions_table_name();
	$offset = ( $page - 1 ) * $per_page;
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT entity_type, taxonomy, entity_slug FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
			$user_id,
			$per_page,
			$offset
		),
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return new WP_Error( 'entity_subscriptions_read_failed', __( 'The subscriptions could not be loaded.', 'extrachill-users' ), array( 'status' => 500 ) );
	}
	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
			$user_id
		)
	);

	return array(
		'subscriptions' => array_map(
			static function ( array $row ): array {
				return array(
					'entity_type' => $row['entity_type'],
					'taxonomy'    => $row['taxonomy'],
					'slug'        => $row['entity_slug'],
				);
			},
			$rows
		),
		'page'          => $page,
		'per_page'      => $per_page,
		'total'         => $total,
		'total_pages'   => (int) ceil( $total / $per_page ),
	);
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

	if ( 'email' === $delivery && extrachill_users_entity_subscription_uses_notification_email_preference( $entity['entity_type'] ) && function_exists( 'ec_users_notification_emails_enabled' ) ) {
		$ids = array_values( array_filter( $ids, 'ec_users_notification_emails_enabled' ) );
	}

	return $ids;
}
