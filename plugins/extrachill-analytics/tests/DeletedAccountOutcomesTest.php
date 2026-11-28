<?php
/**
 * Deleted-account outcome exclusion regression coverage.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}

final class DeletedAccountOutcomesTest extends Extrachill_Analytics_TestCase {
	private function insert_outcome( string $type, array $data, int $column_user = 0 ): int {
		global $wpdb;
		$wpdb->insert(
			extrachill_analytics_events_table(),
			array(
				'event_type' => $type,
				'event_data' => wp_json_encode( $data ),
				'source_url' => '',
				'visitor_id' => 'visitor-deleted',
				'user_id'    => $column_user > 0 ? $column_user : null,
				'created_at' => '2026-01-01 00:00:00',
			)
		);
		return (int) $wpdb->insert_id;
	}

	private function stored_data( int $id ): array {
		global $wpdb;
		$table = extrachill_analytics_events_table();
		return (array) json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT event_data FROM {$table} WHERE id = %d", $id ) ), true ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function delete_account( int $user_id ): void {
		if ( is_multisite() ) {
			wpmu_delete_user( $user_id );
		} else {
			wp_delete_user( $user_id );
		}
	}

	public function test_deleted_account_outcomes_are_stamped_and_excluded(): void {
		if ( ! $this->sqlite_has_json1() ) {
			$this->markTestSkipped( 'JSON functions unavailable.' );
		}
		$this->reset_analytics_tables();
		$doomed = self::factory()->user->create();
		$kept   = self::factory()->user->create();

		$payload  = $this->insert_outcome( 'artist_profile_created', array( 'user_id' => $doomed, 'is_bot' => false ) );
		$column   = $this->insert_outcome( 'artist_link_page_saved', array( 'is_bot' => false ), $doomed );
		$captcha  = $this->insert_outcome( 'artist_signup_started', array( 'user_id' => $doomed, 'bot_reason' => 'turnstile_rejected' ) );
		$survivor = $this->insert_outcome( 'artist_profile_created', array( 'user_id' => $kept, 'is_bot' => false ) );

		$this->delete_account( $doomed );

		$this->assertSame( 'deleted_account', $this->stored_data( $payload )['bot_reason'] ?? null );
		$this->assertSame( 'deleted_account', $this->stored_data( $column )['bot_reason'] ?? null );
		$this->assertSame( 'turnstile_rejected', $this->stored_data( $captcha )['bot_reason'] ?? null, 'Existing bot evidence is preserved.' );
		$this->assertArrayNotHasKey( 'bot_reason', $this->stored_data( $survivor ) );

		$this->assertSame( 'deleted_account', extrachill_analytics_conversion_outcome_trust_class( array( 'event_data' => $this->stored_data( $payload ) ) ) );
		$this->assertSame( 'authenticated_server', extrachill_analytics_conversion_outcome_trust_class( array( 'event_data' => $this->stored_data( $survivor ) ) ) );
	}

	public function test_conversion_coverage_excludes_deleted_account_outcomes(): void {
		$type = 'artist_profile_created';
		$row  = static fn( int $id, array $data ) => (object) array(
			'id'         => $id,
			'event_type' => $type,
			'event_data' => wp_json_encode( $data ),
			'source_url' => '',
			'user_id'    => 0,
			'visitor_id' => '',
			'ts'         => 1000 + $id,
		);
		$page = array(
			$row( 1, array( 'user_id' => 1550, 'bot_reason' => 'deleted_account' ) ),
			$row( 2, array( 'user_id' => 23 ) ),
		);

		$visitor_users = array();
		extrachill_analytics_conversion_observe_outcome_identities( $page, $visitor_users );
		$visitor_to_user = extrachill_analytics_conversion_resolve_outcome_identities( $visitor_users );
		$records         = array();
		$coverage        = array( $type => extrachill_analytics_conversion_outcome_zero_coverage() );
		extrachill_analytics_conversion_collect_outcome_rows( $page, 1, array(), $visitor_to_user, $records, $coverage, 0 );
		$overall     = extrachill_analytics_conversion_outcome_zero_bucket( array( $type ) );
		$by_article  = array();
		$by_category = array();
		extrachill_analytics_conversion_apply_outcome_records( $records, $overall, $by_article, $by_category, $coverage );
		$final = extrachill_analytics_conversion_finalize_outcome_coverage( $coverage[ $type ] );

		$this->assertSame( 2, $final['stored_events'] );
		$this->assertSame( 1, $final['deleted_account_events_excluded'] );
		$this->assertSame( 0, $final['confirmed_bot_events_excluded'] );
		$this->assertSame( 1, $final['trusted_outcomes'] );
		$this->assertSame( 1, $final['authenticated_server_outcomes'] );
	}
}
