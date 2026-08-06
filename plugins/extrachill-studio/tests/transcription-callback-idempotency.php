<?php
/**
 * Focused callback idempotency regression harness.
 *
 * Run with: php tests/transcription-callback-idempotency.php
 */

define( 'ABSPATH', __DIR__ );
define( 'EC_ANALYTICS_EVENT_STUDIO_TRANSCRIPTION_RUN', 'studio_transcription_run' );

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code, $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}

class WP_REST_Request {
	private $body;

	public function __construct( array $body ) {
		$this->body = $body;
	}

	public function get_header( $name ) {
		return 'authorization' === $name ? 'Bearer valid' : '';
	}

	public function get_json_params() {
		return $this->body;
	}
}

class WP_REST_Response {
	public $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}
}

class WP_User {
	public $ID = 7;
	public $user_email = 'artist@example.com';
	public $display_name = 'Artist';
	public $user_login = 'artist';
}

class Callback_Test_WPDB {
	public $options = 'wp_options';

	public function update( $table, array $data, array $where ) {
		unset( $table );
		if ( $GLOBALS['callback_fail_updates'] > 0 ) {
			--$GLOBALS['callback_fail_updates'];
			return 0;
		}
		$key = $where['option_name'];
		if ( ! isset( $GLOBALS['callback_options'][ $key ] ) ) {
			return 0;
		}
		if ( maybe_serialize( $GLOBALS['callback_options'][ $key ] ) !== $where['option_value'] ) {
			return 0;
		}
		$GLOBALS['callback_options'][ $key ] = unserialize( $data['option_value'] );
		return 1;
	}
}

$wpdb = new Callback_Test_WPDB();

function add_action() {}
function register_rest_route() {}
function __($text) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function rest_ensure_response( $value ) { return new WP_REST_Response( $value ); }
function get_site_option( $key, $default = false ) { return 'sweatpants_signed_token_secret' === $key ? 'secret' : $default; }
function wp_native_auth_verify_external_token() { return array( 'scope' => 'callback:write', 'sub' => 7 ); }
function get_user_by() { return new WP_User(); }
function ec_get_blog_id() { return 1; }
function switch_to_blog() {}
function restore_current_blog() {}
function wp_generate_uuid4() { return 'owner-' . ++$GLOBALS['callback_owner']; }
function add_option( $key, $value ) {
	if ( array_key_exists( $key, $GLOBALS['callback_options'] ) ) {
		return false;
	}
	$GLOBALS['callback_options'][ $key ] = $value;
	return true;
}
function get_option( $key, $default = false ) { return $GLOBALS['callback_options'][ $key ] ?? $default; }
function maybe_serialize( $value ) { return is_array( $value ) ? serialize( $value ) : $value; }
function wp_cache_delete() {}
function wp_basename( $path ) { return basename( $path ); }
function get_posts( array $args ) {
	foreach ( $GLOBALS['callback_post_meta'] as $post_id => $meta ) {
		if ( 7 === $args['author'] && ( $meta['_studio_transcription_job_id'] ?? '' ) === $args['meta_value'] ) {
			return array( $post_id );
		}
	}
	return array();
}
function ec_cross_site_rest_request() {
	++$GLOBALS['callback_drafts'];
	if ( $GLOBALS['callback_nested_request'] instanceof WP_REST_Request ) {
		$request                            = $GLOBALS['callback_nested_request'];
		$GLOBALS['callback_nested_request'] = null;
		$GLOBALS['callback_nested_result']  = ec_studio_transcription_handle_callback( $request );
	}
	return array( 'id' => 100 + $GLOBALS['callback_drafts'] );
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['callback_post_meta'][ $post_id ][ $key ] = $value;
	return true;
}
function ec_studio_emit_team_experience_event() { return ++$GLOBALS['callback_analytics']; }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function get_edit_post_link( $post_id ) { return 'https://example.com/edit/' . $post_id; }
function ec_studio_transcription_render_completion_email() { return '<p>Ready</p>'; }
function ec_send_email() {
	++$GLOBALS['callback_email_attempts'];
	$result = array_shift( $GLOBALS['callback_email_results'] );
	return array( 'success' => false !== $result );
}

require dirname( __DIR__ ) . '/inc/transcription/callback.php';

