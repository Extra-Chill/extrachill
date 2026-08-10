<?php
/**
 * Smoke tests for the Newsletter-owned delegated campaign behavior.
 *
 * Run with: php tests/delegated-campaign-owner-smoke.php
 */

define( 'ABSPATH', __DIR__ );

final class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
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
	'blog_id'       => 7,
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
	'post_types'     => array(),
	'domain_authorized' => true,
	'agents'         => array(),
	'blocked_meta_key' => '',
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
function get_post_status( $post_id ) {
	$post = get_post( $post_id );
	return $post ? $post->post_status : false;
}
function get_permalink( $post_id ) {
	return 'https://newsletter.example/' . get_current_blog_id() . '/' . $post_id;
}
function get_posts( $args ) {
	$blog_id = $GLOBALS['newsletter_test']['blog_id'];
	$matches = array();
	foreach ( $GLOBALS['newsletter_test']['posts'][ $blog_id ] ?? array() as $post_id => $post ) {
		if ( isset( $args['name'] ) && ( $post->post_name ?? '' ) !== $args['name'] ) {
			continue;
		}
		if ( isset( $args['post_status'] ) && ! in_array( $post->post_status, $args['post_status'], true ) ) {
			continue;
		}
		$matched = true;
		foreach ( $args['meta_query'] ?? array() as $clause ) {
			if ( (string) ( $GLOBALS['newsletter_test']['meta'][ $blog_id ][ $post_id ][ $clause['key'] ] ?? '' ) !== (string) $clause['value'] ) {
				$matched = false;
				break;
			}
		}
		if ( $matched ) {
			$matches[] = $post_id;
		}
	}
	return -1 === ( $args['posts_per_page'] ?? -1 ) ? $matches : array_slice( $matches, 0, (int) $args['posts_per_page'] );
}
function wp_insert_post( $post, $wp_error = false ) {
	unset( $wp_error );
	$blog_id = $GLOBALS['newsletter_test']['blog_id'];
	$post_id = ++$GLOBALS['newsletter_test']['next_post_id'];
	$GLOBALS['newsletter_test']['posts'][ $blog_id ][ $post_id ] = (object) array(
		'ID'           => $post_id,
		'post_type'    => $post['post_type'],
		'post_status'  => $post['post_status'],
		'post_name'    => $post['post_name'],
		'post_title'   => $post['post_title'],
		'post_content' => $post['post_content'],
		'post_excerpt' => $post['post_excerpt'],
	);
	$GLOBALS['newsletter_test']['meta'][ $blog_id ][ $post_id ] = $post['meta_input'];
	if ( $GLOBALS['newsletter_test']['blocked_meta_key'] ) {
		unset( $GLOBALS['newsletter_test']['meta'][ $blog_id ][ $post_id ][ $GLOBALS['newsletter_test']['blocked_meta_key'] ] );
	}
	return $post_id;
}
function wp_untrash_post( $post_id ) {
	$blog_id = $GLOBALS['newsletter_test']['blog_id'];
	if ( ! isset( $GLOBALS['newsletter_test']['posts'][ $blog_id ][ $post_id ] ) ) {
		return false;
	}
	$GLOBALS['newsletter_test']['posts'][ $blog_id ][ $post_id ]->post_status = 'draft';
	return $GLOBALS['newsletter_test']['posts'][ $blog_id ][ $post_id ];
}
function wp_update_post( $post, $wp_error = false ) {
	unset( $wp_error );
	$blog_id = $GLOBALS['newsletter_test']['blog_id'];
	if ( ! isset( $GLOBALS['newsletter_test']['posts'][ $blog_id ][ $post['ID'] ] ) ) {
		return new WP_Error( 'missing_post' );
	}
	foreach ( array( 'post_status', 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
		if ( array_key_exists( $field, $post ) ) {
			$GLOBALS['newsletter_test']['posts'][ $blog_id ][ $post['ID'] ]->{$field} = $post[ $field ];
		}
	}
	return $post['ID'];
}
function get_post_meta( $post_id, $key, $single = false ) {
	unset( $single );
	$blog_id = $GLOBALS['newsletter_test']['blog_id'];
	return $GLOBALS['newsletter_test']['meta'][ $blog_id ][ $post_id ][ $key ] ?? '';
}
function update_post_meta( $post_id, $key, $value ) {
	if ( $key === $GLOBALS['newsletter_test']['blocked_meta_key'] ) {
		return false;
	}
	$blog_id = $GLOBALS['newsletter_test']['blog_id'];
	$GLOBALS['newsletter_test']['meta'][ $blog_id ][ $post_id ][ $key ] = $value;
	return true;
}
function add_post_meta( $post_id, $key, $value, $unique = false ) {
	$blog_id = $GLOBALS['newsletter_test']['blog_id'];
	if ( $unique && isset( $GLOBALS['newsletter_test']['meta'][ $blog_id ][ $post_id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['newsletter_test']['meta'][ $blog_id ][ $post_id ][ $key ] = $value;
	return true;
}
function extrachill_newsletter_get_sendy_ability( $name ) {
	unset( $name );
	return (object) array();
}
function extrachill_newsletter_ability_push_campaign( $input ) {
	$GLOBALS['newsletter_test']['campaign_calls'][] = array(
		'input'      => $input,
		'blog_id'    => get_current_blog_id(),
		'permalink'  => get_permalink( $input['post_id'] ),
		'content'    => apply_filters( 'the_content', 'owner-content' ),
	);
	if ( $GLOBALS['newsletter_test']['persist_campaign'] ) {
		$GLOBALS['newsletter_test']['meta'][9][ $input['post_id'] ]['_sendy_campaign_id'] = 'campaign-' . $input['post_id'];
	}
	return array(
		'success'     => true,
		'campaign_id' => 'campaign-' . $input['post_id'],
	);
}

require_once __DIR__ . '/support/delegated-campaign-owner-bootstrap.php';
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
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array( 'source' => array( 'site_id' => 0, 'post_id' => 901 ), 'policy' => 'canonical-post-draft' ) ) ), 'zero source site IDs are rejected' );
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array( 'source' => array( 'site_id' => 7, 'post_id' => 0 ), 'policy' => 'canonical-post-draft' ) ) ), 'zero source post IDs are rejected' );
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array( 'source' => array( 'site_id' => -7, 'post_id' => 901 ), 'policy' => 'canonical-post-draft' ) ) ), 'negative source site IDs are rejected' );
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array( 'source' => array( 'site_id' => 7, 'post_id' => -901 ), 'policy' => 'canonical-post-draft' ) ) ), 'negative source post IDs are rejected' );
$assert( null === extrachill_newsletter_sanitize_delegated_campaign_result( array( 'schema' => 'extrachill-newsletter.delegated-campaign-result.v1', 'status' => 'executed', 'record' => array( 'newsletter_post_id' => -1, 'campaign_id' => 'campaign' ), 'error_code' => null ) ), 'negative public record IDs are rejected rather than coerced' );
$assert( null === extrachill_newsletter_sanitize_delegated_campaign_result( array( 'schema' => 'extrachill-newsletter.delegated-campaign-result.v1', 'status' => 'executed', 'record' => array( 'newsletter_post_id' => '1001', 'campaign_id' => 'campaign' ), 'error_code' => null ) ), 'string public record IDs are rejected rather than coerced' );
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array_merge( $input, array( 'html' => '<p>no</p>' ) ) ) ), 'arbitrary HTML is rejected' );
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array_merge( $input, array( 'ability' => 'arbitrary/run' ) ) ) ), 'arbitrary ability names are rejected' );
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array_merge( $input, array( 'recipients' => array( 'person@example.com' ) ) ) ) ), 'recipient input is rejected' );
$assert( is_wp_error( extrachill_newsletter_normalize_delegated_campaign_input( array_merge( $input, array( 'policy' => 'custom' ) ) ) ), 'unsupported policy is rejected' );

