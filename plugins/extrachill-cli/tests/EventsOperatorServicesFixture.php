<?php
/**
 * Minimal extrachill-events service doubles for EventsOperatorCommandsTest.
 *
 * Loaded only after the "owner plugin inactive" assertions so class_exists()
 * flips from false to true mid-test.
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test doubles.

namespace ExtraChillEvents\Core;

class VenueRequalifier {
	public static $flows = array();
	public static function validate_audit_options( $apply_repair, $dry_run, $confirmed ) {
		if ( $apply_repair && ( $dry_run || ! $confirmed ) ) {
			return new \WP_Error( 'apply_repair_requires_confirmation', '--apply-repair requires --yes and cannot be combined with --dry-run.' );
		}
		return true;
	}
	public function zero_yield_candidates( $min_runs, $zero_yield_only, $limit ) {
		$GLOBALS['eoc_calls'][] = array( 'zero_yield_candidates', $min_runs, $zero_yield_only, $limit );
		return null;
	}
	public function target_flows( $target, $limit ) {
		$GLOBALS['eoc_calls'][] = array( 'target_flows', $target, $limit );
		return 'all' === $target ? self::$flows : null;
	}
	public function requalify_flows( $flows, $dry_run, $auto_pause ) {
		$GLOBALS['eoc_calls'][] = array( 'requalify_flows', count( $flows ), $dry_run, $auto_pause );
		return array(
			array(
				'flow_id'     => 42,
				'flow_name'   => 'Venue',
				'new_verdict' => 'bot_blocked',
				'event_count' => 0,
				'action'      => 'recommend_pause',
			),
		);
	}
}

class RootLocationReconciler {
	public static $ready = true;
	public function assert_apply_ready() {
		return self::$ready;
	}
	public function reconcile( $apply ) {
		$GLOBALS['eoc_calls'][] = array( 'reconcile', $apply );
		return array();
	}
}
