<?php
/**
 * Canonical venue booking reporting tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\BookingReportingAbilities;
use ExtraChillEvents\Core\BookingReportingService;
use ExtraChillEvents\Core\BookingSchema;

// Repository tests use PSR-4 filenames and concise method names as descriptions.
// phpcs:disable WordPress.Files.FileName,Generic.Commenting,Squiz.Commenting

require_once __DIR__ . '/Support/BookingTestHarness.php';

/** Covers bounded projections, honest gaps, and exact venue authority. */
final class BookingReportingTest extends BookingTestCase {
	/** @var BookingTestAuthorization */
	private $authorization;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'abilities'       => array(),
			'actions'         => array(),
			'blog_id'         => 7,
			'current_user_id' => 12,
			'options'         => array( BookingSchema::VERSION_OPTION => BookingSchema::SCHEMA_VERSION ),
			'user_caps'       => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated test database double.
		$this->authorization       = new BookingTestAuthorization();
	}

	public function test_empty_period_is_explicit_and_unavailable_metrics_are_not_fabricated(): void {
		$service = $this->service( $this->snapshot() );
		$report  = $service->operations( $this->input(), 12 );

		$this->assertSame( 'empty', $report['state'] );
		$this->assertSame( 0, $report['funnel']['inquiries'] );
		$this->assertSame( 'not_recorded', $report['availability']['inquiry_source'] );
		$this->assertSame( 'not_recorded', $report['availability']['first_response_time'] );
		$this->assertNull( $report['funnel']['first_response_seconds'] );
		$this->assertSame( 0, $report['funnel']['time_to_confirmation_seconds']['sample_size'] );
	}

	public function test_operations_derive_stages_holds_evidence_and_marketing_without_private_data(): void {
		$snapshot                      = $this->snapshot();
		$snapshot['bookings']          = array(
			array(
				'id'            => 1,
				'public_id'     => 'booking-one',
				'venue_term_id' => 55,
				'status'        => 'confirmed',
				'event_id'      => 900,
				'created_at'    => '2026-07-01 10:00:00',
				'updated_at'    => '2026-07-03 10:00:00',
			),
			array(
				'id'            => 2,
				'public_id'     => 'booking-two',
				'venue_term_id' => 55,
				'status'        => 'declined',
				'event_id'      => null,
				'created_at'    => '2026-07-02 10:00:00',
				'updated_at'    => '2026-07-02 11:00:00',
			),
		);
		$snapshot['activities']        = array(
			$this->activity( 1, 'inquiry_submitted', '2026-07-01 10:00:00' ),
			$this->activity( 1, 'status_changed', '2026-07-01 11:00:00', array( 'to_status' => 'negotiating' ) ),
			$this->activity( 1, 'deal_confirmed', '2026-07-03 10:00:00' ),
			$this->activity( 1, 'event_converted', '2026-07-03 10:01:00' ),
			$this->activity( 2, 'inquiry_submitted', '2026-07-02 10:00:00' ),
			$this->activity(
				2,
				'status_changed',
				'2026-07-02 11:00:00',
				array(
					'to_status' => 'declined',
					'note'      => 'Routing conflict.',
				)
			),
			$this->activity(
				1,
				'marketing_operation_submitted',
				'2026-07-03 10:02:00',
				array(
					'status'     => 'executed',
					'projection' => array(
						'classification' => 'success',
						'effect_count'   => 1,
						'share_refs'     => array(
							array(
								'channel'          => 'instagram',
								'platform_post_id' => 'post-1',
							),
						),
					),
				),
				array(
					'channel'     => 'social',
					'external_id' => 'dop_' . str_repeat( 'a', 64 ),
				)
			),
		);
		$snapshot['holds']             = array(
			array(
				'booking_id' => 1,
				'status'     => 'active',
				'expires_at' => '2026-07-04 00:00:00',
			),
			array(
				'booking_id' => 1,
				'status'     => 'converted',
				'expires_at' => '2026-07-10 00:00:00',
			),
		);
		$snapshot['sales_reports']     = array(
			array(
				'id'                 => 10,
				'booking_id'         => 1,
				'corrects_report_id' => null,
			),
			array(
				'id'                 => 11,
				'booking_id'         => 1,
				'corrects_report_id' => 10,
			),
		);
		$snapshot['sales_resolutions'] = array(
			array(
				'report_id' => 10,
				'decision'  => 'exclude',
				'version'   => 1,
			),
		);

		$report = $this->service( $snapshot )->operations( $this->input(), 12 );
		$this->assertSame( 'complete', $report['state'] );
		$this->assertSame( 2, $report['funnel']['inquiries'] );
		$this->assertSame( 1, $report['funnel']['current_stage_counts']['confirmed'] );
		$this->assertSame( 1, $report['funnel']['stage_entry_counts']['confirmed'] );
		$this->assertSame( 1, $report['funnel']['declines']['note_recorded_count'] );
		$this->assertFalse( $report['funnel']['declines']['reason_codes_available'] );
		$this->assertSame( 172800, $report['funnel']['time_to_confirmation_seconds']['average'] );
		$this->assertSame( 1, $report['operations']['holds']['expired'], 'Stored active holds use effective database-time state.' );
		$this->assertSame( 1, $report['operations']['holds']['converted'] );
		$this->assertSame( 1, $report['operations']['ticket_evidence']['corrections_recorded'] );
		$this->assertSame( 1, $report['operations']['ticket_evidence']['latest_explicit_decisions']['not_explicitly_resolved'] );
		$this->assertSame( 'post-1', $report['marketing']['operations'][0]['outcome_refs'][0]['id'] );
		$this->assertArrayNotHasKey( 'contact_email', $report );
	}

	public function test_range_and_cross_venue_authorization_fail_closed_before_reading(): void {
		$reads         = 0;
		$service       = new BookingReportingService(
			$this->authorization,
			null,
			null,
			static function () use ( &$reads ): array {
				++$reads;
				return array();
			}
		);
		$invalid       = $this->input();
		$invalid['to'] = '2028-01-01 00:00:00';
		$this->assertSame( 'invalid_booking_report_range', $service->operations( $invalid, 12 )->get_error_code() );

		$aggregate                             = $this->input();
		$aggregate['venue_term_ids']           = array( 55, 66 );
		$this->authorization->allowed['12:66'] = true;
		$this->assertSame( 'venue_action_forbidden', $service->operations( $aggregate, 12 )->get_error_code() );
		$this->assertSame( 0, $reads );

		$GLOBALS['ec_artist_test']['user_caps'][12]['manage_options'] = true;
		$result = $service->operations( $aggregate, 12 );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $reads );
	}

	public function test_finance_requires_exact_authority_and_reports_incomplete_corrected_and_finalized_states(): void {
		$snapshot                              = $this->snapshot();
		$snapshot['bookings']                  = array(
			array(
				'id'            => 1,
				'public_id'     => 'one',
				'venue_term_id' => 55,
				'status'        => 'completed',
				'event_id'      => 900,
				'created_at'    => '2026-07-01 00:00:00',
				'updated_at'    => '2026-07-02 00:00:00',
			),
		);
		$service                               = $this->service( $snapshot );
		$this->authorization->allowed['99:55'] = false;
		$this->assertSame( 'venue_action_forbidden', $service->finance( $this->input(), 99 )->get_error_code() );
		$this->assertSame( 'incomplete', $service->finance( $this->input(), 12 )['state'] );

		$snapshot['shows'] = array(
			array(
				'status'               => 'draft',
				'currency'             => 'USD',
				'corrects_revision_id' => null,
				'calculation'          => array(
					'artist_payout_minor'          => 50000,
					'venue_net_after_payout_minor' => 30000,
				),
			),
		);
		$draft             = $this->service( $snapshot )->finance( $this->input(), 12 );
		$this->assertSame( 0, $draft['currencies']['USD']['artist_payout_committed_minor'] );
		$this->assertSame( 0, $draft['currencies']['USD']['venue_net_after_payout_minor'] );

		$snapshot['commissions'] = array(
			array(
				'status'           => 'paid',
				'currency'         => 'USD',
				'amount_due_minor' => 20000,
			),
		);
		$snapshot['shows']       = array(
			array(
				'status'               => 'finalized',
				'currency'             => 'USD',
				'corrects_revision_id' => null,
				'calculation'          => array(
					'artist_payout_minor'          => 50000,
					'venue_net_after_payout_minor' => 30000,
				),
			),
		);
		$finalized               = $this->service( $snapshot )->finance( $this->input(), 12 );
		$this->assertSame( 'finalized', $finalized['state'] );
		$this->assertSame( 20000, $finalized['currencies']['USD']['extra_chill_share_paid_minor'] );
		$this->assertSame( 50000, $finalized['currencies']['USD']['artist_payout_committed_minor'] );
		$this->assertSame( 0, $finalized['currencies']['USD']['artist_payout_paid_minor'] );
		$snapshot['truncated'] = true;
		$this->assertSame( 'incomplete', $this->service( $snapshot )->finance( $this->input(), 12 )['state'] );
		$snapshot['truncated']                      = false;
		$snapshot['finance_verification_truncated'] = true;
		$bounded                                    = $this->service( $snapshot )->finance( $this->input(), 12 );
		$this->assertSame( 'incomplete', $bounded['state'] );
		$this->assertTrue( $bounded['bounded']['financial_verification_truncated'] );
		$snapshot['finance_verification_truncated'] = false;
		$snapshot['bookings'][]                     = array_merge(
			$snapshot['bookings'][0],
			array(
				'id'        => 2,
				'public_id' => 'two',
			)
		);
		$this->assertSame( 'incomplete', $this->service( $snapshot )->finance( $this->input(), 12 )['state'] );
		array_pop( $snapshot['bookings'] );

		$snapshot['shows'][0]['status']               = 'paid';
		$snapshot['shows'][0]['corrects_revision_id'] = 4;
		$corrected                                    = $this->service( $snapshot )->finance( $this->input(), 12 );
		$this->assertSame( 'corrected', $corrected['state'] );
		$this->assertSame( 50000, $corrected['currencies']['USD']['artist_payout_paid_minor'] );
	}

	public function test_abilities_are_readonly_bounded_and_do_not_accept_caller_identity(): void {
		$abilities = new BookingReportingAbilities( $this->service( $this->snapshot() ) );
		$abilities->register();
		foreach ( array( 'extrachill/get-venue-booking-performance-report', 'extrachill/get-venue-booking-revenue-report' ) as $name ) {
			$definition = $GLOBALS['ec_artist_test']['abilities'][ $name ];
			$this->assertTrue( $definition['meta']['annotations']['readonly'] );
			$this->assertFalse( $definition['meta']['annotations']['destructive'] );
			$this->assertFalse( $definition['input_schema']['additionalProperties'] );
			$this->assertArrayNotHasKey( 'user_id', $definition['input_schema']['properties'] );
			$expected_limit = false !== strpos( $name, 'revenue' ) ? BookingReportingService::MAX_FINANCE_BOOKINGS : BookingReportingService::MAX_BOOKINGS;
			$this->assertSame( $expected_limit, $definition['input_schema']['properties']['limit']['maximum'] );
		}
	}

	private function service( array $snapshot ): BookingReportingService {
		return new BookingReportingService( $this->authorization, null, null, static fn(): array => $snapshot );
	}

	private function input(): array {
		return array(
			'venue_term_ids' => array( 55 ),
			'from'           => '2026-07-01 00:00:00',
			'to'             => '2026-08-01 00:00:00',
			'limit'          => 100,
		);
	}

	private function snapshot(): array {
		return array(
			'bookings'             => array(),
			'activities'           => array(),
			'holds'                => array(),
			'sales_reports'        => array(),
			'sales_resolutions'    => array(),
			'commissions'          => array(),
			'shows'                => array(),
			'truncated'            => false,
			'activities_truncated' => false,
			'database_now'         => '2026-07-05 00:00:00',
		);
	}

	private function activity( int $booking_id, string $kind, string $occurred_at, array $data = array(), array $extra = array() ): array {
		return array_merge(
			array(
				'booking_id'  => $booking_id,
				'kind'        => $kind,
				'occurred_at' => $occurred_at,
				'channel'     => null,
				'external_id' => null,
				'payload'     => array(
					'version' => 1,
					'data'    => $data,
				),
			),
			$extra
		);
	}
}
