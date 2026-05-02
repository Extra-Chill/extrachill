<?php
/**
 * Game Master conversation runner.
 *
 * Direct consumer of agents-api's AgentConversationLoop and WordPress core's
 * wp-ai-client. Replaces the legacy DataMachine ChatOrchestrator dispatch path
 * with a self-contained one-turn loop that:
 *
 *   1. Builds the system prompt by prepending SOUL.md to a per-turn game
 *      context block built from the request payload.
 *   2. Builds a wp-ai-client message history from the rolling
 *      conversationHistory the frontend roundtrips on every request.
 *   3. Declares the progress_story tool to the provider as a wp-ai-client
 *      function declaration.
 *   4. Dispatches one provider turn through wp_ai_client_prompt().
 *   5. Extracts the assistant's narrative text and (optionally) executes a
 *      progress_story tool call through the agents-api ToolExecutorInterface
 *      adapter declared in inc/tools/progress-story-tool.php.
 *   6. Returns the narrative + next_step_id to the REST handler.
 *
 * Transcript persistence is intentionally a no-op
 * ({@see NullAgentConversationTranscriptPersister}). The frontend already
 * roundtrips full conversationHistory / progression_history /
 * transition_context on every request, so server-side session storage is
 * dead weight here. The session_id parameter is accepted and echoed back
 * for backward-compatible response shape but is not persisted or read.
 *
 * @package ExtraChillAIAdventure
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\AgentConversationLoop;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

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

	$turn_runner = static function ( array $messages, array $turn_context ) use ( $provider, $model, $system_prompt, $tool_declaration, $tool_executor, $context ) {
		return extrachill_ai_adventure_run_turn(
			$messages,
			$turn_context,
			$provider,
			$model,
			$system_prompt,
			$tool_declaration,
			$tool_executor,
			$context
		);
	};

	try {
		$result = AgentConversationLoop::run(
			$initial_messages,
			$turn_runner,
			array(
				'max_turns' => 1,
				'context'   => $context,
			)
		);
	} catch ( \Throwable $e ) {
		return new WP_Error(
			'conversation_loop_failed',
			$e->getMessage(),
			array( 'status' => 500 )
		);
	}

	$narrative    = '';
	$next_step_id = null;

	foreach ( array_reverse( $result['messages'] ) as $envelope ) {
		if ( ! is_array( $envelope ) ) {
			continue;
		}

		$role    = $envelope['role'] ?? '';
		$content = $envelope['content'] ?? '';
		if ( 'assistant' === $role && is_string( $content ) && '' !== $content ) {
			$narrative = $content;
			break;
		}
	}

	foreach ( $result['tool_execution_results'] as $tool_result ) {
		if ( ! is_array( $tool_result ) ) {
			continue;
		}

		if ( EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME !== ( $tool_result['tool_name'] ?? '' ) ) {
			continue;
		}

		$payload = is_array( $tool_result['result'] ?? null ) ? $tool_result['result'] : array();
		if ( ! empty( $payload['progressed'] ) && ! empty( $payload['next_step_id'] ) ) {
			$next_step_id = (string) $payload['next_step_id'];
		}
	}

	return array(
		'narrative'    => $narrative,
		'next_step_id' => $next_step_id,
		'session_id'   => $session_id,
	);
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

/**
 * Execute one conversation turn through wp-ai-client.
 *
 * Returns an AgentConversationResult-shaped array consumed by
 * AgentConversationLoop. Tool calls are executed inline via the supplied
 * ToolExecutorInterface adapter so the loop sees both the assistant message
 * and any tool execution results in a single turn.
 *
 * @param array                                     $messages         Normalized envelopes from the loop.
 * @param array                                     $turn_context     Loop turn context.
 * @param string                                    $provider         wp-ai-client provider id.
 * @param string                                    $model            wp-ai-client model id.
 * @param string                                    $system_prompt    System prompt text.
 * @param array                                     $tool_declaration Normalized RuntimeToolDeclaration.
 * @param \AgentsAPI\AI\Tools\ToolExecutorInterface $tool_executor    Tool executor adapter.
 * @param array                                     $game_context     Game context for tool execution.
 * @return array AgentConversationResult-compatible array.
 */
