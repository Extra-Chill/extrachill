<?php
/** Focused standalone checks for the public gameplay security contract. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
	public string $code;
	public array $data;
	public function __construct( string $code, string $message = '', array $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}
}
class WP_REST_Request {}

function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function __( $value ): string { return $value; }
function wp_json_encode( $value ): string { return json_encode( $value, JSON_UNESCAPED_SLASHES ); }
function wp_salt(): string { return 'test-secret'; }
function get_current_blog_id(): int { return 1; }
function wp_strip_all_tags( $value ): string { return strip_tags( $value ); }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_generate_uuid4(): string { return '123e4567-e89b-12d3-a456-426614174000'; }
function is_post_publicly_viewable( $post ): bool { return 'publish' === $post->post_status; }
function parse_blocks( $content ): array { return $GLOBALS['test_blocks']; }
function get_post( $post_id ) { return 42 === $post_id ? (object) array( 'post_status' => 'publish', 'post_content' => 'saved' ) : null; }

$GLOBALS['admission_calls'] = array();
function extrachill_api_check_public_write_rate_limit( $request, $scope, $limit ) {
	$GLOBALS['admission_calls'][] = array( $scope, $limit );
	return true;
}

require dirname( __DIR__ ) . '/inc/runtime/game-request.php';

function assert_true( $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$inner = array(
	array(
		'name'       => 'extrachill/ai-adventure-path',
		'attributes' => array( 'pathPrompt' => 'Trusted path' ),
		'innerBlocks' => array(
			array(
				'name'       => 'extrachill/ai-adventure-step',
				'attributes' => array(
					'stepId'    => 'start',
					'stepPrompt' => 'Trusted opening',
					'triggers'   => array( array( 'triggerPhrase' => 'Leave', 'destinationStep' => 'end_game' ) ),
				),
				'innerBlocks' => array(),
			),
		),
	),
);
$attributes = array(
	'title'             => 'Trusted title',
	'adventurePrompt'   => 'Trusted adventure',
	'gameMasterPersona' => 'Trusted persona',
	'innerBlocksJSON'   => wp_json_encode( $inner ),
);
$GLOBALS['test_blocks'] = array(
	array(
		'blockName'   => 'extrachill/ai-adventure',
		'attrs'       => $attributes,
		'innerBlocks' => array(),
	),
);

$schema = extrachill_ai_adventure_rest_args();
assert_true( 500 === $schema['playerInput']['maxLength'], 'player input must have a strict maximum' );
assert_true( 24576 === $schema['state']['maxLength'], 'opaque state must have a strict maximum' );
assert_true( is_wp_error( extrachill_ai_adventure_validate_request_shape( array( 'action' => 'play', 'state' => 'x', 'playerInput' => 'go', 'persona' => 'forged' ) ) ), 'extra system context must be rejected' );

$reference = extrachill_ai_adventure_create_reference( 42, $attributes );
$request   = array( 'action' => 'start', 'adventure' => $reference, 'characterName' => 'River' );
$game      = extrachill_ai_adventure_resolve_request( $request );
assert_true( ! is_wp_error( $game ), 'normal start should resolve' );
assert_true( 'Trusted persona' === $game['persona'] && 'Trusted opening' === $game['step_prompt'], 'context must come from saved block data' );

$tampered = substr_replace( $reference, 'A', 5, 1 );
assert_true( is_wp_error( extrachill_ai_adventure_decode_token( $tampered, 'adventure' ) ), 'forged references must fail authentication' );

$state = extrachill_ai_adventure_advance_state( $game, array( 'narrative' => 'Welcome.', 'next_step_id' => null ) );
assert_true( strlen( $state ) <= $schema['state']['maxLength'], 'issued state must fit its REST schema bound' );
$turn  = extrachill_ai_adventure_resolve_request( array( 'action' => 'play', 'state' => $state, 'playerInput' => 'Look around' ) );
assert_true( ! is_wp_error( $turn ) && 'Look around' === $turn['player_input'], 'normal play should preserve bounded player input' );

$GLOBALS['admission_calls'] = array();
$admitted = extrachill_ai_adventure_admit_request( new WP_REST_Request(), array( 'action' => 'play', 'state' => $state ) );
assert_true( true === $admitted && 2 === count( $GLOBALS['admission_calls'] ), 'admission must enforce client and verified-session counters' );
assert_true( array( 'ai-adventure', 30 ) === $GLOBALS['admission_calls'][0] && 12 === $GLOBALS['admission_calls'][1][1], 'admission limits must remain bounded' );

$oversized = $attributes;
$oversized['innerBlocksJSON'] = wp_json_encode( array_fill( 0, 13, $inner[0] ) );
assert_true( is_wp_error( extrachill_ai_adventure_normalize_config( $oversized ) ), 'oversized nested path arrays must be rejected' );

fwrite( STDOUT, "Security contract checks passed.\n" );
