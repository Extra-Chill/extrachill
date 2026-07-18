<?php
/**
 * Artist Dispatch eligibility and audited access lifecycle.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

const EC_USERS_ARTIST_DISPATCH_POLICY_OPTION = 'extrachill_users_artist_dispatch_policy';
const EC_USERS_ARTIST_DISPATCH_STATE_META    = 'extrachill_artist_dispatch_access';
const EC_USERS_ARTIST_DISPATCH_AUDIT_META    = 'extrachill_artist_dispatch_access_event';
const EC_USERS_ARTIST_DISPATCH_TERMS_VERSION = '2026-07-18';
const EC_USERS_ARTIST_DISPATCH_LOCK_META     = 'extrachill_artist_dispatch_access_lock';
const EC_USERS_ARTIST_DISPATCH_DELIVERY_META = 'extrachill_artist_dispatch_delivery_receipt';
const EC_USERS_ARTIST_DISPATCH_LOCK_TTL      = 30;

/**
 * Return the disabled-by-default network policy.
 *
 * @return array<string,mixed>
 */
function ec_users_get_artist_dispatch_policy() {
	$defaults = array(
		'minimum_points'           => null,
		'minimum_account_age_days' => 0,
		'require_onboarding'       => true,
		'require_claimed_artist'   => true,
		'pilot_enabled'            => false,
	);
	$stored   = get_site_option( EC_USERS_ARTIST_DISPATCH_POLICY_OPTION, array() );
	$policy   = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );

	$policy['minimum_points']           = null === $policy['minimum_points'] || '' === $policy['minimum_points']
		? null
		: max( 0, (float) $policy['minimum_points'] );
	$policy['minimum_account_age_days'] = max( 0, (int) $policy['minimum_account_age_days'] );
	$policy['require_onboarding']       = (bool) $policy['require_onboarding'];
	$policy['require_claimed_artist']   = (bool) $policy['require_claimed_artist'];
	$policy['pilot_enabled']            = (bool) $policy['pilot_enabled'];
	unset( $policy['require_active_moderation'] );

	return $policy;
}

/**
 * Store operator-owned policy values.
 *
 * @param array<string,mixed> $input Policy values.
 * @return array<string,mixed>
 */
function ec_users_update_artist_dispatch_policy( array $input ) {
	$policy = ec_users_get_artist_dispatch_policy();

	if ( array_key_exists( 'minimum_points', $input ) ) {
		$policy['minimum_points'] = '' === $input['minimum_points'] || null === $input['minimum_points']
			? null
			: max( 0, (float) $input['minimum_points'] );
	}
	if ( array_key_exists( 'minimum_account_age_days', $input ) ) {
		$policy['minimum_account_age_days'] = max( 0, (int) $input['minimum_account_age_days'] );
	}
	foreach ( array( 'require_onboarding', 'require_claimed_artist', 'pilot_enabled' ) as $field ) {
		if ( array_key_exists( $field, $input ) ) {
			$policy[ $field ] = (bool) $input[ $field ];
		}
	}

	update_site_option( EC_USERS_ARTIST_DISPATCH_POLICY_OPTION, $policy );
	return $policy;
}

/**
 * Return published artist profiles canonically linked to a user.
 *
 * Ec_get_artists_for_user() is the network-safe read side of the Artist
 * Platform's bidirectional membership primitive. It validates IDs against
 * published artist_profile posts on the artist site.
 *
 * @param int $user_id User ID.
 * @return int[]
 */
function ec_users_get_artist_dispatch_artist_ids( $user_id ) {
	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return array();
	}

	return array_values( array_unique( array_map( 'absint', ec_get_artists_for_user( (int) $user_id, false ) ) ) );
}

/**
 * Return a server-resolved artist label for administration displays.
 *
 * @param int $artist_id Artist profile ID.
 * @return string
 */
function ec_users_get_artist_dispatch_artist_label( $artist_id ) {
	$artist_id      = absint( $artist_id );
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
	if ( ! $artist_id || ! $artist_blog_id ) {
		return '';
	}

	switch_to_blog( $artist_blog_id );
	try {
		return 'artist_profile' === get_post_type( $artist_id ) ? (string) get_the_title( $artist_id ) : '';
	} finally {
		restore_current_blog();
	}
}

/**
 * Evaluate every request criterion independently.
 *
 * @param int $user_id User ID.
 * @return array<string,mixed>
 */
function ec_users_get_artist_dispatch_eligibility( $user_id ) {
	$user_id = absint( $user_id );
	$user    = get_userdata( $user_id );
	$policy  = ec_users_get_artist_dispatch_policy();
	$reasons = array();

	$points       = $user && function_exists( 'extrachill_get_user_total_points' ) ? (float) extrachill_get_user_total_points( $user_id ) : 0.0;
	$onboarding   = $user && function_exists( 'ec_is_onboarding_complete' ) ? (bool) ec_is_onboarding_complete( $user_id ) : false;
	$claimed      = $user && '1' !== (string) get_user_meta( $user_id, 'ec_unclaimed', true );
	$moderation   = $user && function_exists( 'extrachill_users_get_moderation_status' )
		? extrachill_users_get_moderation_status( $user_id )
		: array(
			'active' => false,
			'state'  => 'unknown',
		);
	$artist_ids   = $user ? ec_users_get_artist_dispatch_artist_ids( $user_id ) : array();
	$registered   = $user ? strtotime( $user->user_registered . ' UTC' ) : false;
	$account_days = $registered ? max( 0, (int) floor( ( time() - $registered ) / DAY_IN_SECONDS ) ) : 0;

	$criteria = array(
		'policy_configured' => array(
			'passed' => null !== $policy['minimum_points'],
			'value'  => null !== $policy['minimum_points'],
		),
		'pilot_enabled'     => array(
			'passed' => $policy['pilot_enabled'],
			'value'  => $policy['pilot_enabled'],
		),
		'points'            => array(
			'passed'  => null !== $policy['minimum_points'] && $points >= $policy['minimum_points'],
			'value'   => $points,
			'minimum' => $policy['minimum_points'],
		),
		'onboarding'        => array(
			'passed'   => ! $policy['require_onboarding'] || $onboarding,
			'value'    => $onboarding,
			'required' => $policy['require_onboarding'],
		),
		'account_age'       => array(
			'passed'       => $account_days >= $policy['minimum_account_age_days'],
			'value_days'   => $account_days,
			'minimum_days' => $policy['minimum_account_age_days'],
		),
		'claimed_account'   => array(
			'passed' => (bool) $claimed,
			'value'  => (bool) $claimed,
		),
		'active_moderation' => array(
			'passed' => ! empty( $moderation['active'] ),
			'value'  => ! empty( $moderation['active'] ),
			'state'  => isset( $moderation['state'] ) ? (string) $moderation['state'] : 'unknown',
		),
		'claimed_artist'    => array(
			'passed'     => ! $policy['require_claimed_artist'] || ! empty( $artist_ids ),
			'value'      => ! empty( $artist_ids ),
			'required'   => $policy['require_claimed_artist'],
			'artist_ids' => $artist_ids,
		),
	);

	$messages = array(
		'policy_configured' => __( 'The points threshold has not been configured.', 'extrachill-users' ),
		'pilot_enabled'     => __( 'The Artist Dispatch pilot is disabled.', 'extrachill-users' ),
		'points'            => __( 'The minimum points threshold has not been met.', 'extrachill-users' ),
		'onboarding'        => __( 'Account onboarding is incomplete.', 'extrachill-users' ),
		'account_age'       => __( 'The account is too new for this pilot.', 'extrachill-users' ),
		'claimed_account'   => __( 'The account must be claimed before requesting access.', 'extrachill-users' ),
		'active_moderation' => __( 'Active moderation prevents Artist Dispatch access.', 'extrachill-users' ),
		'claimed_artist'    => __( 'A managed or claimed artist profile is required.', 'extrachill-users' ),
	);
	if ( ! $user ) {
		$reasons[] = __( 'A valid account is required.', 'extrachill-users' );
	}
	foreach ( $criteria as $key => $criterion ) {
		if ( empty( $criterion['passed'] ) ) {
			$reasons[] = $messages[ $key ];
		}
	}

	return array(
		'eligible' => $user && empty( $reasons ),
		'criteria' => $criteria,
		'reasons'  => array_values( array_unique( $reasons ) ),
		'policy'   => $policy,
	);
}

