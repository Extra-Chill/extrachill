<?php
/**
 * Standalone tests for deterministic experiment assignment.
 *
 * @package ExtraChillNetwork
 */

declare( strict_types=1 );

// Standalone WordPress stubs intentionally mirror global APIs without full
// production docblocks or output escaping.
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'ABSPATH', __DIR__ . '/' );
define( 'EXTRACHILL_NETWORK_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'EXTRACHILL_NETWORK_PLUGIN_URL', 'https://example.com/wp-content/plugins/extrachill-network/' );

$GLOBALS['experiment_filters']                   = array();
$GLOBALS['experiment_user_id']                   = 0;
$GLOBALS['experiment_blog_id']                   = 1;
$GLOBALS['experiment_enqueued']                  = array();
$GLOBALS['experiment_abilities']                 = array();
$GLOBALS['experiment_actions']                   = array();
$GLOBALS['experiment_cache']                     = array();
$GLOBALS['experiment_cache_adds']                = array();
$GLOBALS['experiment_external_cache']            = true;
$GLOBALS['experiment_site_options']              = array();
$GLOBALS['experiment_can_admin']                 = false;
$GLOBALS['experiment_option_cache']              = array();
$GLOBALS['experiment_simulate_option_cache']     = false;
$GLOBALS['experiment_cache_deletes']             = array();
$GLOBALS['experiment_cache_set_success']         = true;
$GLOBALS['experiment_cache_delete_success']      = true;
$GLOBALS['experiment_post_write_cache_mismatch'] = false;
$GLOBALS['experiment_durable_read_failure']      = false;
$GLOBALS['experiment_durable_write_mismatch']    = false;
$GLOBALS['experiment_write_completed']           = false;

class ExperimentWpdb {
	public int $acquire_result = 1;
	public int $release_result = 1;
	public array $queries      = array();
	public $on_acquire         = null;
	public string $sitemeta    = 'wp_sitemeta';
	public string $last_error  = '';

	public function prepare( $query, ...$args ) {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	public function get_var( $prepared ) {
		$this->queries[] = $prepared;
		if ( false !== strpos( $prepared['query'], 'GET_LOCK' ) ) {
			if ( is_callable( $this->on_acquire ) ) {
				$callback         = $this->on_acquire;
				$this->on_acquire = null;
				$callback();
			}
			return $this->acquire_result;
		}

		return $this->release_result;
	}

	public function get_row( $prepared ) {
		$this->queries[] = $prepared;
		if ( $GLOBALS['experiment_durable_read_failure'] ) {
			$this->last_error = 'simulated durable read failure';
			return null;
		}
		$this->last_error = '';
		$name             = $prepared['args'][1];
		if ( ! array_key_exists( $name, $GLOBALS['experiment_site_options'] ) ) {
			return null;
		}

		return (object) array( 'meta_value' => maybe_serialize( $GLOBALS['experiment_site_options'][ $name ] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fixture mirrors the exact sitemeta row shape.
	}
}

$GLOBALS['wpdb'] = new ExperimentWpdb();

class WP_Error {
	public function __construct( public string $code ) {}
}

function add_action() {}
function wp_register_script() {}
function wp_localize_script() {}
function __( $value ) {
	return $value;
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['experiment_abilities'][ $name ] = $args;
}
function get_site_option( $name, $fallback = false ) {
	if ( $GLOBALS['experiment_simulate_option_cache'] ) {
		$cache_key = get_current_network_id() . ':' . $name;
		if ( array_key_exists( $cache_key, $GLOBALS['experiment_option_cache'] ) ) {
			return $GLOBALS['experiment_option_cache'][ $cache_key ];
		}
		$value = array_key_exists( $name, $GLOBALS['experiment_site_options'] ) ? $GLOBALS['experiment_site_options'][ $name ] : $fallback;
		$GLOBALS['experiment_option_cache'][ $cache_key ] = $value;
		return $value;
	}
	return array_key_exists( $name, $GLOBALS['experiment_site_options'] ) ? $GLOBALS['experiment_site_options'][ $name ] : $fallback;
}
function update_site_option( $name, $value ) {
	$GLOBALS['experiment_site_options'][ $name ] = $GLOBALS['experiment_durable_write_mismatch'] ? array( 'mismatch' => true ) : $value;
	if ( $GLOBALS['experiment_simulate_option_cache'] ) {
		$GLOBALS['experiment_option_cache'][ get_current_network_id() . ':' . $name ] = $value;
	}
	$GLOBALS['experiment_write_completed'] = true;
	return true;
}
function maybe_serialize( $value ) {
	return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fixture mirrors Core option serialization.
}
function maybe_unserialize( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^[aObisCdN]:/', $value ) ) {
		return $value;
	}

	return unserialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Fixture input is locally serialized test data.
}
function current_user_can( $capability ) {
	return 'manage_network_options' === $capability && $GLOBALS['experiment_can_admin'];
}
function get_current_network_id() {
	return 7;
}
function wp_salt() {
	return 'fixed-experiment-test-salt';
}
function do_action( $name, ...$args ) {
	$GLOBALS['experiment_actions'][] = array( $name, $args );
}
function wp_using_ext_object_cache() {
	return $GLOBALS['experiment_external_cache'];
}
function wp_cache_add_global_groups() {}
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	if ( 'site-options' !== $group ) {
		$found = false;
		return false;
	}

	$found = array_key_exists( $key, $GLOBALS['experiment_option_cache'] );
	return $found ? $GLOBALS['experiment_option_cache'][ $key ] : false;
}
function wp_cache_set( $key, $value, $group = '' ) {
	if ( ! $GLOBALS['experiment_cache_set_success'] ) {
		return false;
	}
	if ( 'site-options' === $group ) {
		$GLOBALS['experiment_option_cache'][ $key ] = $GLOBALS['experiment_post_write_cache_mismatch'] && $GLOBALS['experiment_write_completed'] && false !== strpos( $key, ':' . EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION )
			? array( 'mismatch' => true )
			: $value;
	}
	return true;
}
function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['experiment_cache_deletes'][] = array( $key, $group );
	if ( ! $GLOBALS['experiment_cache_delete_success'] ) {
		return false;
	}
	if ( 'site-options' === $group ) {
		unset( $GLOBALS['experiment_option_cache'][ $key ] );
	}
	return true;
}
function wp_cache_add( $key, $value, $group, $ttl ) {
	$cache_key                          = $group . ':' . $key;
	$GLOBALS['experiment_cache_adds'][] = array(
		'key'   => $key,
		'group' => $group,
		'ttl'   => $ttl,
	);
	if ( array_key_exists( $cache_key, $GLOBALS['experiment_cache'] ) ) {
		return false;
	}

	$GLOBALS['experiment_cache'][ $cache_key ] = $value;
	return true;
}
function rest_url( $path ) {
	return 'https://example.com/wp-json/' . $path;
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}
function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}
function wp_enqueue_script( $handle ) {
	$GLOBALS['experiment_enqueued'][] = $handle;
}
function get_current_user_id() {
	return $GLOBALS['experiment_user_id'];
}
function get_current_blog_id() {
	return $GLOBALS['experiment_blog_id'];
}
function apply_filters( $name, $value, ...$args ) {
	if ( empty( $GLOBALS['experiment_filters'][ $name ] ) ) {
		return $value;
	}

	foreach ( $GLOBALS['experiment_filters'][ $name ] as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

require dirname( __DIR__ ) . '/inc/core/experiments.php';
require dirname( __DIR__ ) . '/inc/Abilities/ExperimentAssignmentAbility.php';

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local cross-plugin contract fixture.
$contract_fixture = json_decode( file_get_contents( __DIR__ . '/experiment-contract.fixture.json' ), true );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Coordinated Blog contract fixture.
$blog_contract_fixture = json_decode( file_get_contents( __DIR__ . '/blog-experiment-contract.fixture.json' ), true );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Coordinated Analytics contract fixture.
$analytics_contract_fixture = json_decode( file_get_contents( __DIR__ . '/analytics-experiment-contract.fixture.json' ), true );

$failures = 0;
function experiment_check( string $label, bool $condition ): void {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	echo "FAIL: {$label}\n";
	++$failures;
}

function experiment_consumer_behavior( array $assignment ): string {
	return '' === $assignment['variant'] ? 'normal-feature' : $assignment['variant'];
}

function experiment_definition( bool $eligible = true ): array {
	return array(
		'key'                  => 'geo-bridge-holdout',
		'definition_version'   => 1,
		'assignment_policy'    => 'weighted_random',
		'default_state'        => 'active',
		'default_variant'      => 'control',
		'control_variant'      => 'control',
		'variants'             => array(
			'control'   => 2,
			'treatment' => 3,
		),
		'surfaces'             => array( 'single-post-bridge' ),
		'eligibility_callback' => static function () use ( $eligible ): bool {
			return $eligible;
		},
	);
}

function experiment_definitions( int $count ): array {
	$definitions = array();
	for ( $index = 0; $index < $count; ++$index ) {
		$key                 = 'bounded-experiment-' . $index;
		$definition          = experiment_definition();
		$definition['key']   = $key;
		$definitions[ $key ] = $definition;
	}

	return $definitions;
}

$normalized = extrachill_normalize_experiment_definition( experiment_definition() );
experiment_check( 'valid definitions normalize', is_array( $normalized ) );
$blog_fixture_definition                         = $blog_contract_fixture['definition'];
$blog_fixture_definition['eligibility_callback'] = static function (): bool {
	return true;
};
$normalized_blog_fixture                         = extrachill_normalize_experiment_definition( $blog_fixture_definition, 'geo-bridge-holdout' );
experiment_check( 'Blog cross-contract fixture locks exact normalized definition', is_array( $normalized_blog_fixture ) && $blog_contract_fixture['definition']['key'] === $normalized_blog_fixture['key'] && $blog_contract_fixture['definition']['definition_version'] === $normalized_blog_fixture['definition_version'] && $blog_contract_fixture['definition']['assignment_policy'] === $normalized_blog_fixture['assignment_policy'] && $blog_contract_fixture['definition']['default_state'] === $normalized_blog_fixture['default_state'] && $blog_contract_fixture['definition']['default_variant'] === $normalized_blog_fixture['default_variant'] && $blog_contract_fixture['definition']['control_variant'] === $normalized_blog_fixture['control_variant'] && $blog_contract_fixture['definition']['variants'] === $normalized_blog_fixture['variants'] && $blog_contract_fixture['definition']['surfaces'] === $normalized_blog_fixture['surfaces'] );
experiment_check( 'Blog cross-contract fixture locks eligibility callback owner', 'extrachill_blog_geographic_bridge_experiment_eligible' === $blog_contract_fixture['definition']['eligibility_callback'] );
experiment_check( 'Blog cross-contract fixture locks non-active abstention', array( 'inactive', 'paused', 'completed' ) === $blog_contract_fixture['non_active_contract']['states'] && '' === $blog_contract_fixture['non_active_contract']['assigned_variant'] && true === $blog_contract_fixture['non_active_contract']['normal_geography'] );
experiment_check( 'Analytics cross-contract fixture locks version bound', EXTRACHILL_EXPERIMENT_MAX_DEFINITION_VERSION === $analytics_contract_fixture['definition_version']['maximum'] );
experiment_check( 'Analytics cross-contract fixture locks trusted metadata order', $contract_fixture['metadata_keys'] === $analytics_contract_fixture['metadata_keys'] );
experiment_check( 'Analytics cross-contract fixture locks trusted action names', $contract_fixture['server_actions'] === $analytics_contract_fixture['server_actions'] );
experiment_check( 'Analytics cross-contract fixture locks assignment policy', EXTRACHILL_EXPERIMENT_ASSIGNMENT_POLICY === $analytics_contract_fixture['assignment_policy'] );
experiment_check( 'first control bucket is control', 'control' === extrachill_experiment_variant_for_bucket( $normalized, 0 ) );
experiment_check( 'last control bucket is control', 'control' === extrachill_experiment_variant_for_bucket( $normalized, 1 ) );
experiment_check( 'first treatment bucket is treatment', 'treatment' === extrachill_experiment_variant_for_bucket( $normalized, 2 ) );
experiment_check( 'last treatment bucket is treatment', 'treatment' === extrachill_experiment_variant_for_bucket( $normalized, 4 ) );
experiment_check( 'out-of-range buckets fail to control', 'control' === extrachill_experiment_variant_for_bucket( $normalized, 5 ) );

$invalid                          = experiment_definition();
$invalid['variants']['treatment'] = 0;
experiment_check( 'zero weights invalidate configuration', null === extrachill_normalize_experiment_definition( $invalid ) );
$invalid                          = experiment_definition();
$invalid['variants']['treatment'] = '3';
experiment_check( 'non-integer weights invalidate configuration', null === extrachill_normalize_experiment_definition( $invalid ) );
$invalid                    = experiment_definition();
$invalid['control_variant'] = 'treatment';
experiment_check( 'default must explicitly equal control', null === extrachill_normalize_experiment_definition( $invalid ) );

$maximum             = experiment_definition();
$maximum['variants'] = array(
	'control'   => EXTRACHILL_EXPERIMENT_MAX_VARIANT_WEIGHT,
	'treatment' => EXTRACHILL_EXPERIMENT_MAX_VARIANT_WEIGHT,
);
$maximum_normalized  = extrachill_normalize_experiment_definition( $maximum );
experiment_check( 'documented maximum variant weights are accepted', is_array( $maximum_normalized ) );
experiment_check( 'documented maximum total is accepted', EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT === $maximum_normalized['total_weight'] );
$overflow             = experiment_definition();
$overflow['variants'] = array(
	'control'   => 400000,
	'treatment' => 400000,
	'holdout'   => 200001,
);
experiment_check( 'total above the maximum is rejected before addition', null === extrachill_normalize_experiment_definition( $overflow ) );
$oversized                          = experiment_definition();
$oversized['variants']['treatment'] = EXTRACHILL_EXPERIMENT_MAX_VARIANT_WEIGHT + 1;
experiment_check( 'individual weight above the maximum is rejected', null === extrachill_normalize_experiment_definition( $oversized ) );
$too_many_variants                    = experiment_definition();
$too_many_variants['variants']        = array_fill_keys(
	array_map(
		static function ( $index ): string {
			return 'variant-' . $index;
		},
		range( 0, EXTRACHILL_EXPERIMENT_MAX_VARIANTS )
	),
	1
);
$too_many_variants['default_variant'] = 'variant-0';
$too_many_variants['control_variant'] = 'variant-0';
experiment_check( 'definitions over the variant cap are rejected', null === extrachill_normalize_experiment_definition( $too_many_variants ) );
$too_many_surfaces             = experiment_definition();
$too_many_surfaces['surfaces'] = array_map(
	static function ( $index ): string {
		return 'surface-' . $index;
	},
	range( 0, EXTRACHILL_EXPERIMENT_MAX_SURFACES )
);
experiment_check( 'definitions over the surface cap are rejected', null === extrachill_normalize_experiment_definition( $too_many_surfaces ) );
experiment_check( '28-bit draw maximum fits signed 32-bit PHP', EXTRACHILL_EXPERIMENT_DRAW_RANGE - 1 <= 2147483647 );
experiment_check( 'zero allocation total is rejected', null === extrachill_experiment_unbiased_bucket( 'seed', 0 ) );
experiment_check( 'allocation total above maximum is rejected', null === extrachill_experiment_unbiased_bucket( 'seed', EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT + 1 ) );
experiment_check( 'maximum accepted 28-bit draw reaches last bucket', 999999 === extrachill_experiment_reduce_draw( 267999999, EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT ) );
experiment_check( 'first incomplete-tail draw is rejected', null === extrachill_experiment_reduce_draw( 268000000, EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT ) );
experiment_check( 'maximum 28-bit draw is rejected for million range', null === extrachill_experiment_reduce_draw( EXTRACHILL_EXPERIMENT_DRAW_RANGE - 1, EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT ) );
experiment_check( 'rejection sampling skips the incomplete 28-bit tail', 429057 === extrachill_experiment_unbiased_bucket( 'rejection-355', EXTRACHILL_EXPERIMENT_MAX_TOTAL_WEIGHT ) );

$golden_vectors = array(
	'vector-0' => array( 3, 'treatment' ),
	'vector-1' => array( 1, 'control' ),
	'vector-3' => array( 2, 'treatment' ),
	'vector-5' => array( 4, 'treatment' ),
	'vector-9' => array( 0, 'control' ),
);
foreach ( $golden_vectors as $subject => $expected ) {
	experiment_check(
		"golden 28-bit bucket vector {$subject}",
		extrachill_experiment_unbiased_bucket( 'geo-bridge-holdout' . "\0" . $subject, 5 ) === $expected[0]
	);
	experiment_check(
		"golden 28-bit variant vector {$subject}",
		extrachill_allocate_experiment_variant( 'geo-bridge-holdout', $normalized, $subject ) === $expected[1]
	);
}

$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function (): array {
		return experiment_definitions( EXTRACHILL_EXPERIMENT_MAX_DEFINITIONS );
	},
);
experiment_check( 'registered definition cap is accepted exactly', EXTRACHILL_EXPERIMENT_MAX_DEFINITIONS === count( extrachill_get_normalized_experiment_definitions() ) );
$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function (): array {
		return experiment_definitions( EXTRACHILL_EXPERIMENT_MAX_DEFINITIONS + 1 );
	},
);
experiment_check( 'over-bound registered definition registry fails closed', array() === extrachill_get_normalized_experiment_definitions() );

