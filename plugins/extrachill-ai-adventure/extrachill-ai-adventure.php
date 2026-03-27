<?php
/**
 * Plugin Name: Extra Chill AI Adventure
 * Plugin URI: https://extrachill.com
 * Description: AI-powered interactive text adventure block for WordPress. Create branching narratives with an AI game master powered by Data Machine.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Chris Huber
 * Author URI: https://extrachill.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-ai-adventure
 * Domain Path: /languages
 * Network: false
 *
 * @package ExtraChillAIAdventure
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_AI_ADVENTURE_VERSION', '1.0.0' );
define( 'EXTRACHILL_AI_ADVENTURE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_AI_ADVENTURE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Register the AI adventure blocks.
 *
 * Three-block hierarchy: adventure > path > step.
 */
function extrachill_ai_adventure_register_blocks() {
	$blocks_dir = file_exists( __DIR__ . '/build/blocks' ) ? 'build/blocks' : 'src/blocks';

	register_block_type( __DIR__ . '/' . $blocks_dir . '/ai-adventure' );
	register_block_type( __DIR__ . '/' . $blocks_dir . '/ai-adventure-path' );
	register_block_type( __DIR__ . '/' . $blocks_dir . '/ai-adventure-step' );
}
add_action( 'init', 'extrachill_ai_adventure_register_blocks' );

/**
 * Register REST API route for AI adventure gameplay.
 *
 * Handles introduction requests, conversation turns, and progression analysis.
 */
function extrachill_ai_adventure_register_routes() {
	register_rest_route(
		'extrachill/v1',
		'/ai-adventure',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_ai_adventure_handle_request',
			'permission_callback' => 'extrachill_ai_adventure_permission_check',
		)
	);
}
add_action( 'rest_api_init', 'extrachill_ai_adventure_register_routes' );

/**
 * Permission check for AI adventure endpoint.
 *
 * Allows public access but applies basic rate limiting via transient.
 *
 * @return true|WP_Error
 */
function extrachill_ai_adventure_permission_check() {
	$ip         = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
	$cache_key  = 'ec_ai_adv_rate_' . md5( $ip );
	$requests   = (int) get_transient( $cache_key );
	$max_per_min = 30;

	if ( $requests >= $max_per_min ) {
		return new WP_Error(
			'rate_limited',
			__( 'Too many requests. Please slow down.', 'extrachill-ai-adventure' ),
			array( 'status' => 429 )
		);
	}

	set_transient( $cache_key, $requests + 1, MINUTE_IN_SECONDS );

	return true;
}

/**
 * Handle AI adventure REST request.
 *
 * Routes to introduction or conversation handler based on request params.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_ai_adventure_handle_request( WP_REST_Request $request ) {
	$builder_path = EXTRACHILL_AI_ADVENTURE_PLUGIN_DIR . 'build/blocks/ai-adventure/includes/prompt-builder.php';

	if ( ! file_exists( $builder_path ) ) {
		$builder_path = EXTRACHILL_AI_ADVENTURE_PLUGIN_DIR . 'src/blocks/ai-adventure/includes/prompt-builder.php';
	}

	if ( ! file_exists( $builder_path ) ) {
		return new WP_Error(
			'prompt_builder_missing',
			__( 'AI adventure prompt builder not found.', 'extrachill-ai-adventure' ),
			array( 'status' => 500 )
		);
	}

	require_once $builder_path;

	if ( ! class_exists( 'ExtraChill_AI_Adventure_Prompt_Builder' ) ) {
		return new WP_Error(
			'prompt_builder_unavailable',
			__( 'AI adventure prompt builder class unavailable.', 'extrachill-ai-adventure' ),
			array( 'status' => 500 )
		);
	}

	$params = $request->get_json_params();
	$game   = extrachill_ai_adventure_extract_params( $params );

	$progression_section = ExtraChill_AI_Adventure_Prompt_Builder::build_progression_section( $game['progression_history'] );

	if ( ! empty( $game['is_introduction'] ) ) {
		return extrachill_ai_adventure_handle_introduction( $game );
	}

	return extrachill_ai_adventure_handle_conversation( $game, $progression_section );
}

/**
 * Extract and sanitize game parameters from request.
 *
 * @param array $params Raw request parameters.
 * @return array Sanitized parameters.
 */
function extrachill_ai_adventure_extract_params( $params ) {
	return array(
		'is_introduction'      => ! empty( $params['isIntroduction'] ),
		'character_name'       => sanitize_text_field( $params['characterName'] ?? '' ),
		'adventure_title'      => sanitize_text_field( $params['adventureTitle'] ?? '' ),
		'adventure_prompt'     => sanitize_textarea_field( $params['adventurePrompt'] ?? '' ),
		'path_prompt'          => sanitize_textarea_field( $params['pathPrompt'] ?? '' ),
		'step_prompt'          => sanitize_textarea_field( $params['stepPrompt'] ?? '' ),
		'persona'              => sanitize_textarea_field( $params['gameMasterPersona'] ?? '' ),
		'progression_history'  => ( isset( $params['storyProgression'] ) && is_array( $params['storyProgression'] ) ) ? $params['storyProgression'] : array(),
		'player_input'         => sanitize_text_field( $params['playerInput'] ?? '' ),
		'triggers'             => ( isset( $params['triggers'] ) && is_array( $params['triggers'] ) ) ? $params['triggers'] : array(),
		'conversation_history' => ( isset( $params['conversationHistory'] ) && is_array( $params['conversationHistory'] ) ) ? $params['conversationHistory'] : array(),
		'transition_context'   => ( isset( $params['transitionContext'] ) && is_array( $params['transitionContext'] ) ) ? $params['transitionContext'] : array(),
	);
}