function extrachill_ai_adventure_run_turn(
	array $messages,
	array $turn_context,
	string $provider,
	string $model,
	string $system_prompt,
	array $tool_declaration,
	\AgentsAPI\AI\Tools\ToolExecutorInterface $tool_executor,
	array $game_context
): array {
	$turn = isset( $turn_context['turn'] ) ? (int) $turn_context['turn'] : 1;

	$registry = AiClient::defaultRegistry();
	if ( ! $registry->hasProvider( $provider ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
		throw new \InvalidArgumentException( sprintf( 'Provider "%s" is not registered with wp-ai-client.', $provider ) );
	}

	$provider_id    = $registry->getProviderId( $provider );
	$model_instance = $registry->getProviderModel( $provider_id, $model, null );

	$builder = wp_ai_client_prompt()
		->using_provider( $provider_id )
		->using_model( $model_instance );

	if ( '' !== $system_prompt ) {
		$builder = $builder->using_system_instruction( $system_prompt );
	}

	$history = extrachill_ai_adventure_messages_to_history( $messages );
	if ( ! empty( $history ) ) {
		$builder = $builder->with_history( ...$history );
	}

	$function_declaration = new FunctionDeclaration(
		EXTRACHILL_AI_ADVENTURE_TOOL_FUNCTION_NAME,
		(string) ( $tool_declaration['description'] ?? '' ),
		extrachill_ai_adventure_function_schema( $tool_declaration )
	);
	$builder              = $builder->using_function_declarations( $function_declaration );

	try {
		$ai_result = $builder->generate_text_result();
	} catch ( \Throwable $e ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not rendered output.
		throw new \RuntimeException( 'wp-ai-client request failed: ' . $e->getMessage(), 0, $e );
	}

	$assistant_text = extrachill_ai_adventure_result_text( $ai_result );
	$tool_calls     = extrachill_ai_adventure_extract_tool_calls( $ai_result );

	if ( '' !== $assistant_text ) {
		$messages[] = array(
			'role'    => 'assistant',
			'content' => $assistant_text,
		);
	}

	$tool_execution_results = array();
	foreach ( $tool_calls as $tool_call ) {
		$function_name = (string) $tool_call['name'];
		if ( EXTRACHILL_AI_ADVENTURE_TOOL_FUNCTION_NAME !== $function_name ) {
			continue;
		}

		$parameters = $tool_call['parameters'];

		$messages[] = array(
			'role'     => 'assistant',
			'content'  => sprintf( 'Calling tool: %s', EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME ),
			'metadata' => array(
				'type'       => \AgentsAPI\AI\AgentMessageEnvelope::TYPE_TOOL_CALL,
				'tool_name'  => EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME,
				'parameters' => $parameters,
				'turn'       => $turn,
			),
		);

		$normalized_call = array(
			'tool_name'  => EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME,
			'parameters' => $parameters,
			'metadata'   => array( 'source' => 'extrachill' ),
		);

		$execution = $tool_executor->executeToolCall( $normalized_call, $tool_declaration, $game_context );

		$success     = (bool) ( $execution['success'] ?? false );
		$result_data = is_array( $execution['result'] ?? null ) ? $execution['result'] : array();
		$error_text  = isset( $execution['error'] ) ? (string) $execution['error'] : '';
		$result_text = $success
			? wp_json_encode( $result_data )
			: ( '' !== $error_text ? $error_text : 'Tool execution failed.' );

		$messages[] = array(
			'role'     => 'tool',
			'content'  => is_string( $result_text ) ? $result_text : '',
			'metadata' => array(
				'type'      => \AgentsAPI\AI\AgentMessageEnvelope::TYPE_TOOL_RESULT,
				'tool_name' => EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME,
				'success'   => $success,
				'turn'      => $turn,
				'tool_data' => $result_data,
				'error'     => '' !== $error_text ? $error_text : null,
			),
		);

		$tool_execution_results[] = array(
			'tool_name'  => EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME,
			'result'     => $success
				? array_merge( array( 'success' => true ), $result_data )
				: array(
					'success' => false,
					'error'   => $error_text,
				),
			'parameters' => $parameters,
			'turn_count' => $turn,
		);
	}

	return array(
		'messages'               => $messages,
		'tool_execution_results' => $tool_execution_results,
	);
}

/**
 * Convert AgentMessageEnvelope-normalized messages to wp-ai-client history DTOs.
 *
 * Tool-call / tool-result envelopes are skipped: providers receive the live
 * tool dispatch via function_declarations + the next-turn message, not via
 * replayed historical tool envelopes.
 *
 * @param array $messages Normalized envelopes.
 * @return array<int, Message>
 */
function extrachill_ai_adventure_messages_to_history( array $messages ): array {
	$history = array();

	foreach ( $messages as $envelope ) {
		if ( ! is_array( $envelope ) ) {
			continue;
		}

		$type    = (string) ( $envelope['type'] ?? \AgentsAPI\AI\AgentMessageEnvelope::TYPE_TEXT );
		$role    = (string) ( $envelope['role'] ?? '' );
		$content = $envelope['content'] ?? '';

		if ( \AgentsAPI\AI\AgentMessageEnvelope::TYPE_TEXT !== $type ) {
			continue;
		}

		if ( ! is_string( $content ) || '' === $content ) {
			continue;
		}

		$parts = array( new MessagePart( $content ) );

		if ( 'assistant' === $role || 'model' === $role ) {
			$history[] = new ModelMessage( $parts );
			continue;
		}

		if ( 'user' === $role ) {
			$history[] = new UserMessage( $parts );
		}
	}

	return $history;
}

/**
 * Convert a normalized RuntimeToolDeclaration into a JSON Schema for wp-ai-client.
 *
 * The agents-api declaration accepts both compact (`required` list) and
 * legacy (`required => true` per property) shapes. wp-ai-client expects the
 * compact JSON Schema object form.
 *
 * @param array $declaration Normalized RuntimeToolDeclaration.
 * @return array<string, mixed>|null
 */
function extrachill_ai_adventure_function_schema( array $declaration ): ?array {
	$parameters = $declaration['parameters'] ?? array();
	if ( ! is_array( $parameters ) || empty( $parameters ) ) {
		return null;
	}

	if ( ! isset( $parameters['type'] ) && ! isset( $parameters['properties'] ) ) {
		$parameters = array(
			'type'       => 'object',
			'properties' => $parameters,
		);
	}

	if ( empty( $parameters['type'] ) ) {
		$parameters['type'] = 'object';
	}

	return $parameters;
}

/**
 * Extract assistant text content from a wp-ai-client GenerativeAiResult.
 *
 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result wp-ai-client result.
 * @return string Assistant narrative text, or empty string when none was returned.
 */
function extrachill_ai_adventure_result_text( $result ): string {
	try {
		return (string) $result->toText();
	} catch ( \Throwable $e ) {
		if ( str_contains( $e->getMessage(), 'No text content found' ) ) {
			return '';
		}
		throw $e;
	}
}

/**
 * Extract function calls from a wp-ai-client GenerativeAiResult.
 *
 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result wp-ai-client result.
 * @return array<int, array{name:string,parameters:array,id:mixed}>
 */
function extrachill_ai_adventure_extract_tool_calls( $result ): array {
	$tool_calls = array();
	$candidates = $result->getCandidates();
	if ( empty( $candidates ) ) {
		return $tool_calls;
	}

	foreach ( $candidates[0]->getMessage()->getParts() as $part ) {
		$function_call = $part->getFunctionCall();
		if ( null === $function_call ) {
			continue;
		}

		$tool_calls[] = array(
			'name'       => (string) $function_call->getName(),
			'parameters' => extrachill_ai_adventure_normalize_function_args( $function_call->getArgs() ),
			'id'         => $function_call->getId(),
		);
	}

	return $tool_calls;
}

/**
 * Coerce wp-ai-client function-call args into a plain array.
 *
 * @param mixed $args Args returned by FunctionCall::getArgs().
 * @return array
 */
function extrachill_ai_adventure_normalize_function_args( $args ): array {
	if ( is_array( $args ) ) {
		return $args;
	}

	if ( is_string( $args ) && '' !== $args ) {
		$decoded = json_decode( $args, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
	}

	if ( is_object( $args ) ) {
		return (array) $args;
	}

	return array();
}
