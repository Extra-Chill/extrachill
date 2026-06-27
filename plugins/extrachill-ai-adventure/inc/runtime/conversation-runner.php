<?php
/**
 * Game Master conversation runner.
 *
 * Thin consumer of agents-api's WP_Agent_Conversation_Loop::run_conversation()
 * facade. The facade builds the shipped WP_Agent_Default_Provider_Turn_Adapter
 * (the canonical "run one provider turn through wp-ai-client" primitive) and
 * drives the MEDIATED tool path: the loop owns provider dispatch, assistant-text
 * + tool-call extraction, tool execution via the supplied
 * WP_Agent_Tool_Executor, and transcript assembly.
 *
 * The only consumer-specific glue that lives here is:
 *
 *   1. System-prompt assembly (SOUL.md prepended to a per-turn game context
 *      block built from the request payload).
 *   2. The single progress_story tool declaration + executor
 *      (inc/tools/progress-story-tool.php).
 *   3. Mapping the loop result back to the {narrative, next_step_id, session_id}
 *      shape the REST handlers and block frontend consume.
 *
 * Because AI Adventure's prompt is static (system instruction + the current
 * user turn; the frontend roundtrips full conversationHistory in the context
 * block), it does NOT inject a prompt-input provider — the default identity
 * seam is sufficient and the system prompt is passed through options.
 *
 * Transcript persistence is intentionally a no-op: the frontend already
 * roundtrips full conversationHistory / progression_history /
 * transition_context on every request, so server-side session storage is dead
 * weight here. The session_id parameter is accepted and echoed back for a
 * backward-compatible response shape but is not persisted or read.
 *
 * @package ExtraChillAIAdventure
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\WP_Agent_Conversation_Loop;

/**
 * Run a single game-master turn.
 *
 * @param string $message    Current player input or introduction trigger.
 * @param array  $context    Game context built by extrachill_ai_adventure_build_context().
 * @param string $session_id Opaque session identifier echoed back to the client (not persisted).
 * @return array|WP_Error {
 *     @type string      $narrative    Assistant narrative text.
 *     @type string|null $next_step_id Destination step from progress_story, or null.
 *     @type string      $session_id   Pass-through session identifier.
 * }
 */
function extrachill_ai_adventure_run_conversation( string $message, array $context, string $session_id ) {
	if ( ! function_exists( 'wp_register_agent' ) || ! function_exists( 'wp_get_agent' ) ) {
		return new WP_Error(
			'agents_api_unavailable',
			__( 'agents-api is required for AI Adventure but is not loaded.', 'extrachill-ai-adventure' ),
			array( 'status' => 500 )
		);
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return new WP_Error(
			'wp_ai_client_unavailable',
			__( 'WordPress AI Client is required for AI Adventure but is not available.', 'extrachill-ai-adventure' ),
			array( 'status' => 500 )
		);
	}

	if ( ! class_exists( WP_Agent_Conversation_Loop::class ) ) {
		return new WP_Error(
			'agents_api_unavailable',
			__( 'agents-api conversation loop is required for AI Adventure but is not available.', 'extrachill-ai-adventure' ),
			array( 'status' => 500 )
		);
	}

	$agent = wp_get_agent( EXTRACHILL_AI_ADVENTURE_AGENT_SLUG );
	if ( null === $agent ) {
		return new WP_Error(
			'agent_not_found',
			__( 'Game Master agent is not registered.', 'extrachill-ai-adventure' ),
			array( 'status' => 500 )
		);
	}

	$default_config = $agent->get_default_config();
	$provider       = (string) ( $default_config['default_provider'] ?? '' );
	$model          = (string) ( $default_config['default_model'] ?? '' );

	if ( '' === $provider || '' === $model ) {
		return new WP_Error(
			'model_not_configured',
			__( 'No AI provider/model configured for the Game Master agent.', 'extrachill-ai-adventure' ),
			array( 'status' => 500 )
		);
	}

	$tool_declaration = extrachill_ai_adventure_progress_story_declaration();
	$tool_executor    = new ExtraChill_AI_Adventure_Progress_Story_Executor();

	$system_prompt    = extrachill_ai_adventure_build_system_prompt( $context );
	$initial_messages = array(
		array(
			'role'    => 'user',
			'content' => $message,
		),
	);

	try {
		$result = WP_Agent_Conversation_Loop::run_conversation(
			$initial_messages,
			array( EXTRACHILL_AI_ADVENTURE_TOOL_FUNCTION_NAME => $tool_declaration ),
			$provider,
			$model,
			array(
				'system_prompt' => $system_prompt,
				'tool_executor' => $tool_executor,
				'max_turns'     => 1,
				// The progress_story executor reads the structured triggers list
				// from context; the model only supplies trigger_id. The system
				// prompt is also surfaced through context by the facade.
				'context'       => $context,
			)
		);
	} catch ( \Throwable $e ) {
		return new WP_Error(
			'conversation_loop_failed',
			$e->getMessage(),
			array( 'status' => 500 )
		);
	}

	return array(
		'narrative'    => extrachill_ai_adventure_extract_narrative( $result ),
		'next_step_id' => extrachill_ai_adventure_extract_next_step_id( $result ),
		'session_id'   => $session_id,
	);
}

