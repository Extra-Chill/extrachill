<?php
/**
 * Deterministic experiment assignment.
 *
 * Network owns allocation only. Consumers register code-owned definitions and
 * decide eligibility; identity/privacy providers contribute through generic
 * filters; measurement consumers listen to trusted server hooks without
 * Network persisting outcomes.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maximum accepted value for one variant weight.
 *
 * This is deliberately below the signed-32-bit-safe 2^28 draw domain.
 */
const EXTRACHILL_EXPERIMENT_MAX_VARIANT_WEIGHT = 500000;

/**
 * Maximum accepted sum of all variant weights.
 *
 * This keeps addition overflow checks explicit and the allocation range safely
 * below the 2^28 hash draw used by rejection sampling.
 */
const EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT = 1000000;

/** 2^28 allocation domain; its maximum value fits signed 32-bit PHP. */
const EXTRACHILL_EXPERIMENT_DRAW_RANGE = 268435456;

/** Short lifetime for a browser exposure proof. */
const EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL = 3600;

/** Accepted clock skew for tokens issued slightly in the future. */
const EXTRACHILL_EXPERIMENT_EXPOSURE_FUTURE_SKEW = 60;

/** Shared atomic-cache group for consumed exposure proofs. */
const EXTRACHILL_EXPERIMENT_EXPOSURE_CACHE_GROUP = 'extrachill_experiment_exposures';

/** Network option containing live state for registered experiment versions. */
const EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION = 'extrachill_experiment_lifecycle';

/** The only supported allocation policy. */
const EXTRACHILL_EXPERIMENT_ASSIGNMENT_POLICY = 'weighted_random';

/** Maximum code definition version accepted across Network and Analytics. */
const EXTRACHILL_EXPERIMENT_MAX_DEFINITION_VERSION = 1000000;

/** Maximum code-owned experiment definitions accepted at once. */
const EXTRACHILL_EXPERIMENT_MAX_DEFINITIONS = 64;

/** Maximum variants accepted in one definition and admin item. */
const EXTRACHILL_EXPERIMENT_MAX_VARIANTS = 64;

/** Maximum surfaces accepted in one definition and admin item. */
const EXTRACHILL_EXPERIMENT_MAX_SURFACES = 64;

/** Lifecycle option size after which recovery is reported as over-bound. */
const EXTRACHILL_EXPERIMENT_MAX_LIFECYCLE_RECORDS = 128;

/** Maximum orphan records normalized for admin inspection. */
const EXTRACHILL_EXPERIMENT_MAX_ORPHAN_SAMPLES = 64;

/** Bounded orphan count; max + 1 is the over-bound sentinel. */
const EXTRACHILL_EXPERIMENT_MAX_REPORTED_ORPHANS = 129;

/** Maximum normalized registered plus orphan sample items. */
const EXTRACHILL_EXPERIMENT_MAX_LIST_ITEMS = 128;

/** Advisory lock wait; lifecycle writes never block request workers. */
const EXTRACHILL_EXPERIMENT_LOCK_WAIT_SECONDS = 0;

/** Bounded lifecycle states. */
const EXTRACHILL_EXPERIMENT_STATES = array( 'inactive', 'active', 'paused', 'completed' );

/**
 * Make exposure proof consumption network-global on multisite caches.
 */
function extrachill_register_experiment_cache_group() {
	if ( function_exists( 'wp_cache_add_global_groups' ) ) {
		wp_cache_add_global_groups( array( EXTRACHILL_EXPERIMENT_EXPOSURE_CACHE_GROUP ) );
	}
}
add_action( 'init', 'extrachill_register_experiment_cache_group', 0 );

/**
 * Whether the current operator may administer experiment lifecycle state.
 *
 * Local WP-CLI is a trusted shell operator surface and commonly runs as user
 * zero. REST and web execution always require the network options capability.
 *
 * @return bool Whether experiment administration is allowed.
 */
function extrachill_experiment_admin_permission() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}

	return function_exists( 'current_user_can' ) && current_user_can( 'manage_network_options' );
}

/**
 * Return registered experiment definitions.
 *
 * A definition has this shape:
 *
 *     'stable-key' => array(
 *         'key'                 => 'stable-key',
 *         'definition_version'  => 1,
 *         'assignment_policy'   => 'weighted_random',
 *         'default_state'       => 'inactive',
 *         'default_variant'     => 'control',
 *         'control_variant'     => 'control',
 *         'variants'            => array( 'control' => 50, 'treatment' => 50 ),
 *         'surfaces'            => array( 'single-post-bridge' ),
 *         'eligibility_callback' => static function ( array $context, string $surface ): bool {},
 *     )
 *
 * Eligibility callbacks must re-resolve their own context and compose any
 * applicable Users capability/rollout gate. Assignment is never authorization.
 *
 * @return array<string, array<string, mixed>>
 */
function extrachill_get_experiment_definitions() {
	$definitions = apply_filters( 'extrachill_experiment_definitions', array() );

	return is_array( $definitions ) ? $definitions : array();
}

/**
 * Validate and normalize one code-owned experiment definition.
 *
 * @param mixed  $definition     Candidate definition.
 * @param string $registered_key Optional filter registration key.
 * @return array<string, mixed>|null Normalized definition, or null when invalid.
 */
