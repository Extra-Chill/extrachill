<?php
/**
 * Isolated stubs for persisted qualification tests.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO,Universal.Namespaces.OneDeclarationPerFile -- Same convention as tests/bootstrap.php: this fixture intentionally mixes WP function shims across several real-code namespaces (ExtraChillEvents\Core, ExtraChillEvents\Abilities, ExtraChillEvents\Cli) in one file so each stub resolves via PHP's namespace-fallback rules for the production code that calls it.

namespace ExtraChillEvents\Core;

// wp_get_ability() is intentionally NOT stubbed here. QualifyFingerprinter
// (this namespace) calls the bareword wp_get_ability(), which PHP resolves
// against this namespace before falling back to the global function. This
// suite runs under a sandbox runtime where core's real global
// wp_get_ability() (wp-includes/abilities-api.php) is already loaded and
// PersistedQualificationContextTest registers real test-double abilities
// via wp_register_ability() — shadowing it here would silently defeat that
// and resurrect the redeclaration risk fixed in
// https://github.com/Extra-Chill/extrachill-events/issues/846.

/**
 * @phpstan-assert-if-true \WP_Error $thing
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof \WP_Error;
}

function wp_remote_get(): array {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => '<html><body>Events</body></html>',
	);
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

function wp_remote_retrieve_header(): string {
	return '';
}

// QualifyVerdictsTable is intentionally NOT stubbed here. The real class
// (inc/Core/QualifyVerdictsTable.php, same namespace) is already loaded in
// this sandbox runtime — UnqualifiableFlowsCommand instantiates it directly.
// meets_pause_confirmation() only consults verdict history via a real,
// already-activated table that has no rows for this test's made-up URLs, so
// it returns false exactly like the old stub did, without needing any
// seeded state. See https://github.com/Extra-Chill/extrachill-events/issues/846.

namespace ExtraChillEvents\Abilities;

function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ): bool {
	unset( $hook_name, $callback, $priority, $accepted_args );
	return true;
}

/**
 * @phpstan-assert-if-true \WP_Error $thing
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof \WP_Error;
}

function wp_remote_get(): array {
	return \ExtraChillEvents\Core\wp_remote_get();
}

function wp_remote_retrieve_body( array $response ): string {
	return \ExtraChillEvents\Core\wp_remote_retrieve_body( $response );
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

// DataMachine\Core\ExecutionContext and DataMachineEvents\Utilities\EventIdentifierGenerator
// are intentionally NOT stubbed here. Both are real classes from mounted
// dependency plugins (data-machine, data-machine-events) that are already
// loaded in this sandbox runtime — QualifyFingerprinter guards its own calls
// with class_exists() precisely because these are real, optional
// dependencies, not managed-runtime primitives to duplicate. Declaring
// same-named fixture classes here previously fataled with "Cannot redeclare
// class" the moment this file was reachable from a sandbox suite. The test
// now seeds real datamachine_processed_items rows (see
// PersistedQualificationContextTest::seed_processed_items()) so the real
// ExecutionContext::classifySourceItems() reports the same "already
// processed" outcome the old fake unconditionally returned. See
// https://github.com/Extra-Chill/extrachill-events/issues/846.

namespace ExtraChillEvents\Cli;

/**
 * @phpstan-assert-if-true \WP_Error $thing
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof \WP_Error;
}

// FlowOps is intentionally NOT stubbed here. The real class
// (inc/Cli/FlowOps.php, same namespace) is already loaded in this sandbox
// runtime — UnqualifiableFlowsCommand's apply-repair path calls
// FlowOps::repair_flow_source_url() directly, which reads and writes a real
// datamachine_flows row via $wpdb. The test seeds that row (see
// PersistedQualificationContextTest::seed_flow_row()) so the real repair
// genuinely succeeds instead of being recorded by a fake. See
// https://github.com/Extra-Chill/extrachill-events/issues/846.