$actions = apply_filters( 'datamachine_delegated_operation_actions', array() );
$action  = $actions[ EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION ] ?? array();
$assert( array( 'version', 'normalize_input', 'authorize', 'prepare', 'project', 'retry' ) === array_keys( $action ), 'owner registers the exact Data Machine callback contract' );
$assert( '1' === $action['version'] && is_callable( $action['retry'] ), 'owner contract version and safe retry callback are deterministic' );
extrachill_newsletter_register_delegated_campaign_agent();
$agent = $GLOBALS['newsletter_test']['agents'][ EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_AGENT ] ?? array();
$assert( is_callable( $agent['owner_resolver'] ?? null ) && 1 === $agent['owner_resolver'](), 'complete bootstrap registers a stable owner agent' );

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
		return $GLOBALS['newsletter_test']['domain_authorized'] && 7 === $source['site_id'] && in_array( $context['actor']['user_id'] ?? 0, array( 12, 13 ), true );
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
$invalid_actor_context = array_replace( $first_context, array( 'operation_ref' => 'dop_' . str_repeat( '7', 64 ), 'actor' => array( 'user_id' => '12', 'agent_id' => 0, 'token_id' => 0 ) ) );
$assert( is_wp_error( $action['prepare']( $input, $invalid_actor_context ) ), 'numeric-string actor IDs cannot enter a frozen authorization receipt' );
$negative_actor_context = array_replace( $first_context, array( 'operation_ref' => 'dop_' . str_repeat( '8', 64 ), 'actor' => array( 'user_id' => -12, 'agent_id' => 0, 'token_id' => 0 ) ) );
$assert( is_wp_error( $action['prepare']( $input, $negative_actor_context ) ), 'negative actor IDs cannot enter a frozen authorization receipt' );
$domain_error = static function () {
	return new WP_Error( 'provider_secret_diagnostic' );
};
add_filter( 'extrachill_newsletter_authorize_delegated_campaign', $domain_error, 20, 3 );
$bounded_denial = $action['authorize']( $first_context );
$assert( is_wp_error( $bounded_denial ) && 'newsletter_campaign_forbidden' === $bounded_denial->get_error_code(), 'domain authorization errors collapse to one bounded owner code' );
$GLOBALS['newsletter_test']['filters']['extrachill_newsletter_authorize_delegated_campaign'][20] = array();
$prepared_first = $action['prepare']( $input, $first_context );
$assert( $prepared_first === $action['prepare']( $input, $second_context ), 'prepared owner workflow is actor-neutral and deterministic' );
$assert( 1 === $prepared_first['owner_user_id'] && EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_AGENT === $prepared_first['agent_slug'], 'prepared workflow binds the registered owner and agent' );
$assert( true === extrachill_newsletter_authorize_delegated_campaign_effect( $operation_ref, $input ), 'attested initiating actor is authorized at effect time' );
$GLOBALS['newsletter_test']['domain_authorized'] = false;
$assert( is_wp_error( extrachill_newsletter_authorize_delegated_campaign_effect( $operation_ref, $input ) ), 'revoked domain authorization fails immediately before effects' );
$GLOBALS['newsletter_test']['domain_authorized'] = true;
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
$stored_secret = $GLOBALS['newsletter_test']['site_options']['extrachill_newsletter_delegated_campaign_secret'];
$GLOBALS['newsletter_test']['site_options']['extrachill_newsletter_delegated_campaign_secret'] = '';
$empty_attestation_params = $runtime_params;
$empty_attestation_params['owner_context']['attestation'] = '';
$assert( is_wp_error( extrachill_newsletter_verify_delegated_campaign_task( $empty_attestation_params ) ), 'secret persistence failure cannot authenticate an empty task attestation' );
$GLOBALS['newsletter_test']['site_options']['extrachill_newsletter_delegated_campaign_secret'] = $stored_secret;
$assert( 64 === strlen( extrachill_newsletter_delegated_campaign_lock_name( array( 'site_id' => 7, 'post_id' => 901 ), 'canonical-post-draft' ) ), 'owner lock name stays within the portable MySQL limit' );