function extrachill_normalize_experiment_definition( $definition, $registered_key = '' ) {
	if ( ! is_array( $definition ) ) {
		return null;
	}

	$registered_key = (string) $registered_key;
	$is_legacy      = ! isset( $definition['key'] )
		&& ! isset( $definition['definition_version'] )
		&& ! isset( $definition['assignment_policy'] )
		&& ! isset( $definition['default_state'] );
	$key            = isset( $definition['key'] ) ? (string) $definition['key'] : $registered_key;
	$version        = isset( $definition['definition_version'] ) ? $definition['definition_version'] : ( $is_legacy ? 1 : 0 );
	$policy         = isset( $definition['assignment_policy'] ) ? (string) $definition['assignment_policy'] : ( $is_legacy ? EXTRACHILL_EXPERIMENT_ASSIGNMENT_POLICY : '' );
	$default_state  = isset( $definition['default_state'] ) ? (string) $definition['default_state'] : ( $is_legacy ? 'active' : '' );
	if (
		! $is_legacy
		&& ( ! isset( $definition['key'] )
			|| ! isset( $definition['definition_version'] )
			|| ! isset( $definition['assignment_policy'] )
			|| ! isset( $definition['default_state'] ) )
	) {
		return null;
	}

	$default  = isset( $definition['default_variant'] ) ? (string) $definition['default_variant'] : '';
	$control  = isset( $definition['control_variant'] ) ? (string) $definition['control_variant'] : '';
	$variants = isset( $definition['variants'] ) && is_array( $definition['variants'] )
		? $definition['variants']
		: array();
	$surfaces = isset( $definition['surfaces'] ) && is_array( $definition['surfaces'] )
		? array_values( array_unique( $definition['surfaces'] ) )
		: array();

	if (
		( '' !== $registered_key && $key !== $registered_key )
		|| ( '' !== $key && 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $key ) )
		|| ! is_int( $version )
		|| $version <= 0
		|| $version > EXTRACHILL_EXPERIMENT_MAX_DEFINITION_VERSION
		|| EXTRACHILL_EXPERIMENT_ASSIGNMENT_POLICY !== $policy
		|| ! in_array( $default_state, EXTRACHILL_EXPERIMENT_STATES, true )
		|| '' === $default
		|| $default !== $control
		|| count( $variants ) < 2
		|| count( $variants ) > EXTRACHILL_EXPERIMENT_MAX_VARIANTS
		|| ! isset( $variants[ $default ] )
		|| ( isset( $definition['surfaces'] ) && is_array( $definition['surfaces'] ) && count( $definition['surfaces'] ) > EXTRACHILL_EXPERIMENT_MAX_SURFACES )
		|| empty( $surfaces )
		|| empty( $definition['eligibility_callback'] )
		|| ! is_callable( $definition['eligibility_callback'] )
	) {
		return null;
	}

	$total_weight = 0;
	foreach ( $variants as $variant => $weight ) {
		if (
			! is_string( $variant )
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $variant )
			|| ! is_int( $weight )
			|| $weight <= 0
			|| $weight > EXTRACHILL_EXPERIMENT_MAX_VARIANT_WEIGHT
			|| $total_weight > EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT - $weight
		) {
			return null;
		}
		$total_weight += $weight;
	}

	foreach ( $surfaces as $surface ) {
		if ( ! is_string( $surface ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $surface ) ) {
			return null;
		}
	}

	if ( $total_weight <= 0 ) {
		return null;
	}

	return array(
		'key'                  => $key,
		'definition_version'   => $version,
		'assignment_policy'    => $policy,
		'default_state'        => $default_state,
		'default_variant'      => $default,
		'control_variant'      => $control,
		'variants'             => $variants,
		'surfaces'             => $surfaces,
		'eligibility_callback' => $definition['eligibility_callback'],
		'total_weight'         => $total_weight,
	);
}

/**
 * Return valid code-owned definitions indexed by their declared stable key.
 *
 * The original keyed definition shape is migrated as version 1, weighted
 * random, and active so the already-shipped assignment contract is preserved.
 * New definitions must explicitly provide all lifecycle fields.
 *
 * @return array<string, array<string, mixed>>
 */
function extrachill_get_normalized_experiment_definitions() {
	$definitions = extrachill_get_experiment_definitions();
	if ( count( $definitions ) > EXTRACHILL_EXPERIMENT_MAX_DEFINITIONS ) {
		return array();
	}

	$normalized = array();
	foreach ( $definitions as $key => $definition ) {
		if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $key ) ) {
			continue;
		}
		$candidate = extrachill_normalize_experiment_definition( $definition, $key );
		if ( null !== $candidate ) {
			$normalized[ $key ] = $candidate;
		}
	}

	return $normalized;
}

/**
 * Read and validate the bounded live-state option.
 *
 * Registered records are resolved by direct bounded key lookup, so arbitrarily
 * many orphaned option keys cannot hide or disable valid registered state.
 * Orphan normalization stops after the hard sample cap; the exact count comes
 * from option size minus the bounded registered-key intersection.
 *
 * @param array<int, string>|null $registered_keys Optional pre-resolved bounded keys.
 * @return array{valid: bool, states: array<string, array{definition_version: int, state: string}>, orphaned: array<int, array{key: string, definition_version: int, state: string}>, orphan_count: int, orphan_samples_truncated: bool, over_bound: bool, invalid_keys: array<string, bool>}
 */
function extrachill_get_experiment_lifecycle_option( $registered_keys = null ) {
	$value = get_site_option( EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION, false );

	return extrachill_normalize_experiment_lifecycle_option( $value, $registered_keys );
}

/**
 * Validate one raw lifecycle option value.
 *
 * @param mixed                   $value Raw, unserialized option value.
 * @param array<int, string>|null $registered_keys Optional pre-resolved bounded keys.
 * @return array{valid: bool, states: array<string, array{definition_version: int, state: string}>, orphaned: array<int, array{key: string, definition_version: int, state: string}>, orphan_count: int, orphan_samples_truncated: bool, over_bound: bool, invalid_keys: array<string, bool>}
 */
