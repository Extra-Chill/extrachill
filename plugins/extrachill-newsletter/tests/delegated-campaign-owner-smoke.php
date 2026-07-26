<?php
/**
 * Smoke tests for the Newsletter-owned delegated campaign behavior.
 *
 * Run with: php tests/delegated-campaign-owner-smoke.php
 */

define( 'ABSPATH', __DIR__ );

final class WP_Error {
	private $code;
	public function __construct( $code ) {
		$this->code = $code;
	}
	public function get_error_code() {
		return $this->code;
	}
}

final class NewsletterTestWpdb {
	public $queries = array();
	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query ), $args );
	}
	public function get_var( $query ) {
		$this->queries[] = $query;
		return 1;
	}
}

$GLOBALS['newsletter_test'] = array(
	'blog_id'       => 1,
	'posts'         => array(
		7 => array(
			901 => (object) array(
				'ID'           => 901,
				'post_status'  => 'publish',
				'post_title'   => 'Test Band at The Room',
				'post_content' => '<p>Canonical event details.</p>',
				'post_excerpt' => 'One night only.',
			),
		),
		9 => array(),
	),
	'meta'           => array( 9 => array() ),
	'next_post_id'   => 1000,
	'campaign_calls' => array(),
	'persist_campaign' => true,
	'filters'        => array(),
	'site_options'   => array( 'admin_user_id' => 1 ),
);
$GLOBALS['wpdb'] = new NewsletterTestWpdb();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $accepted_args );
	$GLOBALS['newsletter_test']['filters'][ $hook ][ $priority ][] = $callback;
}
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	add_filter( $hook, $callback, $priority, $accepted_args );
}
function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['newsletter_test']['filters'][ $hook ] ) ) {
		return $value;
	}
	ksort( $GLOBALS['newsletter_test']['filters'][ $hook ] );
	foreach ( $GLOBALS['newsletter_test']['filters'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$args );
		}
	}
	return $value;
}
function absint( $value ) {
	return abs( (int) $value );
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function wp_strip_all_tags( $value ) {
	return strip_tags( $value );
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}
function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	unset( $special_chars, $extra_special_chars );
	return str_repeat( 's', $length );
}
function ec_get_blog_id( $key ) {
	return 'newsletter' === $key ? 9 : null;
}
function get_site_option( $key, $default = false ) {
	return $GLOBALS['newsletter_test']['site_options'][ $key ] ?? $default;
}
function add_site_option( $key, $value ) {
	if ( isset( $GLOBALS['newsletter_test']['site_options'][ $key ] ) ) {
		return false;
	}
	$GLOBALS['newsletter_test']['site_options'][ $key ] = $value;
	return true;
}
function update_site_option( $key, $value ) {
	$GLOBALS['newsletter_test']['site_options'][ $key ] = $value;
	return true;
}
function switch_to_blog( $blog_id ) {
	$GLOBALS['newsletter_test']['blog_stack'][] = $GLOBALS['newsletter_test']['blog_id'];
	$GLOBALS['newsletter_test']['blog_id']      = (int) $blog_id;
}
function get_current_blog_id() {
	return $GLOBALS['newsletter_test']['blog_id'];
}
function restore_current_blog() {
	$GLOBALS['newsletter_test']['blog_id'] = array_pop( $GLOBALS['newsletter_test']['blog_stack'] );
}
function get_post( $post_id ) {
	$blog_id = $GLOBALS['newsletter_test']['blog_id'];
	return $GLOBALS['newsletter_test']['posts'][ $blog_id ][ $post_id ] ?? null;
}
function get_posts( $args ) {
	$blog_id = $GLOBALS['newsletter_test']['blog_id'];
	foreach ( $GLOBALS['newsletter_test']['meta'][ $blog_id ] ?? array() as $post_id => $meta ) {
		$matched = true;
		foreach ( $args['meta_query'] as $clause ) {
			if ( (string) ( $meta[ $clause['key'] ] ?? '' ) !== (string) $clause['value'] ) {
				$matched = false;
				break;
			}
		}
		if ( $matched ) {
			return array( $post_id );
		}
	}
	return array();
}
function wp_insert_post( $post, $wp_error = false ) {
	unset( $wp_error );
	$blog_id = $GLOBALS['newsletter_test']['blog_id'];
	$post_id = ++$GLOBALS['newsletter_test']['next_post_id'];
	$GLOBALS['newsletter_test']['posts'][ $blog_id ][ $post_id ] = (object) array(
		'ID'           => $post_id,
		'post_status'  => $post['post_status'],
		'post_title'   => $post['post_title'],
		'post_content' => $post['post_content'],
		'post_excerpt' => $post['post_excerpt'],
	);
	$GLOBALS['newsletter_test']['meta'][ $blog_id ][ $post_id ] = $post['meta_input'];
	return $post_id;
}
function get_post_meta( $post_id, $key, $single = false ) {
	unset( $single );
	$blog_id = $GLOBALS['newsletter_test']['blog_id'];
	return $GLOBALS['newsletter_test']['meta'][ $blog_id ][ $post_id ][ $key ] ?? '';
}
function update_post_meta( $post_id, $key, $value ) {
	$blog_id = $GLOBALS['newsletter_test']['blog_id'];
	$GLOBALS['newsletter_test']['meta'][ $blog_id ][ $post_id ][ $key ] = $value;
	return true;
}
function extrachill_newsletter_ability_push_campaign( $input ) {
	$GLOBALS['newsletter_test']['campaign_calls'][] = $input;
	if ( $GLOBALS['newsletter_test']['persist_campaign'] ) {
		$GLOBALS['newsletter_test']['meta'][9][ $input['post_id'] ]['_sendy_campaign_id'] = 'campaign-' . $input['post_id'];
	}
	return array(
		'success'     => true,
		'campaign_id' => 'campaign-' . $input['post_id'],
	);
}