function callback_request( $job_id = 'job-123' ) {
	return new WP_REST_Request(
		array(
			'job_id' => $job_id,
			'status' => 'complete',
			'files'  => array( 'transcription' => 'recording.wav.whisper.txt' ),
			'content' => array( 'transcription' => 'Test transcript.' ),
			'stats'   => array( 'segments' => 2, 'duration' => 10 ),
		)
	);
}

function callback_reset() {
	$GLOBALS['callback_options']        = array();
	$GLOBALS['callback_owner']          = 0;
	$GLOBALS['callback_drafts']         = 0;
	$GLOBALS['callback_analytics']      = 0;
	$GLOBALS['callback_email_attempts'] = 0;
	$GLOBALS['callback_email_results']  = array( true );
	$GLOBALS['callback_fail_updates']   = 0;
	$GLOBALS['callback_post_meta']      = array();
	$GLOBALS['callback_nested_request'] = null;
	$GLOBALS['callback_nested_result']  = null;
}

function callback_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

callback_reset();
$first  = ec_studio_transcription_handle_callback( callback_request() );
$second = ec_studio_transcription_handle_callback( callback_request() );
callback_assert( $first instanceof WP_REST_Response && $second instanceof WP_REST_Response, 'sequential replay returns success' );
callback_assert( $first->data === $second->data, 'sequential replay returns the prior result' );
callback_assert( 1 === $GLOBALS['callback_drafts'], 'sequential replay creates one draft' );
callback_assert( 1 === $GLOBALS['callback_analytics'], 'sequential replay emits analytics once' );
callback_assert( 1 === $GLOBALS['callback_email_attempts'], 'sequential replay sends one notification' );

callback_reset();
$request                            = callback_request( 'job-race' );
$GLOBALS['callback_nested_request'] = $request;
$winner                             = ec_studio_transcription_handle_callback( $request );
callback_assert( $winner instanceof WP_REST_Response, 'race winner completes' );
callback_assert( $GLOBALS['callback_nested_result'] instanceof WP_Error, 'concurrent replay is rejected while claimed' );
callback_assert( 'callback_in_progress' === $GLOBALS['callback_nested_result']->code, 'concurrent replay receives the retryable conflict' );
callback_assert( 1 === $GLOBALS['callback_drafts'] && 1 === $GLOBALS['callback_analytics'] && 1 === $GLOBALS['callback_email_attempts'], 'race executes each side effect once' );

callback_reset();
$GLOBALS['callback_email_results'] = array( false, true );
$failed                            = ec_studio_transcription_handle_callback( callback_request( 'job-partial' ) );
$retried                           = ec_studio_transcription_handle_callback( callback_request( 'job-partial' ) );
callback_assert( $failed instanceof WP_Error && 'notification_failed' === $failed->code, 'notification failure requests a retry' );
callback_assert( $retried instanceof WP_REST_Response, 'partial failure retry completes' );
callback_assert( 1 === $GLOBALS['callback_drafts'], 'partial failure retry reuses the draft' );
callback_assert( 1 === $GLOBALS['callback_analytics'], 'partial failure retry reuses the analytics event' );
callback_assert( 2 === $GLOBALS['callback_email_attempts'], 'partial failure retries only the notification' );

callback_reset();
$GLOBALS['callback_fail_updates'] = 1;
$failed                           = ec_studio_transcription_handle_callback( callback_request( 'job-after-draft' ) );
callback_assert( $failed instanceof WP_Error && 'callback_claim_lost' === $failed->code, 'failure after draft leaves a recoverable receipt' );
foreach ( $GLOBALS['callback_options'] as &$receipt ) {
	$receipt['updated_at'] = time() - 301;
}
unset( $receipt );
$recovered = ec_studio_transcription_handle_callback( callback_request( 'job-after-draft' ) );
callback_assert( $recovered instanceof WP_REST_Response, 'stale post-creation failure recovers' );
callback_assert( 1 === $GLOBALS['callback_drafts'], 'post-creation recovery resolves the stamped draft' );
callback_assert( 1 === $GLOBALS['callback_analytics'] && 1 === $GLOBALS['callback_email_attempts'], 'post-creation recovery runs remaining side effects once' );

fwrite( STDOUT, "Transcription callback idempotency tests passed.\n" );