function extrachill_normalize_experiment_lifecycle_option( $value, $registered_keys = null ) {
	if ( false === $value ) {
		return array(
			'valid'                    => true,
			'states'                   => array(),
			'orphaned'                 => array(),
			'orphan_count'             => 0,
			'orphan_samples_truncated' => false,
			'over_bound'               => false,
			'invalid_keys'             => array(),
		);
	}
	if ( ! is_array( $value ) ) {
		return array(
			'valid'                    => false,
			'states'                   => array(),
			'orphaned'                 => array(),
			'orphan_count'             => 0,
			'orphan_samples_truncated' => false,
			'over_bound'               => false,
			'invalid_keys'             => array(),
		);
	}

	$states             = array();
	$orphaned           = array();
	$invalid_keys       = array();
	$registered_present = 0;
	$registered_keys    = is_array( $registered_keys ) ? $registered_keys : array_keys( extrachill_get_normalized_experiment_definitions() );
	if ( count( $registered_keys ) > EXTRACHILL_EXPERIMENT_MAX_DEFINITIONS ) {
		$registered_keys = array();
	}
	foreach ( $registered_keys as $key ) {
		if ( ! array_key_exists( $key, $value ) ) {
			continue;
		}
		++$registered_present;
		$record = $value[ $key ];
		if (
			! is_array( $record )
			|| array( 'definition_version', 'state' ) !== array_keys( $record )
			|| ! is_int( $record['definition_version'] )
			|| $record['definition_version'] <= 0
			|| $record['definition_version'] > EXTRACHILL_EXPERIMENT_MAX_DEFINITION_VERSION
			|| ! is_string( $record['state'] )
			|| ! in_array( $record['state'], EXTRACHILL_EXPERIMENT_STATES, true )
		) {
			$invalid_keys[ $key ] = true;
			continue;
		}
		$states[ $key ] = $record;
	}

	$actual_orphan_count = count( $value ) - $registered_present;
	$orphan_count        = min( $actual_orphan_count, EXTRACHILL_EXPERIMENT_MAX_REPORTED_ORPHANS );
	foreach ( $value as $key => $record ) {
		if ( is_string( $key ) && in_array( $key, $registered_keys, true ) ) {
			continue;
		}
		if ( count( $orphaned ) >= EXTRACHILL_EXPERIMENT_MAX_ORPHAN_SAMPLES ) {
			break;
		}
		$key_is_valid = is_string( $key ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $key );
		$orphaned[]   = array(
			'key'                => $key_is_valid ? $key : 'invalid-' . substr( hash( 'sha256', (string) $key ), 0, 16 ),
			'definition_version' => is_array( $record ) && isset( $record['definition_version'] ) && is_int( $record['definition_version'] ) && $record['definition_version'] > 0 && $record['definition_version'] <= EXTRACHILL_EXPERIMENT_MAX_DEFINITION_VERSION ? $record['definition_version'] : 0,
			'state'              => is_array( $record ) && isset( $record['state'] ) && is_string( $record['state'] ) && in_array( $record['state'], EXTRACHILL_EXPERIMENT_STATES, true ) ? $record['state'] : 'inactive',
		);
	}

	return array(
		'valid'                    => true,
		'states'                   => $states,
		'orphaned'                 => $orphaned,
		'orphan_count'             => $orphan_count,
		'orphan_samples_truncated' => $actual_orphan_count > count( $orphaned ),
		'over_bound'               => count( $value ) > EXTRACHILL_EXPERIMENT_MAX_LIFECYCLE_RECORDS,
		'invalid_keys'             => $invalid_keys,
	);
}

/**
 * Resolve live state for the current code definition version.
 *
 * Missing and older-version state uses the reviewed code default. Corrupt or
 * future-version state fails closed and never broadens eligibility.
 *
 * @param array<string, mixed>      $definition Normalized definition.
 * @param array<string, mixed>|null $lifecycle Optional preloaded option data.
 * @return string Effective lifecycle state.
 */
function extrachill_get_experiment_state( array $definition, $lifecycle = null ) {
	$lifecycle = is_array( $lifecycle ) ? $lifecycle : extrachill_get_experiment_lifecycle_option();
	if ( empty( $lifecycle['valid'] ) ) {
		return 'inactive';
	}

	$key = $definition['key'];
	if ( ! empty( $lifecycle['invalid_keys'][ $key ] ) ) {
		return 'inactive';
	}
	$record = isset( $lifecycle['states'][ $key ] ) ? $lifecycle['states'][ $key ] : null;
	if ( null === $record || $record['definition_version'] < $definition['definition_version'] ) {
		return $definition['default_state'];
	}
	if ( $record['definition_version'] !== $definition['definition_version'] ) {
		return 'inactive';
	}

	return $record['state'];
}

/**
 * Whether a registered experiment is active for a consumer surface/context.
 *
 * Consumers may call this before rendering candidates or enqueueing assets.
 * It does not replace consumer authorization; the eligibility callback must
 * compose every applicable capability or rollout check itself.
 *
 * @param string               $experiment_key Stable experiment key.
 * @param string               $surface Consumer surface, or empty for lifecycle-only checks.
 * @param array<string, mixed> $context Consumer-owned eligibility context.
 * @return bool Whether the current definition is active and consumer-eligible.
 */
function extrachill_experiment_is_active( $experiment_key, $surface = '', array $context = array() ) {
	$definitions = extrachill_get_normalized_experiment_definitions();
	$definition  = isset( $definitions[ $experiment_key ] ) ? $definitions[ $experiment_key ] : null;
	if ( null === $definition || 'active' !== extrachill_get_experiment_state( $definition ) ) {
		return false;
	}
	if ( '' === $surface ) {
		return true;
	}
	if ( ! in_array( $surface, $definition['surfaces'], true ) ) {
		return false;
	}

	try {
		return (bool) call_user_func( $definition['eligibility_callback'], $context, $surface );
	} catch ( \Throwable $error ) {
		return false;
	}
}

/**
 * Return audit-safe definitions and effective live states.
 *
 * @return array<string, mixed>
 */
