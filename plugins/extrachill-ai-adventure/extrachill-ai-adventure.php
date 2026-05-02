<?php
/**
 * Plugin Name: Extra Chill AI Adventure
 * Plugin URI: https://extrachill.com
 * Description: AI-powered interactive text adventure block for WordPress. Create branching narratives with a game master agent built on the Agents API substrate and WordPress core's AI Client.
 * Version: 1.2.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
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

define( 'EXTRACHILL_AI_ADVENTURE_VERSION', '1.2.0' );
define( 'EXTRACHILL_AI_ADVENTURE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_AI_ADVENTURE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The agent slug used for AI adventure game mastering.
 */
define( 'EXTRACHILL_AI_ADVENTURE_AGENT_SLUG', 'game-master' );

// Load ability registration (hooks into wp_abilities_api_init).
require_once EXTRACHILL_AI_ADVENTURE_PLUGIN_DIR . 'inc/abilities/progress-story.php';

// Register the game-master agent declaratively (hooks into wp_agents_api_init).
require_once EXTRACHILL_AI_ADVENTURE_PLUGIN_DIR . 'inc/agent/register-agent.php';

// Runtime tool declaration + executor for the progress_story tool.
require_once EXTRACHILL_AI_ADVENTURE_PLUGIN_DIR . 'inc/tools/progress-story-tool.php';

// Conversation runner that dispatches turns through agents-api + wp-ai-client.
require_once EXTRACHILL_AI_ADVENTURE_PLUGIN_DIR . 'inc/runtime/conversation-runner.php';

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
 * Permission check with rate limiting.
 *
 * @return true|WP_Error
 */
function extrachill_ai_adventure_permission_check() {
	$ip          = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	$cache_key   = 'ec_ai_adv_rate_' . md5( $ip );
	$requests    = (int) get_transient( $cache_key );
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
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_ai_adventure_handle_request( WP_REST_Request $request ) {
	$params = $request->get_json_params();
	$game   = extrachill_ai_adventure_extract_params( $params );

	if ( ! empty( $game['is_introduction'] ) ) {
		return extrachill_ai_adventure_handle_introduction( $game );
	}

	return extrachill_ai_adventure_handle_conversation( $game );
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
		'session_id'           => sanitize_text_field( $params['sessionId'] ?? '' ),
	);
}

/**
 * Handle introduction request (entering a new step).
 *
 * @param array $params Game parameters.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_ai_adventure_handle_introduction( $params ) {
	$context = extrachill_ai_adventure_build_context( $params, 'introduction' );
	$message = 'What happens now?';

	$response = extrachill_ai_adventure_run_conversation( $message, $context, $params['session_id'] );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return new WP_REST_Response(
		array(
			'narrative'  => $response['narrative'],
			'nextStepId' => $response['next_step_id'] ?? null,
			'sessionId'  => $response['session_id'],
		),
		200
	);
}

/**
 * Handle conversation turn (player input).
 *
 * @param array $params Game parameters.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_ai_adventure_handle_conversation( $params ) {
	$context  = extrachill_ai_adventure_build_context( $params, 'conversation' );
	$message  = 'Player says/does: ' . $params['player_input'];
	$response = extrachill_ai_adventure_run_conversation( $message, $context, $params['session_id'] );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return new WP_REST_Response(
		array(
			'narrative'  => $response['narrative'],
			'nextStepId' => $response['next_step_id'],
			'sessionId'  => $response['session_id'],
		),
		200
	);
}

/**
 * Build game context for Data Machine client_context.
 *
 * @param array  $params    Game parameters.
 * @param string $turn_type One of: introduction, conversation, progression.
 * @return array Context data for DM.
 */
function extrachill_ai_adventure_build_context( $params, $turn_type ) {
	$context = array(
		'game_turn_type'   => $turn_type,
		'adventure_title'  => $params['adventure_title'],
		'adventure_prompt' => $params['adventure_prompt'],
		'path_prompt'      => $params['path_prompt'],
		'step_prompt'      => $params['step_prompt'],
		'character_name'   => $params['character_name'],
		'persona'          => $params['persona'],
	);

	// Build progression history summary.
	if ( ! empty( $params['progression_history'] ) ) {
		$lines = array();
		$i     = 1;
		foreach ( array_slice( $params['progression_history'], -10 ) as $entry ) {
			$lines[] = $i . '. Step: "' . ( $entry['stepAction'] ?? '' ) . '" / Player chose: "' . ( $entry['triggerActivated'] ?? '' ) . '"';
			++$i;
		}
		$context['story_progression'] = implode( "\n", $lines );
	}

	// Build conversation history summary.
	if ( ! empty( $params['conversation_history'] ) ) {
		$lines = array();
		foreach ( array_slice( $params['conversation_history'], -10 ) as $entry ) {
			$label   = ( 'player' === $entry['type'] ) ? $params['character_name'] : 'Game Master';
			$lines[] = $label . ': "' . ( $entry['content'] ?? '' ) . '"';
		}
		$context['conversation_history'] = implode( "\n", $lines );
	}

	// Triggers — both human-readable descriptions for the AI and structured
	// data for the progress_story tool to validate against.
	if ( ! empty( $params['triggers'] ) ) {
		$trigger_descriptions         = array_map(
			function ( $t ) {
				return ( $t['action'] ?? '' );
			},
			$params['triggers']
		);
		$context['available_choices'] = implode( ' | ', $trigger_descriptions );

		// Structured triggers for the progress_story tool.
		$context['triggers'] = array_map(
			function ( $t ) {
				return array(
					'id'          => $t['id'] ?? '',
					'action'      => $t['action'] ?? '',
					'destination' => $t['destination'] ?? '',
				);
			},
			$params['triggers']
		);
	}

	// Transition context for introductions.
	if ( 'introduction' === $turn_type && ! empty( $params['transition_context'] ) ) {
		$lines = array();
		foreach ( array_slice( $params['transition_context'], -2 ) as $entry ) {
			$label   = ( 'player' === $entry['type'] ) ? $params['character_name'] : 'Game Master';
			$lines[] = $label . ': "' . ( $entry['content'] ?? '' ) . '"';
		}
		$context['transition_context'] = implode( "\n", $lines );
	}

	return $context;
}

/**
 * Self-register AI adventure blocks into Blocks Everywhere allowlist.
 *
 * Excluded from bbPress (too heavy for forum posts).
 *
 * @param string[] $allowed_blocks Current allowed blocks.
 * @param string   $editor_type    Editor context.
 * @return string[]
 */
function extrachill_ai_adventure_be_allowlist( $allowed_blocks, $editor_type = '' ) {
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