$GLOBALS['newsletter_test']['content_filter_blog'] = 0;
add_filter(
	'the_content',
	static function ( $content ) {
		$GLOBALS['newsletter_test']['content_filter_blog'] = get_current_blog_id();
		return $content . '-filtered';
	}
);
$assert( 7 === get_current_blog_id() && ! post_type_exists( 'newsletter' ), 'delegated execution starts without Newsletter globals on the Events site' );
$result = extrachill_newsletter_execute_delegated_campaign( $input );
$assert( 'executed' === $result['status'], 'published canonical source executes' );
$assert( 1001 === $result['record']['newsletter_post_id'], 'execution returns the Newsletter-owned draft reference' );
$assert( 'campaign-1001' === $result['record']['campaign_id'], 'execution returns the Newsletter-owned campaign reference' );
$assert( array( 'schema', 'status', 'record', 'error_code' ) === array_keys( $result ), 'projection exposes only bounded top-level fields' );
$assert( false === strpos( serialize( $result ), 'Canonical event details' ), 'projection does not expose source content' );
$assert( 7 === get_current_blog_id() && ! post_type_exists( 'newsletter' ), 'owner execution restores the Events blog and removes temporary post type globals' );
$assert( 9 === $GLOBALS['newsletter_test']['campaign_calls'][0]['blog_id'], 'campaign executes in the Newsletter blog context' );
$assert( 'https://newsletter.example/9/1001' === $GLOBALS['newsletter_test']['campaign_calls'][0]['permalink'], 'campaign permalink resolves in the Newsletter blog context' );
$assert( 9 === $GLOBALS['newsletter_test']['content_filter_blog'] && 'owner-content-filtered' === $GLOBALS['newsletter_test']['campaign_calls'][0]['content'], 'campaign content filters run in the Newsletter blog context' );
$assert( extrachill_newsletter_record_delegated_campaign_outcome( $operation_ref, $result ), 'owner persists the exact operation outcome' );
$assert( extrachill_newsletter_record_delegated_campaign_outcome( $operation_ref, extrachill_newsletter_delegated_campaign_result( 'failed', null, null, 'newsletter_campaign_forbidden' ) ), 'duplicate delivery acknowledges without replacing terminal truth' );
$assert( 'executed' === extrachill_newsletter_get_delegated_campaign_outcome( $operation_ref )['status'], 'terminal owner outcomes are monotonic' );
$canonical = static fn( $status, $outputs = array() ) => array( 'schema_version' => 'datamachine.run_result.v1', 'status' => $status, 'outputs' => $outputs );
$projected = $action['project']( $canonical( 'completed' ), $first_context );
$assert( 1 === $projected['effect_count'] && 'executed' === $projected['classification'], 'reconciliation projects the authoritative campaign record' );
$assert( array( 'classification', 'record', 'error_code', 'effect_count' ) === array_keys( $projected ), 'delegated projection exposes only bounded fields' );
$assert( false === strpos( serialize( $projected ), 'Canonical event details' ), 'delegated projection excludes canonical content and transport details' );
$no_op_ref     = 'dop_' . str_repeat( 'b', 64 );
$no_op_context = array_replace( $first_context, array( 'operation_ref' => $no_op_ref ) );
extrachill_newsletter_record_delegated_campaign_outcome( $no_op_ref, extrachill_newsletter_delegated_campaign_result( 'no-op', null, null, 'newsletter_campaign_empty_source' ) );
$no_op_projection = $action['project']( $canonical( 'completed', array( 'effect_count' => 0 ) ), $no_op_context );
$assert( 0 === $no_op_projection['effect_count'] && 'no-op' === $no_op_projection['classification'], 'operation-specific no-op cannot inherit an older campaign record' );
$lifecycle_context = array_replace( $first_context, array( 'operation_ref' => 'dop_' . str_repeat( 'c', 64 ) ) );
foreach ( array( 'submitted', 'executing', 'retrying', 'cancelled' ) as $lifecycle ) {
	$lifecycle_projection = $action['project']( $canonical( $lifecycle ), $lifecycle_context );
	$assert( $lifecycle === $lifecycle_projection['classification'] && ! isset( $lifecycle_projection['effect_count'] ), $lifecycle . ' projection preserves lifecycle without claiming no-op' );
}
$assert( is_wp_error( $action['project']( array( 'schema_version' => 'legacy', 'status' => 'completed' ), $first_context ) ), 'projection accepts only canonical Data Machine run results' );