function extrachill_list_experiments() {
	$definitions = extrachill_get_normalized_experiment_definitions();
	$lifecycle   = extrachill_get_experiment_lifecycle_option( array_keys( $definitions ) );
	$output      = array();
	foreach ( $definitions as $definition ) {
		$output[] = array(
			'key'                => $definition['key'],
			'registered'         => true,
			'orphaned'           => false,
			'definition_version' => $definition['definition_version'],
			'assignment_policy'  => $definition['assignment_policy'],
			'default_state'      => $definition['default_state'],
			'state'              => extrachill_get_experiment_state( $definition, $lifecycle ),
			'default_variant'    => $definition['default_variant'],
			'control_variant'    => $definition['control_variant'],
			'variants'           => $definition['variants'],
			'surfaces'           => $definition['surfaces'],
		);
	}
	foreach ( $lifecycle['orphaned'] as $orphaned ) {
		$output[] = array(
			'key'                => $orphaned['key'],
			'registered'         => false,
			'orphaned'           => true,
			'definition_version' => $orphaned['definition_version'],
			'assignment_policy'  => '',
			'default_state'      => 'inactive',
			'state'              => $orphaned['state'],
			'default_variant'    => '',
			'control_variant'    => '',
			'variants'           => array(),
			'surfaces'           => array(),
		);
	}

	return array(
		'items'                    => $output,
		'registered_count'         => count( $definitions ),
		'orphan_count'             => $lifecycle['orphan_count'],
		'orphan_samples_truncated' => $lifecycle['orphan_samples_truncated'],
		'lifecycle_over_bound'     => $lifecycle['over_bound'],
	);
}

/**
 * Return the network-scoped MySQL advisory lock name.
 *
 * @return string Bounded connection lock name.
 */
function extrachill_experiment_lifecycle_lock_name() {
	$network_id = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;

	return 'extrachill_exp_lifecycle_' . max( 1, $network_id );
}

/**
 * Return Core's exact network-option cache keys.
 *
 * Core stores network options under `network_id:option_name` and misses under
 * `network_id:notoptions`, both in the `site-options` cache group.
 *
 * @return array{option: string, notoptions: string}
 */
function extrachill_experiment_lifecycle_cache_keys() {
	$network_id = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;
	$network_id = max( 1, $network_id );

	return array(
		'option'     => $network_id . ':' . EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION,
		'notoptions' => $network_id . ':notoptions',
	);
}

/**
 * Read the exact lifecycle row without consulting WordPress caches or filters.
 *
 * @return array{exists: bool, value: mixed}|\WP_Error Durable snapshot or error.
 */
function extrachill_read_experiment_lifecycle_option_durable() {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->sitemeta ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
		return new \WP_Error( 'experiment_lifecycle_durable_read_unavailable', __( 'Experiment lifecycle storage could not be read.', 'extrachill-network' ), array( 'status' => 500 ) );
	}

	$network_id = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;
	try {
		$query = $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 1",
			max( 1, $network_id ),
			EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION
		);
		$row   = $wpdb->get_row( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock requires a cache-independent durable snapshot.
	} catch ( \Throwable $error ) {
		return new \WP_Error( 'experiment_lifecycle_durable_read_failed', __( 'Experiment lifecycle storage could not be read.', 'extrachill-network' ), array( 'status' => 500 ) );
	}

	if ( ! empty( $wpdb->last_error ) ) {
		return new \WP_Error( 'experiment_lifecycle_durable_read_failed', __( 'Experiment lifecycle storage could not be read.', 'extrachill-network' ), array( 'status' => 500 ) );
	}
	if ( null === $row ) {
		return array(
			'exists' => false,
			'value'  => false,
		);
	}
	if ( ! is_object( $row ) || ! property_exists( $row, 'meta_value' ) ) {
		return new \WP_Error( 'experiment_lifecycle_durable_read_failed', __( 'Experiment lifecycle storage could not be read.', 'extrachill-network' ), array( 'status' => 500 ) );
	}

	return array(
		'exists' => true,
		'value'  => maybe_unserialize( $row->meta_value ),
	);
}

/**
 * Restore and verify Core's exact cache representation of a durable snapshot.
 *
 * @param array{exists: bool, value: mixed} $snapshot Durable option snapshot.
 * @return true|\WP_Error True on success, otherwise a fail-closed error.
 * @throws \RuntimeException When a cache operation or verification fails internally.
 */
function extrachill_restore_experiment_lifecycle_option_cache( array $snapshot ) {
	$keys = extrachill_experiment_lifecycle_cache_keys();

	try {
		$found = false;
		wp_cache_get( $keys['option'], 'site-options', false, $found );
		if ( $found && ! wp_cache_delete( $keys['option'], 'site-options' ) ) {
			throw new \RuntimeException( 'cache delete failed' );
		}

		$notoptions = wp_cache_get( $keys['notoptions'], 'site-options' );
		$notoptions = is_array( $notoptions ) ? $notoptions : array();
		if ( $snapshot['exists'] ) {
			unset( $notoptions[ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] );
			if ( ! wp_cache_set( $keys['option'], $snapshot['value'], 'site-options' ) ) {
				throw new \RuntimeException( 'cache set failed' );
			}
		} else {
			$notoptions[ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] = true;
		}
		if ( ! wp_cache_set( $keys['notoptions'], $notoptions, 'site-options' ) ) {
			throw new \RuntimeException( 'notoptions cache set failed' );
		}

		$found        = false;
		$cached_value = wp_cache_get( $keys['option'], 'site-options', false, $found );
		$notoptions   = wp_cache_get( $keys['notoptions'], 'site-options' );
		if (
			$snapshot['exists'] !== $found
			|| ( $snapshot['exists'] && $snapshot['value'] !== $cached_value )
			|| ! is_array( $notoptions )
			|| isset( $notoptions[ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] ) === $snapshot['exists']
		) {
			throw new \RuntimeException( 'cache verification failed' );
		}
	} catch ( \Throwable $error ) {
		return new \WP_Error( 'experiment_lifecycle_cache_sync_failed', __( 'Experiment lifecycle cache could not be synchronized.', 'extrachill-network' ), array( 'status' => 500 ) );
	}

	return true;
}

/**
 * Acquire the lifecycle write lock without waiting.
 *
 * @return true|\WP_Error True when held, otherwise a fail-closed error.
 */
