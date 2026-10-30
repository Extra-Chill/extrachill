<?php
/**
 * Standalone security tests for bounded cross-site service assertions.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['sa_filters']         = array();
$GLOBALS['sa_actions']         = array();
$GLOBALS['sa_source_grants']   = array();
$GLOBALS['sa_target_grants']   = array();
$GLOBALS['sa_blog_id']         = 2;
$GLOBALS['sa_current_user_id'] = 0;
$GLOBALS['sa_user_sets']       = array();
$GLOBALS['sa_http_request']    = null;
$GLOBALS['sa_scheduled']       = array();
$GLOBALS['sa_deleted']         = array();

class WP_Error {
	private string $code;
	private string $message;
	private $data;

	public function __construct( $code, $message, $data = null ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
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

class WP_REST_Server {}

class WP_REST_Request {
	private string $method;
	private string $route;
	private array $headers = array();
	private array $query   = array();
	private string $body   = '';

	public function __construct( $method, $route ) {
		$this->method = strtoupper( (string) $method );
		$this->route  = (string) $route;
	}

	public function set_headers( $headers ) {
		foreach ( $headers as $name => $value ) {
			$this->headers[ strtolower( str_replace( '_', '-', $name ) ) ] = (string) $value;
		}
	}

	public function add_header( $name, $value ) {
		$this->headers[ strtolower( str_replace( '_', '-', $name ) ) ] = (string) $value;
	}

	public function get_header( $name ) {
		$key = strtolower( str_replace( '_', '-', $name ) );
		return array_key_exists( $key, $this->headers ) ? $this->headers[ $key ] : null;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_route() {
		return $this->route;
	}

	public function set_query_params( $query ) {
		$this->query = $query;
	}

	public function get_query_params() {
		return $this->query;
	}

	public function set_body( $body ) {
		$this->body = (string) $body;
	}

	public function get_body() {
		return $this->body;
	}
}

class ServiceAssertionWpdb {
	public string $options = 'wp_options';
	public string $last_error = '';
	public array $rows = array();
	public array $prepared = array();
	public bool $fail = false;

	public function prepare( $query, ...$args ) {
		$this->prepared = $args;
		return $query;
	}

	public function query( $query ) {
		unset( $query );
		if ( $this->fail ) {
			$this->last_error = 'storage unavailable';
			return false;
		}
		$this->last_error = '';
		$key              = $this->prepared[0];
		if ( isset( $this->rows[ $key ] ) ) {
			return 0;
		}
		$this->rows[ $key ] = $this->prepared[1];
		return 1;
	}

	public function suppress_errors( $suppress = true ) {
		unset( $suppress );
		return false;
	}
}

$GLOBALS['wpdb'] = new ServiceAssertionWpdb();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['sa_filters'][ $hook ][] = compact( 'callback', 'priority', 'accepted_args' );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['sa_actions'][ $hook ][] = compact( 'callback', 'priority', 'accepted_args' );
}

function apply_filters( $hook, $value, ...$args ) {
	if ( 'ec_cross_site_service_assertion_source_grants' === $hook ) {
		return $GLOBALS['sa_source_grants'];
	}
	if ( 'ec_cross_site_service_assertion_target_grants' === $hook ) {
		return $GLOBALS['sa_target_grants'];
	}
	foreach ( $GLOBALS['sa_filters'][ $hook ] ?? array() as $registered ) {
		$value = call_user_func_array( $registered['callback'], array_merge( array( $value ), array_slice( $args, 0, $registered['accepted_args'] - 1 ) ) );
	}
	return $value;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_salt( $scheme = 'auth' ) {
	return str_repeat( substr( $scheme, 0, 1 ), 64 );
}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function get_current_blog_id() {
	return $GLOBALS['sa_blog_id'];
}

function get_current_user_id() {
	return $GLOBALS['sa_current_user_id'];
}

function wp_set_current_user( $user_id ) {
	$GLOBALS['sa_user_sets'][]       = (int) $user_id;
	$GLOBALS['sa_current_user_id'] = (int) $user_id;
}

function ec_get_blog_id( $site_key ) {
	return 'destination' === $site_key ? 5 : null;
}

function ec_get_site_url( $site_key ) {
	return 'destination' === $site_key ? 'https://target.example.test' : null;
}

function wp_schedule_single_event( $timestamp, $hook, $args ) {
	$GLOBALS['sa_scheduled'][] = compact( 'timestamp', 'hook', 'args' );
	return true;
}

function delete_option( $option_name ) {
	$GLOBALS['sa_deleted'][] = $option_name;
	return true;
}

function wp_remote_request( $url, $args ) {
	$GLOBALS['sa_http_request'] = compact( 'url', 'args' );
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => '{}',
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function get_user_by( $field, $user_id ) {
	unset( $field );
	return $user_id > 0 ? (object) array( 'ID' => $user_id ) : false;
}

require_once dirname( __DIR__ ) . '/inc/core/cross-site-rest.php';
require_once dirname( __DIR__ ) . '/inc/core/service-assertions.php';

function sa_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

function sa_grant( $active_key_id = 'key-current', $keys = null ) {
	return array(
		'service_id'     => 'worker.alpha',
		'scope'          => 'records:write',
		'source_site_id' => 2,
		'target_site_id' => 5,
		'target_host'    => 'target.example.test',
		'method'         => 'POST',
		'route'          => '/example/v1/records/commit',
		'active_key_id'  => $active_key_id,
		'keys'           => $keys ?? array(
			'key-current' => str_repeat( 'c', 64 ),
			'key-old'     => str_repeat( 'o', 64 ),
		),
	);
}

function sa_reset( $grant = null ) {
	$grant                       = $grant ?? sa_grant();
	$GLOBALS['sa_source_grants'] = array( $grant );
	$GLOBALS['sa_target_grants'] = array( $grant );
	$GLOBALS['sa_blog_id']       = 2;
	$GLOBALS['sa_user_sets']     = array();
	$GLOBALS['wpdb']->rows       = array();
	$GLOBALS['wpdb']->fail       = false;
	$_SERVER['REMOTE_ADDR']      = '127.0.0.1';
	$_SERVER['HTTP_HOST']        = 'target.example.test';
}

function sa_mint( $body = null, $query = null ) {
	$args = array(
		'user_id'            => 0,
		'service_assertion'  => array(
			'service_id' => 'worker.alpha',
			'scope'      => 'records:write',
		),
	);
	if ( null !== $body ) {
		$args['body'] = $body;
	}
	if ( null !== $query ) {
		$args['query'] = $query;
	}

	return ec_cross_site_build_service_assertion_headers(
		'destination',
		'POST',
		'/example/v1/records/commit',
		$args,
		'worker.alpha',
		'records:write'
	);
}

function sa_request( $headers, $method = 'POST', $route = '/example/v1/records/commit', $body = null, $query = null ) {
	$request = new WP_REST_Request( $method, $route );
	$request->set_headers( $headers );
	if ( null !== $body ) {
		$request->set_body( wp_json_encode( $body ) );
	}
	if ( null !== $query ) {
		$request->set_query_params( $query );
	}
	return $request;
}

function sa_resign( &$headers, WP_REST_Request $request, $secret ) {
	$claims = array();
	foreach ( ec_cross_site_service_assertion_headers() as $claim => $header ) {
		if ( 'signature' !== $claim ) {
			$claims[ $claim ] = $headers[ $header ];
		}
	}
	$payload = ec_cross_site_service_assertion_payload(
		$claims,
		$request->get_method(),
		$request->get_route(),
		ec_cross_site_service_assertion_digest( ec_cross_site_service_assertion_canonical_query( $request->get_query_params() ) ),
		ec_cross_site_service_assertion_digest( ec_cross_site_service_assertion_request_body( $request ) )
	);
	$headers['X-EC-Service-Signature'] = hash_hmac( 'sha256', $payload, $secret );
}

function sa_verify( WP_REST_Request $request ) {
	return ec_cross_site_verify_service_assertion( null, new WP_REST_Server(), $request );
}

sa_reset();
$body    = array( 'z' => 2, 'a' => array( 'b' => 1, 'a' => 0 ) );
$query   = array( 'page' => 1, 'flags' => array( 'b' => false, 'a' => true ) );
$headers = sa_mint( $body, $query );
sa_assert( is_array( $headers ) && 11 === count( $headers ), 'configured source mints a complete assertion' );
$request = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
$GLOBALS['sa_blog_id'] = 5;
sa_assert( null === sa_verify( $request ), 'target verifies a bound request' );
$context = ec_cross_site_verified_service_context( $request );
sa_assert( 'worker.alpha' === $context['service_id'] && 'records:write' === $context['scope'], 'target exposes normalized verified claims' );
sa_assert( 0 === $GLOBALS['sa_current_user_id'] && array() === $GLOBALS['sa_user_sets'], 'user zero remains user zero without impersonation' );

sa_reset();
$GLOBALS['sa_current_user_id'] = 41;
$headers                       = sa_mint( $body, $query );
$headers                       = array_merge( ec_cross_site_build_auth_headers( 41 ), $headers );
$request                       = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
$GLOBALS['sa_blog_id']         = 5;
sa_assert( null === sa_verify( $request ) && 41 === $GLOBALS['sa_current_user_id'], 'positive-user authentication coexists with service verification' );
sa_assert( array() === $GLOBALS['sa_user_sets'], 'service verification does not replace positive-user identity' );

sa_reset();
$plain = new WP_REST_Request( 'GET', '/example/v1/public' );
sa_assert( 'unchanged' === ec_cross_site_verify_service_assertion( 'unchanged', new WP_REST_Server(), $plain ), 'no-assertion requests preserve existing behavior' );
$partial = sa_request( array( 'X-EC-Service-ID' => 'worker.alpha' ) );
sa_assert( 'ec_service_assertion_invalid' === sa_verify( $partial )->get_error_code(), 'partial headers fail closed' );

$binding_cases = array(
	'wrong route'  => array( 'POST', '/example/v1/records/read', $body, $query ),
	'wrong method' => array( 'PUT', '/example/v1/records/commit', $body, $query ),
	'wrong body'   => array( 'POST', '/example/v1/records/commit', array( 'changed' => true ), $query ),
	'wrong query'  => array( 'POST', '/example/v1/records/commit', $body, array( 'page' => 2 ) ),
);
foreach ( $binding_cases as $label => $parts ) {
	sa_reset();
	$headers               = sa_mint( $body, $query );
	$request               = sa_request( $headers, $parts[0], $parts[1], $parts[2], $parts[3] );
	$GLOBALS['sa_blog_id'] = 5;
	sa_assert( is_wp_error( sa_verify( $request ) ), $label . ' is rejected' );
}

sa_reset();
$headers                        = sa_mint( $body, $query );
$_SERVER['HTTP_HOST']           = 'other.example.test';
$request                        = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
$GLOBALS['sa_blog_id']          = 5;
sa_assert( is_wp_error( sa_verify( $request ) ), 'wrong target host is rejected' );

sa_reset();
$headers               = sa_mint( $body, $query );
$request               = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
$GLOBALS['sa_blog_id'] = 6;
sa_assert( is_wp_error( sa_verify( $request ) ), 'wrong target site is rejected' );

sa_reset();
$headers                                  = sa_mint( $body, $query );
$headers['X-EC-Service-Signature']        = str_repeat( '0', 64 );
$request                                  = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
$GLOBALS['sa_blog_id']                    = 5;
sa_assert( is_wp_error( sa_verify( $request ) ), 'forged signature is rejected' );

sa_reset();
$headers                           = sa_mint( $body, $query );
$headers['X-EC-Service-Issued-At'] = (string) ( time() - 120 );
$headers['X-EC-Service-Expires-At'] = (string) ( time() - 60 );
$request                           = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
sa_resign( $headers, $request, str_repeat( 'c', 64 ) );
$request->set_headers( $headers );
$GLOBALS['sa_blog_id'] = 5;
sa_assert( is_wp_error( sa_verify( $request ) ), 'expired assertion is rejected' );

sa_reset();
$headers                                    = sa_mint( $body, $query );
$headers['X-EC-Service-Scope']              = 'records:read';
$request                                    = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
sa_resign( $headers, $request, str_repeat( 'c', 64 ) );
$request->set_headers( $headers );
$GLOBALS['sa_blog_id'] = 5;
sa_assert( is_wp_error( sa_verify( $request ) ), 'ungranted scope is rejected after valid signing' );
$denied = ec_cross_site_build_service_assertion_headers( 'destination', 'POST', '/example/v1/records/commit', array(), 'unknown.worker', 'records:write' );
sa_assert( is_wp_error( $denied ), 'unregistered service cannot mint' );
$GLOBALS['sa_blog_id'] = 3;
$denied                 = sa_mint( $body, $query );
sa_assert( is_wp_error( $denied ), 'registered service cannot mint from the wrong source site' );

sa_reset();
$headers               = sa_mint( $body, $query );
$request               = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$GLOBALS['sa_blog_id'] = 5;
sa_assert( is_wp_error( sa_verify( $request ) ), 'non-loopback callers cannot present assertions' );

sa_reset();
$headers                           = sa_mint( $body, $query );
$headers['X-EC-Service-Issued-At'] = (string) ( time() + 30 );
$headers['X-EC-Service-Expires-At'] = (string) ( time() + 60 );
$request                           = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
sa_resign( $headers, $request, str_repeat( 'c', 64 ) );
$request->set_headers( $headers );
$GLOBALS['sa_blog_id'] = 5;
sa_assert( is_wp_error( sa_verify( $request ) ), 'issued-at beyond bounded skew is rejected' );

sa_reset();
$headers                            = sa_mint( $body, $query );
$headers['X-EC-Service-Expires-At'] = (string) ( (int) $headers['X-EC-Service-Issued-At'] + 61 );
$request                            = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
sa_resign( $headers, $request, str_repeat( 'c', 64 ) );
$request->set_headers( $headers );
$GLOBALS['sa_blog_id'] = 5;
sa_assert( is_wp_error( sa_verify( $request ) ), 'overlong expiry is rejected' );

sa_reset();
$headers               = sa_mint( $body, $query );
$first                 = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
$second                = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
$GLOBALS['sa_blog_id'] = 5;
sa_assert( null === sa_verify( $first ), 'first transport assertion is consumed' );
$replay = sa_verify( $second );
sa_assert( is_wp_error( $replay ) && 'ec_service_assertion_replay' === $replay->get_error_code(), 'second use loses the atomic replay claim' );

$rotated = sa_grant( 'key-current' );
sa_reset( $rotated );
$headers                       = sa_mint( $body, $query );
$headers['X-EC-Service-Key-ID'] = 'key-old';
$request                       = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
sa_resign( $headers, $request, str_repeat( 'o', 64 ) );
$request->set_headers( $headers );
$GLOBALS['sa_blog_id'] = 5;
sa_assert( null === sa_verify( $request ), 'target accepts a configured overlap key during rotation' );

sa_reset( $rotated );
$headers                       = sa_mint( $body, $query );
$headers['X-EC-Service-Key-ID'] = 'key-old';
$request                       = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
sa_resign( $headers, $request, str_repeat( 'o', 64 ) );
$request->set_headers( $headers );
$revoked                       = $rotated;
$revoked['keys']               = array( 'key-current' => str_repeat( 'c', 64 ) );
$GLOBALS['sa_target_grants']   = array( $revoked );
$GLOBALS['sa_blog_id']         = 5;
sa_assert( is_wp_error( sa_verify( $request ) ), 'removing a key revokes it at the target' );

sa_reset();
$headers               = sa_mint( $body, $query );
$request               = sa_request( $headers, 'POST', '/example/v1/records/commit', $body, $query );
$GLOBALS['wpdb']->fail = true;
$GLOBALS['sa_blog_id'] = 5;
$unavailable           = sa_verify( $request );
sa_assert( is_wp_error( $unavailable ) && 503 === $unavailable->get_error_data()['status'], 'persistent storage failure fails closed' );
$serialized_error = serialize( $unavailable );
sa_assert( ! str_contains( $serialized_error, str_repeat( 'c', 64 ) ) && ! str_contains( $serialized_error, $headers['X-EC-Service-Signature'] ), 'errors redact secrets and assertions' );

sa_reset();
$GLOBALS['sa_http_request'] = null;
$response                   = ec_cross_site_rest_request(
	'destination',
	'POST',
	'/example/v1/records/commit',
	array(
		'user_id'           => 0,
		'body'              => $body,
		'service_assertion' => array(
			'service_id' => 'worker.alpha',
			'scope'      => 'records:write',
		),
	)
);
sa_assert( array() === $response && null !== $GLOBALS['sa_http_request'], 'service requests force the fresh HTTP transport' );
sa_assert( ! isset( $GLOBALS['sa_http_request']['args']['headers']['X-EC-Internal-User'] ), 'user zero emits no positive-user assertion' );
sa_assert( isset( $GLOBALS['sa_http_request']['args']['headers']['X-EC-Service-Signature'] ), 'HTTP transport carries the minted assertion' );

$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/service-assertions.php' )
	. file_get_contents( __FILE__ )
	. file_get_contents( dirname( __DIR__ ) . '/docs/service-assertions.md' );
$prohibited = array(
	implode( '', array( 's', 'hop' ) ),
	implode( '', array( 'e', 'vents' ) ),
	implode( '', array( 'com', 'merce' ) ),
	implode( '', array( 'pay', 'ment' ) ),
	implode( '', array( 'stri', 'pe' ) ),
	implode( '', array( 'ship', 'po' ) ),
	implode( '', array( 'woo', 'com', 'merce' ) ),
);
foreach ( $prohibited as $word ) {
	sa_assert( ! str_contains( strtolower( $source ), $word ), 'generic assertion contract remains layer-pure' );
}

echo "All service assertion tests passed.\n";
