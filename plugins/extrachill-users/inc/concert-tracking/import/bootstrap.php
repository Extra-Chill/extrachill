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
require_once __DIR__ . '/Sources/ConcertImportAuthProvider.php';
require_once __DIR__ . '/Sources/SetlistFmAuthProvider.php';
require_once __DIR__ . '/Sources/PhishNetAuthProvider.php';
require_once __DIR__ . '/Sources/SetlistFmImportSource.php';
require_once __DIR__ . '/Sources/PhishNetImportSource.php';

// Register the Action Scheduler worker hook.
\ExtraChill\Users\Concert_Import\ImportOrchestrator::register_hooks();

// Register each source's API key with Data Machine's encrypted auth provider
// registry. This makes the credentials discoverable via
// `wp datamachine auth status` and encrypts them at rest via the standard
// AES-256-GCM envelope. Wired on plugins_loaded so Data Machine has finished
// declaring its base classes.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\\DataMachine\\Core\\OAuth\\BaseAuthProvider' ) ) {
			return;
		}
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