function extrachill_acquire_experiment_lifecycle_lock() {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
		return new \WP_Error( 'experiment_lifecycle_lock_unavailable', __( 'Experiment lifecycle lock is unavailable.', 'extrachill-network' ), array( 'status' => 503 ) );
	}

	try {
		$query  = $wpdb->prepare(
			'SELECT GET_LOCK( %s, %d )',
			extrachill_experiment_lifecycle_lock_name(),
			EXTRACHILL_EXPERIMENT_LOCK_WAIT_SECONDS
		);
		$locked = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks require a direct uncached connection query.
	} catch ( \Throwable $error ) {
		return new \WP_Error( 'experiment_lifecycle_lock_failed', __( 'Experiment lifecycle lock could not be acquired.', 'extrachill-network' ), array( 'status' => 503 ) );
	}

	return '1' === (string) $locked
		? true
		: new \WP_Error( 'experiment_lifecycle_lock_failed', __( 'Experiment lifecycle lock could not be acquired.', 'extrachill-network' ), array( 'status' => 503 ) );
}

/**
 * Release the lifecycle advisory lock held by this database connection.
 *
 * @return true|\WP_Error True when released, otherwise a fail-closed error.
 */
function extrachill_release_experiment_lifecycle_lock() {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
		return new \WP_Error( 'experiment_lifecycle_lock_release_failed', __( 'Experiment lifecycle lock could not be released.', 'extrachill-network' ), array( 'status' => 500 ) );
	}

	try {
		$query    = $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', extrachill_experiment_lifecycle_lock_name() );
		$released = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks require a direct uncached connection query.
	} catch ( \Throwable $error ) {
		return new \WP_Error( 'experiment_lifecycle_lock_release_failed', __( 'Experiment lifecycle lock could not be released.', 'extrachill-network' ), array( 'status' => 500 ) );
	}

	return '1' === (string) $released
		? true
		: new \WP_Error( 'experiment_lifecycle_lock_release_failed', __( 'Experiment lifecycle lock could not be released.', 'extrachill-network' ), array( 'status' => 500 ) );
}

/**
 * Transition one registered experiment while the advisory lock is held.
 *
 * @param string $experiment_key Stable experiment key.
 * @param int    $definition_version Expected current code version.
 * @param string $new_state Requested state.
 * @return array<string, mixed>|\WP_Error Normalized changed state.
 */
function extrachill_transition_experiment_state_locked( $experiment_key, $definition_version, $new_state ) {
	$definitions = extrachill_get_normalized_experiment_definitions();
	$definition  = isset( $definitions[ $experiment_key ] ) ? $definitions[ $experiment_key ] : null;
	if ( null === $definition ) {
		return new \WP_Error( 'experiment_not_registered', __( 'Experiment is not registered.', 'extrachill-network' ), array( 'status' => 404 ) );
	}
	if ( $definition['definition_version'] !== $definition_version ) {
		return new \WP_Error( 'experiment_definition_version_mismatch', __( 'Experiment definition version does not match current code.', 'extrachill-network' ), array( 'status' => 409 ) );
	}
	if ( ! in_array( $new_state, EXTRACHILL_EXPERIMENT_STATES, true ) ) {
		return new \WP_Error( 'invalid_experiment_state', __( 'Experiment lifecycle state is invalid.', 'extrachill-network' ), array( 'status' => 400 ) );
	}

	$durable = extrachill_read_experiment_lifecycle_option_durable();
	if ( $durable instanceof \WP_Error ) {
		return $durable;
	}
	$lifecycle = extrachill_normalize_experiment_lifecycle_option( $durable['value'] );
	if ( empty( $lifecycle['valid'] ) ) {
		return new \WP_Error( 'invalid_experiment_lifecycle_option', __( 'Experiment lifecycle storage is corrupt.', 'extrachill-network' ), array( 'status' => 500 ) );
	}
	$cache_restored = extrachill_restore_experiment_lifecycle_option_cache( $durable );
	if ( $cache_restored instanceof \WP_Error ) {
		return $cache_restored;
	}
	$old_state = extrachill_get_experiment_state( $definition, $lifecycle );
	$allowed   = array(
		'inactive'  => array( 'active' ),
		'active'    => array( 'paused', 'completed' ),
		'paused'    => array( 'active', 'completed' ),
		'completed' => array(),
	);
	if ( $old_state !== $new_state && ! in_array( $new_state, $allowed[ $old_state ], true ) ) {
		return new \WP_Error( 'invalid_experiment_state_transition', __( 'Experiment lifecycle transition is invalid.', 'extrachill-network' ), array( 'status' => 409 ) );
	}

	$result           = array(
		'experiment_key'     => $definition['key'],
		'definition_version' => $definition['definition_version'],
		'previous_state'     => $old_state,
		'state'              => $new_state,
	);
	$stored_record    = isset( $lifecycle['states'][ $definition['key'] ] ) ? $lifecycle['states'][ $definition['key'] ] : null;
	$version_mismatch = null !== $stored_record && $stored_record['definition_version'] !== $definition['definition_version'];
	$needs_recovery   = ! empty( $lifecycle['orphaned'] ) || ! empty( $lifecycle['invalid_keys'] ) || $version_mismatch;
	if ( $old_state === $new_state && ! $needs_recovery ) {
		return $result;
	}

	if ( $old_state !== $new_state || ! empty( $lifecycle['invalid_keys'][ $definition['key'] ] ) || $version_mismatch ) {
		$lifecycle['states'][ $definition['key'] ] = array(
			'definition_version' => $definition['definition_version'],
			'state'              => $new_state,
		);
	}
	$intended = $lifecycle['states'];
	if ( ! update_site_option( EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION, $intended ) ) {
		return new \WP_Error( 'experiment_state_update_failed', __( 'Experiment lifecycle state could not be saved.', 'extrachill-network' ), array( 'status' => 500 ) );
	}
	$written = extrachill_read_experiment_lifecycle_option_durable();
	if ( $written instanceof \WP_Error ) {
		return $written;
	}
	if ( empty( $written['exists'] ) || $intended !== $written['value'] ) {
		return new \WP_Error( 'experiment_lifecycle_durable_write_mismatch', __( 'Experiment lifecycle state could not be verified.', 'extrachill-network' ), array( 'status' => 500 ) );
	}
	$cache_restored = extrachill_restore_experiment_lifecycle_option_cache( $written );
	if ( $cache_restored instanceof \WP_Error ) {
		return $cache_restored;
	}

	return $result;
}

