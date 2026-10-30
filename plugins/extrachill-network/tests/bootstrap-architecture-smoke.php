<?php
/**
 * Structural bootstrap, provider lifecycle, and compatibility tests.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'EXTRACHILL_NETWORK_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

$GLOBALS['bootstrap_actions'] = array();

function do_action( $name, ...$args ) {
	$GLOBALS['bootstrap_actions'][] = array_merge( array( $name ), $args );
}

function is_admin() {
	return (bool) ( $GLOBALS['bootstrap_is_admin'] ?? false );
}

function is_network_admin() {
	return (bool) ( $GLOBALS['bootstrap_is_network_admin'] ?? false );
}

require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Foundation/bootstrap.php';
require_once EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/FeatureProviders.php';

function bootstrap_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

// Registration is named, rejects duplicates, and isolates unavailable/failing providers.
$GLOBALS['extrachill_network_feature_providers'] = array();
$runs = array();
bootstrap_assert(
	extrachill_network_register_feature_provider(
		'first',
		static function () use ( &$runs ) {
			$runs[] = 'first';
		},
		static function () {
			return true;
		}
	),
	'provider registration accepts a valid named provider'
);
bootstrap_assert(
	! extrachill_network_register_feature_provider( 'first', static function () {}, static function () { return true; } ),
	'duplicate provider registration preserves the first owner'
);
extrachill_network_register_feature_provider( 'absent', static function () {}, static function () { return false; } );
extrachill_network_register_feature_provider(
	'throwing',
	static function () {
		throw new RuntimeException( 'expected provider failure' );
	},
	static function () {
		return true;
	}
);
extrachill_network_register_feature_provider(
	'last',
	static function () use ( &$runs ) {
		$runs[] = 'last';
	},
	static function () {
		return true;
	}
);

$statuses = extrachill_network_boot_feature_providers();
bootstrap_assert( array( 'first', 'last' ) === $runs, 'optional provider failures do not stop later providers' );
bootstrap_assert( 'booted' === $statuses['first'], 'available provider reports booted status' );
bootstrap_assert( 'skipped' === $statuses['absent'], 'unavailable provider reports skipped status' );
bootstrap_assert( 'failed' === $statuses['throwing'], 'throwing provider reports failed status' );
extrachill_network_boot_feature_providers();
bootstrap_assert( array( 'first', 'last' ) === $runs, 'provider composition is idempotent' );

// Default composition remains stable in frontend, CLI, and network-admin contexts.
foreach ( array( 'frontend', 'cli', 'admin' ) as $context ) {
	$GLOBALS['extrachill_network_feature_providers'] = array();
	$GLOBALS['bootstrap_is_admin']                   = 'admin' === $context;
	$GLOBALS['bootstrap_is_network_admin']           = 'admin' === $context;
	extrachill_network_register_default_feature_providers();
	$providers = $GLOBALS['extrachill_network_feature_providers'];

	bootstrap_assert( 8 === count( $providers ), "full composition registers every provider in {$context} context" );
	bootstrap_assert(
		( 'admin' === $context ) === call_user_func( $providers['administration']['is_available'] ),
		"administration prerequisites are explicit in {$context} context"
	);
}

// Every file formerly loaded by the entry point remains owned by one boundary.
$composition = file_get_contents( EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Foundation/bootstrap.php' )
	. file_get_contents( EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/FeatureProviders.php' );
$compatibility_paths = array(
	'inc/core/blog-ids.php',
	'inc/core/mail.php',
	'inc/core/service-assertions.php',
	'inc/core/cross-site-rest.php',
	'inc/core/frontend-path-resolver.php',
	'inc/core/cross-site-content-migration.php',
	'inc/core/extrachill-turnstile.php',
	'inc/core/oauth-helpers.php',
	'inc/core/object-cache-config.php',
	'inc/core/legacy-path-redirects.php',
	'inc/core/new-site-setup.php',
	'inc/core/ad-policy.php',
	'inc/core/experiments.php',
	'inc/integrations/ad-delivery.php',
	'inc/integrations/member-ad-benefit.php',
	'inc/integrations/artist-profile-discussions.php',
	'inc/integrations/artist-term-binding-deletion.php',
	'inc/cross-site-links/cross-site-links.php',
	'inc/cross-site-links/network-bridge.php',
	'inc/taxonomy/register.php',
	'inc/taxonomy/network-terms.php',
	'inc/taxonomy/term-classification.php',
	'inc/NetworkStats/bootstrap.php',
	'inc/NetworkStats/helpers.php',
	'inc/Abilities/TaxonomyCountAbilities.php',
	'inc/Abilities/NetworkTermAbilities.php',
	'inc/Abilities/NetworkMediaAbilities.php',
	'inc/Abilities/QRCodeAbility.php',
	'inc/Abilities/MailAbilities.php',
	'inc/Abilities/AdPolicyAbility.php',
	'inc/Abilities/ExperimentAssignmentAbility.php',
	'inc/Abilities/CrossSiteContentMigrationAbilities.php',
	'inc/Abilities/CommentEditorAbilities.php',
	'inc/commerce/auth/bootstrap.php',
	'admin/network-menu.php',
);
foreach ( $compatibility_paths as $path ) {
	bootstrap_assert( false !== strpos( $composition, $path ), "public API owner retained for {$path}" );
}

// Foundation lifecycle code must remain ignorant of downstream feature domains.
$foundation = file_get_contents( EXTRACHILL_NETWORK_PLUGIN_DIR . 'inc/Foundation/bootstrap.php' );
$forbidden  = array( 'ad-policy', 'experiments.php', 'taxonomy/', 'integrations/', 'commerce/', 'theme/', 'og-cards/', 'admin/' );
foreach ( $forbidden as $term ) {
	bootstrap_assert( false === strpos( $foundation, $term ), "foundation excludes downstream reference {$term}" );
}

$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/FoundationBootstrapProbe.php' );
exec( $command, $output, $exit_code );
bootstrap_assert( 0 === $exit_code, 'foundation-only child-process bootstrap succeeds' );