$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function (): array {
		return array( 'geo-bridge-holdout' => experiment_definition() );
	},
);

$missing_subject = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'missing subject fails to control', 'control' === $missing_subject['variant'] );
experiment_check( 'missing subject remains unmeasured', false === $missing_subject['measurement_eligible'] );

$GLOBALS['experiment_filters']['extrachill_experiment_subject_key'] = array(
	static function (): string {
		return 'visitor-123';
	},
);
$privacy_excluded = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'privacy exclusion fails to control', 'control' === $privacy_excluded['variant'] );
experiment_check( 'privacy exclusion remains unmeasured', false === $privacy_excluded['measurement_eligible'] );

$GLOBALS['experiment_filters']['extrachill_experiment_measurement_eligible'] = array(
	static function (): bool {
		return true;
	},
);
$GLOBALS['experiment_actions'] = array();
$assigned                      = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'provider-approved subject receives an assignment', true === $assigned['measurement_eligible'] );
experiment_check( 'assignment stays within registered variants', in_array( $assigned['variant'], array( 'control', 'treatment' ), true ) );
experiment_check( 'assignment returns a nonce-bearing opaque exposure proof', 1 === preg_match( '/^\d{10}\.[a-f0-9]{32}\.[a-f0-9]{64}$/', $assigned['exposure_token'] ) );
experiment_check( 'trusted server assignment hook fires once', 1 === count( $GLOBALS['experiment_actions'] ) && 'extrachill_experiment_assignment' === $GLOBALS['experiment_actions'][0][0] );
experiment_check( 'assignment hook contains only bounded metadata', 'experiment_key,definition_version,assignment_policy,variant,surface' === implode( ',', array_keys( $GLOBALS['experiment_actions'][0][1][0] ) ) );
experiment_check( 'cross-contract fixture locks assignment hook name', $contract_fixture['server_actions']['assignment'] === $GLOBALS['experiment_actions'][0][0] );
experiment_check( 'cross-contract fixture locks one metadata argument', 1 === $contract_fixture['server_actions']['accepted_args'] && 1 === count( $GLOBALS['experiment_actions'][0][1] ) );
experiment_check( 'cross-contract fixture locks metadata keys', array_keys( $GLOBALS['experiment_actions'][0][1][0] ) === $contract_fixture['metadata_keys'] );