/**
 * Atomically transition one registered experiment's current code version.
 *
 * The network-scoped MySQL advisory lock serializes the complete option
 * read/modify/write. Acquisition never waits, and release is always attempted
 * in `finally`. Lock acquisition, callback, and release errors all fail closed.
 *
 * @param string $experiment_key Stable experiment key.
 * @param int    $definition_version Expected current code version.
 * @param string $new_state Requested state.
 * @return array<string, mixed>|\WP_Error Normalized changed state.
 */
function extrachill_transition_experiment_state( $experiment_key, $definition_version, $new_state ) {
	$locked = extrachill_acquire_experiment_lifecycle_lock();
	if ( $locked instanceof \WP_Error ) {
		return $locked;
	}

	$result  = null;
	$release = null;
	try {
		$result = extrachill_transition_experiment_state_locked( $experiment_key, $definition_version, $new_state );
	} catch ( \Throwable $error ) {
		$result = new \WP_Error( 'experiment_lifecycle_transition_failed', __( 'Experiment lifecycle transition failed.', 'extrachill-network' ), array( 'status' => 500 ) );
	} finally {
		$release = extrachill_release_experiment_lifecycle_lock();
	}

	if ( $release instanceof \WP_Error ) {
		return $release;
	}
	if ( is_array( $result ) && $result['previous_state'] !== $result['state'] ) {
		do_action( 'extrachill_experiment_state_changed', $result );
	}

	return $result;
}

/**
 * Select a weighted variant for a zero-based bucket.
 *
 * @param array<string, mixed> $definition Normalized definition.
 * @param int                  $bucket     Zero-based bucket.
 * @return string Selected variant.
 */
function extrachill_experiment_variant_for_bucket( array $definition, $bucket ) {
	$default = isset( $definition['default_variant'] ) ? (string) $definition['default_variant'] : 'control';
	$total   = isset( $definition['total_weight'] ) ? (int) $definition['total_weight'] : 0;

	if ( $total <= 0 || $bucket < 0 || $bucket >= $total || empty( $definition['variants'] ) ) {
		return $default;
	}

	$boundary = 0;
	foreach ( $definition['variants'] as $variant => $weight ) {
		$boundary += (int) $weight;
		if ( $bucket < $boundary ) {
			return (string) $variant;
		}
	}

	return $default;
}

/**
 * Reduce one bounded draw to a bucket without modulo bias.
 *
 * @param int $draw  Unsigned 28-bit draw.
 * @param int $total Number of buckets.
 * @return int|null Bucket, or null when the draw/total is invalid or rejected.
 */
function extrachill_experiment_reduce_draw( $draw, $total ) {
	$draw  = (int) $draw;
	$total = (int) $total;
	if (
		$draw < 0
		|| $draw >= EXTRACHILL_EXPERIMENT_DRAW_RANGE
		|| $total <= 0
		|| $total > EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT
	) {
		return null;
	}

	$limit = EXTRACHILL_EXPERIMENT_DRAW_RANGE - ( EXTRACHILL_EXPERIMENT_DRAW_RANGE % $total );
	if ( $draw >= $limit ) {
		return null;
	}

	return $draw % $total;
}

/**
 * Convert a deterministic seed to an unbiased zero-based bucket.
 *
 * SHA-256 supplies successive unsigned 28-bit draws, each no larger than
 * 268,435,455 and therefore safe when PHP_INT_SIZE is 4. Values in the
 * incomplete high tail are rejected before modulo reduction. Rehashing with a
 * counter supplies more draws without a biased fallback.
 *
 * @param string $seed  Deterministic allocation seed.
 * @param int    $total Number of buckets.
 * @return int|null Unbiased bucket, or null for an invalid total.
 */
function extrachill_experiment_unbiased_bucket( $seed, $total ) {
	$total = (int) $total;
	if ( $total <= 0 || $total > EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT ) {
		return null;
	}

	$round = 0;

	while ( true ) {
		$hash = hash( 'sha256', $seed . "\0" . $round );
		for ( $offset = 0; $offset <= 56; $offset += 7 ) {
			$bucket = extrachill_experiment_reduce_draw( hexdec( substr( $hash, $offset, 7 ) ), $total );
			if ( null !== $bucket ) {
				return $bucket;
			}
		}
		++$round;
	}
}

/**
 * Pure deterministic allocation from an explicit subject key.
 *
 * Blog/site identity is intentionally absent from the hash, so a subject keeps
 * the same assignment across the multisite network.
 *
 * @param string               $experiment_key Stable experiment key.
 * @param array<string, mixed> $definition     Normalized definition.
 * @param string               $subject_key    Explicit stable subject key.
 * @return string Selected variant.
 */
function extrachill_allocate_experiment_variant( $experiment_key, array $definition, $subject_key ) {
	$default = isset( $definition['default_variant'] ) ? (string) $definition['default_variant'] : 'control';
	$total   = isset( $definition['total_weight'] ) ? (int) $definition['total_weight'] : 0;

	if ( '' === $experiment_key || '' === $subject_key || $total <= 0 ) {
		return $default;
	}

	$bucket = extrachill_experiment_unbiased_bucket( $experiment_key . "\0" . $subject_key, $total );
	if ( null === $bucket ) {
		return $default;
	}

	return extrachill_experiment_variant_for_bucket( $definition, $bucket );
}

/**
 * Resolve the current request's explicit subject and privacy eligibility.
 *
 * @param string               $experiment_key Experiment key.
 * @param string               $surface        Registered surface.
 * @param array<string, mixed> $context        Consumer context.
 * @return string Eligible subject key, or an empty string.
 */