require_once dirname( __DIR__ ) . '/inc/core/delegated-campaign.php';

$failures = array();
$passes   = 0;
$assert   = static function ( $condition, $message ) use ( &$failures, &$passes ) {
	if ( $condition ) {
		++$passes;
		return;
	}
	$failures[] = $message;
};

$input = array(
	'source' => array(
		'site_id' => 7,
		'post_id' => 901,
	),
	'policy' => 'canonical-post-draft',
);

$normalized = extrachill_newsletter_normalize_delegated_campaign_input( $input );
$assert( $input === $normalized, 'canonical source input normalizes without adding caller-controlled fields' );
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array( 'source' => array( 'site_id' => '7', 'post_id' => 901 ), 'policy' => 'canonical-post-draft' ) ) ), 'numeric strings are rejected by the strict source contract' );
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array_merge( $input, array( 'html' => '<p>no</p>' ) ) ) ), 'arbitrary HTML is rejected' );
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array_merge( $input, array( 'ability' => 'arbitrary/run' ) ) ) ), 'arbitrary ability names are rejected' );
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array_merge( $input, array( 'recipients' => array( 'person@example.com' ) ) ) ) ), 'recipient input is rejected' );
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array_merge( $input, array( 'policy' => 'custom' ) ) ) ), 'unsupported policy is rejected' );

$actions = apply_filters( 'datamachine_delegated_operation_actions', array() );
$action  = $actions[ EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION ] ?? array();
$assert( array( 'version', 'normalize_input', 'authorize', 'prepare', 'project' ) === array_keys( $action ), 'owner registers the exact Data Machine callback contract' );

$datamachine_path = getenv( 'DATAMACHINE_PATH' );
if ( is_string( $datamachine_path ) && '' !== $datamachine_path ) {
	$registry_file = rtrim( $datamachine_path, '/' ) . '/inc/Core/DelegatedOperations/DelegatedOperationRegistry.php';
	$assert( is_file( $registry_file ), 'merged Data Machine delegated operation registry is available' );
	require_once $registry_file;
	$registered = ( new \DataMachine\Core\DelegatedOperations\DelegatedOperationRegistry() )->get( EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION );
	$assert( ! is_wp_error( $registered ), 'merged Data Machine registry accepts the Newsletter owner action' );
	$assert( $action['version'] === $registered['version'], 'registered owner contract version survives Data Machine validation' );
}

$assert( is_wp_error( $action['authorize']( array( 'action' => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION, 'input' => $input, 'actor' => array( 'user_id' => 1 ) ) ) ), 'newsletter administration alone does not authorize a domain operation' );