$exposure = extrachill_validate_experiment_exposure(
	'geo-bridge-holdout',
	1,
	'weighted_random',
	$assigned['variant'],
	'single-post-bridge',
	array(),
	$assigned['exposure_token']
);
experiment_check( 'matching signed exposure validates', is_array( $exposure ) );
experiment_check(
	'tampered definition version is rejected',
	null === extrachill_validate_experiment_exposure(
		'geo-bridge-holdout',
		2,
		'weighted_random',
		$assigned['variant'],
		'single-post-bridge',
		array(),
		$assigned['exposure_token']
	)
);
experiment_check(
	'tampered assignment policy is rejected',
	null === extrachill_validate_experiment_exposure(
		'geo-bridge-holdout',
		1,
		'other_policy',
		$assigned['variant'],
		'single-post-bridge',
		array(),
		$assigned['exposure_token']
	)
);
experiment_check(
	'tampered variant is rejected',
	null === extrachill_validate_experiment_exposure(
		'geo-bridge-holdout',
		1,
		'weighted_random',
		'control' === $assigned['variant'] ? 'treatment' : 'control',
		'single-post-bridge',
		array(),
		$assigned['exposure_token']
	)
);
experiment_check(
	'tampered context is rejected',
	null === extrachill_validate_experiment_exposure(
		'geo-bridge-holdout',
		1,
		'weighted_random',
		$assigned['variant'],
		'single-post-bridge',
		array( 'post_id' => '99' ),
		$assigned['exposure_token']
	)
);
$expired_metadata = array(
	'experiment_key'     => 'geo-bridge-holdout',
	'definition_version' => 1,
	'assignment_policy'  => 'weighted_random',
	'variant'            => $assigned['variant'],
	'surface'            => 'single-post-bridge',
);
$expired_token    = extrachill_experiment_exposure_token( $expired_metadata, 'visitor-123', array(), 1700000000, str_repeat( '1', 32 ) );
experiment_check(
	'expired exposure proof is rejected',
	null === extrachill_validate_experiment_exposure(
		'geo-bridge-holdout',
		1,
		'weighted_random',
		$assigned['variant'],
		'single-post-bridge',
		array(),
		$expired_token,
		1700000000 + EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL + 1
	)
);

$same_second_token_one = extrachill_experiment_exposure_token( $expired_metadata, 'visitor-123', array(), 1700000100 );
$same_second_token_two = extrachill_experiment_exposure_token( $expired_metadata, 'visitor-123', array(), 1700000100 );
experiment_check( 'concurrent same-second assignments receive unique random nonces', $same_second_token_one !== $same_second_token_two );
experiment_check(
	'first same-second nonce token validates',
	is_array(
		extrachill_validate_experiment_exposure(
			'geo-bridge-holdout',
			1,
			'weighted_random',
			$assigned['variant'],
			'single-post-bridge',
			array(),
			$same_second_token_one,
			1700000100
		)
	)
);
experiment_check(
	'second same-second nonce token validates independently',
	is_array(
		extrachill_validate_experiment_exposure(
			'geo-bridge-holdout',
			1,
			'weighted_random',
			$assigned['variant'],
			'single-post-bridge',
			array(),
			$same_second_token_two,
			1700000100
		)
	)
);