function extrachill_experiment_subject( $experiment_key, $surface, array $context ) {
	$user_id     = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$subject_key = $user_id > 0 ? 'wp-user:' . $user_id : '';
	$subject_key = (string) apply_filters(
		'extrachill_experiment_subject_key',
		$subject_key,
		$experiment_key,
		$surface,
		$context
	);
	$subject_key = trim( $subject_key );
	$eligible    = (bool) apply_filters(
		'extrachill_experiment_measurement_eligible',
		false,
		$experiment_key,
		$surface,
		$context
	);

	return '' !== $subject_key && strlen( $subject_key ) <= 256 && $eligible ? $subject_key : '';
}

/**
 * Canonicalize bounded scalar context for exposure signing.
 *
 * @param array<string, mixed> $context Consumer context.
 * @return string Canonical JSON.
 */
function extrachill_experiment_context_json( array $context ) {
	$bounded = array();
	foreach ( $context as $key => $value ) {
		if ( is_string( $key ) && is_scalar( $value ) && ! is_null( $value ) ) {
			$bounded[ $key ] = $value;
		}
	}
	ksort( $bounded, SORT_STRING );

	return (string) wp_json_encode( $bounded );
}

/**
 * Create a short-lived proof binding exposure to a server assignment.
 *
 * @param array<string, string> $metadata    Assignment metadata.
 * @param string                $subject_key Resolved subject key.
 * @param array<string, mixed>  $context     Consumer context.
 * @param int|null              $issued_at   Optional issue time for tests.
 * @param string|null           $nonce       Optional 128-bit hex nonce for tests.
 * @return string Signed exposure token.
 */
function extrachill_experiment_exposure_token( array $metadata, $subject_key, array $context, $issued_at = null, $nonce = null ) {
	$issued_at = null === $issued_at ? time() : (int) $issued_at;
	if ( null === $nonce ) {
		try {
			$nonce = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return '';
		}
	} else {
		$nonce = (string) $nonce;
	}
	if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $nonce ) ) {
		return '';
	}
	$message   = implode(
		"\0",
		array(
			$metadata['experiment_key'],
			(string) $metadata['definition_version'],
			$metadata['assignment_policy'],
			$metadata['variant'],
			$metadata['surface'],
			(string) $issued_at,
			$nonce,
			extrachill_experiment_context_json( $context ),
			$subject_key,
		)
	);
	$signature = hash_hmac( 'sha256', $message, wp_salt( 'auth' ) );

	return $issued_at . '.' . $nonce . '.' . $signature;
}

/**
 * Validate an exposure proof and return trusted bounded metadata.
 *
 * @param string               $experiment_key Experiment key.
 * @param int                  $definition_version Code definition version.
 * @param string               $assignment_policy Allocation policy.
 * @param string               $variant        Assigned variant.
 * @param string               $surface        Registered surface.
 * @param array<string, mixed> $context        Consumer context.
 * @param string               $token          Signed assignment proof.
 * @param int|null             $now            Optional current time for tests.
 * @return array<string, mixed>|null Trusted metadata, or null when invalid.
 */
function extrachill_validate_experiment_exposure( $experiment_key, $definition_version, $assignment_policy, $variant, $surface, array $context, $token, $now = null ) {
	if ( 1 !== preg_match( '/^(\d{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/', (string) $token, $matches ) ) {
		return null;
	}

	$now       = null === $now ? time() : (int) $now;
	$issued_at = (int) $matches[1];
	if ( $issued_at > $now + EXTRACHILL_EXPERIMENT_EXPOSURE_FUTURE_SKEW || $issued_at < $now - EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL ) {
		return null;
	}

	$definitions = extrachill_get_normalized_experiment_definitions();
	$definition  = isset( $definitions[ $experiment_key ] ) ? $definitions[ $experiment_key ] : null;
	if (
		null === $definition
		|| 'active' !== extrachill_get_experiment_state( $definition )
		|| $definition['definition_version'] !== $definition_version
		|| $definition['assignment_policy'] !== $assignment_policy
		|| ! in_array( $surface, $definition['surfaces'], true )
		|| ! isset( $definition['variants'][ $variant ] )
	) {
		return null;
	}

	try {
		$eligible = (bool) call_user_func( $definition['eligibility_callback'], $context, $surface );
	} catch ( \Throwable $error ) {
		$eligible = false;
	}
	$subject_key = $eligible ? extrachill_experiment_subject( $experiment_key, $surface, $context ) : '';
	if ( '' === $subject_key || extrachill_allocate_experiment_variant( $experiment_key, $definition, $subject_key ) !== $variant ) {
		return null;
	}

	$metadata = array(
		'experiment_key'     => (string) $experiment_key,
		'definition_version' => $definition['definition_version'],
		'assignment_policy'  => $definition['assignment_policy'],
		'variant'            => (string) $variant,
		'surface'            => (string) $surface,
	);
	$expected = extrachill_experiment_exposure_token( $metadata, $subject_key, $context, $issued_at, $matches[2] );

	return hash_equals( $expected, (string) $token ) ? $metadata : null;
}

/**
 * Atomically claim one validated exposure proof before emitting its hook.
 *
 * The persistent object-cache `add` operation is the compare-and-set boundary:
 * exactly one concurrent request can create a digest key. Installations without
 * a shared external object cache fail closed rather than claiming replay safety
 * that the request-local core cache cannot provide.
 *
 * @param string   $token Validated exposure token.
 * @param int|null $now   Optional current time for tests.
 * @return bool True only for the first consumer of this token.
 */