add_filter(
	'extrachill_newsletter_authorize_delegated_campaign',
	static function ( $authorized, $source, $context ) {
		unset( $authorized );
		return 7 === $source['site_id'] && in_array( $context['actor']['user_id'] ?? 0, array( 12, 13 ), true );
	},
	10,
	3
);
$operation_ref  = 'dop_' . str_repeat( 'a', 64 );
$first_context  = array( 'action' => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION, 'operation_ref' => $operation_ref, 'input' => $input, 'actor' => array( 'user_id' => 12 ) );
$second_context = array( 'action' => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION, 'operation_ref' => $operation_ref, 'input' => $input, 'actor' => array( 'user_id' => 13 ) );
$assert( true === $action['authorize']( $first_context ), 'first delegated domain actor is freshly authorized' );
$assert( true === $action['authorize']( $second_context ), 'second delegated domain actor is freshly authorized' );
$assert( is_wp_error( $action['authorize']( array_replace( $first_context, array( 'actor' => array( 'user_id' => 99 ) ) ) ) ), 'unapproved actor remains denied' );
$assert( $action['prepare']( $input, $first_context ) === $action['prepare']( $input, $second_context ), 'prepared owner workflow is actor-neutral and deterministic' );
$prepared_params = $action['prepare']( $input, $first_context )['workflow']['steps'][0]['flow_step_settings']['params'];
$runtime_params  = array_merge(
	$prepared_params,
	array(
		'task_type'        => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_TASK,
		'pipeline_job_id'  => 81,
		'pipeline_step_id' => 'step-1',
		'scheduled_at'     => '2030-01-01 00:00:00',
		'job'              => array( 'parent_job_id' => 81 ),
	)
);
$assert( ! is_wp_error( extrachill_newsletter_verify_delegated_campaign_task( $runtime_params ) ), 'private owner attestation accepts the real Data Machine task envelope' );
$prepared_params['owner_context']['attestation'] = str_repeat( '0', 64 );
$assert( is_wp_error( extrachill_newsletter_verify_delegated_campaign_task( $prepared_params ) ), 'direct task invocation without the owner capability is rejected' );
$assert( 64 === strlen( extrachill_newsletter_delegated_campaign_lock_name( array( 'site_id' => 7, 'post_id' => 901 ), 'canonical-post-draft' ) ), 'owner lock name stays within the portable MySQL limit' );

$frozen_operations = array();
$executions        = 0;
$submit            = static function ( $operation_id, $owner_input, $context ) use ( $action, &$frozen_operations, &$executions ) {
	$normalized = $action['normalize_input']( $owner_input, $context );
	if ( is_wp_error( $normalized ) ) {
		return $normalized;
	}
	$context['input'] = $normalized;
	$authorized       = $action['authorize']( $context );
	if ( true !== $authorized ) {
		return $authorized;
	}
	$prepared   = $action['prepare']( $normalized, $context );
	$fingerprint = hash( 'sha256', serialize( array( $action['version'], $normalized, $prepared ) ) );
	if ( isset( $frozen_operations[ $operation_id ] ) ) {
		return hash_equals( $frozen_operations[ $operation_id ], $fingerprint )
			? array( 'replayed' => true )
			: new WP_Error( 'delegated_operation_conflict' );
	}
	$frozen_operations[ $operation_id ] = $fingerprint;
	++$executions;
	return array( 'replayed' => false );
};
$assert( false === $submit( 'shared-operation', $input, $first_context )['replayed'], 'first authorized actor freezes the owner request' );
$assert( true === $submit( 'shared-operation', $input, $second_context )['replayed'], 'second authorized actor replays the same owner-neutral request' );
$assert( 1 === $executions, 'actor replay creates one delegated owner execution' );
$drift                  = $input;
$drift['source']['post_id'] = 902;
$conflict               = $submit( 'shared-operation', $drift, $first_context );
$assert( is_wp_error( $conflict ) && 'delegated_operation_conflict' === $conflict->get_error_code(), 'frozen operation identity rejects source fingerprint drift' );

