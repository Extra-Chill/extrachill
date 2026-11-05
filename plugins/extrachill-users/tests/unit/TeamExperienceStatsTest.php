<?php
/**
 * Live MCP team-cohort reporting (#1083).
 *
 * @package ExtraChill\Users
 */
class Test_Team_Experience_Stats extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		extrachill_analytics_events_create_table();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . extrachill_analytics_events_table() );
	}

	private function event( string $type, array $data, int $actor, string $created_at = '' ): void {
		global $wpdb;
		$this->assertNotFalse(
			$wpdb->insert(
				extrachill_analytics_events_table(),
				array(
					'event_type' => $type,
					'event_data' => wp_json_encode( $data ),
					'user_id'    => $actor,
					'blog_id'    => get_current_blog_id(),
					'created_at' => $created_at ?: gmdate( 'Y-m-d H:i:s' ),
				)
			)
		);
	}

	public function test_mcp_counts_payload_team_subject_and_splits_outcomes(): void {
		$team  = self::factory()->user->create( array( 'role' => 'extra_chill_team' ) );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		foreach ( array( $team, $other ) as $subject ) {
			// Deliberately invert the table actor: only payload identity counts.
			$actor = $subject === $team ? $other : $team;
			$this->event( 'mcp_session_started', array( 'user_id' => $subject ), $actor );
			foreach ( array( 'success', 'error', 'failure', 'unexpected' ) as $status ) {
				$this->event( 'mcp_tool_invoked', array( 'user_id' => $subject, 'status' => $status, 'ability' => 'extrachill/test' ), $actor );
			}
		}
		$this->event( 'mcp_tool_invoked', array( 'status' => 'success' ), $team );
		$this->event( 'mcp_tool_invoked', array( 'user_id' => $team ), $other );
		$this->event( 'mcp_session_started', array( 'user_id' => $team ), $other, gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) );

		$result = extrachill_users_ability_get_team_experience_stats( array( 'days' => 28 ) );
		$this->assertSame( 1, $result['events']['mcp_session_started'] );
		$this->assertSame( 5, $result['events']['mcp_tool_invoked'] );
		$this->assertSame(
			array(
				'sessions'           => 1,
				'tool_calls'         => 5,
				'tool_calls_success' => 1,
				'tool_calls_failure' => 2,
				'tool_calls_unknown' => 2,
				'top_abilities'      => array( 'extrachill/test' => 4 ),
			),
			$result['mcp_usage']
		);
		$this->assertSame( 2, extrachill_users_team_mcp_usage( 0 )['sessions'] );
		$this->assertArrayHasKey( 'team_login_activity', $result );
		$this->assertArrayNotHasKey( 'roadie_tool_invoked', $result['events'] );
	}

	public function test_mcp_reader_counts_beyond_one_page(): void {
		$team = self::factory()->user->create( array( 'role' => 'extra_chill_team' ) );
		for ( $i = 0; $i < 501; ++$i ) {
			$this->event( 'mcp_session_started', array( 'user_id' => $team ), 0 );
		}
		$this->assertSame( 501, extrachill_users_team_mcp_usage( 28 )['sessions'] );
	}
}