function extrachill_consume_experiment_exposure_token( $token, $now = null ) {
	if ( function_exists( 'wp_using_ext_object_cache' ) && ! wp_using_ext_object_cache() ) {
		return false;
	}
	if ( 1 !== preg_match( '/^(\d{10})\.[a-f0-9]{32}\.[a-f0-9]{64}$/', (string) $token, $matches ) ) {
		return false;
	}

	$now       = null === $now ? time() : (int) $now;
	$issued_at = (int) $matches[1];
	$ttl       = $issued_at + EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL - $now;
	if (
		$issued_at > $now + EXTRACHILL_EXPERIMENT_EXPOSURE_FUTURE_SKEW
		|| $ttl <= 0
		|| $ttl > EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL + EXTRACHILL_EXPERIMENT_EXPOSURE_FUTURE_SKEW
	) {
		return false;
	}

	$digest = hash( 'sha256', (string) $token );

	return wp_cache_add(
		'exposure_' . $digest,
		1,
		EXTRACHILL_EXPERIMENT_EXPOSURE_CACHE_GROUP,
		$ttl
	);
}

/**
 * Resolve an assignment while preserving consumer and privacy boundaries.
 *
 * @param string               $experiment_key Experiment key.
 * @param string               $surface        Bounded consumer surface.
 * @param array<string, mixed> $context        Consumer-owned eligibility context.
 * @return array<string, mixed> Neutral assignment metadata.
 */
function extrachill_resolve_experiment_assignment( $experiment_key, $surface, array $context = array() ) {
	$experiment_key = (string) $experiment_key;
	$surface        = (string) $surface;
	if (
		1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $experiment_key )
		|| 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $surface )
	) {
		return array(
			'experiment_key'       => $experiment_key,
			'definition_version'   => 0,
			'assignment_policy'    => '',
			'variant'              => '',
			'surface'              => $surface,
			'measurement_eligible' => false,
			'exposure_token'       => '',
		);
	}

	$definitions = extrachill_get_normalized_experiment_definitions();
	$result      = array(
		'experiment_key'       => $experiment_key,
		'definition_version'   => 0,
		'assignment_policy'    => '',
		'variant'              => '',
		'surface'              => $surface,
		'measurement_eligible' => false,
		'exposure_token'       => '',
	);
	$definition  = isset( $definitions[ $experiment_key ] ) ? $definitions[ $experiment_key ] : null;

	if ( null === $definition ) {
		return $result;
	}

	$result['definition_version'] = $definition['definition_version'];
	$result['assignment_policy']  = $definition['assignment_policy'];
	if ( 'active' !== extrachill_get_experiment_state( $definition ) ) {
		return $result;
	}

	$result['variant'] = $definition['default_variant'];
	if ( ! extrachill_experiment_is_active( $experiment_key, $surface, $context ) ) {
		return $result;
	}

	$subject_key = extrachill_experiment_subject( $experiment_key, $surface, $context );
	if ( '' === $subject_key ) {
		return $result;
	}

	$metadata = array(
		'experiment_key'     => $experiment_key,
		'definition_version' => $definition['definition_version'],
		'assignment_policy'  => $definition['assignment_policy'],
		'variant'            => extrachill_allocate_experiment_variant( $experiment_key, $definition, $subject_key ),
		'surface'            => $surface,
	);
	$token    = extrachill_experiment_exposure_token( $metadata, $subject_key, $context );
	if ( '' === $token ) {
		return $result;
	}

	$result['variant']              = $metadata['variant'];
	$result['measurement_eligible'] = true;
	$result['exposure_token']       = $token;
	/**
	 * Fires after a privacy-eligible assignment is resolved server-side.
	 *
	 * Analytics may consume this hook for persistence. The payload deliberately
	 * excludes subject identity and consumer context.
	 *
	 * @param array{experiment_key: string, definition_version: int, assignment_policy: string, variant: string, surface: string} $metadata Bounded assignment metadata.
	 */
	do_action( 'extrachill_experiment_assignment', $metadata );

	return $result;
}

/**
 * Register the cache-neutral browser assignment asset.
 */
function extrachill_register_experiment_assignment_script() {
	$path = EXTRACHILL_NETWORK_PLUGIN_DIR . 'assets/js/experiment-assignment.js';
	if ( ! file_exists( $path ) ) {
		return;
	}

	wp_register_script(
		'extrachill-experiment-assignment',
		EXTRACHILL_NETWORK_PLUGIN_URL . 'assets/js/experiment-assignment.js',
		array(),
		(string) filemtime( $path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
	wp_localize_script(
		'extrachill-experiment-assignment',
		'ecExperimentAssignment',
		array(
			'assignmentEndpoint' => rest_url( 'wp-abilities/v1/abilities/extrachill/resolve-experiment-assignment/run' ),
			'exposureEndpoint'   => rest_url( 'wp-abilities/v1/abilities/extrachill/record-experiment-exposure/run' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_register_experiment_assignment_script', 5 );

/**
 * Build cache-neutral data attributes for an experiment surface.
 *
 * The output deliberately contains only code/config values and the declared
 * control. It never embeds a subject or request-specific assignment.
 *
 * @param string               $experiment_key Experiment key.
 * @param string               $surface        Registered surface.
 * @param array<string, mixed> $context        Consumer eligibility context.
 * @return string Escaped HTML attributes, or an empty string for invalid config.
 */
function extrachill_experiment_attributes( $experiment_key, $surface, array $context = array() ) {
	if (
		1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', (string) $experiment_key )
		|| 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', (string) $surface )
	) {
		return '';
	}

	$definitions = extrachill_get_normalized_experiment_definitions();
	$definition  = isset( $definitions[ $experiment_key ] ) ? $definitions[ $experiment_key ] : null;

	if ( null === $definition || ! extrachill_experiment_is_active( $experiment_key, $surface, $context ) ) {
		return '';
	}

	wp_enqueue_script( 'extrachill-experiment-assignment' );

	return sprintf(
		'data-ec-experiment-key="%s" data-ec-experiment-surface="%s" data-ec-experiment-variant="%s" data-ec-experiment-context="%s"',
		esc_attr( $experiment_key ),
		esc_attr( $surface ),
		esc_attr( $definition['default_variant'] ),
		esc_attr( wp_json_encode( $context ) )
	);
}
