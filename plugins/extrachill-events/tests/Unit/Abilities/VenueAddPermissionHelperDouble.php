<?php
/**
 * PermissionHelper double for the venue-add unit suite.
 *
 * Models Data Machine's run_as_system() just far enough to prove the
 * contract: Data Machine flow abilities only pass for the caller while the
 * gateway has elevated to the system (extrachill#1810).
 */

namespace DataMachine\Abilities;

if ( ! class_exists( PermissionHelper::class ) ) {
	class PermissionHelper {
		public static function run_as_system( callable $callback ) {
			$previous                               = $GLOBALS['venue_add_test']['as_system'] ?? false;
			$GLOBALS['venue_add_test']['as_system'] = true;
			try {
				return $callback();
			} finally {
				$GLOBALS['venue_add_test']['as_system'] = $previous;
			}
		}
	}
}
