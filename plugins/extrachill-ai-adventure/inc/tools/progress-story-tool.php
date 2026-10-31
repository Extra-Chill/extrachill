<?php
/**
 * progress_story runtime tool wiring.
 *
 * Declares the `progress_story` runtime tool via agents-api's
 * WP_Agent_Tool_Declaration and provides a WP_Agent_Tool_Executor
 * implementation that delegates to the `extrachill/progress-story` ability.
 *
 * The tool name (`progress_story`) is deliberately slash-free: the shipped
 * WP_Agent_Default_Provider_Turn_Adapter passes the declaration name straight
 * through to the provider's function declaration, and OpenAI/Anthropic reject
 * `/` in function names. The underlying WordPress ability keeps its namespaced
 * `extrachill/progress-story` identifier — the tool name and the ability name
 * are distinct concepts, bridged by the executor.
 *
 * @package ExtraChillAIAdventure
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Result;
use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;

/**
 * Canonical agents-api tool name for the progress_story tool.
 *
 * Deliberately slash-free so the shipped WP_Agent_Default_Provider_Turn_Adapter
 * — which passes the declaration name straight through to the provider via
 * wp-ai-client FunctionDeclaration — emits a name OpenAI/Anthropic accept (both
 * reject `/` in function names). The conversation loop keys the transcript and
 * tool-execution results by this canonical name. The underlying WordPress
 * ability is still the namespaced `extrachill/progress-story`
 * ({@see EXTRACHILL_AI_ADVENTURE_PROGRESS_STORY_ABILITY}); the tool name and the
 * ability name are distinct concepts.
 */
define( 'EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME', 'progress_story' );

/**
 * Provider-facing function name. Identical to the canonical tool name — a single
 * slash-free identifier now serves both the provider function declaration and
 * the agents-api runtime tool name. Retained as a named alias for call sites
 * that read it as "the function name the model sees."
 */
define( 'EXTRACHILL_AI_ADVENTURE_TOOL_FUNCTION_NAME', EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME );

/**
 * WordPress ability backing the progress_story tool (namespaced, slash-form).
 */
define( 'EXTRACHILL_AI_ADVENTURE_PROGRESS_STORY_ABILITY', 'extrachill/progress-story' );

/**
 * Build the normalized agents-api WP_Agent_Tool_Declaration for progress_story.
 *
 * Returned shape conforms to {@see WP_Agent_Tool_Declaration::normalize()}.
 *
 * @return array Normalized runtime tool declaration.
 */
function extrachill_ai_adventure_progress_story_declaration(): array {
	return WP_Agent_Tool_Declaration::normalize(
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
			'executor'    => WP_Agent_Tool_Declaration::EXECUTOR_CLIENT,
			'scope'       => WP_Agent_Tool_Declaration::SCOPE_RUN,
		)
	);
}

/**
 * agents-api WP_Agent_Tool_Executor implementation for progress_story.
 *
 * Bridges agents-api tool dispatch into the `extrachill/progress-story`
 * ability. The conversation runner passes the structured triggers list
 * through `$context['triggers']`; the AI only supplies `trigger_id`.
 */
class ExtraChill_AI_Adventure_Progress_Story_Executor implements WP_Agent_Tool_Executor {

	/**
	 * @inheritDoc
	 */
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		$tool_name  = (string) ( $tool_call['tool_name'] ?? EXTRACHILL_AI_ADVENTURE_TOOL_RUNTIME_NAME );
		$parameters = is_array( $tool_call['parameters'] ?? null ) ? $tool_call['parameters'] : array();
		$trigger_id = isset( $parameters['trigger_id'] ) ? (string) $parameters['trigger_id'] : '';

		if ( '' === $trigger_id ) {
			return WP_Agent_Tool_Result::error(
				$tool_name,
				'trigger_id is required. Check the available triggers in the game context.'
			);
		}

		$triggers = isset( $context['triggers'] ) && is_array( $context['triggers'] ) ? $context['triggers'] : array();
		if ( empty( $triggers ) ) {
			return WP_Agent_Tool_Result::error(
				$tool_name,
				'No story triggers are available for the current step. Continue the conversation instead.'
			);
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( EXTRACHILL_AI_ADVENTURE_PROGRESS_STORY_ABILITY ) : null;
		if ( ! $ability ) {
			return WP_Agent_Tool_Result::error(
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
			return WP_Agent_Tool_Result::error( $tool_name, $ability_result->get_error_message() );
		}

		if ( ! empty( $ability_result['progressed'] ) ) {
			return WP_Agent_Tool_Result::success(
				$tool_name,
				array(
					'progressed'   => true,
					'next_step_id' => (string) ( $ability_result['next_step_id'] ?? '' ),
					'message'      => 'Story progressed. Narrate the transition to the new scene.',
				)
			);
		}

		return WP_Agent_Tool_Result::success(
			$tool_name,
			array(
				'progressed' => false,
				'message'    => 'Trigger "' . $trigger_id . '" not found in available triggers. Continue the conversation.',
			)
		);
	}
}
