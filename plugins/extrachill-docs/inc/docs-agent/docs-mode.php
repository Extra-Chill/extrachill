<?php
/**
 * Docs Agent Execution Mode
 *
 * Registers a `docs` agent execution mode with Data Machine and provides its
 * guidance content. When an AI step runs with `agent_modes: ['docs']` in its
 * pipeline or flow config, Data Machine's `AgentModeDirective` (priority 22)
 * picks up the registration and injects this plugin's writing rules into the
 * AI's system context automatically.
 *
 * Pattern mirrors `data-machine-editor`'s `editor` mode registration. The
 * editorial content itself lives in runner-configs/writing-rules.md so that
 * non-engineers can edit the rules in one canonical location without touching
 * PHP. Per-target context layers can be added later as additional callbacks
 * on the same `datamachine_agent_mode_docs` filter — they stack additively.
 *
 * @package ExtraChillDocs
 * @since   0.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the `docs` agent execution mode with Data Machine's mode registry.
 *
 * Skipped cleanly when Data Machine is not loaded (e.g. activation order
 * or environments where extrachill-docs is loaded for other reasons).
 *
 * @since 0.4.0
 * @return void
 */
function extrachill_docs_register_docs_mode(): void {
	if ( ! class_exists( '\DataMachine\Engine\AI\AgentModeRegistry' ) ) {
		return;
	}

	\DataMachine\Engine\AI\AgentModeRegistry::register(
		'docs',
		60,
		array(
			'label'       => __( 'Docs Agent', 'extrachill-docs' ),
			'description' => __(
				'User-facing documentation generation for the Extra Chill network. Enforces end-user voice rules: no plugin / hook / slug naming, no developer terminology, no code blocks. Output reads as help articles for fans, artists, customers — never as technical docs.',
				'extrachill-docs'
			),
		)
	);
}
add_action( 'datamachine_agent_modes', 'extrachill_docs_register_docs_mode' );

/**
 * Provide guidance content for the `docs` execution mode.
 *
 * Reads the canonical writing rules from runner-configs/writing-rules.md and
 * appends them to whatever default Data Machine's AgentModeDirective passed in
 * (currently empty for new modes, but the additive contract is preserved).
 *
 * Other extensions can hook into the same filter at a different priority to
 * append per-run context (target repo, audience, etc.) without touching this
 * function.
 *
 * @since 0.4.0
 *
 * @param string $content Existing guidance text (empty by default for new modes).
 * @param array  $payload Full AI request payload (agent_id, flow_step_id, etc.).
 *                        Part of the filter signature; unused by this provider.
 * @return string Guidance text injected into the system context at priority 22.
 */
function extrachill_docs_provide_docs_mode_guidance( string $content, array $payload ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	$rules_path = EXTRACHILL_DOCS_PLUGIN_DIR . 'runner-configs/writing-rules.md';

	if ( ! is_readable( $rules_path ) ) {
		return $content;
	}

	// Local plugin file read (not a remote URL); WP_Filesystem/wp_remote_get do not apply.
	$rules = file_get_contents( $rules_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $rules || '' === trim( $rules ) ) {
		return $content;
	}

	$rules = trim( $rules );

	return '' === trim( $content ) ? $rules : $content . "\n\n" . $rules;
}
add_filter( 'datamachine_agent_mode_docs', 'extrachill_docs_provide_docs_mode_guidance', 10, 2 );