/**
 * Read the current network user-meta state.
 *
 * @param int $user_id User ID.
 * @return array<string,mixed>
 */
function ec_users_get_artist_dispatch_state( $user_id ) {
	$state = get_user_meta( absint( $user_id ), EC_USERS_ARTIST_DISPATCH_STATE_META, true );
	return is_array( $state ) ? $state : array();
}

/**
 * Compare-and-swap the current state record.
 *
 * @param int   $user_id User ID.
 * @param array $next    Replacement state.
 * @param array $current State read while holding the transition lock.
 * @return bool
 */
function ec_users_write_artist_dispatch_state( $user_id, array $next, array $current ) {
	if ( empty( $current ) ) {
		return false !== add_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_STATE_META, $next, true );
	}

	return false !== update_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_STATE_META, $next, $current );
}

/**
 * Acquire a bounded per-user transition lock.
 *
 * @param int    $user_id    User ID.
 * @param string $request_id Request UUID or operation name.
 * @return array|WP_Error Lock record or error.
 */
function ec_users_acquire_artist_dispatch_lock( $user_id, $request_id ) {
	$user_id = absint( $user_id );
	$key     = EC_USERS_ARTIST_DISPATCH_LOCK_META . '_' . $user_id;
	$blog_id                      = ec_users_get_artist_dispatch_blog_id();
	for ( $attempt = 0; 5 > $attempt; ++$attempt ) {
		$now  = time();
		$lock = array(
			'owner_token' => wp_generate_uuid4(),
			'request_id'  => sanitize_text_field( $request_id ),
			'expires_at'  => $now + EC_USERS_ARTIST_DISPATCH_LOCK_TTL,
		);
		switch_to_blog( $blog_id );
		$acquired = ec_users_add_artist_dispatch_option_once( $key, $lock );
		restore_current_blog();
		if ( $acquired ) {
			return $lock;
		}

		switch_to_blog( $blog_id );
		$current = get_option( $key, array() );
		restore_current_blog();
		if ( is_array( $current ) && $now >= (int) ( $current['expires_at'] ?? 0 ) ) {
			return new WP_Error( 'artist_dispatch_lock_reconciliation_required', __( 'A prior Artist Dispatch update exceeded its lock window and requires reconciliation.', 'extrachill-users' ), array( 'status' => 409 ) );
		}
		usleep( 50000 );
	}

	return new WP_Error( 'artist_dispatch_locked', __( 'Another Artist Dispatch access update is in progress. Please retry.', 'extrachill-users' ), array( 'status' => 409 ) );
}

/**
 * Release a lock only when this caller still owns it.
 *
 * @param int   $user_id User ID.
 * @param array $lock    Owned lock record.
 * @return bool
 */
function ec_users_release_artist_dispatch_lock( $user_id, array $lock ) {
	$blog_id = ec_users_get_artist_dispatch_blog_id();
	$key     = EC_USERS_ARTIST_DISPATCH_LOCK_META . '_' . absint( $user_id );
	switch_to_blog( $blog_id );
	try {
		return ec_users_delete_artist_dispatch_option_if_value( $key, $lock );
	} finally {
		restore_current_blog();
	}
}

/**
 * Atomically delete an option only when its serialized value still matches.
 *
 * @param string $key      Option name.
 * @param array  $expected Expected current value.
 * @return bool
 */
function ec_users_delete_artist_dispatch_option_if_value( $key, array $expected ) {
	global $wpdb;

	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core-owned options table name.
			$key,
			maybe_serialize( $expected )
		)
	);
	if ( $deleted ) {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
	return 1 === $deleted;
}

/**
 * Atomically reserve a unique option key without core's overwrite-on-conflict upsert.
 *
 * @param string $key   Option name.
 * @param array  $value Option value.
 * @return bool
 */
function ec_users_add_artist_dispatch_option_once( $key, array $value ) {
	global $wpdb;

	$inserted = $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core-owned options table name; the unique option key is the lock primitive.
			$key,
			maybe_serialize( $value )
		)
	);
	if ( 1 === $inserted ) {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return true;
	}
	return false;
}

/**
 * Return a self-safe view without application copy or decision notes.
 *
 * @param int $user_id User ID.
 * @return array<string,mixed>
 */
