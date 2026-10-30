<?php
/**
 * Agent-dispatch prompt contract for the PHP fatal alarm (issue #323).
 *
 * @package ExtraChill\Analytics
 */

/**
 * Keep the issue-to-cook workflow and the full incident evidence, untruncated.
 */
final class FatalRateAlarmPromptTest extends Extrachill_Analytics_TestCase {

	/**
	 * A realistic alarm retains all evidence alongside the current workflow.
	 */
	public function test_fix_prompt_tracks_and_cooks_issue_without_truncating_evidence(): void {
		update_site_option( 'extrachill_analytics_fatal_alarm_mention', '123456789012345678' );

		$file  = '/var/www/extrachill.com/htdocs/wp-content/plugins/extrachill-analytics/inc/abilities/reports/class-content-revenue-diagnostics-ability.php';
		$error = 'PHP Fatal error: Uncaught TypeError: array_merge(): Argument #2 must be of type array, null given in ' . $file . ':128';
		$alarm = array(
			'severity'    => 'fatal',
			'file'        => $file,
			'signature'   => hash( 'sha256', $error ),
			'count'       => 42,
			'rate_per_hr' => 168,
			'first_seen'  => '2026-05-01 12:00:00',
			'last_seen'   => '2026-05-01 12:15:00',
			'sample'      => substr( $error . ' Stack trace: #0 {main}', 0, 240 ),
			'reasons'     => array( 'new_signature', 'over_threshold', 'escalated' ),
		);

		$prompt = extrachill_analytics_format_fatal_alarm_fix_prompt( $alarm );

		$this->assertSame( 240, strlen( $alarm['sample'] ) );
		$this->assertStringStartsWith( '<@123456789012345678> 🚨 **PHP fatal alarm — investigate and fix.**', $prompt );
		foreach ( array(
			'**Goal:** find the root cause and get a fix up as a PR.',
			'**Workflow:**',
			'wp extrachill analytics errors --since=1h --severity=fatal',
			'to find the owning repo',
			'File a GitHub issue in that repo with the fatal evidence pasted in',
			'homeboy agent-task cook',
			'one cook per issue',
			'If it has stopped firing but the evidence shows a real defect, still file the issue and cook it',
			'**Site-breaking fatals: merge and emergency deploy.**',
			'front-door or page 500s you can reproduce with `curl`',
			'the fix is minimal, revertable, and PR CI is green',
			'gh api -X PUT repos/<owner>/<repo>/pulls/<n>/merge -f merge_method=squash',
			'dispatch extrachill-network `deploy.yml` for that one component and say it is an emergency',
			'wp extrachill analytics errors --since=15m --severity=fatal',
			'the affected site returns 200',
			'**Everything else is PR-only:**',
			'The run ends at the PR and the operator merges.',
			'Report back with the issue URL, the PR URL, and any merge or deploy you performed.',
			'**Fatal details (from the live debug.log tail):**',
			'- Severity: FATAL',
			'- Location: `' . $alarm['file'] . '`',
			'- Signature: `' . $alarm['signature'] . '`',
			'- Rate: 42 in the last window (~168/hr)',
			'- First seen: 2026-05-01 12:00:00 UTC · Last seen: 2026-05-01 12:15:00 UTC',
			'- Sample: `' . $alarm['sample'] . '`',
		) as $expected ) {
			$this->assertStringContainsString( $expected, $prompt );
		}
		foreach ( array( 'Hard constraints', 'Do NOT', 'fix it in a worktree' ) as $outdated ) {
			$this->assertStringNotContainsString( $outdated, $prompt );
		}
		// Length is the dispatch channel's concern; the prompt is never truncated.
		$this->assertStringEndsWith( 'Report back with the issue URL, the PR URL, and any merge or deploy you performed.', $prompt );
	}

	/**
	 * The notify-only message keeps every field even when the evidence is long.
	 */
	public function test_notify_message_is_not_truncated(): void {
		$file  = '/var/www/extrachill.com/wp-content/plugins/' . str_repeat( 'very-long-directory-name/', 80 ) . 'owner.php';
		$alarm = array(
			'severity'    => 'fatal',
			'file'        => $file,
			'signature'   => hash( 'sha256', $file ),
			'count'       => 3,
			'rate_per_hr' => 12,
			'first_seen'  => '2026-05-01 12:00:00',
			'last_seen'   => '2026-05-01 12:05:00',
			'sample'      => 'PHP Fatal error: Uncaught Error in ' . $file,
			'reasons'     => array( 'new_signature' ),
		);

		$message = extrachill_analytics_format_fatal_alarm_message( $alarm, false );

		$this->assertGreaterThan( 2000, strlen( $message ) );
		$this->assertStringContainsString( '**Where:** `' . $file . '`', $message );
		$this->assertStringEndsWith( 'Triage: `wp extrachill analytics errors --since=1h --severity=fatal`', $message );
	}
}