$future_now                       = 1700000200;
$future_issued                    = $future_now + EXTRACHILL_EXPERIMENT_EXPOSURE_FUTURE_SKEW;
$future_token                     = extrachill_experiment_exposure_token( $expired_metadata, 'visitor-123', array(), $future_issued, str_repeat( '2', 32 ) );
$GLOBALS['experiment_cache']      = array();
$GLOBALS['experiment_cache_adds'] = array();
experiment_check(
	'future-skew token validates at acceptance boundary',
	is_array(
		extrachill_validate_experiment_exposure(
			'geo-bridge-holdout',
			1,
			'weighted_random',
			$assigned['variant'],
			'single-post-bridge',
			array(),
			$future_token,
			$future_now
		)
	)
);
experiment_check( 'future-skew token is atomically consumed', extrachill_consume_experiment_exposure_token( $future_token, $future_now ) );
experiment_check(
	'future-skew marker spans complete remaining validity',
	EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL + EXTRACHILL_EXPERIMENT_EXPOSURE_FUTURE_SKEW === $GLOBALS['experiment_cache_adds'][0]['ttl']
);

$race_issued                      = time() - 1;
$race_token                       = extrachill_experiment_exposure_token( $expired_metadata, 'visitor-123', array(), $race_issued, str_repeat( '3', 32 ) );
$race_first_validation            = extrachill_validate_experiment_exposure(
	'geo-bridge-holdout',
	1,
	'weighted_random',
	$assigned['variant'],
	'single-post-bridge',
	array(),
	$race_token,
	$race_issued + 1
);
$race_second_validation           = extrachill_validate_experiment_exposure(
	'geo-bridge-holdout',
	1,
	'weighted_random',
	$assigned['variant'],
	'single-post-bridge',
	array(),
	$race_token,
	$race_issued + 1
);
$GLOBALS['experiment_cache']      = array();
$GLOBALS['experiment_cache_adds'] = array();
experiment_check( 'concurrent requests can both finish stateless validation', is_array( $race_first_validation ) && is_array( $race_second_validation ) );
experiment_check( 'first concurrent request atomically consumes token', extrachill_consume_experiment_exposure_token( $race_token, $race_issued + 1 ) );
experiment_check( 'second concurrent request loses atomic cache race', ! extrachill_consume_experiment_exposure_token( $race_token, $race_issued + 1 ) );
experiment_check( 'atomic claim uses the network exposure cache group', EXTRACHILL_EXPERIMENT_EXPOSURE_CACHE_GROUP === $GLOBALS['experiment_cache_adds'][0]['group'] );
experiment_check( 'atomic claim expires within token TTL', $GLOBALS['experiment_cache_adds'][0]['ttl'] > 0 && $GLOBALS['experiment_cache_adds'][0]['ttl'] <= EXTRACHILL_EXPERIMENT_EXPOSURE_TOKEN_TTL );

$first_blog_variant            = $assigned['variant'];
$GLOBALS['experiment_blog_id'] = 9;
$second_blog_assignment        = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'assignment is stable across multisite blogs', $first_blog_variant === $second_blog_assignment['variant'] );
experiment_check(
	'explicit subject allocation is deterministic',
	extrachill_allocate_experiment_variant( 'geo-bridge-holdout', $normalized, 'visitor-123' )
		=== extrachill_allocate_experiment_variant( 'geo-bridge-holdout', $normalized, 'visitor-123' )
);

$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function (): array {
		return array( 'geo-bridge-holdout' => experiment_definition( false ) );
	},
);
$denied = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'consumer denial cannot be escalated by assignment', 'control' === $denied['variant'] );
experiment_check( 'consumer denial remains unmeasured', false === $denied['measurement_eligible'] );
$GLOBALS['experiment_enqueued'] = array();
experiment_check( 'consumer denial emits no experiment attributes', '' === extrachill_experiment_attributes( 'geo-bridge-holdout', 'single-post-bridge' ) );
experiment_check( 'consumer denial enqueues no client asset', array() === $GLOBALS['experiment_enqueued'] );

$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function (): array {
		return array( 'geo-bridge-holdout' => experiment_definition() );
	},
);
$markup = extrachill_experiment_attributes(
	'geo-bridge-holdout',
	'single-post-bridge',
	array( 'post_id' => 42 )
);
experiment_check( 'markup contains the stable experiment key', false !== strpos( $markup, 'data-ec-experiment-key="geo-bridge-holdout"' ) );
experiment_check( 'markup contains only the declared control', false !== strpos( $markup, 'data-ec-experiment-variant="control"' ) );
experiment_check( 'markup contains no subject identity', false === strpos( $markup, 'visitor-123' ) );
experiment_check( 'markup enqueues the browser assignment path', in_array( 'extrachill-experiment-assignment', $GLOBALS['experiment_enqueued'], true ) );

$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function () use ( $invalid ): array {
		return array( 'broken' => $invalid );
	},
);
$broken = extrachill_resolve_experiment_assignment( 'broken', 'single-post-bridge' );
experiment_check( 'invalid configuration produces no assignment', '' === $broken['variant'] );
experiment_check( 'invalid configuration remains unmeasured', false === $broken['measurement_eligible'] );

$ability = new \ExtraChillNetwork\Abilities\ExperimentAssignmentAbility();
$ability->register();
$ability_args = $GLOBALS['experiment_abilities']['extrachill/resolve-experiment-assignment'];
experiment_check( 'assignment uses the public core Abilities API', true === $ability_args['meta']['show_in_rest'] );
experiment_check( 'assignment ability is read-only', true === $ability_args['meta']['annotations']['readonly'] );
experiment_check( 'ability context is bounded to ten scalar properties', 10 === $ability_args['input_schema']['properties']['context']['maxProperties'] );
$exposure_args = $GLOBALS['experiment_abilities']['extrachill/record-experiment-exposure'];
experiment_check( 'exposure uses the existing core Abilities route', true === $exposure_args['meta']['show_in_rest'] );
experiment_check( 'exposure ability requires a signed token', in_array( 'exposure_token', $exposure_args['input_schema']['required'], true ) );
$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function (): array {
		return array( 'geo-bridge-holdout' => experiment_definition() );
	},
);
$GLOBALS['experiment_actions']                                      = array();
$GLOBALS['experiment_cache']                                        = array();
$GLOBALS['experiment_cache_adds']                                   = array();
$exposure_result = $ability->execute_exposure(
	array(
		'experiment_key'     => 'geo-bridge-holdout',
		'definition_version' => 1,
		'assignment_policy'  => 'weighted_random',
		'variant'            => $assigned['variant'],
		'surface'            => 'single-post-bridge',
		'exposure_token'     => $assigned['exposure_token'],
	)
);
experiment_check( 'valid exposure ability call is accepted', true === $exposure_result['accepted'] );
experiment_check( 'first exposure use creates one atomic digest claim', 1 === count( $GLOBALS['experiment_cache_adds'] ) && 0 === strpos( $GLOBALS['experiment_cache_adds'][0]['key'], 'exposure_' ) );
experiment_check( 'valid exposure emits one trusted server hook', 1 === count( $GLOBALS['experiment_actions'] ) && 'extrachill_experiment_exposure' === $GLOBALS['experiment_actions'][0][0] );
experiment_check( 'cross-contract fixture locks exposure hook name', $contract_fixture['server_actions']['exposure'] === $GLOBALS['experiment_actions'][0][0] );
experiment_check( 'exposure hook receives one bounded metadata array', 1 === count( $GLOBALS['experiment_actions'][0][1] ) && array_keys( $GLOBALS['experiment_actions'][0][1][0] ) === $contract_fixture['metadata_keys'] );
$replayed_exposure = $ability->execute_exposure(
	array(
		'experiment_key'     => 'geo-bridge-holdout',
		'definition_version' => 1,
		'assignment_policy'  => 'weighted_random',
		'variant'            => $assigned['variant'],
		'surface'            => 'single-post-bridge',
		'exposure_token'     => $assigned['exposure_token'],
	)
);
experiment_check( 'replayed valid exposure token is rejected', $replayed_exposure instanceof WP_Error && 'experiment_exposure_already_consumed' === $replayed_exposure->code );
experiment_check( 'replayed exposure emits no duplicate hook', 1 === count( $GLOBALS['experiment_actions'] ) );
$invalid_exposure = $ability->execute_exposure(
	array(
		'experiment_key'     => 'geo-bridge-holdout',
		'definition_version' => 1,
		'assignment_policy'  => 'weighted_random',
		'variant'            => $assigned['variant'],
		'surface'            => 'single-post-bridge',
		'exposure_token'     => '1700000000.' . str_repeat( '0', 32 ) . '.' . str_repeat( '0', 64 ),
	)
);
experiment_check( 'forged exposure is rejected server-side', $invalid_exposure instanceof WP_Error );
experiment_check( 'forged exposure emits no server hook', 1 === count( $GLOBALS['experiment_actions'] ) );