function ec_users_get_artist_dispatch_safe_state( $user_id ) {
	$state         = ec_users_get_artist_dispatch_state( $user_id );
	$terms_current = ! empty( $state['terms_acknowledged'] ) && EC_USERS_ARTIST_DISPATCH_TERMS_VERSION === ( $state['terms_version'] ?? '' );
	$safe          = array(
		'status'                 => isset( $state['status'] ) ? (string) $state['status'] : 'none',
		'request_id'             => isset( $state['request_id'] ) ? (string) $state['request_id'] : '',
		'requested_at'           => isset( $state['requested_at'] ) ? (int) $state['requested_at'] : 0,
		'artist_id'              => isset( $state['artist_id'] ) ? (int) $state['artist_id'] : 0,
		'terms_acknowledged'     => $terms_current,
		'terms_version'          => $terms_current ? EC_USERS_ARTIST_DISPATCH_TERMS_VERSION : '',
		'terms_renewal_required' => 'approved' === ( $state['status'] ?? '' ) && ! $terms_current,
		'decided_at'             => isset( $state['decision']['decided_at'] ) ? (int) $state['decision']['decided_at'] : 0,
		'revoked_at'             => isset( $state['revocation']['revoked_at'] ) ? (int) $state['revocation']['revoked_at'] : 0,
		'eligibility'            => ec_users_get_artist_dispatch_eligibility( $user_id ),
	);
	if ( $safe['artist_id'] ) {
		$safe['artist_label'] = ec_users_get_artist_dispatch_artist_label( $safe['artist_id'] );
	}

	return $safe;
}

/**
 * Validate a canonical lowercase UUID v4.
 *
 * @param string $request_id Candidate request ID.
 * @return bool
 */
function ec_users_is_artist_dispatch_request_id( $request_id ) {
	return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', (string) $request_id );
}

/**
 * Return the exact analytics payload allowed by the owner contract.
 *
 * @param int    $user_id    Subject user ID.
 * @param string $request_id Optional canonical request UUID.
 * @param string $cohort     Optional fixed-enum cohort.
 * @return array<string,mixed>
 */
function ec_users_get_artist_dispatch_event_payload( $user_id, $request_id = '', $cohort = '' ) {
	$user_id = absint( $user_id );
	if ( 0 >= $user_id ) {
		return array();
	}

	$payload = array( 'user_id' => $user_id );
	if ( ec_users_is_artist_dispatch_request_id( $request_id ) ) {
		$payload['request_id'] = $request_id;
	}
	if ( in_array( $cohort, array( 'eligible', 'moderated' ), true ) ) {
		$payload['eligibility_cohort'] = $cohort;
	}
	return $payload;
}

/**
 * Resolve a lifecycle event constant without local string fallbacks.
 *
 * @param string $event_type Lifecycle transition.
 * @return string
 */
function ec_users_get_artist_dispatch_analytics_event( $event_type ) {
	$constants = array(
		'requested' => 'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REQUESTED',
		'approved'  => 'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_APPROVED',
		'rejected'  => 'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REJECTED',
		'revoked'   => 'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REVOKED',
	);
	$constant  = $constants[ $event_type ] ?? '';
	return $constant && defined( $constant ) ? (string) constant( $constant ) : '';
}

/**
 * Test whether an external side effect already has a durable receipt.
 *
 * @param int    $user_id    User ID.
 * @param string $channel    Delivery channel.
 * @param string $event_type Lifecycle transition.
 * @param string $request_id Request UUID.
 * @return bool
 */
function ec_users_has_artist_dispatch_delivery_receipt( $user_id, $channel, $event_type, $request_id ) {
	return ! empty( ec_users_get_artist_dispatch_delivery_receipt( $user_id, $channel, $event_type, $request_id ) );
}

/**
 * Build the unique user-meta key for one external delivery.
 *
 * @param string $channel    Delivery channel.
 * @param string $event_type Lifecycle transition.
 * @param string $request_id Request UUID.
 * @return string
 */
function ec_users_get_artist_dispatch_delivery_meta_key( $user_id, $channel, $event_type, $request_id ) {
	return EC_USERS_ARTIST_DISPATCH_DELIVERY_META . '_' . md5( absint( $user_id ) . '|' . sanitize_key( $channel ) . '|' . sanitize_key( $event_type ) . '|' . sanitize_text_field( $request_id ) );
}

/**
 * Get a durable external-delivery receipt.
 *
 * @param int    $user_id    User ID.
 * @param string $channel    Delivery channel.
 * @param string $event_type Lifecycle transition.
 * @param string $request_id Request UUID.
 * @return array
 */
function ec_users_get_artist_dispatch_delivery_receipt( $user_id, $channel, $event_type, $request_id ) {
	$blog_id = ec_users_get_artist_dispatch_blog_id();
	switch_to_blog( $blog_id );
	try {
		$receipt = get_option( ec_users_get_artist_dispatch_delivery_meta_key( $user_id, $channel, $event_type, $request_id ), array() );
	} finally {
		restore_current_blog();
	}
	return is_array( $receipt ) ? $receipt : array();
}

/**
 * Append a durable external-delivery receipt once while under lock.
 *
 * @param int    $user_id    User ID.
 * @param string $channel    Delivery channel.
 * @param string $event_type Lifecycle transition.
 * @param string $request_id Request UUID.
 * @return bool
 */
