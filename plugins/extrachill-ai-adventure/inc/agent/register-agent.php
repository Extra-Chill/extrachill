<?php
/**
 * Game Master agent registration.
 *
 * Registers the `game-master` agent declaratively via agents-api. Identity
 * (SOUL.md, default provider/model, conversation compaction policy) ships
 * as code with this plugin so the agent stops being a hand-maintained DB row.
 *
 * Memory store is intentionally not declared: SOUL.md is read directly from
 * the plugin source and prepended to the system prompt every turn (see the
 * conversation runner). agents-api memory seeds are a scaffolding hint for
 * consumers that materialize per-instance memory; this plugin does not.
 *
 * @package ExtraChillAIAdventure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Absolute path to the game-master SOUL.md shipped with this plugin.
 *
 * @return string
 */
function extrachill_ai_adventure_soul_path(): string {
	return EXTRACHILL_AI_ADVENTURE_PLUGIN_DIR . 'agent/SOUL.md';
}

/**
 * Read the game-master SOUL.md identity content.
 *
 * Cached per-request because it is consumed on every conversation turn.
 *
 * @return string
 */
function extrachill_ai_adventure_get_soul(): string {
	static $soul = null;
	if ( null !== $soul ) {
		return $soul;
	}

	$path = extrachill_ai_adventure_soul_path();
	$soul = is_readable( $path ) ? (string) file_get_contents( $path ) : '';

	return $soul;
}

/**
 * Register the game-master agent with agents-api.
 *
 * Runs on `wp_agents_api_init` per the agents-api consumer integration
 * pattern. Feature-detects `wp_register_agent` so the plugin degrades
 * gracefully when agents-api is not active (e.g. during early plugin
 * activation order races).
 */
function extrachill_ai_adventure_register_agent(): void {
	if ( ! function_exists( 'wp_register_agent' ) ) {
		return;
	}

	wp_register_agent(
		EXTRACHILL_AI_ADVENTURE_AGENT_SLUG,
		array(
			'label'        => __( 'Game Master', 'extrachill-ai-adventure' ),
			'description'  => __( 'Narrator and companion-character game master for AI Adventure interactive fiction blocks.', 'extrachill-ai-adventure' ),
			'memory_seeds' => array(
				'SOUL.md' => extrachill_ai_adventure_soul_path(),
			),
			'default_config' => array(
				'default_provider' => 'openai',
				'default_model'    => 'gpt-4o-mini',
				'tool_policy'      => array(
					'mode'  => 'allow',
					'tools' => array( 'progress_story' ),
				),
			),
			'meta'         => array(
				'source_plugin'  => 'extrachill-ai-adventure/extrachill-ai-adventure.php',
				'source_type'    => 'bundled-agent',
				'source_package' => 'extrachill-ai-adventure',
				'source_version' => defined( 'EXTRACHILL_AI_ADVENTURE_VERSION' ) ? EXTRACHILL_AI_ADVENTURE_VERSION : '',
			),
		)
	);
}
add_action( 'wp_agents_api_init', 'extrachill_ai_adventure_register_agent' );
