<?php
/**
 * Tool: progress_story
 *
 * Data Machine chat tool that wraps the extrachill/progress-story ability.
 * Available only to the game-master agent via tool_policy.
 *
 * The AI game master calls this tool when it determines the player has made
 * a clear decision that matches one of the available story branches. The tool
 * validates the trigger and returns the next step destination.
 *
 * @package ExtraChillAIAdventure
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

/**
 * Progress Story tool for AI Adventure game sessions.
 */
class ExtraChill_AI_Adventure_ProgressStory extends BaseTool {

	/**
	 * Register the tool with Data Machine.
	 */
	public function __construct() {
		$this->registerTool(
			'progress_story',
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array(
				'ability'      => 'extrachill/progress-story',
				'access_level' => 'public',
			)
		);
	}

	/**
	 * Tool definition for the AI agent.
	 *
	 * @return array Tool definition.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Progress the story to the next step when the player has made a clear decision. '
				. 'Call this ONLY when the player has expressed a definitive choice that matches one of the available story branches. '
				. 'Do NOT call this for questions, uncertainty, or general conversation — only for committed decisions. '
				. 'The available triggers are provided in the game context. Choose the trigger_id that best matches the player\'s decision.',
			'parameters'  => array(
				'trigger_id' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'The ID of the story branch trigger that the player\'s decision matches. Must be one of the trigger IDs from the current step\'s available choices.',
				),
			),
		);
	}

	/**
	 * Handle tool call from the AI agent.
	 *
	 * Extracts triggers from client_context (passed by the game frontend),
	 * then delegates to the progress-story ability.
	 *
	 * @param array $parameters Tool parameters from AI. Contains 'trigger_id'.
	 * @param array $tool_def   Tool definition metadata.
	 * @return array Standardized tool response.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$trigger_id = $parameters['trigger_id'] ?? '';

		if ( empty( $trigger_id ) ) {
			return $this->buildErrorResponse(
				'trigger_id is required. Check the available triggers in the game context.',
				'progress_story'
			);
		}

		// Retrieve triggers from the chat session's client_context.
		$triggers = $this->get_triggers_from_context( $tool_def );

		if ( empty( $triggers ) ) {
			return $this->buildErrorResponse(
				'No story triggers are available for the current step. Continue the conversation instead.',
				'progress_story'
			);
		}

		// Execute the ability.
		$ability = wp_get_ability( 'extrachill/progress-story' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Progress story ability is not available.',
				'progress_story'
			);
		}

		$result = $ability->execute(
			array(
				'trigger_id' => $trigger_id,
				'triggers'   => $triggers,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'progress_story'
			);
		}

		if ( ! empty( $result['progressed'] ) ) {
			return array(
				'success'   => true,
				'data'      => array(
					'progressed'   => true,
					'next_step_id' => $result['next_step_id'],
					'message'      => 'Story progressed. Narrate the transition to the new scene.',
				),
				'tool_name' => 'progress_story',
			);
		}

		return array(
			'success'   => true,
			'data'      => array(
				'progressed' => false,
				'message'    => 'Trigger "' . $trigger_id . '" not found in available triggers. Continue the conversation.',
			),
			'tool_name' => 'progress_story',
		);
	}

	/**
	 * Extract triggers from the tool definition's context.
	 *
	 * The game frontend passes triggers in client_context, which DM makes
	 * available via the tool_def's context chain.
	 *
	 * @param array $tool_def Tool definition metadata.
	 * @return array Array of trigger objects with 'id' and 'destination'.
	 */
	private function get_triggers_from_context( array $tool_def ): array {
		// Client context is injected into the tool execution context by DM.
		$client_context = $tool_def['client_context'] ?? array();

		if ( ! empty( $client_context['triggers'] ) && is_array( $client_context['triggers'] ) ) {
			return $client_context['triggers'];
		}

		return array();
	}
}