/**
 * Handle introduction request (new step entry).
 *
 * @param array $params Game parameters.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_ai_adventure_handle_introduction( $params ) {
	$messages = ExtraChill_AI_Adventure_Prompt_Builder::build_introduction_messages( $params );

	$response = extrachill_ai_adventure_call_ai( $messages );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return new WP_REST_Response( array( 'narrative' => $response ), 200 );
}

/**
 * Handle conversation turn (player input).
 *
 * @param array  $params              Game parameters.
 * @param string $progression_section  Formatted progression history.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_ai_adventure_handle_conversation( $params, $progression_section ) {
	// Check for step progression first (if triggers exist) to avoid wasted AI calls.
	$next_step_id = null;

	if ( ! empty( $params['triggers'] ) ) {
		$next_step_id = extrachill_ai_adventure_analyze_progression( $params, $progression_section );
	}

	// If progressing to a new step, skip the narrative call entirely.
	if ( $next_step_id ) {
		return new WP_REST_Response(
			array(
				'narrative'  => '',
				'nextStepId' => $next_step_id,
			),
			200
		);
	}

	// No progression — generate the narrative response.
	$conversation_messages = ExtraChill_AI_Adventure_Prompt_Builder::build_conversation_messages( $params, $progression_section );
	$narrative_response    = extrachill_ai_adventure_call_ai( $conversation_messages );

	if ( is_wp_error( $narrative_response ) ) {
		return $narrative_response;
	}

	return new WP_REST_Response(
		array(
			'narrative'  => $narrative_response,
			'nextStepId' => null,
		),
		200
	);
}

/**
 * Analyze player input for step progression triggers.
 *
 * @param array  $params              Game parameters.
 * @param string $progression_section  Formatted progression history.
 * @return string|null Next step ID or null.
 */
function extrachill_ai_adventure_analyze_progression( $params, $progression_section ) {
	$progression_messages = ExtraChill_AI_Adventure_Prompt_Builder::build_progression_messages(
		$params,
		$progression_section,
		$params['triggers']
	);

	$response = extrachill_ai_adventure_call_ai( $progression_messages );

	if ( is_wp_error( $response ) || empty( $response ) ) {
		return null;
	}

	$json_start = strpos( $response, '{' );
	if ( false === $json_start ) {
		return null;
	}

	$progression_data = json_decode( substr( $response, $json_start ), true );

	if ( empty( $progression_data['shouldProgress'] ) || empty( $progression_data['triggerId'] ) ) {
		return null;
	}

	foreach ( $params['triggers'] as $trigger ) {
		if ( isset( $trigger['id'] ) && $trigger['id'] === $progression_data['triggerId'] ) {
			return $trigger['destination'] ?? null;
		}
	}

	return null;
}

/**
 * Call the AI backend via Data Machine.
 *
 * Uses the datamachine_ai_request filter for model-agnostic AI calls.
 * Falls back to chubes_ai_request for backward compatibility.
 *
 * @param array $messages Chat completion messages array.
 * @return string|WP_Error AI response content or error.
 */
function extrachill_ai_adventure_call_ai( $messages ) {
	$request = array(
		'messages' => $messages,
		'model'    => 'gpt-4o-mini',
	);

	// Try Data Machine first, then legacy filter.
	$response = apply_filters( 'datamachine_ai_request', null, $request );

	if ( null === $response ) {
		$response = apply_filters( 'chubes_ai_request', $request, 'openai' );
	}

	if ( empty( $response['success'] ) ) {
		return new WP_Error(
			'ai_request_failed',
			$response['error'] ?? __( 'AI request failed.', 'extrachill-ai-adventure' ),
			array( 'status' => 500 )
		);
	}

	return $response['data']['choices'][0]['message']['content'] ?? '';
}

/**
 * Self-register AI adventure blocks into editor allowlists.
 *
 * @param string[] $allowed_blocks Current allowed blocks.
 * @param string   $editor_type    Editor context.
 * @return string[]
 */
function extrachill_ai_adventure_be_allowlist( $allowed_blocks, $editor_type = '' ) {
	// Exclude from lightweight editors (bbPress forums).
	if ( 'bbpress' === $editor_type ) {
		return $allowed_blocks;
	}

	return array_values(
		array_unique(
			array_merge(
				$allowed_blocks,
				array(
					'extrachill/ai-adventure',
					'extrachill/ai-adventure-path',
					'extrachill/ai-adventure-step',
				)
			)
		)
	);
}
add_filter( 'blocks_everywhere_allowed_blocks', 'extrachill_ai_adventure_be_allowlist', 20, 2 );

/**
 * Self-register AI adventure blocks into Studio Compose allowlist.
 *
 * @param string[] $allowed_blocks Current allowed blocks.
 * @return string[]
 */
function extrachill_ai_adventure_studio_allowlist( $allowed_blocks ) {
	return array_values(
		array_unique(
			array_merge(
				$allowed_blocks,
				array(
					'extrachill/ai-adventure',
					'extrachill/ai-adventure-path',
					'extrachill/ai-adventure-step',
				)
			)
		)
	);
}
add_filter( 'extrachill_studio_allowed_blocks', 'extrachill_ai_adventure_studio_allowlist' );