$missing_key = experiment_definition();
unset( $missing_key['key'] );
experiment_check( 'new definitions require an explicit stable key', null === extrachill_normalize_experiment_definition( $missing_key, 'geo-bridge-holdout' ) );
$mismatched_key        = experiment_definition();
$mismatched_key['key'] = 'other-experiment';
experiment_check( 'declared key must match its registration key', null === extrachill_normalize_experiment_definition( $mismatched_key, 'geo-bridge-holdout' ) );
$zero_version                       = experiment_definition();
$zero_version['definition_version'] = 0;
experiment_check( 'definition version must be a positive integer', null === extrachill_normalize_experiment_definition( $zero_version, 'geo-bridge-holdout' ) );
$oversized_version                       = experiment_definition();
$oversized_version['definition_version'] = EXTRACHILL_EXPERIMENT_MAX_DEFINITION_VERSION + 1;
experiment_check( 'definition version must fit Analytics contract', null === extrachill_normalize_experiment_definition( $oversized_version, 'geo-bridge-holdout' ) );
$invalid_policy                      = experiment_definition();
$invalid_policy['assignment_policy'] = 'deterministic';
experiment_check( 'unknown assignment policies fail closed', null === extrachill_normalize_experiment_definition( $invalid_policy, 'geo-bridge-holdout' ) );
$missing_default_state = experiment_definition();
unset( $missing_default_state['default_state'] );
experiment_check( 'new definitions require a reviewed default state', null === extrachill_normalize_experiment_definition( $missing_default_state, 'geo-bridge-holdout' ) );

$legacy = experiment_definition();
unset( $legacy['key'], $legacy['definition_version'], $legacy['assignment_policy'], $legacy['default_state'] );
$legacy_normalized = extrachill_normalize_experiment_definition( $legacy, 'geo-bridge-holdout' );
experiment_check( 'legacy keyed definitions migrate as version one', 1 === $legacy_normalized['definition_version'] );
experiment_check( 'legacy keyed definitions preserve active behavior', 'active' === $legacy_normalized['default_state'] );
experiment_check( 'legacy keyed definitions migrate to weighted random', 'weighted_random' === $legacy_normalized['assignment_policy'] );

$lifecycle_definition                  = experiment_definition();
$lifecycle_definition['default_state'] = 'inactive';
$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function () use ( &$lifecycle_definition ): array {
		return array( 'geo-bridge-holdout' => $lifecycle_definition );
	},
);
$GLOBALS['experiment_site_options']                                 = array();
$GLOBALS['experiment_actions']                                      = array();
$GLOBALS['experiment_enqueued']                                     = array();
$inactive_assignment = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'missing live state uses reviewed inactive default', false === $inactive_assignment['measurement_eligible'] );
experiment_check( 'geo bridge holdout is inactive by code default', 'inactive' === $lifecycle_definition['default_state'] );
experiment_check( 'inactive default requires explicit operator activation', ! isset( $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] ) );
experiment_check( 'inactive experiment returns no forced treatment', '' === $inactive_assignment['variant'] );
experiment_check( 'inactive lifecycle preserves normal consumer behavior', 'normal-feature' === experiment_consumer_behavior( $inactive_assignment ) );
experiment_check( 'inactive experiment helper fails closed', ! extrachill_experiment_is_active( 'geo-bridge-holdout', 'single-post-bridge' ) );
experiment_check( 'inactive experiment emits no attributes', '' === extrachill_experiment_attributes( 'geo-bridge-holdout', 'single-post-bridge' ) );
experiment_check( 'inactive experiment enqueues no client asset', array() === $GLOBALS['experiment_enqueued'] );
experiment_check( 'inactive experiment emits no trusted hook', array() === $GLOBALS['experiment_actions'] );

$transition = extrachill_transition_experiment_state( 'geo-bridge-holdout', 1, 'active' );
experiment_check( 'inactive transitions to active', is_array( $transition ) && 'inactive' === $transition['previous_state'] && 'active' === $transition['state'] );
experiment_check( 'state transition emits one bounded audit action', 1 === count( $GLOBALS['experiment_actions'] ) && 'extrachill_experiment_state_changed' === $GLOBALS['experiment_actions'][0][0] && array( 'experiment_key', 'definition_version', 'previous_state', 'state' ) === array_keys( $GLOBALS['experiment_actions'][0][1][0] ) );
experiment_check( 'state option contains only version and state', array( 'definition_version', 'state' ) === array_keys( $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ]['geo-bridge-holdout'] ) );
experiment_check( 'active helper composes consumer eligibility', extrachill_experiment_is_active( 'geo-bridge-holdout', 'single-post-bridge' ) );
$active_assignment = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'active lifecycle resolves measured assignment', true === $active_assignment['measurement_eligible'] );
experiment_check( 'active response includes definition version', 1 === $active_assignment['definition_version'] );
experiment_check( 'active response includes assignment policy', 'weighted_random' === $active_assignment['assignment_policy'] );

$paused = extrachill_transition_experiment_state( 'geo-bridge-holdout', 1, 'paused' );
experiment_check( 'active transitions to paused', is_array( $paused ) && 'paused' === $paused['state'] );
experiment_check( 'paused lifecycle rejects a previously issued exposure proof', null === extrachill_validate_experiment_exposure( 'geo-bridge-holdout', 1, 'weighted_random', $active_assignment['variant'], 'single-post-bridge', array(), $active_assignment['exposure_token'] ) );
$paused_assignment = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'paused experiment returns no forced treatment', '' === $paused_assignment['variant'] );
experiment_check( 'paused lifecycle preserves normal consumer behavior', 'normal-feature' === experiment_consumer_behavior( $paused_assignment ) );
$GLOBALS['experiment_actions']  = array();
$GLOBALS['experiment_enqueued'] = array();
experiment_check( 'paused experiment emits no attributes', '' === extrachill_experiment_attributes( 'geo-bridge-holdout', 'single-post-bridge' ) );
experiment_check( 'paused experiment enqueues no assets or hooks', array() === $GLOBALS['experiment_enqueued'] && array() === $GLOBALS['experiment_actions'] );
$resumed = extrachill_transition_experiment_state( 'geo-bridge-holdout', 1, 'active' );
experiment_check( 'paused transitions back to active', is_array( $resumed ) && 'active' === $resumed['state'] );
$completed = extrachill_transition_experiment_state( 'geo-bridge-holdout', 1, 'completed' );
experiment_check( 'active transitions to completed', is_array( $completed ) && 'completed' === $completed['state'] );
$completed_assignment = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
experiment_check( 'completed experiment returns no forced treatment', '' === $completed_assignment['variant'] );
experiment_check( 'completed lifecycle preserves normal consumer behavior', 'normal-feature' === experiment_consumer_behavior( $completed_assignment ) );
$GLOBALS['experiment_actions']  = array();
$GLOBALS['experiment_enqueued'] = array();
experiment_check( 'completed experiment emits no attributes', '' === extrachill_experiment_attributes( 'geo-bridge-holdout', 'single-post-bridge' ) );
experiment_check( 'completed experiment enqueues no assets or hooks', array() === $GLOBALS['experiment_enqueued'] && array() === $GLOBALS['experiment_actions'] );
$restart = extrachill_transition_experiment_state( 'geo-bridge-holdout', 1, 'active' );
experiment_check( 'completed definition version is terminal', $restart instanceof WP_Error && 'invalid_experiment_state_transition' === $restart->code );