$safe_retry_ref = 'dop_' . str_repeat( 'd', 64 );
extrachill_newsletter_record_delegated_campaign_outcome( $safe_retry_ref, extrachill_newsletter_delegated_campaign_result( 'failed', null, null, 'newsletter_campaign_busy' ) );
$safe_retry_context = array_replace( $first_context, array( 'operation_ref' => $safe_retry_ref ) );
$assert( true === $action['retry']( $canonical( 'failed: newsletter_campaign_busy' ), $safe_retry_context ), 'retry permits a pre-effect failure with a durable owner receipt' );
$unsafe_retry_ref = 'dop_' . str_repeat( 'e', 64 );
extrachill_newsletter_record_delegated_campaign_outcome( $unsafe_retry_ref, extrachill_newsletter_delegated_campaign_result( 'failed', 1001, null, 'newsletter_campaign_reconciliation_required' ) );
$unsafe_retry_context = array_replace(
	$first_context,
	array(
		'operation_ref' => $unsafe_retry_ref,
		'input'         => array( 'source' => array( 'site_id' => 7, 'post_id' => 999 ), 'policy' => 'canonical-post-draft' ),
	)
);
$assert( is_wp_error( $action['retry']( $canonical( 'failed: newsletter_campaign_reconciliation_required' ), $unsafe_retry_context ) ), 'retry rejects an indeterminate external effect' );
$assert( is_wp_error( $action['retry']( array( 'schema_version' => 'legacy', 'status' => 'failed' ), $safe_retry_context ) ), 'retry rejects noncanonical failure envelopes' );

$redacted_ref = 'dop_' . str_repeat( 'f', 64 );
$assert( extrachill_newsletter_record_delegated_campaign_outcome( $redacted_ref, array( 'schema' => 'extrachill-newsletter.delegated-campaign-result.v1', 'status' => 'failed', 'record' => null, 'error_code' => 'provider_secret_token' ) ), 'owner stores a malformed provider code only after redaction' );
$redacted = extrachill_newsletter_get_delegated_campaign_outcome( $redacted_ref );
$assert( 'newsletter_campaign_failed' === $redacted['error_code'] && false === strpos( serialize( $redacted ), 'provider_secret_token' ), 'raw provider codes cannot cross the delegated boundary' );