function ec_users_add_artist_dispatch_delivery_receipt( $user_id, $channel, $event_type, $request_id ) {
	$blog_id = ec_users_get_artist_dispatch_blog_id();
	switch_to_blog( $blog_id );
	try {
		return ec_users_add_artist_dispatch_option_once(
			ec_users_get_artist_dispatch_delivery_meta_key( $user_id, $channel, $event_type, $request_id ),
			array(
				'channel'     => sanitize_key( $channel ),
				'event'       => sanitize_key( $event_type ),
				'request_id'  => sanitize_text_field( $request_id ),
				'status'      => 'reserved',
				'reserved_at' => time(),
			)
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Mark a reserved receipt delivered after the external side effect succeeds.
 *
 * @param int    $user_id    User ID.
 * @param string $channel    Delivery channel.
 * @param string $event_type Lifecycle transition.
 * @param string $request_id Request UUID.
 * @return bool
 */
function ec_users_mark_artist_dispatch_delivery_receipt( $user_id, $channel, $event_type, $request_id ) {
	$receipt = ec_users_get_artist_dispatch_delivery_receipt( $user_id, $channel, $event_type, $request_id );
	if ( empty( $receipt ) ) {
		return false;
	}
	if ( 'delivered' === ( $receipt['status'] ?? '' ) ) {
		return true;
	}
	$delivered                 = $receipt;
	$delivered['status']       = 'delivered';
	$delivered['delivered_at'] = time();
	$blog_id = ec_users_get_artist_dispatch_blog_id();
	switch_to_blog( $blog_id );
	try {
		return update_option( ec_users_get_artist_dispatch_delivery_meta_key( $user_id, $channel, $event_type, $request_id ), $delivered, false );
	} finally {
		restore_current_blog();
	}
}

/**
 * Remove a reserved delivery receipt after an explicit delivery failure.
 *
 * @param int    $user_id    User ID.
 * @param string $channel    Delivery channel.
 * @param string $event_type Lifecycle transition.
 * @param string $request_id Request UUID.
 * @return bool
 */
function ec_users_remove_artist_dispatch_delivery_receipt( $user_id, $channel, $event_type, $request_id ) {
	$receipt = ec_users_get_artist_dispatch_delivery_receipt( $user_id, $channel, $event_type, $request_id );
	if ( empty( $receipt ) ) {
		return true;
	}
	$blog_id = ec_users_get_artist_dispatch_blog_id();
	switch_to_blog( $blog_id );
	try {
		return ec_users_delete_artist_dispatch_option_if_value( ec_users_get_artist_dispatch_delivery_meta_key( $user_id, $channel, $event_type, $request_id ), $receipt );
	} finally {
		restore_current_blog();
	}
}

/**
 * Emit one owner event and persist its delivery marker after success.
 *
 * Must run while holding the user's transition lock.
 *
 * @param int    $user_id    User ID.
 * @param string $event_type Lifecycle transition.
 * @param array  $state      Current state under lock.
 * @return array|WP_Error Updated state or error.
 */
function ec_users_maybe_emit_artist_dispatch_event( $user_id, $event_type, array $state ) {
	if ( ! empty( $state['deliveries']['analytics'][ $event_type ] ) ) {
		return $state;
	}
	$request_id = (string) ( $state['request_id'] ?? '' );
	$receipt    = ec_users_get_artist_dispatch_delivery_receipt( $user_id, 'analytics', $event_type, $request_id );
	if ( ! empty( $receipt ) && 'delivered' !== ( $receipt['status'] ?? '' ) ) {
		return new WP_Error( 'artist_dispatch_analytics_reconciliation_required', __( 'The prior analytics delivery outcome is ambiguous and requires reconciliation.', 'extrachill-users' ) );
	}
	if ( ! empty( $receipt ) ) {
		$next = $state;
		$next['deliveries']['analytics'][ $event_type ] = time();
		return ec_users_write_artist_dispatch_state( $user_id, $next, $state )
			? $next
			: new WP_Error( 'artist_dispatch_analytics_marker_failed', __( 'The Artist Dispatch analytics delivery marker could not be repaired.', 'extrachill-users' ) );
	}
	$event_name = ec_users_get_artist_dispatch_analytics_event( $event_type );
	if ( ! $event_name || ! function_exists( 'ec_users_emit_team_experience_event' ) ) {
		return $state;
	}
	if ( ! ec_users_add_artist_dispatch_delivery_receipt( $user_id, 'analytics', $event_type, $request_id ) ) {
		return new WP_Error( 'artist_dispatch_analytics_receipt_failed', __( 'The Artist Dispatch analytics delivery receipt could not be reserved.', 'extrachill-users' ) );
	}

	$payload = ec_users_get_artist_dispatch_event_payload( $user_id, $request_id );
	unset( $payload['user_id'] );
	if ( 0 >= ec_users_emit_team_experience_event( $event_name, $user_id, $payload ) ) {
		if ( ! ec_users_remove_artist_dispatch_delivery_receipt( $user_id, 'analytics', $event_type, $request_id ) ) {
			return new WP_Error( 'artist_dispatch_analytics_reconciliation_required', __( 'Analytics delivery failed and its reservation could not be cleared.', 'extrachill-users' ) );
		}
		return new WP_Error( 'artist_dispatch_analytics_failed', __( 'The Artist Dispatch analytics event could not be recorded.', 'extrachill-users' ) );
	}
	if ( ! ec_users_mark_artist_dispatch_delivery_receipt( $user_id, 'analytics', $event_type, $request_id ) ) {
		return new WP_Error( 'artist_dispatch_analytics_reconciliation_required', __( 'Analytics delivery succeeded but its durable receipt could not be finalized.', 'extrachill-users' ) );
	}

	$next = $state;
	$next['deliveries']['analytics'][ $event_type ] = time();
	if ( ! ec_users_write_artist_dispatch_state( $user_id, $next, $state ) ) {
		return new WP_Error( 'artist_dispatch_analytics_marker_failed', __( 'The Artist Dispatch analytics delivery marker could not be saved.', 'extrachill-users' ) );
	}
	return $next;
}

/**
 * Append an event once for a request UUID and transition.
 *
 * @param int    $user_id    User ID.
 * @param string $event_type Event type.
 * @param string $request_id Request UUID.
 * @param int    $actor_id   Actor user ID.
 * @param array  $details    Non-sensitive event details.
 * @return bool
 */
function ec_users_add_artist_dispatch_audit_event( $user_id, $event_type, $request_id, $actor_id, array $details = array() ) {
	foreach ( get_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_AUDIT_META, false ) as $event ) {
		if ( is_array( $event ) && ( $event['event'] ?? '' ) === $event_type && ( $event['request_id'] ?? '' ) === $request_id ) {
			return true;
		}
	}

	return false !== add_user_meta(
		$user_id,
		EC_USERS_ARTIST_DISPATCH_AUDIT_META,
		array(
			'schema_version' => 1,
			'event'          => sanitize_key( $event_type ),
			'request_id'     => sanitize_text_field( $request_id ),
			'occurred_at'    => time(),
			'actor_id'       => absint( $actor_id ),
			'details'        => $details,
		),
		false
	);
}

/**
 * Resolve the notification actor, including actorless WP-CLI transitions.
 *
 * @param int $actor_id Requested actor.
 * @return int
 */
function ec_users_resolve_artist_dispatch_notification_actor( $actor_id ) {
	$actor_id = absint( $actor_id );
	if ( $actor_id && get_userdata( $actor_id ) ) {
		return $actor_id;
	}
	$bot_id = function_exists( 'ec_get_network_bot_user_id' ) ? absint( ec_get_network_bot_user_id() ) : 0;
	return $bot_id && get_userdata( $bot_id ) ? $bot_id : 0;
}

/**
 * Send one transition notification and mark only successful delivery.
 *
 * Must run while holding the user's transition lock.
 *
 * @param int    $user_id    Recipient.
 * @param string $event_type Transition.
 * @param int    $actor_id   Actor.
 * @param array  $state      Current state under lock.
 * @return array|WP_Error Updated state or error.
 */
function ec_users_maybe_notify_artist_dispatch_transition( $user_id, $event_type, $actor_id, array $state ) {
	if ( ! empty( $state['deliveries']['notifications'][ $event_type ] ) ) {
		return $state;
	}

	$titles = array(
		'approved' => __( 'Your Artist Dispatch access was approved.', 'extrachill-users' ),
		'rejected' => __( 'Your Artist Dispatch access request was not approved.', 'extrachill-users' ),
		'revoked'  => __( 'Your Artist Dispatch access was revoked.', 'extrachill-users' ),
	);
	if ( ! isset( $titles[ $event_type ] ) ) {
		return $state;
	}
	$request_id = (string) ( $state['request_id'] ?? '' );
	$receipt    = ec_users_get_artist_dispatch_delivery_receipt( $user_id, 'notification', $event_type, $request_id );
	if ( ! empty( $receipt ) && 'delivered' !== ( $receipt['status'] ?? '' ) ) {
		return new WP_Error( 'artist_dispatch_notification_reconciliation_required', __( 'The prior notification delivery outcome is ambiguous and requires reconciliation.', 'extrachill-users' ) );
	}
	if ( ! empty( $receipt ) ) {
		$next = $state;
		$next['deliveries']['notifications'][ $event_type ] = time();
		return ec_users_write_artist_dispatch_state( $user_id, $next, $state )
			? $next
			: new WP_Error( 'artist_dispatch_notification_marker_failed', __( 'The Artist Dispatch notification delivery marker could not be repaired.', 'extrachill-users' ) );
	}
	$notification_actor = ec_users_resolve_artist_dispatch_notification_actor( $actor_id );
	if ( ! $notification_actor || ! function_exists( 'ec_users_notify' ) ) {
		return new WP_Error( 'artist_dispatch_notification_actor_missing', __( 'A valid Artist Dispatch notification actor is unavailable.', 'extrachill-users' ) );
	}

	$blog_id = ec_users_get_artist_dispatch_blog_id();
	switch_to_blog( $blog_id );
	try {
		$link = home_url( '/submit/' );
	} finally {
		restore_current_blog();
	}
	if ( ! ec_users_add_artist_dispatch_delivery_receipt( $user_id, 'notification', $event_type, $request_id ) ) {
		return new WP_Error( 'artist_dispatch_notification_receipt_failed', __( 'The Artist Dispatch notification delivery receipt could not be reserved.', 'extrachill-users' ) );
	}

	$inserted = ec_users_notify(
		$user_id,
		array(
			'actor_id' => $notification_actor,
			'type'     => 'artist_dispatch_' . $event_type,
			'link'     => $link,
			'title'    => $titles[ $event_type ],
			'item_id'  => isset( $state['artist_id'] ) ? absint( $state['artist_id'] ) : 0,
		)
	);
	if ( 1 > $inserted ) {
		if ( ! ec_users_remove_artist_dispatch_delivery_receipt( $user_id, 'notification', $event_type, $request_id ) ) {
			return new WP_Error( 'artist_dispatch_notification_reconciliation_required', __( 'Notification delivery failed and its reservation could not be cleared.', 'extrachill-users' ) );
		}
		return new WP_Error( 'artist_dispatch_notification_failed', __( 'The Artist Dispatch notification could not be delivered.', 'extrachill-users' ) );
	}
	if ( ! ec_users_mark_artist_dispatch_delivery_receipt( $user_id, 'notification', $event_type, $request_id ) ) {
		return new WP_Error( 'artist_dispatch_notification_reconciliation_required', __( 'Notification delivery succeeded but its durable receipt could not be finalized.', 'extrachill-users' ) );
	}

	$next = $state;
	$next['deliveries']['notifications'][ $event_type ] = time();
	if ( ! ec_users_write_artist_dispatch_state( $user_id, $next, $state ) ) {
		return new WP_Error( 'artist_dispatch_notification_marker_failed', __( 'The Artist Dispatch notification delivery marker could not be saved.', 'extrachill-users' ) );
	}
	return $next;
}

/**
 * Repair/finalize append-only and delivered transition side effects.
 *
 * Must run while holding the user's transition lock.
 *
 * @param int    $user_id    User ID.
 * @param string $event_type Lifecycle transition.
 * @param int    $actor_id   Actor user ID.
 * @param array  $state      Current state under lock.
 * @param array  $details    Non-sensitive audit details.
 * @return array|WP_Error Updated state or error.
 */
function ec_users_finalize_artist_dispatch_transition( $user_id, $event_type, $actor_id, array $state, array $details = array() ) {
	if ( ! ec_users_add_artist_dispatch_audit_event( $user_id, $event_type, (string) $state['request_id'], $actor_id, $details ) ) {
		return new WP_Error( 'artist_dispatch_audit_failed', __( 'The Artist Dispatch audit event could not be saved.', 'extrachill-users' ) );
	}
	$state = ec_users_maybe_emit_artist_dispatch_event( $user_id, $event_type, $state );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	if ( in_array( $event_type, array( 'approved', 'rejected', 'revoked' ), true ) ) {
		$state = ec_users_maybe_notify_artist_dispatch_transition( $user_id, $event_type, $actor_id, $state );
	}
	return $state;
}

/**
 * Add the bounded role on the main site without replacing existing roles.
 *
 * @param int $user_id User ID.
 * @return array|WP_Error Grant details.
 */
function ec_users_grant_artist_dispatch_role( $user_id ) {
	$user_id = absint( $user_id );
	$blog_id = ec_users_get_artist_dispatch_blog_id();
	if ( ! $user_id || ! get_userdata( $user_id ) || ! $blog_id ) {
		return new WP_Error( 'invalid_artist_dispatch_user', __( 'A valid user and main site are required.', 'extrachill-users' ) );
	}

	$membership_preexisted = is_user_member_of_blog( $user_id, $blog_id );
	ec_users_register_artist_dispatch_role_on_main();
	$role_preexisted = false;
	if ( $membership_preexisted ) {
		switch_to_blog( $blog_id );
		try {
			$role_preexisted = in_array( EC_USERS_ARTIST_DISPATCH_ROLE, (array) ( new WP_User( $user_id ) )->roles, true );
		} finally {
			restore_current_blog();
		}
	}
	if ( ! $membership_preexisted ) {
		$result = add_user_to_blog( $blog_id, $user_id, EC_USERS_ARTIST_DISPATCH_ROLE );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	switch_to_blog( $blog_id );
	try {
		ec_users_register_artist_dispatch_role();
		$user = new WP_User( $user_id );
		if ( ! in_array( EC_USERS_ARTIST_DISPATCH_ROLE, (array) $user->roles, true ) ) {
			$user->add_role( EC_USERS_ARTIST_DISPATCH_ROLE );
		}
		$granted = in_array( EC_USERS_ARTIST_DISPATCH_ROLE, (array) ( new WP_User( $user_id ) )->roles, true );
	} finally {
		restore_current_blog();
	}

	if ( ! $granted ) {
		if ( ! $membership_preexisted ) {
			remove_user_from_blog( $user_id, $blog_id );
		}
		return new WP_Error( 'artist_dispatch_grant_failed', __( 'The Artist Dispatch role could not be granted.', 'extrachill-users' ) );
	}

	return array(
		'blog_id'               => $blog_id,
		'role'                  => EC_USERS_ARTIST_DISPATCH_ROLE,
		'membership_preexisted' => $membership_preexisted,
		'role_preexisted'       => $role_preexisted,
	);
}

/**
 * Remove only the product role and clean up grant-created empty membership.
 *
 * @param int  $user_id               User ID.
 * @param bool $membership_preexisted Whether membership existed before approval.
 * @return array<string,mixed>|WP_Error
 */
function ec_users_revoke_artist_dispatch_role( $user_id, $membership_preexisted ) {
	$user_id = absint( $user_id );
	$blog_id = ec_users_get_artist_dispatch_blog_id();
	$removed = false;
	$cleaned = false;

	if ( ! $user_id || ! $blog_id || ! is_user_member_of_blog( $user_id, $blog_id ) ) {
		return array(
			'role_removed'       => false,
			'membership_removed' => false,
		);
	}

	switch_to_blog( $blog_id );
	try {
		$user = new WP_User( $user_id );
		if ( in_array( EC_USERS_ARTIST_DISPATCH_ROLE, (array) $user->roles, true ) ) {
			$user->remove_role( EC_USERS_ARTIST_DISPATCH_ROLE );
			$removed = true;
		}
		$user             = new WP_User( $user_id );
		$remaining_access = ! empty( $user->roles ) || ! empty( array_filter( (array) $user->caps ) );
	} finally {
		restore_current_blog();
	}

	if ( ! $membership_preexisted && ! $remaining_access && is_user_member_of_blog( $user_id, $blog_id ) ) {
		$result = remove_user_from_blog( $user_id, $blog_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$cleaned = true === $result;
	}
	if ( is_user_member_of_blog( $user_id, $blog_id ) ) {
		switch_to_blog( $blog_id );
		try {
			if ( in_array( EC_USERS_ARTIST_DISPATCH_ROLE, (array) ( new WP_User( $user_id ) )->roles, true ) ) {
				return new WP_Error( 'artist_dispatch_role_revoke_failed', __( 'The Artist Dispatch role could not be removed.', 'extrachill-users' ) );
			}
		} finally {
			restore_current_blog();
		}
	}

	return array(
		'role_removed'       => $removed,
		'membership_removed' => $cleaned,
	);
}

/**
 * Create or return a pending self-service request.
 *
 * @param int   $user_id User ID.
 * @param array $input   Application fields.
 * @return array|WP_Error
 */
function ec_users_request_artist_dispatch_access( $user_id, array $input ) {
	$user_id       = absint( $user_id );
	$terms_version = isset( $input['terms_version'] ) ? sanitize_text_field( (string) $input['terms_version'] ) : '';
	if ( true !== ( $input['acknowledgement'] ?? false ) ) {
		return new WP_Error( 'artist_dispatch_acknowledgement_required', __( 'You must acknowledge the Artist Dispatch terms and your affiliation disclosure.', 'extrachill-users' ), array( 'status' => 400 ) );
	}
	if ( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION !== $terms_version ) {
		return new WP_Error( 'invalid_artist_dispatch_terms_version', __( 'The Artist Dispatch terms version is missing or no longer current.', 'extrachill-users' ), array( 'status' => 400 ) );
	}

	$lock = ec_users_acquire_artist_dispatch_lock( $user_id, 'request' );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$current = ec_users_get_artist_dispatch_state( $user_id );
		$status  = $current['status'] ?? '';
		if ( 'approved' === $status ) {
			if ( ! empty( $current['terms_acknowledged'] ) && ( $current['terms_version'] ?? '' ) === $terms_version ) {
				return new WP_Error( 'artist_dispatch_already_approved', __( 'Artist Dispatch access is already approved with current terms.', 'extrachill-users' ), array( 'status' => 409 ) );
			}
			$moderation = extrachill_users_get_moderation_status( $user_id );
			if ( empty( $moderation['active'] ) ) {
				return new WP_Error( 'artist_dispatch_moderated', __( 'Active moderation prevents Artist Dispatch terms renewal.', 'extrachill-users' ), array( 'status' => 403 ) );
			}
			$renewed                        = $current;
			$renewed['previous_request_id'] = $current['request_id'] ?? '';
			$renewed['request_id']          = wp_generate_uuid4();
			$renewed['terms_acknowledged']  = true;
			$renewed['terms_version']       = $terms_version;
			$renewed['terms_accepted_at']   = time();
			if ( ! ec_users_write_artist_dispatch_state( $user_id, $renewed, $current ) ) {
				return new WP_Error( 'artist_dispatch_state_write_failed', __( 'The Artist Dispatch terms renewal could not be saved.', 'extrachill-users' ) );
			}
			if ( ! ec_users_add_artist_dispatch_audit_event( $user_id, 'terms_renewed', $renewed['request_id'], $user_id, array( 'terms_version' => $terms_version ) ) ) {
				if ( ! ec_users_write_artist_dispatch_state( $user_id, $current, $renewed ) ) {
					return new WP_Error( 'artist_dispatch_rollback_failed', __( 'The terms renewal audit and state rollback both failed.', 'extrachill-users' ) );
				}
				return new WP_Error( 'artist_dispatch_audit_failed', __( 'The Artist Dispatch terms renewal audit could not be saved.', 'extrachill-users' ) );
			}
			return $renewed;
		}
		if ( 'pending' === $status ) {
			if ( empty( $current['terms_acknowledged'] ) || ( $current['terms_version'] ?? '' ) !== $terms_version ) {
				return new WP_Error( 'artist_dispatch_stale_terms', __( 'The pending request accepted an outdated terms version and cannot be changed in place.', 'extrachill-users' ), array( 'status' => 409 ) );
			}
			return ec_users_finalize_artist_dispatch_transition( $user_id, 'requested', $user_id, $current );
		}

		$eligibility = ec_users_get_artist_dispatch_eligibility( $user_id );
		if ( empty( $eligibility['eligible'] ) ) {
			return new WP_Error(
				'artist_dispatch_ineligible',
				implode( ' ', $eligibility['reasons'] ),
				array(
					'status'      => 403,
					'eligibility' => $eligibility,
				)
			);
		}
		$artist_id = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;
		if ( ! $artist_id || ! in_array( $artist_id, $eligibility['criteria']['claimed_artist']['artist_ids'], true ) ) {
			return new WP_Error( 'invalid_artist_dispatch_artist', __( 'Select an artist profile you canonically manage or claim.', 'extrachill-users' ), array( 'status' => 403 ) );
		}
		$description = isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '';
		$length      = function_exists( 'mb_strlen' ) ? mb_strlen( $description ) : strlen( $description );
		if ( 50 > $length || 2000 < $length ) {
			return new WP_Error( 'invalid_artist_dispatch_description', __( 'The proposed Dispatch description must be between 50 and 2,000 characters.', 'extrachill-users' ), array( 'status' => 400 ) );
		}
		$sample_url = isset( $input['sample_url'] ) ? esc_url_raw( (string) $input['sample_url'] ) : '';
		if ( '' !== $sample_url && ! wp_http_validate_url( $sample_url ) ) {
			return new WP_Error( 'invalid_artist_dispatch_sample_url', __( 'The sample URL must be a valid HTTP or HTTPS URL.', 'extrachill-users' ), array( 'status' => 400 ) );
		}

		$state = array(
			'schema_version'       => 1,
			'status'               => 'pending',
			'request_id'           => wp_generate_uuid4(),
			'requested_at'         => time(),
			'artist_id'            => $artist_id,
			'terms_acknowledged'   => true,
			'terms_version'        => $terms_version,
			'terms_accepted_at'    => time(),
			'description'          => $description,
			'sample_url'           => $sample_url,
			'eligibility_snapshot' => $eligibility,
		);
		if ( ! ec_users_write_artist_dispatch_state( $user_id, $state, $current ) ) {
			return new WP_Error( 'artist_dispatch_state_write_failed', __( 'The Artist Dispatch request could not be saved.', 'extrachill-users' ) );
		}
		return ec_users_finalize_artist_dispatch_transition( $user_id, 'requested', $user_id, $state, array( 'terms_version' => $terms_version ) );
	} finally {
		ec_users_release_artist_dispatch_lock( $user_id, $lock );
	}
}

/**
 * Approve a matching pending request or repair its missing role on retry.
 *
 * @param int    $user_id    User ID.
 * @param string $request_id Request UUID.
 * @param string $note       Internal note.
 * @param int    $actor_id   Decision actor.
 * @return array|WP_Error
 */
function ec_users_approve_artist_dispatch_access( $user_id, $request_id, $note, $actor_id ) {
	$user_id    = absint( $user_id );
	$request_id = sanitize_text_field( $request_id );
	$lock       = ec_users_acquire_artist_dispatch_lock( $user_id, $request_id );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$current = ec_users_get_artist_dispatch_state( $user_id );
		if ( ( $current['request_id'] ?? '' ) !== $request_id ) {
			return new WP_Error( 'artist_dispatch_request_mismatch', __( 'The request ID does not match current state.', 'extrachill-users' ), array( 'status' => 409 ) );
		}
		if ( 'approved' === ( $current['status'] ?? '' ) ) {
			$repair = ec_users_grant_artist_dispatch_role( $user_id );
			return is_wp_error( $repair ) ? $repair : ec_users_finalize_artist_dispatch_transition( $user_id, 'approved', $actor_id, $current );
		}
		if ( 'pending' !== ( $current['status'] ?? '' ) ) {
			return new WP_Error( 'artist_dispatch_not_pending', __( 'Only pending requests can be approved.', 'extrachill-users' ), array( 'status' => 409 ) );
		}
		if ( empty( $current['terms_acknowledged'] ) || EC_USERS_ARTIST_DISPATCH_TERMS_VERSION !== ( $current['terms_version'] ?? '' ) ) {
			return new WP_Error( 'artist_dispatch_stale_terms', __( 'The pending request must accept the current Artist Dispatch terms before approval.', 'extrachill-users' ), array( 'status' => 409 ) );
		}
		$moderation = extrachill_users_get_moderation_status( $user_id );
		if ( empty( $moderation['active'] ) ) {
			return new WP_Error( 'artist_dispatch_moderated', __( 'Active moderation prevents Artist Dispatch approval.', 'extrachill-users' ), array( 'status' => 403 ) );
		}
		$eligibility = ec_users_get_artist_dispatch_eligibility( $user_id );
		if ( empty( $eligibility['eligible'] ) || ! in_array( (int) $current['artist_id'], $eligibility['criteria']['claimed_artist']['artist_ids'], true ) ) {
			return new WP_Error(
				'artist_dispatch_no_longer_eligible',
				__( 'The request no longer satisfies the Artist Dispatch policy.', 'extrachill-users' ),
				array(
					'status'      => 403,
					'eligibility' => $eligibility,
				)
			);
		}

		$grant = ec_users_grant_artist_dispatch_role( $user_id );
		if ( is_wp_error( $grant ) ) {
			return $grant;
		}
		$now                  = time();
		$approved             = $current;
		$approved['status']   = 'approved';
		$approved['decision'] = array(
			'decided_at' => $now,
			'actor_id'   => absint( $actor_id ),
			'note'       => sanitize_textarea_field( $note ),
		);
		$approved['grant']    = array_merge(
			$grant,
			array(
				'granted_at' => $now,
				'actor_id'   => absint( $actor_id ),
			)
		);
		if ( ! ec_users_write_artist_dispatch_state( $user_id, $approved, $current ) ) {
			if ( empty( $grant['role_preexisted'] ) ) {
				$rollback = ec_users_revoke_artist_dispatch_role( $user_id, ! empty( $grant['membership_preexisted'] ) );
				if ( is_wp_error( $rollback ) ) {
					return new WP_Error( 'artist_dispatch_rollback_failed', __( 'The approval state failed and the granted role could not be rolled back.', 'extrachill-users' ) );
				}
			}
			return new WP_Error( 'artist_dispatch_state_write_failed', __( 'Artist Dispatch approval could not be saved; the role grant was rolled back.', 'extrachill-users' ) );
		}
		return ec_users_finalize_artist_dispatch_transition( $user_id, 'approved', $actor_id, $approved );
	} finally {
		ec_users_release_artist_dispatch_lock( $user_id, $lock );
	}
}

/**
 * Reject a matching pending request.
 *
 * @param int    $user_id    User ID.
 * @param string $request_id Request UUID.
 * @param string $reason     Rejection reason.
 * @param int    $actor_id   Decision actor.
 * @return array|WP_Error
 */
function ec_users_reject_artist_dispatch_access( $user_id, $request_id, $reason, $actor_id ) {
	$user_id    = absint( $user_id );
	$request_id = sanitize_text_field( $request_id );
	$reason     = sanitize_textarea_field( $reason );
	if ( '' === $reason ) {
		return new WP_Error( 'artist_dispatch_reason_required', __( 'A rejection reason is required.', 'extrachill-users' ), array( 'status' => 400 ) );
	}
	$lock = ec_users_acquire_artist_dispatch_lock( $user_id, $request_id );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$current = ec_users_get_artist_dispatch_state( $user_id );
		if ( ( $current['request_id'] ?? '' ) !== $request_id ) {
			return new WP_Error( 'artist_dispatch_request_mismatch', __( 'The request ID does not match current state.', 'extrachill-users' ), array( 'status' => 409 ) );
		}
		if ( 'rejected' === ( $current['status'] ?? '' ) ) {
			return ec_users_finalize_artist_dispatch_transition( $user_id, 'rejected', $actor_id, $current );
		}
		if ( 'pending' !== ( $current['status'] ?? '' ) ) {
			return new WP_Error( 'artist_dispatch_not_pending', __( 'Only pending requests can be rejected.', 'extrachill-users' ), array( 'status' => 409 ) );
		}
		$rejected             = $current;
		$rejected['status']   = 'rejected';
		$rejected['decision'] = array(
			'decided_at' => time(),
			'actor_id'   => absint( $actor_id ),
			'note'       => $reason,
		);
		if ( ! ec_users_write_artist_dispatch_state( $user_id, $rejected, $current ) ) {
			return new WP_Error( 'artist_dispatch_state_write_failed', __( 'The Artist Dispatch rejection could not be saved.', 'extrachill-users' ) );
		}
		return ec_users_finalize_artist_dispatch_transition( $user_id, 'rejected', $actor_id, $rejected );
	} finally {
		ec_users_release_artist_dispatch_lock( $user_id, $lock );
	}
}

/**
 * Revoke an approved grant without touching posts or unrelated roles.
 *
 * @param int    $user_id    User ID.
 * @param string $request_id Request UUID.
 * @param string $reason     Revocation reason.
 * @param int    $actor_id   Decision actor.
 * @return array|WP_Error
 */
function ec_users_revoke_artist_dispatch_access( $user_id, $request_id, $reason, $actor_id ) {
	$user_id    = absint( $user_id );
	$request_id = sanitize_text_field( $request_id );
	$reason     = sanitize_textarea_field( $reason );
	if ( '' === $reason ) {
		return new WP_Error( 'artist_dispatch_reason_required', __( 'A revocation reason is required.', 'extrachill-users' ), array( 'status' => 400 ) );
	}
	$lock = ec_users_acquire_artist_dispatch_lock( $user_id, $request_id );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$current = ec_users_get_artist_dispatch_state( $user_id );
		if ( ( $current['request_id'] ?? '' ) !== $request_id ) {
			return new WP_Error( 'artist_dispatch_request_mismatch', __( 'The request ID does not match current state.', 'extrachill-users' ), array( 'status' => 409 ) );
		}
		if ( 'revoked' === ( $current['status'] ?? '' ) ) {
			return ec_users_finalize_artist_dispatch_transition( $user_id, 'revoked', $actor_id, $current );
		}
		if ( 'approved' !== ( $current['status'] ?? '' ) ) {
			return new WP_Error( 'artist_dispatch_not_approved', __( 'Only approved access can be revoked.', 'extrachill-users' ), array( 'status' => 409 ) );
		}
		$membership_preexisted = ! empty( $current['grant']['membership_preexisted'] );
		$cleanup               = ec_users_revoke_artist_dispatch_role( $user_id, $membership_preexisted );
		if ( is_wp_error( $cleanup ) ) {
			return $cleanup;
		}
		$revoked               = $current;
		$revoked['status']     = 'revoked';
		$revoked['revocation'] = array(
			'revoked_at' => time(),
			'actor_id'   => absint( $actor_id ),
			'reason'     => $reason,
			'cleanup'    => $cleanup,
		);
		if ( ! ec_users_write_artist_dispatch_state( $user_id, $revoked, $current ) ) {
			$restored = ec_users_grant_artist_dispatch_role( $user_id );
			return is_wp_error( $restored )
				? new WP_Error( 'artist_dispatch_rollback_failed', __( 'The revocation state and role could not be restored consistently.', 'extrachill-users' ) )
				: new WP_Error( 'artist_dispatch_state_write_failed', __( 'The revocation state could not be saved; the role was restored.', 'extrachill-users' ) );
		}
		return ec_users_finalize_artist_dispatch_transition( $user_id, 'revoked', $actor_id, $revoked, $cleanup );
	} finally {
		ec_users_release_artist_dispatch_lock( $user_id, $lock );
	}
}

/**
 * Moderation integration: revoke now, never restore when moderation clears.
 *
 * @param int    $user_id  User ID.
 * @param int    $actor_id Moderation actor.
 * @param string $reason   Moderation reason.
 * @return array|WP_Error
 */
function ec_users_revoke_artist_dispatch_for_moderation( $user_id, $actor_id, $reason ) {
	$state = ec_users_get_artist_dispatch_state( $user_id );
	if ( 'approved' !== ( $state['status'] ?? '' ) ) {
		return $state;
	}
	/* translators: %s: moderation reason. */
	$revocation_reason = sprintf( __( 'Revoked by account moderation: %s', 'extrachill-users' ), sanitize_text_field( $reason ) );

	return ec_users_revoke_artist_dispatch_access(
		$user_id,
		(string) $state['request_id'],
		$revocation_reason,
		$actor_id
	);
}