$lifecycle_definition['definition_version'] = 2;
experiment_check( 'higher code version resets to reviewed default', ! extrachill_experiment_is_active( 'geo-bridge-holdout' ) );
$version_two = extrachill_transition_experiment_state( 'geo-bridge-holdout', 2, 'active' );
experiment_check( 'higher code version can restart completed experiment', is_array( $version_two ) && 2 === $version_two['definition_version'] );
$version_two_paused = extrachill_transition_experiment_state( 'geo-bridge-holdout', 2, 'paused' );
$version_two_done   = extrachill_transition_experiment_state( 'geo-bridge-holdout', 2, 'completed' );
experiment_check( 'paused experiment can transition directly to completed', is_array( $version_two_paused ) && is_array( $version_two_done ) && 'completed' === $version_two_done['state'] );
$stale_transition = extrachill_transition_experiment_state( 'geo-bridge-holdout', 1, 'active' );
experiment_check( 'stale definition version cannot change live state', $stale_transition instanceof WP_Error && 'experiment_definition_version_mismatch' === $stale_transition->code );
$unknown_transition = extrachill_transition_experiment_state( 'unknown-option-key', 1, 'active' );
experiment_check( 'transition rejects arbitrary option keys', $unknown_transition instanceof WP_Error && 'experiment_not_registered' === $unknown_transition->code );

$GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] = 'corrupt';
experiment_check( 'non-array option corruption fails closed', ! extrachill_experiment_is_active( 'geo-bridge-holdout' ) );
$corrupt_transition = extrachill_transition_experiment_state( 'geo-bridge-holdout', 2, 'active' );
experiment_check( 'corrupt option cannot be overwritten by transition', $corrupt_transition instanceof WP_Error && 'invalid_experiment_lifecycle_option' === $corrupt_transition->code );
$other_definition                  = experiment_definition();
$other_definition['key']           = 'unrelated-experiment';
$other_definition['default_state'] = 'inactive';
$GLOBALS['experiment_filters']['extrachill_experiment_definitions']           = array(
	static function () use ( &$lifecycle_definition, &$other_definition ): array {
		return array(
			'geo-bridge-holdout'   => $lifecycle_definition,
			'unrelated-experiment' => $other_definition,
		);
	},
);
$GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] = array(
	'geo-bridge-holdout'   => array(
		'definition_version' => 1,
		'state'              => 'completed',
	),
	'unrelated-experiment' => array(
		'definition_version' => 1,
		'state'              => 'active',
	),
	'removed-experiment'   => array(
		'definition_version' => 4,
		'state'              => 'paused',
	),
	'unknown-broken'       => 'corrupt',
);
$recovered_lifecycle = extrachill_get_experiment_lifecycle_option();
experiment_check( 'removed stored key does not invalidate lifecycle option', true === $recovered_lifecycle['valid'] );
experiment_check( 'removed and malformed unknown keys are reported as orphaned', 2 === count( $recovered_lifecycle['orphaned'] ) && 'removed-experiment' === $recovered_lifecycle['orphaned'][0]['key'] && 'unknown-broken' === $recovered_lifecycle['orphaned'][1]['key'] && 0 === $recovered_lifecycle['orphaned'][1]['definition_version'] );
experiment_check( 'orphan does not disable unrelated active experiment', extrachill_experiment_is_active( 'unrelated-experiment' ) );
$orphaned_list = extrachill_list_experiments();
experiment_check( 'admin output reports exact orphan count', 2 === $orphaned_list['orphan_count'] && false === $orphaned_list['orphan_samples_truncated'] );
experiment_check( 'admin output reports normalized orphan samples', 4 === count( $orphaned_list['items'] ) && true === $orphaned_list['items'][2]['orphaned'] && false === $orphaned_list['items'][2]['registered'] && 4 === $orphaned_list['items'][2]['definition_version'] && 'paused' === $orphaned_list['items'][2]['state'] && 0 === $orphaned_list['items'][3]['definition_version'] && 'inactive' === $orphaned_list['items'][3]['state'] );
$migration_recovery = extrachill_transition_experiment_state( 'geo-bridge-holdout', 2, 'inactive' );
experiment_check( 'authorized idempotent write migrates current definition version', is_array( $migration_recovery ) && 2 === $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ]['geo-bridge-holdout']['definition_version'] );
experiment_check( 'authorized state write prunes removed experiment record', ! isset( $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ]['removed-experiment'] ) );
experiment_check( 'authorized state write prunes malformed unknown record', ! isset( $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ]['unknown-broken'] ) );
experiment_check( 'recovery write preserves unrelated active state', 'active' === $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ]['unrelated-experiment']['state'] && extrachill_experiment_is_active( 'unrelated-experiment' ) );

$GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ]['geo-bridge-holdout'] = array(
	'definition_version' => 2,
	'state'              => 'unknown',
);
$isolated_corruption = extrachill_get_experiment_lifecycle_option();
experiment_check( 'invalid registered state is isolated to its experiment', true === $isolated_corruption['valid'] && ! extrachill_experiment_is_active( 'geo-bridge-holdout' ) && extrachill_experiment_is_active( 'unrelated-experiment' ) );
$repair_transition = extrachill_transition_experiment_state( 'geo-bridge-holdout', 2, 'active' );
experiment_check( 'authorized transition repairs isolated registered state', is_array( $repair_transition ) && extrachill_experiment_is_active( 'geo-bridge-holdout' ) && extrachill_experiment_is_active( 'unrelated-experiment' ) );

$over_bound_option = array(
	'geo-bridge-holdout'   => array(
		'definition_version' => 2,
		'state'              => 'inactive',
	),
	'unrelated-experiment' => array(
		'definition_version' => 1,
		'state'              => 'active',
	),
);
for ( $index = 0; $index <= EXTRACHILL_EXPERIMENT_MAX_LIFECYCLE_RECORDS; ++$index ) {
	$over_bound_option[ 'removed-' . $index ] = array(
		'definition_version' => 1,
		'state'              => 'completed',
	);
}
$GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] = $over_bound_option;
$over_bound_lifecycle = extrachill_get_experiment_lifecycle_option();
$over_bound_list      = extrachill_list_experiments();
experiment_check( 'over-bound option still recovers registered active state', true === $over_bound_lifecycle['over_bound'] && extrachill_experiment_is_active( 'unrelated-experiment' ) );
experiment_check( 'over-bound orphan processing uses exact hard sample cap', EXTRACHILL_EXPERIMENT_MAX_ORPHAN_SAMPLES === count( $over_bound_lifecycle['orphaned'] ) && true === $over_bound_lifecycle['orphan_samples_truncated'] );
experiment_check( 'over-bound orphan count uses bounded sentinel', EXTRACHILL_EXPERIMENT_MAX_REPORTED_ORPHANS === $over_bound_list['orphan_count'] );
experiment_check( 'over-bound admin output remains below exact item cap', count( $over_bound_list['items'] ) <= EXTRACHILL_EXPERIMENT_MAX_LIST_ITEMS && true === $over_bound_list['lifecycle_over_bound'] );
$over_bound_recovery = extrachill_transition_experiment_state( 'geo-bridge-holdout', 2, 'inactive' );
experiment_check( 'authorized recovery prunes over-bound orphan option', is_array( $over_bound_recovery ) && 2 === count( $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] ) && extrachill_experiment_is_active( 'unrelated-experiment' ) );

$GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] = array(
	'geo-bridge-holdout'   => array(
		'definition_version' => 2,
		'state'              => 'inactive',
	),
	'unrelated-experiment' => array(
		'definition_version' => 1,
		'state'              => 'inactive',
	),
);
$GLOBALS['experiment_simulate_option_cache']                                  = true;
$GLOBALS['experiment_option_cache']                    = array();
$GLOBALS['experiment_cache_deletes']                   = array();
$preloaded_stale_snapshot                              = get_site_option( EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION );
$notoptions_key                                        = get_current_network_id() . ':notoptions';
$GLOBALS['experiment_option_cache'][ $notoptions_key ] = array( EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION => true );
experiment_check( 'request A preloads stale lifecycle cache before lock', 'inactive' === $preloaded_stale_snapshot['unrelated-experiment']['state'] );
$query_count_before_stale    = count( $GLOBALS['wpdb']->queries );
$GLOBALS['wpdb']->on_acquire = static function (): void {
	// Request B commits durable state while request A still holds its stale local cache.
	$GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ]['unrelated-experiment']['state'] = 'active';
};
$stale_snapshot_transition   = extrachill_transition_experiment_state( 'geo-bridge-holdout', 2, 'active' );
experiment_check( 'lock acquisition precedes lifecycle snapshot read', is_array( $stale_snapshot_transition ) && extrachill_experiment_is_active( 'unrelated-experiment' ) );
experiment_check( 'fresh locked snapshot preserves concurrent unrelated write', 'active' === $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ]['unrelated-experiment']['state'] );
experiment_check( 'locked snapshot reads exact durable row after GET_LOCK', false !== strpos( $GLOBALS['wpdb']->queries[ $query_count_before_stale ]['query'], 'GET_LOCK' ) && false !== strpos( $GLOBALS['wpdb']->queries[ $query_count_before_stale + 1 ]['query'], 'SELECT meta_value' ) && array( get_current_network_id(), EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ) === $GLOBALS['wpdb']->queries[ $query_count_before_stale + 1 ]['args'] );
$coherent_cache_key = get_current_network_id() . ':' . EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION;
experiment_check( 'successful update restores lifecycle cache coherence', $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] === $GLOBALS['experiment_option_cache'][ $coherent_cache_key ] );
experiment_check( 'successful update removes stale exact notoptions marker', ! isset( $GLOBALS['experiment_option_cache'][ $notoptions_key ][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] ) );

$GLOBALS['experiment_option_cache'][ $coherent_cache_key ] = array( 'stale' => true );
$GLOBALS['experiment_cache_delete_success']                = false;
$GLOBALS['experiment_actions']                             = array();
$cache_delete_failure                                      = extrachill_transition_experiment_state( 'unrelated-experiment', 1, 'paused' );
experiment_check( 'cache delete failure returns error and suppresses audit', $cache_delete_failure instanceof WP_Error && 'experiment_lifecycle_cache_sync_failed' === $cache_delete_failure->code && array() === $GLOBALS['experiment_actions'] );
$GLOBALS['experiment_cache_delete_success'] = true;
unset( $GLOBALS['experiment_option_cache'][ $coherent_cache_key ] );

$GLOBALS['experiment_cache_set_success'] = false;
$GLOBALS['experiment_actions']           = array();
$cache_restore_failure                   = extrachill_transition_experiment_state( 'unrelated-experiment', 1, 'paused' );
experiment_check( 'cache set failure returns error and suppresses audit', $cache_restore_failure instanceof WP_Error && 'experiment_lifecycle_cache_sync_failed' === $cache_restore_failure->code && array() === $GLOBALS['experiment_actions'] );
$GLOBALS['experiment_cache_set_success'] = true;

$GLOBALS['experiment_durable_read_failure'] = true;
$GLOBALS['experiment_actions']              = array();
$query_count_before_read_failure            = count( $GLOBALS['wpdb']->queries );
$durable_read_failure                       = extrachill_transition_experiment_state( 'unrelated-experiment', 1, 'paused' );
experiment_check( 'durable read failure returns error and suppresses audit', $durable_read_failure instanceof WP_Error && 'experiment_lifecycle_durable_read_failed' === $durable_read_failure->code && array() === $GLOBALS['experiment_actions'] );
experiment_check( 'durable read failure still releases lock in finally', false !== strpos( $GLOBALS['wpdb']->queries[ count( $GLOBALS['wpdb']->queries ) - 1 ]['query'], 'RELEASE_LOCK' ) && count( $GLOBALS['wpdb']->queries ) === $query_count_before_read_failure + 3 );
$GLOBALS['experiment_durable_read_failure'] = false;

$durable_baseline                             = $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ];
$GLOBALS['experiment_durable_write_mismatch'] = true;
$GLOBALS['experiment_actions']                = array();
$durable_write_mismatch                       = extrachill_transition_experiment_state( 'unrelated-experiment', 1, 'paused' );
experiment_check( 'durable post-write mismatch returns error and suppresses audit', $durable_write_mismatch instanceof WP_Error && 'experiment_lifecycle_durable_write_mismatch' === $durable_write_mismatch->code && array() === $GLOBALS['experiment_actions'] );
$GLOBALS['experiment_durable_write_mismatch']                                 = false;
$GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] = $durable_baseline;

$GLOBALS['experiment_post_write_cache_mismatch'] = true;
$GLOBALS['experiment_write_completed']           = false;
$GLOBALS['experiment_actions']                   = array();
$post_write_cache_mismatch                       = extrachill_transition_experiment_state( 'unrelated-experiment', 1, 'paused' );
experiment_check( 'post-write cache mismatch returns error and suppresses audit', $post_write_cache_mismatch instanceof WP_Error && 'experiment_lifecycle_cache_sync_failed' === $post_write_cache_mismatch->code && array() === $GLOBALS['experiment_actions'] );
$GLOBALS['experiment_post_write_cache_mismatch']                              = false;
$GLOBALS['experiment_write_completed']                                        = false;
$GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] = $durable_baseline;
$GLOBALS['experiment_simulate_option_cache']                                  = false;
$GLOBALS['experiment_option_cache'] = array();

$GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] = array(
	'geo-bridge-holdout'   => array(
		'definition_version' => 2,
		'state'              => 'inactive',
	),
	'unrelated-experiment' => array(
		'definition_version' => 1,
		'state'              => 'inactive',
	),
);
$first_concurrent_transition  = extrachill_transition_experiment_state( 'geo-bridge-holdout', 2, 'active' );
$second_concurrent_transition = extrachill_transition_experiment_state( 'unrelated-experiment', 1, 'active' );
experiment_check( 'serialized different-experiment transitions preserve both writes', is_array( $first_concurrent_transition ) && is_array( $second_concurrent_transition ) && extrachill_experiment_is_active( 'geo-bridge-holdout' ) && extrachill_experiment_is_active( 'unrelated-experiment' ) );

