<?php
/**
 * Focused Socials public-contract regression harness.
 *
 * Run with: php tests/social-publish-contract.php
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

class WP_Post {
	public $ID;
	public $post_type = 'post';
	public $post_status = 'publish';
	public $post_author = 7;

	public function __construct( $id ) {
		$this->ID = $id;
	}
}

class Social_Publish_Test_Ability {
	private $name;

	public function __construct( $name ) {
		$this->name = $name;
	}

	public function execute( $input ) {
		$GLOBALS['social_calls'][] = array(
			'name'  => $this->name,
			'input' => $input,
		);
		$responses = &$GLOBALS['social_responses'][ $this->name ];
		return array_shift( $responses );
	}
}

function add_action() {}
function add_filter() {}
function register_post_meta( $post_type, $key, $args ) {
	$GLOBALS['registered_social_meta'][ $key ] = $args;
}
function wp_register_ability() {}
function __( $text ) { return $text; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_current_blog_id() { return $GLOBALS['social_current_blog_id'] ?? 12; }
function switch_to_blog( $blog_id ) {
	$GLOBALS['social_blog_stack'][]  = get_current_blog_id();
	$GLOBALS['social_current_blog_id'] = $blog_id;
}
function restore_current_blog() {
	$GLOBALS['social_current_blog_id'] = array_pop( $GLOBALS['social_blog_stack'] );
}
function ec_get_blog_id() { return 1; }
function attachment_url_to_postid() { return 84; }
function get_permalink( $post ) {
	$host = 1 === get_current_blog_id() ? 'main.example.test/article-' : 'studio.example.test/social-';
	return 'https://' . $host . ( $post instanceof WP_Post ? $post->ID : $post ) . '/';
}
function get_post( $post_id ) { return $GLOBALS['social_posts'][ $post_id ] ?? null; }
function current_user_can() { return true; }
function user_can() { return true; }
function wp_get_ability( $name ) {
	return array_key_exists( $name, $GLOBALS['social_responses'] ) ? new Social_Publish_Test_Ability( $name ) : null;
}
function get_post_meta( $post_id, $key ) { return $GLOBALS['social_meta'][ $post_id ][ $key ] ?? ''; }
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['social_meta'][ $post_id ][ $key ] = $value;
	return true;
}

require dirname( __DIR__ ) . '/inc/social-drafts.php';

function social_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['registered_social_meta'] = array();
ExtraChillStudio\register_social_meta();
social_assert( isset( $GLOBALS['registered_social_meta'][ ExtraChillStudio\META_SOURCE_POST ] ), 'review drafts register canonical source post identity' );
social_assert( isset( $GLOBALS['registered_social_meta'][ ExtraChillStudio\META_SOURCE_URL ] ), 'review drafts register canonical source URL' );

function social_delivery( $status = 'queued', $duplicate = false ) {
	return array(
		'success'  => true,
		'delivery' => array(
			'delivery_ref' => 'dop_' . str_repeat( 'a', 64 ),
			'status'       => $status,
			'duplicate'    => $duplicate,
			'retryable'    => 'failed' === $status,
			'deliveries'   => array(),
			'errors'       => 'failed' === $status ? array( array( 'channel' => 'instagram', 'code' => 'undelivered' ) ) : array(),
		),
	);
}

function reset_social_test() {
	$post_id = 42;
	$GLOBALS['social_calls']     = array();
	$GLOBALS['social_responses'] = array();
	$GLOBALS['social_current_blog_id'] = 12;
	$GLOBALS['social_blog_stack'] = array();
	$GLOBALS['social_posts']     = array( $post_id => new WP_Post( $post_id ) );
	$GLOBALS['social_meta']      = array(
		$post_id => array(
			ExtraChillStudio\META_PLATFORMS   => array( 'instagram' ),
			ExtraChillStudio\META_CAPTION     => 'Approved caption.',
			ExtraChillStudio\META_IMAGES      => array(
				array(
					'url'       => 'https://extrachill.example.test/image.jpg',
					'source_id' => '1:84',
				),
			),
			ExtraChillStudio\META_MEDIA_KIND  => 'image',
			ExtraChillStudio\META_PUBLISH_LOG => array(),
		),
	);
	return $post_id;
}

$post_id = reset_social_test();
$missing = ExtraChillStudio\enqueue_social_publish( $GLOBALS['social_posts'][ $post_id ] );
social_assert( ! $missing['success'], 'missing Socials dependency fails closed' );
social_assert( 'social_publish_capability_unavailable' === $missing['error']['code'], 'missing dependency uses stable capability code' );
social_assert( true === $missing['error']['retryable'], 'missing dependency remains retryable' );

$post_id = reset_social_test();
$GLOBALS['social_responses']['datamachine/enqueue-social-publish'] = array(
	array(
		'success' => false,
		'error'   => array(
			'code'      => 'social_publish_scheduler_unavailable',
			'message'   => 'Scheduling failed.',
			'retryable' => true,
		),
	),
	social_delivery(),
);
ExtraChillStudio\on_publish_crosspost( 'publish', 'pending', $GLOBALS['social_posts'][ $post_id ] );
$failure = $GLOBALS['social_meta'][ $post_id ][ ExtraChillStudio\META_PUBLISH_LOG ][0];
social_assert( 'social_publish_scheduler_unavailable' === $failure['code'], 'transient enqueue failure is recorded stably' );
social_assert( true === $failure['retryable'], 'transient enqueue failure preserves retryability' );
$recovered = ExtraChillStudio\retry_social_publish( $post_id );
social_assert( $recovered['success'], 'pre-receipt retry replays enqueue successfully' );
social_assert( $GLOBALS['social_calls'][0]['input']['idempotency_key'] === $GLOBALS['social_calls'][1]['input']['idempotency_key'], 'pre-receipt retry reuses the original idempotency identity' );
social_assert( array() === $GLOBALS['social_meta'][ $post_id ][ ExtraChillStudio\META_PUBLISH_LOG ], 'successful handoff clears obsolete transient errors' );

$post_id = reset_social_test();
$provider_error = array(
	'success' => false,
	'error'   => array(
		'code'      => 'social_publish_provider_unavailable',
		'message'   => 'Provider unavailable.',
		'retryable' => false,
	),
);
$GLOBALS['social_responses']['datamachine/enqueue-social-publish'] = array( $provider_error );
$provider_result = ExtraChillStudio\enqueue_social_publish( $GLOBALS['social_posts'][ $post_id ] );
social_assert( $provider_error === $provider_result, 'provider-unavailable response remains owner-defined and stable' );

$post_id = reset_social_test();
$GLOBALS['social_responses']['datamachine/enqueue-social-publish'] = array(
	social_delivery(),
	social_delivery( 'queued', true ),
);
ExtraChillStudio\on_publish_crosspost( 'publish', 'pending', $GLOBALS['social_posts'][ $post_id ] );
ExtraChillStudio\on_publish_crosspost( 'publish', 'pending', $GLOBALS['social_posts'][ $post_id ] );
social_assert( 2 === count( $GLOBALS['social_calls'] ), 'duplicate publish transition safely replays enqueue' );
social_assert( $GLOBALS['social_calls'][0]['input']['idempotency_key'] === $GLOBALS['social_calls'][1]['input']['idempotency_key'], 'duplicate transitions use one idempotency identity' );
social_assert( 'studio-social-publish:12:42' === $GLOBALS['social_calls'][0]['input']['idempotency_key'], 'idempotency identity is site scoped' );
social_assert( '1:84' === $GLOBALS['social_calls'][0]['input']['content_ref']['asset_refs'][0]['source_id'], 'cross-site canonical media identity reaches Socials intact' );
social_assert( ! isset( $GLOBALS['social_calls'][0]['input']['attribution_post'] ), 'ordinary review drafts omit source attribution' );
social_assert( 'dop_' . str_repeat( 'a', 64 ) === $GLOBALS['social_meta'][ $post_id ][ ExtraChillStudio\META_DELIVERY_REF ], 'only opaque delivery receipt is persisted' );

$post_id = reset_social_test();
$source_post_id = 99;
$GLOBALS['social_posts'][ $source_post_id ] = new WP_Post( $source_post_id );
$GLOBALS['social_meta'][ $post_id ][ ExtraChillStudio\META_SOURCE_POST ] = $source_post_id;
$GLOBALS['social_meta'][ $post_id ][ ExtraChillStudio\META_SOURCE_URL ] = 'https://main.example.test/article-99/';
$GLOBALS['social_responses']['datamachine/enqueue-social-publish'] = array( social_delivery() );
ExtraChillStudio\enqueue_social_publish( $GLOBALS['social_posts'][ $post_id ] );
$article_input = $GLOBALS['social_calls'][0]['input'];
social_assert( array( 'site_id' => 1, 'post_id' => 99 ) === $article_input['attribution_post'], 'article review enqueue declares canonical source attribution' );
social_assert( 42 === $article_input['content_ref']['post_id'], 'article attribution does not replace the Studio review resource' );
social_assert( 'https://studio.example.test/social-42/' === $article_input['content_ref']['source_url'], 'article attribution does not replace the review source URL' );
social_assert( 'studio-social-publish:12:42' === $article_input['idempotency_key'], 'article attribution preserves review idempotency identity' );

$post_id = reset_social_test();
$GLOBALS['social_meta'][ $post_id ][ ExtraChillStudio\META_SOURCE_POST ] = 99;
$GLOBALS['social_responses']['datamachine/enqueue-social-publish'] = array( social_delivery() );
$invalid_attribution = ExtraChillStudio\enqueue_social_publish( $GLOBALS['social_posts'][ $post_id ] );
social_assert( ! $invalid_attribution['success'], 'invalid declared attribution blocks review enqueue' );
social_assert( 'social_publish_attribution_invalid' === $invalid_attribution['error']['code'], 'invalid attribution uses a stable error code' );
social_assert( false === $invalid_attribution['error']['retryable'], 'invalid attribution is non-retryable' );
social_assert( array() === $GLOBALS['social_calls'], 'invalid attribution fails before calling Socials' );

$post_id = reset_social_test();
$GLOBALS['social_meta'][ $post_id ][ ExtraChillStudio\META_DELIVERY_REF ] = 'dop_' . str_repeat( 'a', 64 );
$GLOBALS['social_responses']['datamachine/retry-social-publish'] = array( social_delivery( 'retrying' ) );
$retried = ExtraChillStudio\retry_social_publish( $post_id );
social_assert( $retried['success'], 'explicit retry succeeds' );
social_assert( 'datamachine/retry-social-publish' === $GLOBALS['social_calls'][0]['name'], 'retry delegates to the Socials retry ability' );
social_assert( $GLOBALS['social_meta'][ $post_id ][ ExtraChillStudio\META_DELIVERY_REF ] === $GLOBALS['social_calls'][0]['input']['delivery_ref'], 'retry reuses the opaque delivery receipt' );

$post_id = reset_social_test();
$GLOBALS['social_meta'][ $post_id ][ ExtraChillStudio\META_DELIVERY_REF ] = 'dop_' . str_repeat( 'a', 64 );
$GLOBALS['social_responses']['datamachine/get-social-publish'] = array(
	social_delivery( 'queued' ),
	social_delivery( 'failed' ),
);
$queued = ExtraChillStudio\get_social_publish_state( $post_id );
$failed = ExtraChillStudio\get_social_publish_state( $post_id );
social_assert( 'queued' === $queued['delivery']['status'], 'state reads preserve queued owner state' );
social_assert( 'failed' === $failed['delivery']['status'] && true === $failed['delivery']['retryable'], 'state reads preserve retryable failed owner state' );
social_assert( 2 === count( $GLOBALS['social_calls'] ), 'state is read from Socials instead of copied locally' );

fwrite( STDOUT, "Social publish contract tests passed.\n" );
