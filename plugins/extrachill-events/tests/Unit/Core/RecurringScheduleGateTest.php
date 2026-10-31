<?php
/**
 * Tests for the per-request recurring schedule gate (Extra-Chill/extrachill#470).
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

use ExtraChillEvents\Core\QualifyVerdictsTable;
use ExtraChillEvents\Core\RecurringScheduleGate;
use PHPUnit\Framework\TestCase;

/** Load global WordPress and Action Scheduler doubles only inside this class's isolated process. */
function extrachill_events_load_recurring_schedule_gate_test_environment(): void {
	$GLOBALS['recurring_gate_test'] = array(
		'transients' => array(),
		'options'    => array(),
		'scheduled'  => array(),
		'lookups'    => 0,
		'schedules'  => 0,
		'tables'     => array(),
	);

	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			return $GLOBALS['recurring_gate_test']['transients'][ $key ] ?? false;
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $expiration = 0 ) {
			$GLOBALS['recurring_gate_test']['transients'][ $key ] = array( $value, $expiration );
			return true;
		}
	}
	if ( ! function_exists( 'as_has_scheduled_action' ) ) {
		function as_has_scheduled_action( $hook, $args = array(), $group = '' ) {
			unset( $args );
			++$GLOBALS['recurring_gate_test']['lookups'];
			return isset( $GLOBALS['recurring_gate_test']['scheduled'][ $group . '|' . $hook ] );
		}
	}
	if ( ! function_exists( 'as_next_scheduled_action' ) ) {
		function as_next_scheduled_action( $hook, $args = array(), $group = '' ) {
			return as_has_scheduled_action( $hook, $args, $group ) ? time() : false;
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default_value = false ) {
			return $GLOBALS['recurring_gate_test']['options'][ $name ] ?? $default_value;
		}
	}
	if ( ! function_exists( 'delete_option' ) ) {
		function delete_option( $name ) {
			unset( $GLOBALS['recurring_gate_test']['options'][ $name ] );
			return true;
		}
	}

	if ( ! isset( $GLOBALS['wpdb'] ) ) {
		$GLOBALS['wpdb'] = new class() {
			public $prefix = 'wp_7_';
			public function get_var( $query ) {
				++$GLOBALS['recurring_gate_test']['lookups'];
				preg_match( "/LIKE '([^']+)'/", (string) $query, $m );
				return isset( $GLOBALS['recurring_gate_test']['tables'][ $m[1] ?? '' ] ) ? $m[1] : null;
			}
		};
	}

	require_once dirname( __DIR__, 3 ) . '/inc/Core/RecurringScheduleGate.php';
	require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifyVerdictsTable.php';
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecurringScheduleGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		extrachill_events_load_recurring_schedule_gate_test_environment();
	}

	/** Schedule callback double; returns an action ID like as_schedule_recurring_action. */
	private function scheduler( string $hook, string $group, int $action_id = 7 ): callable {
		return static function () use ( $hook, $group, $action_id ): int {
			++$GLOBALS['recurring_gate_test']['schedules'];
			if ( $action_id > 0 ) {
				$GLOBALS['recurring_gate_test']['scheduled'][ $group . '|' . $hook ] = true;
			}
			return $action_id;
		};
	}

	public function test_first_request_schedules_and_later_requests_skip_the_lookup(): void {
		RecurringScheduleGate::ensure( 'ece_sweep', 'ece', $this->scheduler( 'ece_sweep', 'ece' ) );
		$this->assertSame( 1, $GLOBALS['recurring_gate_test']['lookups'] );
		$this->assertSame( 1, $GLOBALS['recurring_gate_test']['schedules'] );

		RecurringScheduleGate::ensure( 'ece_sweep', 'ece', $this->scheduler( 'ece_sweep', 'ece' ) );
		RecurringScheduleGate::ensure( 'ece_sweep', 'ece', $this->scheduler( 'ece_sweep', 'ece' ) );
		$this->assertSame( 1, $GLOBALS['recurring_gate_test']['lookups'], 'a confirmed schedule is not looked up again' );
		$this->assertSame( 1, $GLOBALS['recurring_gate_test']['schedules'] );
	}

	public function test_existing_schedule_is_confirmed_without_rescheduling(): void {
		$GLOBALS['recurring_gate_test']['scheduled']['ece|ece_sweep'] = true;
		RecurringScheduleGate::ensure( 'ece_sweep', 'ece', $this->scheduler( 'ece_sweep', 'ece' ) );
		$this->assertSame( 0, $GLOBALS['recurring_gate_test']['schedules'] );
		$this->assertCount( 1, $GLOBALS['recurring_gate_test']['transients'] );
	}

	public function test_failed_schedule_is_retried_on_the_next_request(): void {
		RecurringScheduleGate::ensure( 'ece_sweep', 'ece', $this->scheduler( 'ece_sweep', 'ece', 0 ) );
		$this->assertSame( array(), $GLOBALS['recurring_gate_test']['transients'] );

		RecurringScheduleGate::ensure( 'ece_sweep', 'ece', $this->scheduler( 'ece_sweep', 'ece' ) );
		$this->assertSame( 2, $GLOBALS['recurring_gate_test']['schedules'] );
		$this->assertCount( 1, $GLOBALS['recurring_gate_test']['transients'] );
	}

	public function test_hooks_are_gated_independently_with_their_own_ttl(): void {
		RecurringScheduleGate::ensure( 'ece_a', 'ece', $this->scheduler( 'ece_a', 'ece' ) );
		RecurringScheduleGate::ensure( 'ece_b', 'ece', $this->scheduler( 'ece_b', 'ece' ), 300 );
		$this->assertSame( 2, $GLOBALS['recurring_gate_test']['schedules'] );

		$ttls = array_column( $GLOBALS['recurring_gate_test']['transients'], 1 );
		sort( $ttls );
		$this->assertSame( array( 300, RecurringScheduleGate::TTL ), $ttls );
	}

	public function test_qualify_verdicts_install_trusts_the_marker_and_heals_a_dropped_table(): void {
		$option  = 'extrachill_events_qualify_verdicts_schema_version';
		$version = ( new \ReflectionClassConstant( QualifyVerdictsTable::class, 'SCHEMA_VERSION' ) )->getValue();
		$GLOBALS['recurring_gate_test']['options'][ $option ] = $version;
		QualifyVerdictsTable::maybe_install();
		$this->assertSame( 0, $GLOBALS['recurring_gate_test']['lookups'], 'a current marker skips the table probe' );

		$this->assertFalse( QualifyVerdictsTable::table_exists() );
		$this->assertArrayNotHasKey( $option, $GLOBALS['recurring_gate_test']['options'], 'a missing table clears the marker so the next request reinstalls' );
	}
}