/**
 * Extract the assistant narrative from a conversation-loop result.
 *
 * The loop appends the provider turn's assistant text as a canonical text
 * envelope. Walk the transcript from the end and return the most recent
 * non-empty assistant text.
 *
 * @param array $result Normalized WP_Agent_Conversation_Loop result.
 * @return string Assistant narrative text, or empty string when none was returned.
 */
function extrachill_ai_adventure_extract_narrative( array $result ): string {
	$messages = isset( $result['messages'] ) && is_array( $result['messages'] ) ? $result['messages'] : array();

	foreach ( array_reverse( $messages ) as $envelope ) {
		if ( ! is_array( $envelope ) ) {
			continue;
		}

		$type    = (string) ( $envelope['type'] ?? 'text' );
		$role    = (string) ( $envelope['role'] ?? '' );
		$content = $envelope['content'] ?? '';

		if ( 'text' === $type && 'assistant' === $role && is_string( $content ) && '' !== $content ) {
			return $content;
		}
	}

	return '';
}

/**
 * Extract the progressed next_step_id from a conversation-loop result.
 *
 * The loop's mediated path records each executed tool in
 * `tool_execution_results`, where the executor's normalized
 * WP_Agent_Tool_Result lives under `result` and its success payload under
 * `result.result`. Return the destination step only when progress_story
 * reported `progressed`.
 *
 * @param array $result Normalized WP_Agent_Conversation_Loop result.
 * @return string|null Destination step id, or null when the story did not progress.
 */
function extrachill_ai_adventure_extract_next_step_id( array $result ): ?string {
	$tool_results = isset( $result['tool_execution_results'] ) && is_array( $result['tool_execution_results'] )
		? $result['tool_execution_results']
		: array();

	foreach ( $tool_results as $tool_result ) {
		if ( ! is_array( $tool_result ) ) {
			continue;
		}

		if ( EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME !== ( $tool_result['tool_name'] ?? '' ) ) {
			continue;
		}

		$envelope = is_array( $tool_result['result'] ?? null ) ? $tool_result['result'] : array();
		$payload  = is_array( $envelope['result'] ?? null ) ? $envelope['result'] : array();

		if ( ! empty( $payload['progressed'] ) && ! empty( $payload['next_step_id'] ) ) {
			return (string) $payload['next_step_id'];
		}
	}

	return null;
}

/**
 * Build the system prompt fed to the provider every turn.
 *
 * SOUL.md identity content is prepended verbatim. The per-turn game context
 * (adventure / path / step prompts, persona, progression history, available
 * choices, transition context) is rendered as a labelled key/value block.
 *
 * @param array $context Game context built by extrachill_ai_adventure_build_context().
 * @return string
 */
function extrachill_ai_adventure_build_system_prompt( array $context ): string {
	$soul = extrachill_ai_adventure_get_soul();

	$lines = array();
	if ( '' !== $soul ) {
		$lines[] = $soul;
		$lines[] = '';
	}

	$lines[] = '## Current Game Context';
	$lines[] = '';

	$labels = array(
		'game_turn_type'       => 'Turn Type',
		'adventure_title'      => 'Adventure',
		'adventure_prompt'     => 'Adventure Prompt',
		'path_prompt'          => 'Path Prompt',
		'step_prompt'          => 'Step Prompt',
		'character_name'       => 'Player Character',
		'persona'              => 'Game Master Persona',
		'story_progression'    => 'Story Progression (recent)',
		'conversation_history' => 'Conversation History (recent)',
		'available_choices'    => 'Available Story Choices',
		'transition_context'   => 'Transition Context',
	);

	foreach ( $labels as $key => $label ) {
		if ( empty( $context[ $key ] ) || ! is_string( $context[ $key ] ) ) {
			continue;
		}

		$lines[] = '### ' . $label;
		$lines[] = $context[ $key ];
		$lines[] = '';
	}

	if ( ! empty( $context['triggers'] ) && is_array( $context['triggers'] ) ) {
		$lines[] = '### Available Trigger IDs';
		foreach ( $context['triggers'] as $trigger ) {
			if ( ! is_array( $trigger ) ) {
				continue;
			}
			$id     = (string) ( $trigger['id'] ?? '' );
			$action = (string) ( $trigger['action'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$lines[] = '- `' . $id . '` — ' . $action;
		}
		$lines[] = '';
	}

	return rtrim( implode( "\n", $lines ) );
}