$replayed = extrachill_newsletter_execute_delegated_campaign( $input );
$assert( 1001 === $replayed['record']['newsletter_post_id'], 'owner replay recovers one Newsletter draft' );
$assert( 1 === count( $GLOBALS['newsletter_test']['posts'][9] ), 'owner replay does not duplicate Newsletter drafts' );
$assert( 1 === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'owner replay uses the durable campaign receipt without invoking transport again' );

$GLOBALS['newsletter_test']['posts'][9][1001]->post_name   = 'mutated-away-from-deterministic-slug';
$GLOBALS['newsletter_test']['posts'][9][1001]->post_status = 'future';
$mutated_replay = extrachill_newsletter_execute_delegated_campaign( $input );
$assert( 'executed' === $mutated_replay['status'] && 1001 === $mutated_replay['record']['newsletter_post_id'], 'metadata identity recovers the same record after slug and status mutation' );
$assert( 1 === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'slug and future-status replay never invokes the provider twice' );
$GLOBALS['newsletter_test']['posts'][9][1001]->post_status = 'custom-review';
$custom_status_replay = extrachill_newsletter_execute_delegated_campaign( $input );
$assert( 1001 === $custom_status_replay['record']['newsletter_post_id'] && 1 === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'registered custom statuses remain recoverable without duplicate effects' );
unset( $GLOBALS['newsletter_test']['meta'][9][1001]['_extrachill_newsletter_source_post_id'] );
$GLOBALS['newsletter_test']['posts'][9][1001]->post_name = 'mutated-with-partial-meta';
$partial_mutation_replay = extrachill_newsletter_execute_delegated_campaign( $input );
$assert( 'executed' === $partial_mutation_replay['status'] && 1001 === $partial_mutation_replay['record']['newsletter_post_id'], 'durable source identity recovers a campaign after slug and metadata mutation' );
$assert( '901' === (string) $GLOBALS['newsletter_test']['meta'][9][1001]['_extrachill_newsletter_source_post_id'], 'trusted source identity repairs missing post metadata' );
$assert( 1 === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'partial metadata recovery never invokes the provider twice' );

$failed_second_context = array_replace( $first_context, array( 'operation_ref' => 'dop_' . str_repeat( '1', 64 ) ) );
$failed_second = $action['project']( $canonical( 'failed: second-operation' ), $failed_second_context );
$assert( 'failed' === $failed_second['classification'] && null === $failed_second['record'], 'a second failed operation cannot inherit a source-level campaign receipt' );
$no_op_second_context = array_replace( $first_context, array( 'operation_ref' => 'dop_' . str_repeat( '2', 64 ) ) );
$no_op_second = $action['project']( $canonical( 'completed_no_items', array( 'effect_count' => 0 ) ), $no_op_second_context );
$assert( 'no-op' === $no_op_second['classification'] && null === $no_op_second['record'] && 0 === $no_op_second['effect_count'], 'a second no-op operation cannot inherit a source-level campaign receipt' );

