<?php
/**
 * Tests for QualifyVerdict::confirmation_for() and recheck_interval_for().
 *
 * Pure-unit — no WP test framework required.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\QualifyVerdict;
use PHPUnit\Framework\TestCase;

class QualifyConfirmationRulesTest extends TestCase {

	public function test_qualified_verdicts_have_no_confirmation_rule(): void {
		$this->assertNull( QualifyVerdict::confirmation_for( QualifyVerdict::QUALIFIED_STRUCTURED ) );
		$this->assertNull( QualifyVerdict::confirmation_for( QualifyVerdict::QUALIFIED_FOR_FLYER ) );
	}

	public function test_extraction_gap_requires_two_verdicts_over_48h(): void {
		$rule = QualifyVerdict::confirmation_for( QualifyVerdict::EXTRACTION_GAP );
		$this->assertIsArray( $rule );
		$this->assertSame( 2, $rule['verdicts'] );
		$this->assertSame( 48, $rule['hours'] );
	}

	public function test_bot_blocked_requires_three_verdicts_over_7_days(): void {
		$rule = QualifyVerdict::confirmation_for( QualifyVerdict::BOT_BLOCKED );
		$this->assertIsArray( $rule );
		$this->assertSame( 3, $rule['verdicts'] );
		$this->assertSame( 168, $rule['hours'] );
	}

	public function test_unreachable_requires_three_verdicts_over_7_days(): void {
		$rule = QualifyVerdict::confirmation_for( QualifyVerdict::UNREACHABLE );
		$this->assertIsArray( $rule );
		$this->assertSame( 3, $rule['verdicts'] );
		$this->assertSame( 168, $rule['hours'] );
	}

	public function test_reservation_only_pauses_on_single_verdict(): void {
		$rule = QualifyVerdict::confirmation_for( QualifyVerdict::RESERVATION_ONLY );
		$this->assertIsArray( $rule );
		$this->assertSame( 1, $rule['verdicts'] );
		$this->assertSame( 0, $rule['hours'] );
	}

	public function test_covered_elsewhere_pauses_on_single_verdict(): void {
		$rule = QualifyVerdict::confirmation_for( QualifyVerdict::COVERED_ELSEWHERE );
		$this->assertIsArray( $rule );
		$this->assertSame( 1, $rule['verdicts'] );
		$this->assertSame( 0, $rule['hours'] );
	}

	public function test_unknown_verdict_has_no_rule(): void {
		$this->assertNull( QualifyVerdict::confirmation_for( 'not_a_real_verdict' ) );
	}

	public function test_extraction_gap_recheck_interval_is_14_days(): void {
		$this->assertSame( 14 * DAY_IN_SECONDS, QualifyVerdict::recheck_interval_for( QualifyVerdict::EXTRACTION_GAP ) );
	}

	public function test_bot_blocked_recheck_interval_is_7_days(): void {
		$this->assertSame( 7 * DAY_IN_SECONDS, QualifyVerdict::recheck_interval_for( QualifyVerdict::BOT_BLOCKED ) );
	}

	public function test_unreachable_recheck_interval_is_3_days(): void {
		$this->assertSame( 3 * DAY_IN_SECONDS, QualifyVerdict::recheck_interval_for( QualifyVerdict::UNREACHABLE ) );
	}

	public function test_qualified_for_flyer_recheck_interval_is_21_days(): void {
		$this->assertSame( 21 * DAY_IN_SECONDS, QualifyVerdict::recheck_interval_for( QualifyVerdict::QUALIFIED_FOR_FLYER ) );
	}

	public function test_permanent_verdicts_have_null_recheck_interval(): void {
		$this->assertNull( QualifyVerdict::recheck_interval_for( QualifyVerdict::RESERVATION_ONLY ) );
		$this->assertNull( QualifyVerdict::recheck_interval_for( QualifyVerdict::COVERED_ELSEWHERE ) );
	}

	public function test_qualified_structured_has_null_recheck_interval(): void {
		$this->assertNull( QualifyVerdict::recheck_interval_for( QualifyVerdict::QUALIFIED_STRUCTURED ) );
	}

	public function test_unknown_verdict_has_null_recheck_interval(): void {
		$this->assertNull( QualifyVerdict::recheck_interval_for( 'not_a_real_verdict' ) );
	}
}