$query_count_before_invalid = count( $GLOBALS['wpdb']->queries );
$invalid_locked_transition  = extrachill_transition_experiment_state( 'geo-bridge-holdout', 2, 'inactive' );
experiment_check( 'invalid locked transition still releases in finally', $invalid_locked_transition instanceof WP_Error && count( $GLOBALS['wpdb']->queries ) === $query_count_before_invalid + 3 && false !== strpos( $GLOBALS['wpdb']->queries[ count( $GLOBALS['wpdb']->queries ) - 1 ]['query'], 'RELEASE_LOCK' ) );

$snapshot_before_lock_failure       = $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ];
$GLOBALS['wpdb']->acquire_result    = 0;
$query_count_before_acquire_failure = count( $GLOBALS['wpdb']->queries );
$acquire_failure                    = extrachill_transition_experiment_state( 'unrelated-experiment', 1, 'paused' );
experiment_check( 'advisory lock acquisition failure fails closed without write', $acquire_failure instanceof WP_Error && 'experiment_lifecycle_lock_failed' === $acquire_failure->code && $snapshot_before_lock_failure === $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ] );
experiment_check( 'failed acquisition does not release an unheld lock', count( $GLOBALS['wpdb']->queries ) === $query_count_before_acquire_failure + 1 );
$GLOBALS['wpdb']->acquire_result = 1;
$GLOBALS['experiment_actions']   = array();
$lock_retry                      = extrachill_transition_experiment_state( 'unrelated-experiment', 1, 'paused' );
experiment_check( 'lock contention retry succeeds without losing unrelated transition', is_array( $lock_retry ) && 'paused' === $lock_retry['state'] && 'active' === $GLOBALS['experiment_site_options'][ EXTRACHILL_EXPERIMENT_LIFECYCLE_OPTION ]['geo-bridge-holdout']['state'] );
experiment_check( 'successful contention retry emits one audit action', 1 === count( $GLOBALS['experiment_actions'] ) && 'extrachill_experiment_state_changed' === $GLOBALS['experiment_actions'][0][0] );

$GLOBALS['experiment_actions']   = array();
$GLOBALS['wpdb']->release_result = 0;
$release_failure                 = extrachill_transition_experiment_state( 'unrelated-experiment', 1, 'active' );
experiment_check( 'advisory lock release failure fails closed', $release_failure instanceof WP_Error && 'experiment_lifecycle_lock_release_failed' === $release_failure->code );
experiment_check( 'release failure suppresses lifecycle audit action', array() === $GLOBALS['experiment_actions'] );
$GLOBALS['wpdb']->release_result = 1;

$lock_query = $GLOBALS['wpdb']->queries[0];
experiment_check( 'advisory lock name is network scoped', extrachill_experiment_lifecycle_lock_name() === $lock_query['args'][0] && false !== strpos( $lock_query['args'][0], '7' ) );
experiment_check( 'advisory lock wait exactly matches zero-wait constant', EXTRACHILL_EXPERIMENT_LOCK_WAIT_SECONDS === $lock_query['args'][1] );

$GLOBALS['experiment_filters']['extrachill_experiment_definitions'] = array(
	static function () use ( &$lifecycle_definition ): array {
		return array( 'geo-bridge-holdout' => $lifecycle_definition );
	},
);

$GLOBALS['experiment_site_options'] = array();
$list                               = extrachill_list_experiments();
experiment_check( 'admin listing exposes normalized effective state', 1 === count( $list['items'] ) && 'inactive' === $list['items'][0]['state'] );
experiment_check( 'admin listing never exposes eligibility callback', ! isset( $list['items'][0]['eligibility_callback'] ) );
experiment_check( 'unregistered experiment helper fails closed', ! extrachill_experiment_is_active( 'unregistered' ) );

$list_ability                    = $GLOBALS['experiment_abilities']['extrachill/list-experiments'];
$transition_ability              = $GLOBALS['experiment_abilities']['extrachill/transition-experiment-state'];
$GLOBALS['experiment_user_id']   = 0;
$GLOBALS['experiment_can_admin'] = false;
experiment_check( 'web user zero is denied experiment administration', false === $list_ability['permission_callback']() );
$GLOBALS['experiment_user_id'] = 44;
experiment_check( 'ordinary web user is denied experiment administration', false === $transition_ability['permission_callback']() );
$list_schema      = $list_ability['output_schema'];
$list_item_schema = $list_schema['properties']['items']['items'];
experiment_check( 'admin list envelope schema declares exact output properties', array_keys( $list ) === $list_schema['required'] && array_keys( $list_schema['properties'] ) === $list_schema['required'] && false === $list_schema['additionalProperties'] );
experiment_check( 'admin list schema rejects undeclared item properties', false === $list_item_schema['additionalProperties'] );
experiment_check( 'admin list schema declares every normalized item property', array_keys( $list_item_schema['properties'] ) === $list_item_schema['required'] && array_keys( $list['items'][0] ) === $list_item_schema['required'] );
experiment_check( 'admin list schema bounds definition versions to Analytics contract', EXTRACHILL_EXPERIMENT_MAX_DEFINITION_VERSION === $list_item_schema['properties']['definition_version']['maximum'] );
experiment_check( 'admin list schema exactly matches item and nested caps', EXTRACHILL_EXPERIMENT_MAX_LIST_ITEMS === $list_schema['properties']['items']['maxItems'] && EXTRACHILL_EXPERIMENT_MAX_VARIANTS === $list_item_schema['properties']['variants']['maxProperties'] && EXTRACHILL_EXPERIMENT_MAX_SURFACES === $list_item_schema['properties']['surfaces']['maxItems'] );
experiment_check( 'admin list schema exactly matches registered definition cap', EXTRACHILL_EXPERIMENT_MAX_DEFINITIONS === $list_schema['properties']['registered_count']['maximum'] );
experiment_check( 'admin list schema exactly matches bounded orphan count', EXTRACHILL_EXPERIMENT_MAX_REPORTED_ORPHANS === $list_schema['properties']['orphan_count']['maximum'] );
experiment_check( 'registered and orphan sample caps exactly compose item cap', EXTRACHILL_EXPERIMENT_MAX_LIST_ITEMS === EXTRACHILL_EXPERIMENT_MAX_DEFINITIONS + EXTRACHILL_EXPERIMENT_MAX_ORPHAN_SAMPLES );
experiment_check( 'admin transition ability rejects extra option properties', false === $transition_ability['input_schema']['additionalProperties'] );
$GLOBALS['experiment_can_admin'] = true;
experiment_check( 'network options capability permits lifecycle administration', true === $transition_ability['permission_callback']() );

$lifecycle_definition['default_state'] = 'active';
$GLOBALS['experiment_site_options']    = array();
$cache_assignment                      = extrachill_resolve_experiment_assignment( 'geo-bridge-holdout', 'single-post-bridge' );
$GLOBALS['experiment_external_cache']  = false;
$GLOBALS['experiment_actions']         = array();
$cache_failure                         = $ability->execute_exposure(
	array(
		'experiment_key'     => 'geo-bridge-holdout',
		'definition_version' => 2,
		'assignment_policy'  => 'weighted_random',
		'variant'            => $cache_assignment['variant'],
		'surface'            => 'single-post-bridge',
		'exposure_token'     => $cache_assignment['exposure_token'],
	)
);
experiment_check( 'missing shared cache fails exposure closed', $cache_failure instanceof WP_Error );
experiment_check( 'cache failure emits no trusted exposure hook', array() === $GLOBALS['experiment_actions'] );
$GLOBALS['experiment_external_cache'] = true;

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All experiment assignment tests passed.\n";
exit( 0 );