$bound_ref = 'dop_' . str_repeat( '3', 64 );
switch_to_blog( 9 );
$assert( extrachill_newsletter_claim_delegated_campaign_effect( 1001, $bound_ref ), 'effect owner receipt binds the exact operation immutably' );
$assert( extrachill_newsletter_record_delegated_campaign_effect_state( 1001, $bound_ref, 'creating' ), 'effect owner receipt enters creating state' );
$assert( extrachill_newsletter_record_delegated_campaign_effect_state( 1001, $bound_ref, 'completed' ), 'effect owner receipt records completion' );
restore_current_blog();
$bound_execution = extrachill_newsletter_execute_delegated_campaign( $input, $bound_ref );
$bound_context = array_replace( $first_context, array( 'operation_ref' => $bound_ref ) );
$bound_projection = $action['project']( $canonical( 'completed' ), $bound_context );
$assert( 'executed' === $bound_execution['status'] && 1001 === $bound_projection['record']['newsletter_post_id'], 'missing outcome reconciles only through the exact operation-bound record' );
$assert( ! array_key_exists( 'state', $bound_projection['record'] ), 'operation reconciliation exposes no internal effect state' );
$assert( true === $action['retry']( $canonical( 'failed: outcome-write' ), $bound_context ), 'operation-bound campaign receipt proves retry safe after outcome persistence failure' );
$later_ref = 'dop_' . str_repeat( '5', 64 );
$GLOBALS['newsletter_test']['meta'][9][1001]['_extrachill_newsletter_delegated_campaign_operation_ref'] = $later_ref;
$later_execution = extrachill_newsletter_execute_delegated_campaign( $input, $later_ref );
$assert( 'no-op' === $later_execution['status'] && null === $later_execution['record'], 'mutable effect-owner meta cannot let a later operation claim an existing campaign' );
$assert( 1 === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'later operation no-op never invokes the provider again' );
$later_context = array_replace( $first_context, array( 'operation_ref' => $later_ref ) );
$later_missing_outcome = $action['project']( $canonical( 'completed' ), $later_context );
$assert( 'executed' !== $later_missing_outcome['classification'] && null === $later_missing_outcome['record'], 'missing later-operation outcome cannot fall back to another operation effect' );
$GLOBALS['newsletter_test']['meta'][9][1001]['_extrachill_newsletter_delegated_campaign_state'] = 'indeterminate';
$GLOBALS['newsletter_test']['site_options']['extrachill_newsletter_delegated_effect_9_1001']['state'] = 'indeterminate';
$stored_source_post_meta = $GLOBALS['newsletter_test']['meta'][9][1001]['_extrachill_newsletter_source_post_id'];
unset( $GLOBALS['newsletter_test']['meta'][9][1001]['_extrachill_newsletter_source_post_id'] );
$GLOBALS['newsletter_test']['posts'][7][901]->post_status = 'draft';
$indeterminate_with_id = extrachill_newsletter_execute_delegated_campaign( $input, $bound_ref );
$assert( 'newsletter_campaign_reconciliation_required' === $indeterminate_with_id['error_code'], 'indeterminate effect state wins over mutable source no-op checks' );
$assert( 1 === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'indeterminate campaign ID replay remains fenced from the provider' );
$assert( extrachill_newsletter_record_delegated_campaign_outcome( $bound_ref, extrachill_newsletter_delegated_campaign_result( 'executed', 1001, 'campaign-1001', null ) ), 'indeterminate projection test stores an executed operation outcome' );
$indeterminate_projection = $action['project']( $canonical( 'completed' ), $bound_context );
$assert( 'failed' === $indeterminate_projection['classification'] && null === $indeterminate_projection['record'], 'immutable operation receipt keeps indeterminate state ahead of a stored executed outcome' );
$indeterminate_noop_projection = $action['project']( $canonical( 'completed_no_items', array( 'effect_count' => 0 ) ), $bound_context );
$assert( 'failed' === $indeterminate_noop_projection['classification'], 'owned indeterminate state outranks canonical no-op projection' );
$indeterminate_cancelled_projection = $action['project']( $canonical( 'cancelled' ), $bound_context );
$assert( 'failed' === $indeterminate_cancelled_projection['classification'], 'owned indeterminate state outranks canonical cancellation' );
$assert( is_wp_error( $action['retry']( $canonical( 'failed: outcome-write' ), $bound_context ) ), 'indeterminate state cannot prove retry safe even with a campaign ID' );
$foreign_indeterminate = extrachill_newsletter_execute_delegated_campaign( $input, $later_ref );
$assert( 'no-op' === $foreign_indeterminate['status'], 'foreign operation remains a no-op while the owning effect is indeterminate' );
$GLOBALS['newsletter_test']['posts'][7][901]->post_status = 'publish';
$GLOBALS['newsletter_test']['meta'][9][1001]['_extrachill_newsletter_source_post_id'] = $stored_source_post_meta;
$GLOBALS['newsletter_test']['meta'][9][1001]['_extrachill_newsletter_delegated_campaign_state'] = 'completed';
$GLOBALS['newsletter_test']['site_options']['extrachill_newsletter_delegated_effect_9_1001']['state'] = 'completed';
$invalid_ref = 'dop_' . str_repeat( '4', 64 );
$assert( extrachill_newsletter_bind_delegated_campaign_record( $invalid_ref, 1001, $input ), 'invalid campaign reference test binds the exact operation record' );
$GLOBALS['newsletter_test']['meta'][9][1001]['_sendy_campaign_id'] = 'raw provider diagnostic with spaces';
$invalid_context = array_replace( $first_context, array( 'operation_ref' => $invalid_ref ) );
$invalid_projection = $action['project']( $canonical( 'completed' ), $invalid_context );
$assert( 'failed' === $invalid_projection['classification'] && null === $invalid_projection['record'], 'operation fallback rejects an unbounded persisted campaign reference' );
$assert( false === strpos( serialize( $invalid_projection ), 'provider diagnostic' ), 'invalid durable provider data is redacted from projection' );
$GLOBALS['newsletter_test']['meta'][9][1001]['_sendy_campaign_id'] = 'campaign-1001';