$result = extrachill_newsletter_execute_delegated_campaign( $input );
$assert( 'executed' === $result['status'], 'published canonical source executes' );
$assert( 1001 === $result['record']['newsletter_post_id'], 'execution returns the Newsletter-owned draft reference' );
$assert( 'campaign-1001' === $result['record']['campaign_id'], 'execution returns the Newsletter-owned campaign reference' );
$assert( array( 'schema', 'status', 'record', 'error_code' ) === array_keys( $result ), 'projection exposes only bounded top-level fields' );
$assert( false === strpos( serialize( $result ), 'Canonical event details' ), 'projection does not expose source content' );
$assert( extrachill_newsletter_record_delegated_campaign_outcome( $operation_ref, $result ), 'owner persists the exact operation outcome' );
$projected = $action['project']( array( 'status' => 'completed' ), $first_context );
$assert( 1 === $projected['effect_count'] && 'executed' === $projected['classification'], 'reconciliation projects the authoritative campaign record' );
$assert( array( 'effect_count', 'classification', 'record', 'error_code' ) === array_keys( $projected ), 'delegated projection exposes only bounded fields' );
$assert( false === strpos( serialize( $projected ), 'Canonical event details' ), 'delegated projection excludes canonical content and transport details' );
$no_op_ref     = 'dop_' . str_repeat( 'b', 64 );
$no_op_context = array_replace( $first_context, array( 'operation_ref' => $no_op_ref ) );
extrachill_newsletter_record_delegated_campaign_outcome( $no_op_ref, extrachill_newsletter_delegated_campaign_result( 'no-op', null, null, 'newsletter_campaign_empty_source' ) );
$no_op_projection = $action['project']( array( 'status' => 'completed' ), $no_op_context );
$assert( 0 === $no_op_projection['effect_count'] && 'no-op' === $no_op_projection['classification'], 'operation-specific no-op cannot inherit an older campaign record' );

$replayed = extrachill_newsletter_execute_delegated_campaign( $input );
$assert( 1001 === $replayed['record']['newsletter_post_id'], 'owner replay recovers one Newsletter draft' );
$assert( 1 === count( $GLOBALS['newsletter_test']['posts'][9] ), 'owner replay does not duplicate Newsletter drafts' );
$assert( 1 === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'owner replay uses the durable campaign receipt without invoking transport again' );

$GLOBALS['newsletter_test']['posts'][7][902] = (object) array(
	'ID'           => 902,
	'post_status'  => 'publish',
	'post_title'   => '',
	'post_content' => '',
	'post_excerpt' => '',
);
$empty = extrachill_newsletter_execute_delegated_campaign(
	array(
		'source' => array(
			'site_id' => 7,
			'post_id' => 902,
		),
		'policy' => 'canonical-post-draft',
	)
);
$assert( 'no-op' === $empty['status'], 'empty canonical source is a no-op' );
$assert( null === $empty['record'], 'no-op does not claim a campaign record' );
$assert( 1 === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'no-op does not invoke campaign transport' );

$missing = extrachill_newsletter_execute_delegated_campaign(
	array(
		'source' => array(
			'site_id' => 7,
			'post_id' => 999,
		),
		'policy' => 'canonical-post-draft',
	)
);
$assert( 'no-op' === $missing['status'], 'missing canonical source is a no-op' );
$assert( 'newsletter_campaign_source_unavailable' === $missing['error_code'], 'missing source returns a bounded code' );

$GLOBALS['newsletter_test']['posts'][7][903] = (object) array(
	'ID'           => 903,
	'post_status'  => 'publish',
	'post_title'   => 'Uncertain campaign',
	'post_content' => '<p>Transport response without a durable receipt.</p>',
	'post_excerpt' => '',
);
$uncertain_input = array(
	'source' => array(
		'site_id' => 7,
		'post_id' => 903,
	),
	'policy' => 'canonical-post-draft',
);
$GLOBALS['newsletter_test']['persist_campaign'] = false;
$uncertain = extrachill_newsletter_execute_delegated_campaign( $uncertain_input );
$assert( 'newsletter_campaign_reference_missing' === $uncertain['error_code'], 'transport success without a durable campaign reference fails closed' );
$calls_after_uncertain = count( $GLOBALS['newsletter_test']['campaign_calls'] );
$uncertain_replay      = extrachill_newsletter_execute_delegated_campaign( $uncertain_input );
$assert( 'newsletter_campaign_reconciliation_required' === $uncertain_replay['error_code'], 'indeterminate campaign receipt requires owner reconciliation' );
$assert( $calls_after_uncertain === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'indeterminate replay never creates another external campaign' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "All {$passes} delegated campaign owner assertions passed.\n";
