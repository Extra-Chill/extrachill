<?php
/**
 * progress_story runtime tool wiring.
 *
 * Declares the `extrachill/progress-story` runtime tool via agents-api's
 * RuntimeToolDeclaration and provides a ToolExecutorInterface implementation
 * that delegates to the `extrachill/progress-story` ability. This is the
 * direct replacement for the legacy DataMachine BaseTool registration.
 *
 * The provider-facing function name is `progress_story` (no slash) because
 * OpenAI/Anthropic function names disallow `/`. The agents-api runtime tool
 * name is the namespaced `extrachill/progress-story`. The two are bridged by
 * the conversation runner.
 *
 * @package ExtraChillAIAdventure
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\Tools\RuntimeToolDeclaration;
use AgentsAPI\AI\Tools\ToolExecutionResult;
use AgentsAPI\AI\Tools\ToolExecutorInterface;

/**
 * Provider-facing function name used by wp-ai-client function declarations.
 */
define( 'EXTRACHILL_AI_ADVENTURE_TOOL_FUNCTION_NAME', 'progress_story' );

/**
 * agents-api runtime tool name (namespaced; required by RuntimeToolDeclaration).
 */
define( 'EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME', 'extrachill/progress-story' );

/**
 * Build the normalized agents-api RuntimeToolDeclaration for progress_story.
 *
 * Returned shape conforms to {@see RuntimeToolDeclaration::normalize()}.
 *
 * @return array Normalized runtime tool declaration.
 */
function extrachill_ai_adventure_progress_story_declaration(): array {
	return RuntimeToolDeclaration::normalize(
		array(
			'name'        => EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME,
			'description' => 'Progress the story to the next step when the player has made a clear decision. '
				. 'Call this ONLY when the player has expressed a definitive choice that matches one of the available story branches. '
				. 'Do NOT call this for questions, uncertainty, or general conversation — only for committed decisions. '
				. 'The available triggers are provided in the game context. Choose the trigger_id that best matches the player\'s decision.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'trigger_id' => array(
						'type'        => 'string',
						'description' => 'The ID of the story branch trigger that the player\'s decision matches. Must be one of the trigger IDs from the current step\'s available choices.',
					),
				),
				'required'   => array( 'trigger_id' ),
			),
			'executor'    => RuntimeToolDeclaration::EXECUTOR_CLIENT,
			'scope'       => RuntimeToolDeclaration::SCOPE_RUN,
		)
	);
}

/**
 * agents-api ToolExecutorInterface implementation for progress_story.
 *
 * Bridges agents-api tool dispatch into the `extrachill/progress-story`
 * ability. The conversation runner passes the structured triggers list
 * through `$context['triggers']`; the AI only supplies `trigger_id`.
 */
class ExtraChill_AI_Adventure_Progress_Story_Executor implements ToolExecutorInterface {

	/**
	 * @inheritDoc
	 */
	public function executeToolCall( array $tool_call, array $tool_definition, array $context = array() ): array {
		$tool_name  = (string) ( $tool_call['tool_name'] ?? EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME );
		$parameters = is_array( $tool_call['parameters'] ?? null ) ? $tool_call['parameters'] : array();
		$trigger_id = isset( $parameters['trigger_id'] ) ? (string) $parameters['trigger_id'] : '';

		if ( '' === $trigger_id ) {
			return ToolExecutionResult::error(
				$tool_name,
				'trigger_id is required. Check the available triggers in the game context.'
			);
		}

		$triggers = isset( $context['triggers'] ) && is_array( $context['triggers'] ) ? $context['triggers'] : array();
		if ( empty( $triggers ) ) {
			return ToolExecutionResult::error(
				$tool_name,
				'No story triggers are available for the current step. Continue the conversation instead.'
			);
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/progress-story' ) : null;
		if ( ! $ability ) {
			return ToolExecutionResult::error(
				$tool_name,
				'Progress story ability is not available.'
			);
		}

		$ability_result = $ability->execute(
			array(
				'trigger_id' => $trigger_id,
				'triggers'   => $triggers,
			)
		);

		if ( is_wp_error( $ability_result ) ) {
			return ToolExecutionResult::error( $tool_name, $ability_result->get_error_message() );
		}

		if ( ! empty( $ability_result['progressed'] ) ) {
			return ToolExecutionResult::success(
				$tool_name,
				array(
					'progressed'   => true,
					'next_step_id' => (string) ( $ability_result['next_step_id'] ?? '' ),
					'message'      => 'Story progressed. Narrate the transition to the new scene.',
				)
			);
		}

		return ToolExecutionResult::success(
			$tool_name,
			array(
				'progressed' => false,
				'message'    => 'Trigger "' . $trigger_id . '" not found in available triggers. Continue the conversation.',
			)
		);
	}
}