$GLOBALS['newsletter_test']['posts'][7][906] = (object) array(
	'ID'           => 906,
	'post_status'  => 'publish',
	'post_title'   => 'Mutated identity source',
	'post_content' => '<p>Must not adopt another campaign.</p>',
	'post_excerpt' => '',
);
$original_identity_meta = $GLOBALS['newsletter_test']['meta'][9][1001];
$GLOBALS['newsletter_test']['meta'][9][1001]['_extrachill_newsletter_source_site_id']  = 7;
$GLOBALS['newsletter_test']['meta'][9][1001]['_extrachill_newsletter_source_post_id']  = 906;
$GLOBALS['newsletter_test']['meta'][9][1001]['_extrachill_newsletter_campaign_policy'] = 'canonical-post-draft';
$mutated_identity_input = array( 'source' => array( 'site_id' => 7, 'post_id' => 906 ), 'policy' => 'canonical-post-draft' );
$mutated_identity = extrachill_newsletter_execute_delegated_campaign( $mutated_identity_input, 'dop_' . str_repeat( '6', 64 ) );
$assert( 'newsletter_campaign_draft_identity_conflict' === $mutated_identity['error_code'], 'immutable record identity prevents rebinding consistently mutated source metadata' );
$assert( 1 === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'mutated source metadata cannot invoke or inherit the provider effect' );
$GLOBALS['newsletter_test']['meta'][9][1001] = $original_identity_meta;

foreach ( array( 908, 909, 910 ) as $source_id ) {
	$GLOBALS['newsletter_test']['posts'][7][ $source_id ] = (object) array(
		'ID'           => $source_id,
		'post_status'  => 'publish',
		'post_title'   => 'Identity source ' . $source_id,
		'post_content' => '<p>Identity test.</p>',
		'post_excerpt' => '',
	);
}
$source_908 = array( 'source' => array( 'site_id' => 7, 'post_id' => 908 ), 'policy' => 'canonical-post-draft' );
switch_to_blog( 9 );
$campaignless_id = extrachill_newsletter_get_or_create_delegated_draft(
	array( 'site_id' => 7, 'post_id' => 908, 'title' => 'Identity source 908', 'content' => '<p>Identity test.</p>', 'excerpt' => '' ),
	$source_908
);
$GLOBALS['newsletter_test']['meta'][9][ $campaignless_id ]['_extrachill_newsletter_source_post_id'] = 909;
unset( $GLOBALS['newsletter_test']['meta'][9][ $campaignless_id ]['_extrachill_newsletter_delegated_campaign_identity'] );
restore_current_blog();
$campaignless_rebind = extrachill_newsletter_execute_delegated_campaign( array( 'source' => array( 'site_id' => 7, 'post_id' => 909 ), 'policy' => 'canonical-post-draft' ) );
$assert( 'newsletter_campaign_draft_identity_conflict' === $campaignless_rebind['error_code'], 'reverse post receipt prevents campaignless source rebinding after metadata and hash mutation' );

$source_910 = array( 'source' => array( 'site_id' => 7, 'post_id' => 910 ), 'policy' => 'canonical-post-draft' );
switch_to_blog( 9 );
$authoritative_910 = extrachill_newsletter_get_or_create_delegated_draft(
	array( 'site_id' => 7, 'post_id' => 910, 'title' => 'Identity source 910', 'content' => '<p>Identity test.</p>', 'excerpt' => '' ),
	$source_910
);
$GLOBALS['newsletter_test']['posts'][9][ $authoritative_910 ]->post_name = 'mutated-authoritative-slug';
$slug_sibling = ++$GLOBALS['newsletter_test']['next_post_id'];
$GLOBALS['newsletter_test']['posts'][9][ $slug_sibling ] = (object) array(
	'ID' => $slug_sibling, 'post_type' => 'newsletter', 'post_status' => 'future',
	'post_name' => 'delegated-' . hash( 'sha256', '7:910:canonical-post-draft' ),
	'post_title' => 'Slug sibling', 'post_content' => 'Sibling', 'post_excerpt' => '',
);
$GLOBALS['newsletter_test']['meta'][9][ $slug_sibling ] = array( '_extrachill_newsletter_source_site_id' => 99, '_extrachill_newsletter_delegated_campaign_state' => 'indeterminate' );
restore_current_blog();
$calls_before_mixed_duplicate = count( $GLOBALS['newsletter_test']['campaign_calls'] );
$mixed_duplicate = extrachill_newsletter_execute_delegated_campaign( $source_910 );
$assert( 'newsletter_campaign_draft_identity_conflict' === $mixed_duplicate['error_code'], 'metadata/index and slug claims are unioned before duplicate resolution' );
$assert( $calls_before_mixed_duplicate === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'mixed duplicate identity paths cannot invoke the provider' );

$GLOBALS['newsletter_test']['posts'][9][1001]->post_status = 'trash';
$trashed_replay = extrachill_newsletter_execute_delegated_campaign( $input );
$assert( 'executed' === $trashed_replay['status'] && 'draft' === $GLOBALS['newsletter_test']['posts'][9][1001]->post_status, 'trashed campaign record is restored and reused safely' );
$assert( 1 === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'trashed provider receipt never creates another campaign' );

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

