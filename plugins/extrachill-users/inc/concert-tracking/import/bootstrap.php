<?php
/**
 * Concert import framework bootstrap.
 *
 * Loads framework + adapters, wires the Action Scheduler hook, and registers
 * the default setlist.fm + phish.net adapters via the
 * `extrachill_concert_import_sources` filter. Third parties can add more
 * adapters by hooking the same filter.
 *
 * @package ExtraChill\Users\Concert_Import
 * @since 0.13.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/ExternalEvent.php';
require_once __DIR__ . '/ImportSource.php';
require_once __DIR__ . '/EventMatcher.php';
require_once __DIR__ . '/ImportOrchestrator.php';
require_once __DIR__ . '/EventCreator.php';
require_once __DIR__ . '/Sources/SetlistFmImportSource.php';
require_once __DIR__ . '/Sources/PhishNetImportSource.php';

// Register the Action Scheduler worker hook.
\ExtraChill\Users\Concert_Import\ImportOrchestrator::register_hooks();

// Register each source's API key with Data Machine's encrypted auth provider
// registry. This makes the credentials discoverable via
// `wp datamachine auth status` and encrypts them at rest via the standard
// AES-256-GCM envelope.
//
// The AuthProvider classes (ConcertImportAuthProvider + its SetlistFm/PhishNet
// subclasses) extend Data Machine's `DataMachine\Core\OAuth\BaseAuthProvider`.
// That parent is supplied by Data Machine's PSR-4 autoloader, which is not
// guaranteed to be registered at the moment this bootstrap runs (it loads on an
// `init` hook in extrachill-users.php). Requiring the subclass files eagerly at
// the top level therefore intermittently fataled with a front-end white screen
// ("Class DataMachine\Core\OAuth\BaseAuthProvider not found") on cold-boot races
// where DM's autoloader had not yet declared the parent.
//
// Fix: defer the require_once of every DM-dependent AuthProvider file until DM's
// base class is confirmed present, inside the existing class_exists guard on
// plugins_loaded priority 30. When Data Machine is absent the concert-import
// auth providers are simply not registered and the rest of the plugin boots
// cleanly. The non-DM Sources (the ImportSource subclasses) only instantiate an
// AuthProvider at fetch time, so they remain safe to load eagerly above.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\DataMachine\\Core\\OAuth\\BaseAuthProvider' ) ) {
			return;
		}
		require_once __DIR__ . '/Sources/ConcertImportAuthProvider.php';
		require_once __DIR__ . '/Sources/SetlistFmAuthProvider.php';
		require_once __DIR__ . '/Sources/PhishNetAuthProvider.php';
		\ExtraChill\Users\Concert_Import\Sources\ConcertImportAuthProvider::register_with_datamachine();
	},
	30
);

// Register default sources.
add_filter(
	'extrachill_concert_import_sources',
	function ( $sources ) {
		if ( ! is_array( $sources ) ) {
			$sources = array();
		}
		$sources[] = new \ExtraChill\Users\Concert_Import\Sources\SetlistFmImportSource();
		$sources[] = new \ExtraChill\Users\Concert_Import\Sources\PhishNetImportSource();
		return $sources;
	}
);
