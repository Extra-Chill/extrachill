<?php
/**
 * Standalone tests for cross-site REST transport fallback.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AUTH_SALT', 'cross-site-rest-test-salt' );

$GLOBALS['csr_blog_id']         = 2;
$GLOBALS['csr_blog_stack']      = array();
$GLOBALS['csr_current_user_id'] = 17;
$GLOBALS['csr_http_requests']   = array();
$GLOBALS['csr_http_responses']  = array();
$GLOBALS['csr_in_process']      = array();
$GLOBALS['csr_ability_notices'] = array();
$GLOBALS['csr_abilities']       = array( 'example/shared-ability' );
$GLOBALS['csr_routes']          = array(
	'/example/v1/shared' => array( 'shared' => true ),
);

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

class WP_REST_Request {
	private string $method;
	private string $route;
	private array $headers  = array();
	private array $query    = array();
	private string $body    = '';

	public function __construct( $method, $route ) {
		$this->method = (string) $method;
		$this->route  = (string) $route;
	}

	public function add_header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}

	public function set_header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}

	public function set_query_params( $query ) {
		$this->query = $query;
	}

	public function set_body( $body ) {
		$this->body = (string) $body;
	}

	public function get_route() {
		return $this->route;
	}
}

class CrossSiteRestResponse {
	private $data;
	private ?WP_Error $error;

	public function __construct( $data = null, ?WP_Error $error = null ) {
		$this->data  = $data;
		$this->error = $error;
	}

	public function is_error() {
		return null !== $this->error;
	}

	public function as_error() {
		return $this->error;
	}

	public function get_data() {
		return $this->data;
	}
}

function add_filter() {}

function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_has_ability( $name ) {
	return in_array( $name, $GLOBALS['csr_abilities'], true );
}

function get_current_blog_id() {
	return $GLOBALS['csr_blog_id'];
}

function switch_to_blog( $blog_id ) {
	$GLOBALS['csr_blog_stack'][] = $GLOBALS['csr_blog_id'];
	$GLOBALS['csr_blog_id']      = (int) $blog_id;
	return true;
}

function restore_current_blog() {
	$GLOBALS['csr_blog_id'] = array_pop( $GLOBALS['csr_blog_stack'] );
	return true;
}

function get_current_user_id() {
	return $GLOBALS['csr_current_user_id'];
}

function wp_set_current_user( $user_id ) {
	$GLOBALS['csr_current_user_id'] = (int) $user_id;
}

function ec_get_blog_id( $site_key ) {
	return 'target' === $site_key ? 5 : null;
}

function ec_get_site_url( $site_key ) {
	return 'target' === $site_key ? 'https://target.example.test' : null;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function rest_do_request( WP_REST_Request $request ) {
	$GLOBALS['csr_in_process'][] = array(
		'blog_id' => get_current_blog_id(),
		'user_id' => get_current_user_id(),
		'route'   => $request->get_route(),
	);

	$route = $request->get_route();
	if ( preg_match( '#^/wp-abilities/v1/abilities/([a-zA-Z0-9\-/]+)/run/?$#', $route, $matches ) ) {
		if ( ! wp_has_ability( $matches[1] ) ) {
			$GLOBALS['csr_ability_notices'][] = $matches[1];
			return new CrossSiteRestResponse(
				null,
				new WP_Error( 'rest_ability_not_found', 'Ability not found.', array( 'status' => 404 ) )
			);
		}

		return new CrossSiteRestResponse( array( 'ability' => $matches[1], 'runtime' => 'shared' ) );
	}

	if ( isset( $GLOBALS['csr_routes'][ $route ] ) ) {
		return new CrossSiteRestResponse( $GLOBALS['csr_routes'][ $route ] );
	}

	return new CrossSiteRestResponse(
		null,
		new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', array( 'status' => 404 ) )
	);
}

function wp_remote_request( $url, $args ) {
	$GLOBALS['csr_http_requests'][] = array(
		'url'            => $url,
		'args'           => $args,
		'caller_blog_id' => get_current_blog_id(),
		'caller_user_id' => get_current_user_id(),
	);

	$host  = $args['headers']['Host'] ?? '';
	$route = (string) parse_url( $url, PHP_URL_PATH );
	if ( 'target.example.test' === $host && '/wp-json/example/v1/target-only' === $route ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'target_blog_id' => 5, 'owner' => 'site-activated-plugin' ) ),
		);
	}
	if ( 'target.example.test' === $host && '/wp-json/wp-abilities/v1/abilities/example/target-only-ability/run' === $route ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'ability' => 'example/target-only-ability', 'runtime' => 'target' ) ),
		);
	}
	if ( isset( $GLOBALS['csr_http_responses'][ $route ] ) ) {
		return $GLOBALS['csr_http_responses'][ $route ];
	}

	return array(
		'response' => array( 'code' => 404 ),
		'body'     => wp_json_encode( array( 'code' => 'rest_no_route', 'message' => 'No route found.' ) ),
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function wp_salt() {
	return AUTH_SALT;
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function get_user_by( $field, $user_id ) {
	unset( $field );
	return (object) array( 'ID' => $user_id );
}

require_once dirname( __DIR__ ) . '/inc/core/cross-site-rest.php';

function csr_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

$shared = ec_cross_site_rest_request( 'target', 'GET', '/example/v1/shared' );
csr_assert( array( 'shared' => true ) === $shared, 'network-shared route dispatches in process' );
csr_assert( 5 === $GLOBALS['csr_in_process'][0]['blog_id'], 'in-process route runs in the target blog context' );
csr_assert( array() === $GLOBALS['csr_http_requests'], 'successful in-process dispatch does not consume an HTTP worker' );
csr_assert( 2 === get_current_blog_id() && 17 === get_current_user_id(), 'shared-route dispatch restores caller context' );

$shared_ability = ec_cross_site_rest_request( 'target', 'GET', '/wp-abilities/v1/abilities/example/shared-ability/run' );
csr_assert( 'shared' === $shared_ability['runtime'], 'registered shared ability dispatches in process' );
csr_assert( 2 === count( $GLOBALS['csr_in_process'] ), 'registered shared ability does not consume an HTTP worker' );

$target_only_ability = ec_cross_site_rest_request( 'target', 'GET', '/wp-abilities/v1/abilities/example/target-only-ability/run' );
csr_assert( 'target' === $target_only_ability['runtime'], 'source-missing ability dispatches through the target bootstrap' );
csr_assert( array() === $GLOBALS['csr_ability_notices'], 'source-missing ability does not invoke the noticing registry lookup' );
csr_assert( 2 === count( $GLOBALS['csr_in_process'] ), 'source-missing ability skips in-process dispatch' );
csr_assert( 1 === count( $GLOBALS['csr_http_requests'] ), 'source-missing ability performs one HTTP request' );

$target_only = ec_cross_site_rest_request(
	'target',
	'GET',
	'/example/v1/target-only',
	array( 'user_id' => 29 )
);
csr_assert( 'site-activated-plugin' === $target_only['owner'], 'target-only route retries through its canonical host bootstrap' );
csr_assert( 5 === $GLOBALS['csr_in_process'][2]['blog_id'] && 29 === $GLOBALS['csr_in_process'][2]['user_id'], 'initial attempt uses target blog and requested user contexts' );
csr_assert( 2 === count( $GLOBALS['csr_http_requests'] ), 'missing in-process route performs one HTTP fallback' );
$fallback = $GLOBALS['csr_http_requests'][1];
csr_assert( 2 === $fallback['caller_blog_id'] && 17 === $fallback['caller_user_id'], 'blog and user contexts are restored before HTTP fallback' );
csr_assert( isset( $fallback['args']['headers']['X-EC-Internal-Signature'] ), 'HTTP fallback preserves signed user authentication' );
csr_assert( 2 === get_current_blog_id() && 17 === get_current_user_id(), 'target-only dispatch leaves caller context unchanged' );
csr_assert( ! isset( $GLOBALS['ec_in_cross_site_dispatch'] ), 'cross-site dispatch marker is restored' );

$GLOBALS['csr_http_responses']['/wp-json/example/v1/retry'] = array(
	'response' => array( 'code' => 429 ),
	'body'     => wp_json_encode(
		array(
			'code'    => 'target_busy',
			'message' => 'Try again later.',
			'data'    => array(
				'status'      => 418,
				'retryable'   => true,
				'transient'   => false,
				'retry_after' => 12.25,
				'secret'      => 'do-not-reflect',
				'context'     => array( 'token' => 'also-secret' ),
			),
		)
	),
);
$retry_error = ec_cross_site_rest_request_http( 'target', 'GET', '/example/v1/retry' );
csr_assert( 'target_busy' === $retry_error->get_error_code(), 'valid target machine code is preserved' );
csr_assert( 'Cross-site request failed' === $retry_error->get_error_message(), 'target-provided error messages are not reflected' );
csr_assert(
	array(
		'status'      => 429,
		'retryable'   => true,
		'transient'   => false,
		'retry_after' => 13,
	) === $retry_error->get_error_data(),
	'typed retry metadata is allowlisted and target status cannot override HTTP'
);

$GLOBALS['csr_http_responses']['/wp-json/example/v1/invalid-retry'] = array(
	'response' => array( 'code' => 503 ),
	'body'     => wp_json_encode(
		array(
			'data' => array(
				'retryable'   => 1,
				'transient'   => 'true',
				'retry_after' => '30',
			),
		)
	),
);
$invalid_retry = ec_cross_site_rest_request_http( 'target', 'GET', '/example/v1/invalid-retry' );
csr_assert( array( 'status' => 503 ) === $invalid_retry->get_error_data(), 'invalid retry metadata types are omitted' );

$GLOBALS['csr_http_responses']['/wp-json/example/v1/retry-tiny']     = array(
	'response' => array( 'code' => 503 ),
	'body'     => wp_json_encode( array( 'data' => array( 'retry_after' => 0.000001 ) ) ),
);
$GLOBALS['csr_http_responses']['/wp-json/example/v1/retry-huge']     = array(
	'response' => array( 'code' => 503 ),
	'body'     => '{"data":{"retry_after":1e308}}',
);
$GLOBALS['csr_http_responses']['/wp-json/example/v1/retry-infinite'] = array(
	'response' => array( 'code' => 503 ),
	'body'     => '{"data":{"retry_after":1e309}}',
);
$retry_tiny     = ec_cross_site_rest_request_http( 'target', 'GET', '/example/v1/retry-tiny' );
$retry_huge     = ec_cross_site_rest_request_http( 'target', 'GET', '/example/v1/retry-huge' );
$retry_infinite = ec_cross_site_rest_request_http( 'target', 'GET', '/example/v1/retry-infinite' );
csr_assert( 1 === $retry_tiny->get_error_data()['retry_after'], 'tiny positive retry delay rounds up to one second' );
csr_assert( 86400 === $retry_huge->get_error_data()['retry_after'], 'huge finite retry delay clamps before integer conversion' );
csr_assert( array( 'status' => 503 ) === $retry_infinite->get_error_data(), 'infinite retry delay is omitted' );

$GLOBALS['csr_http_responses']['/wp-json/example/v1/retry-negative'] = array(
	'response' => array( 'code' => 503 ),
	'body'     => wp_json_encode( array( 'data' => array( 'retry_after' => -2 ) ) ),
);
$GLOBALS['csr_http_responses']['/wp-json/example/v1/retry-zero']     = array(
	'response' => array( 'code' => 503 ),
	'body'     => wp_json_encode( array( 'data' => array( 'retry_after' => 0 ) ) ),
);
$retry_negative = ec_cross_site_rest_request_http( 'target', 'GET', '/example/v1/retry-negative' );
$retry_zero     = ec_cross_site_rest_request_http( 'target', 'GET', '/example/v1/retry-zero' );
csr_assert( array( 'status' => 503 ) === $retry_negative->get_error_data(), 'negative retry delay is omitted' );
csr_assert( array( 'status' => 503 ) === $retry_zero->get_error_data(), 'zero retry delay is omitted' );

$GLOBALS['csr_http_responses']['/wp-json/example/v1/credential-message'] = array(
	'response' => array( 'code' => 500 ),
	'body'     => wp_json_encode(
		array(
			'code'    => 'target_failure',
			'message' => 'Authorization: Bearer private-token',
		)
	),
);
$credential_message = ec_cross_site_rest_request_http( 'target', 'GET', '/example/v1/credential-message' );
csr_assert( 'target_failure' === $credential_message->get_error_code(), 'credential message does not affect valid target code' );
csr_assert( 'Cross-site request failed' === $credential_message->get_error_message(), 'credential-shaped target messages are not reflected' );

$GLOBALS['csr_http_responses']['/wp-json/example/v1/invalid-envelope'] = array(
	'response' => array( 'code' => 500 ),
	'body'     => wp_json_encode(
		array(
			'code'    => 'Target Busy',
			'message' => array( 'nested' => 'message' ),
		)
	),
);
$invalid_envelope = ec_cross_site_rest_request_http( 'target', 'GET', '/example/v1/invalid-envelope' );
csr_assert( 'ec_cross_site_error' === $invalid_envelope->get_error_code(), 'invalid target machine code is omitted' );
csr_assert( 'Cross-site request failed' === $invalid_envelope->get_error_message(), 'object-shaped target message is ignored' );

$GLOBALS['csr_http_responses']['/wp-json/example/v1/array-message'] = array(
	'response' => array( 'code' => 500 ),
	'body'     => wp_json_encode(
		array(
			'code'    => 'target_failure',
			'message' => array( 'one', 'two' ),
		)
	),
);
$array_message = ec_cross_site_rest_request_http( 'target', 'GET', '/example/v1/array-message' );
csr_assert( 'Cross-site request failed' === $array_message->get_error_message(), 'array-shaped target message is ignored' );

$GLOBALS['csr_http_responses']['/wp-json/example/v1/retry-nan'] = array(
	'response' => array( 'code' => 503 ),
	'body'     => '{"data":{"retry_after":NaN}}',
);
$retry_nan = ec_cross_site_rest_request_http( 'target', 'GET', '/example/v1/retry-nan' );
csr_assert( array( 'status' => 503 ) === $retry_nan->get_error_data(), 'NaN JSON body remains malformed and contributes no retry metadata' );

$GLOBALS['csr_http_responses']['/wp-json/example/v1/malformed'] = array(
	'response' => array( 'code' => 502 ),
	'body'     => '<html>bad gateway</html>',
);
$malformed = ec_cross_site_rest_request_http( 'target', 'GET', '/example/v1/malformed' );
csr_assert( 'ec_cross_site_error' === $malformed->get_error_code() && 'Cross-site request failed' === $malformed->get_error_message(), 'malformed error body keeps generic compatibility behavior' );
csr_assert( array( 'status' => 502 ) === $malformed->get_error_data(), 'malformed error body preserves only HTTP status' );

echo "All cross-site REST dispatch tests passed.\n";