$GLOBALS['newsletter_test']['posts'][7][904] = (object) array(
	'ID'           => 904,
	'post_status'  => 'publish',
	'post_title'   => 'Identity persistence test',
	'post_content' => '<p>Do not push before identity is durable.</p>',
	'post_excerpt' => '',
);
$identity_input = array(
	'source' => array( 'site_id' => 7, 'post_id' => 904 ),
	'policy' => 'canonical-post-draft',
);
$GLOBALS['newsletter_test']['persist_campaign'] = true;
$GLOBALS['newsletter_test']['blocked_meta_key'] = '_extrachill_newsletter_source_post_id';
$calls_before_identity = count( $GLOBALS['newsletter_test']['campaign_calls'] );
$records_before_identity = count( $GLOBALS['newsletter_test']['posts'][9] );
$identity_failed       = extrachill_newsletter_execute_delegated_campaign( $identity_input );
$assert( 'newsletter_campaign_draft_identity_failed' === $identity_failed['error_code'], 'partial draft identity fails closed with a bounded code' );
$assert( $calls_before_identity === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'partial draft identity never reaches the non-idempotent provider' );
$GLOBALS['newsletter_test']['blocked_meta_key'] = '';
$identity_replay = extrachill_newsletter_execute_delegated_campaign( $identity_input );
$assert( 'executed' === $identity_replay['status'] && $calls_before_identity + 1 === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'identity repair replay creates only one external campaign' );
$assert( $records_before_identity + 1 === count( $GLOBALS['newsletter_test']['posts'][9] ), 'partial metadata recovery reuses one slug candidate instead of creating duplicate records' );

$duplicate_id = ++$GLOBALS['newsletter_test']['next_post_id'];
$GLOBALS['newsletter_test']['posts'][9][ $duplicate_id ] = (object) array(
	'ID'           => $duplicate_id,
	'post_type'    => 'newsletter',
	'post_status'  => 'future',
	'post_name'    => 'duplicate-mutated-slug',
	'post_title'   => 'Duplicate record',
	'post_content' => '<p>Duplicate.</p>',
	'post_excerpt' => '',
);
$GLOBALS['newsletter_test']['meta'][9][ $duplicate_id ] = array(
	'_extrachill_newsletter_source_site_id'  => 7,
	'_extrachill_newsletter_source_post_id'  => 904,
	'_extrachill_newsletter_campaign_policy' => 'canonical-post-draft',
	'_extrachill_newsletter_delegated_campaign_state' => 'indeterminate',
);
$calls_before_duplicate = count( $GLOBALS['newsletter_test']['campaign_calls'] );
$duplicate_replay = extrachill_newsletter_execute_delegated_campaign( $identity_input );
$assert( 'newsletter_campaign_draft_identity_conflict' === $duplicate_replay['error_code'], 'duplicate metadata identities fail closed even when one sibling is indeterminate' );
$assert( $calls_before_duplicate === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'duplicate records never trigger a second provider effect' );

$GLOBALS['newsletter_test']['posts'][7][905] = (object) array(
	'ID'           => 905,
	'post_status'  => 'publish',
	'post_title'   => 'Collision source',
	'post_content' => '<p>Canonical collision content.</p>',
	'post_excerpt' => '',
);
$collision_input = array( 'source' => array( 'site_id' => 7, 'post_id' => 905 ), 'policy' => 'canonical-post-draft' );
$collision_id    = ++$GLOBALS['newsletter_test']['next_post_id'];
$GLOBALS['newsletter_test']['posts'][9][ $collision_id ] = (object) array(
	'ID'           => $collision_id,
	'post_status'  => 'draft',
	'post_name'    => 'delegated-' . hash( 'sha256', '7:905:canonical-post-draft' ),
	'post_title'   => 'Unrelated content',
	'post_content' => '<p>Must not cross the boundary.</p>',
	'post_excerpt' => '',
);
$GLOBALS['newsletter_test']['meta'][9][ $collision_id ] = array(
	'_extrachill_newsletter_source_site_id' => 99,
	'_sendy_campaign_id'                    => 'unrelated-campaign',
);
$calls_before_collision = count( $GLOBALS['newsletter_test']['campaign_calls'] );
$collision = extrachill_newsletter_execute_delegated_campaign( $collision_input );
$assert( 'newsletter_campaign_draft_identity_conflict' === $collision['error_code'], 'predictable slug collisions fail closed against mismatched identity' );
$assert( $calls_before_collision === count( $GLOBALS['newsletter_test']['campaign_calls'] ), 'identity collision cannot report or invoke an unrelated campaign' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "All {$passes} delegated campaign owner assertions passed.\n";
