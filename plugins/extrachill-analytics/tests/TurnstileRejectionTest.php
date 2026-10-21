<?php
/**
 * Captcha evidence and solver-signature cleanup regression coverage.
 *
 * @package ExtraChill\Analytics
 */
require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

final class TurnstileRejectionTest extends Extrachill_Analytics_TestCase {
	private const VISITOR = '12345678-1234-4123-8123-123456789abc';
	private const OTHER = '22345678-1234-4123-8123-123456789abc';

	public function set_up(): void {
		parent::set_up();
		$this->forget_evidence();
		unset( $_SERVER['HTTP_SEC_GPC'], $_SERVER['HTTP_DNT'] );
		$_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] = self::VISITOR;
	}

	public function tear_down(): void {
		$this->forget_evidence();
		unset( $_SERVER['HTTP_SEC_GPC'], $_SERVER['HTTP_DNT'] );
		parent::tear_down();
	}

	private function forget_evidence(): void {
		unset( $GLOBALS['extrachill_analytics_bot_evidence'] );
		foreach ( array( self::VISITOR, self::OTHER ) as $visitor ) {
			delete_site_transient( extrachill_analytics_bot_evidence_key( get_current_blog_id(), $visitor ) );
		}
	}

	/**
	 * Simulate the next HTTP request: per-request state is gone, transients persist.
	 */
	private function next_request(): void {
		unset( $GLOBALS['extrachill_analytics_bot_evidence'] );
	}

	/**
	 * Insert a historical row directly, bypassing write-time classification.
	 */
	private function legacy_row( $type, array $data, $visitor = self::VISITOR ) {
		global $wpdb;
		$wpdb->insert(
			extrachill_analytics_events_table(),
			array(
				'event_type' => $type,
				'event_data' => wp_json_encode( $data ),
				'blog_id'    => get_current_blog_id(),
				'visitor_id' => $visitor,
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	private function row( $id ) {
		global $wpdb;
		return json_decode( $wpdb->get_var( $wpdb->prepare( 'SELECT event_data FROM ' . extrachill_analytics_events_table() . ' WHERE id = %d', $id ) ), true );
	}

	public function test_rejection_updates_only_recent_same_blog_visitor_events_and_later_writes(): void {
		global $wpdb;
		$recent = extrachill_track_analytics_event( 'page_view', array( 'is_bot' => false, 'keep' => 'yes' ) );
		$old = extrachill_track_analytics_event( 'page_view', array( 'is_bot' => false ) );
		$wpdb->update( extrachill_analytics_events_table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'id' => $old ) );
		$other = extrachill_track_analytics_event( 'page_view', array( 'is_bot' => false ), '', self::OTHER );
		$other_blog = extrachill_track_analytics_event( 'page_view', array( 'is_bot' => false ) );
		$wpdb->update( extrachill_analytics_events_table(), array( 'blog_id' => get_current_blog_id() + 100 ), array( 'id' => $other_blog ) );

		do_action( 'extrachill_turnstile_rejected', 'missing_token', 'secret-not-stored' );
		$this->assertTrue( (bool) $this->row( $recent )['is_bot'] );
		$this->assertSame( 'yes', $this->row( $recent )['keep'] );
		foreach ( array( $old, $other, $other_blog ) as $id ) {
			$this->assertFalse( $this->row( $id )['is_bot'] );
		}
		$later = extrachill_track_analytics_event( 'form_submit', array( 'is_bot' => false ) );
		$this->assertTrue( $this->row( $later )['is_bot'] );
		$this->assertTrue( extrachill_analytics_classify_request()['is_bot'] );
		$this->assertSame( 'confirmed_bot', extrachill_analytics_conversion_outcome_trust_class( array( 'event_data' => $this->row( $later ), 'user_id' => 123 ) ) );
	}

	public function test_missing_invalid_or_opted_out_cookie_does_nothing(): void {
		$id = extrachill_track_analytics_event( 'page_view', array( 'is_bot' => false ) );
		foreach ( array( '', 'invalid' ) as $cookie ) {
			$_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] = $cookie;
			do_action( 'extrachill_turnstile_rejected', 'missing_token', '' );
		}
		$_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] = self::VISITOR;
		$_SERVER['HTTP_SEC_GPC'] = '1';
		do_action( 'extrachill_turnstile_rejected', 'missing_token', '' );
		$this->assertFalse( $this->row( $id )['is_bot'] );
		$this->assertEmpty( $GLOBALS['extrachill_analytics_bot_evidence'] ?? array() );
		$this->assertSame( '', extrachill_analytics_visitor_bot_reason( self::VISITOR ) );
	}

	public function test_rejection_evidence_survives_into_later_beacon_requests(): void {
		do_action( 'extrachill_turnstile_rejected', 'turnstile_missing_token', '' );
		$this->next_request();

		$later = extrachill_track_analytics_event( 'form_submit', array( 'is_bot' => false ) );
		$this->assertTrue( $this->row( $later )['is_bot'] );
		$this->assertSame( 'turnstile_rejected', $this->row( $later )['bot_reason'] );

		$other = extrachill_track_analytics_event( 'form_submit', array( 'is_bot' => false ), '', self::OTHER );
		$this->assertFalse( $this->row( $other )['is_bot'] );
	}

	public function test_solver_signature_flags_visitor_at_write_time(): void {
		$page  = extrachill_track_analytics_event( 'page_view', array( 'is_bot' => false ) );
		$other = extrachill_track_analytics_event( 'page_view', array( 'is_bot' => false ), '', self::OTHER );
		$click = extrachill_track_analytics_event( 'cta_click', array( 'label' => 'Subscribe Failure: ERROR_EMPTY_KEY' ) );
		$this->next_request();
		$submit = extrachill_track_analytics_event( 'form_submit', array( 'form' => 'newsletter-archive' ) );

		foreach ( array( $page, $click, $submit ) as $id ) {
			$this->assertTrue( (bool) $this->row( $id )['is_bot'] );
			$this->assertSame( 'solver_signature', $this->row( $id )['bot_reason'] );
		}
		$this->assertFalse( $this->row( $other )['is_bot'] );

		// A later rejection must not clobber the first recorded reason.
		do_action( 'extrachill_turnstile_rejected', 'turnstile_missing_token', '' );
		$this->assertSame( 'solver_signature', $this->row( $page )['bot_reason'] );
	}

	public function test_generic_failure_labels_are_not_solver_evidence(): void {
		$click = extrachill_track_analytics_event( 'cta_click', array( 'label' => 'Subscribing... Security verification required.', 'is_bot' => false ) );
		$field = extrachill_track_analytics_event( 'cta_click', array( 'other' => 'ERROR_EMPTY_KEY', 'is_bot' => false ) );
		$type  = extrachill_track_analytics_event( 'form_submit', array( 'label' => 'ERROR_EMPTY_KEY', 'is_bot' => false ) );
		foreach ( array( $click, $field, $type ) as $id ) {
			$this->assertFalse( $this->row( $id )['is_bot'] );
		}
		$this->assertSame( '', extrachill_analytics_visitor_bot_reason( self::VISITOR ) );
	}

	public function test_backfill_matches_only_solver_labels_and_is_dry_run_batched_and_idempotent(): void {
		global $wpdb;
		$signature  = $this->legacy_row( 'cta_click', array( 'label' => 'Failure: ERROR_EMPTY_KEY', 'is_bot' => false ) );
		$page       = $this->legacy_row( 'page_view', array( 'keep' => 'yes', 'is_bot' => false ) );
		$rejected   = $this->legacy_row( 'form_submit', array( 'is_bot' => true, 'bot_reason' => 'turnstile_rejected' ) );
		$other_blog = $this->legacy_row( 'page_view', array( 'is_bot' => false ) );
		$wpdb->update( extrachill_analytics_events_table(), array( 'blog_id' => get_current_blog_id() + 100 ), array( 'id' => $other_blog ) );
		$generic     = $this->legacy_row( 'cta_click', array( 'label' => 'Security verification required.', 'is_bot' => false ), self::OTHER );
		$wrong_field = $this->legacy_row( 'cta_click', array( 'other' => 'ERROR_EMPTY_KEY', 'is_bot' => false ), self::OTHER );
		$wrong_type  = $this->legacy_row( 'form_submit', array( 'label' => 'ERROR_EMPTY_KEY', 'is_bot' => false ), self::OTHER );

		$dry = extrachill_analytics_backfill_solver_bots( array( 'batch_size' => 1 ) );
		$this->assertArrayNotHasKey( 'error', $dry );
		$this->assertSame( 2, $dry['scanned'] );
		$this->assertFalse( $this->row( $page )['is_bot'] );

		$live = extrachill_analytics_backfill_solver_bots( array( 'batch_size' => 1, 'live' => true ) );
		$this->assertSame( 2, $live['stamped_bot'] );
		$this->assertTrue( (bool) $this->row( $signature )['is_bot'] );
		$this->assertTrue( (bool) $this->row( $page )['is_bot'] );
		$this->assertSame( 'yes', $this->row( $page )['keep'] );
		$this->assertSame( 'turnstile_rejected', $this->row( $rejected )['bot_reason'] );
		$this->assertSame( 'confirmed_bot', extrachill_analytics_conversion_outcome_trust_class( array( 'event_data' => $this->row( $page ) ) ) );
		foreach ( array( $other_blog, $generic, $wrong_field, $wrong_type ) as $id ) {
			$this->assertFalse( $this->row( $id )['is_bot'] );
		}

		$again = extrachill_analytics_backfill_solver_bots( array( 'live' => true ) );
		$this->assertSame( 0, $again['scanned'] );
		$this->assertSame( 0, $again['stamped_bot'] );
	}
}
