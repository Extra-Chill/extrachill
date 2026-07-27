<?php
/**
 * Ticket-sales evidence and booking settlement tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\TicketSettlementAbilities;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\TicketSettlementService;
use ExtraChillEvents\Core\VenueAuthorization;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class TicketSettlementTest extends BookingTestCase {
	/** @var BookingRepository */
	private $bookings;
	/** @var BookingTestAuthorization */
	private $authorization;
	/** @var TicketSettlementService */
	private $service;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'       => 7,
			'stack'         => array(),
			'uuid'          => 0,
			'options'       => array( BookingSchema::VERSION_OPTION => BookingSchema::SCHEMA_VERSION ),
			'dbdelta'       => array(),
			'abilities'     => array(),
			'actions'       => array(),
			'cache_deletes' => array(),
			'terms'         => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'Settlement Room',
					),
					56 => (object) array(
						'term_id'  => 56,
						'taxonomy' => 'venue',
						'name'     => 'Other Room',
					),
				),
			),
			'meta'          => array(),
			'posts'         => array(
				7 => array(
					900 => (object) array(
						'ID'          => 900,
						'post_type'   => 'data_machine_events',
						'post_status' => 'publish',
					),
				),
			),
			'post_meta'     => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
		$this->bookings            = new BookingRepository();
		$this->authorization       = new BookingTestAuthorization();
		$this->service             = new TicketSettlementService( $this->bookings, new BookingActivityRepository(), $this->authorization );
	}

	public function test_schema_installs_signed_evidence_and_frozen_settlement_contracts(): void {
		$this->assertTrue( BookingSchema::install() );
		$this->assertTrue( BookingSchema::health() );
		$sales       = $GLOBALS['wpdb']->schemas[ BookingSchema::sales_reports_table() ];
		$settlements = $GLOBALS['wpdb']->schemas[ BookingSchema::settlements_table() ];
		$this->assertSame( 'bigint', $sales['columns']['gross_minor']['Type'] );
		$this->assertSame( 'bigint', $sales['columns']['tickets_sold']['Type'] );
		$this->assertTrue( $sales['indexes']['provider_external_report']['unique'] );
		$this->assertTrue( $settlements['indexes']['booking_id']['unique'] );
		$this->assertArrayHasKey( 'booking_version', $settlements['columns'] );
		$this->assertArrayHasKey( 'evidence_hash', $settlements['columns'] );
		$this->assertArrayHasKey( 'integrity_hash', $settlements['columns'] );
		$this->assertArrayHasKey( 'payment_reference', $settlements['columns'] );
	}

	public function test_reports_are_immutable_idempotent_and_corrected_by_signed_observations(): void {
		$booking = $this->create_event_booking();
		$input   = $this->report_input( $booking['id'], 'report-1' );
		$first   = $this->service->record_sales( $input, 12 );
		$retry   = $this->service->record_sales( $input, 12 );
		$this->assertSame( $first['id'], $retry['id'] );
		$this->assertSame( 1, count( $this->service->list_sales( $booking['id'], 12 ) ) );

		$conflict                = $input;
		$conflict['gross_minor'] = 99999;
		$this->assertSame( 'sales_report_idempotency_conflict', $this->service->record_sales( $conflict, 12 )->get_error_code() );

		$correction                       = $this->report_input( $booking['id'], 'report-1-correction' );
		$correction['corrects_report_id'] = $first['id'];
		$correction['tickets_sold']       = -1;
		$correction['tickets_refunded']   = -1;
		$correction['gross_minor']        = -1;
		$correction['fees_minor']         = -20;
		$correction['tax_minor']          = -10;
		$correction['refunds_minor']      = -1000;
		$correction['net_minor']          = -1000;
		$recorded                         = $this->service->record_sales( $correction, 12 );
		$this->assertSame( $first['id'], $recorded['corrects_report_id'] );
		$this->assertSame( -1, $recorded['gross_minor'] );
		$this->assertCount( 2, $this->service->list_sales( $booking['id'], 12 ) );
	}

	public function test_calculation_handles_refunds_corrections_currencies_and_rounding(): void {
		$booking = $this->create_event_booking();
		$this->service->record_sales( $this->report_input( $booking['id'], 'usd-base' ), 12 );
		$correction                     = $this->report_input( $booking['id'], 'usd-correction' );
		$correction['gross_minor']      = -1;
		$correction['net_minor']        = -1000;
		$correction['tickets_sold']     = -1;
		$correction['tickets_refunded'] = 1;
		$correction['refunds_minor']    = 1000;
		$this->service->record_sales( $correction, 12 );
		$eur             = $this->report_input( $booking['id'], 'eur-report' );
		$eur['currency'] = 'EUR';
		$this->service->record_sales( $eur, 12 );

		$gross = $this->service->calculate(
			array(
				'booking_id'       => $booking['id'],
				'basis'            => 'gross_ticket_sales',
				'basis_points'     => 2000,
				'currency'         => 'USD',
				'adjustment_minor' => -25,
			),
			12
		);
		$this->assertSame( 10000, $gross['basis_amount_minor'] );
		$this->assertSame( 2000, $gross['share_amount_minor'] );
		$this->assertSame( 1975, $gross['amount_due_minor'] );
		$this->assertCount( 2, $gross['included_report_ids'], 'EUR evidence must never be mixed into a USD settlement.' );

		$net = $this->service->calculate(
			array(
				'booking_id'   => $booking['id'],
				'basis'        => 'net_ticket_sales',
				'basis_points' => 2000,
				'currency'     => 'USD',
			),
			12
		);
		$this->assertSame( 7000, $net['basis_amount_minor'] );
		$this->assertSame( 1400, $net['amount_due_minor'] );
		$this->assertSame( 1, TicketSettlementService::basis_points_amount( 1, 5000 ) );
		$this->assertSame( -1, TicketSettlementService::basis_points_amount( -1, 5000 ) );
		$this->assertSame( 1, TicketSettlementService::basis_points_amount( 2, 2500 ) );
		$this->assertSame( 'settlement_amount_out_of_range', TicketSettlementService::basis_points_amount( PHP_INT_MIN, 1 )->get_error_code() );
	}

	public function test_evidence_hash_uses_content_fingerprints_and_detects_tampering(): void {
		$booking  = $this->create_event_booking();
		$report   = $this->service->record_sales( $this->report_input( $booking['id'], 'integrity-1' ), 12 );
		$report_2 = $this->service->record_sales( $this->report_input( $booking['id'], 'integrity-2' ), 12 );
		$preview  = $this->preview( $booking['id'] );
		$this->assertSame(
			hash(
				'sha256',
				wp_json_encode(
					array(
						array(
							'id'           => $report['id'],
							'request_hash' => $report['request_hash'],
						),
						array(
							'id'           => $report_2['id'],
							'request_hash' => $report_2['request_hash'],
						),
					)
				)
			),
			$preview['evidence_hash']
		);

		$sales = BookingSchema::sales_reports_table();
		$GLOBALS['wpdb']->rows[ $sales ][ $report['id'] ]['gross_minor'] = 999999;
		$tampered = $this->service->calculate(
			array(
				'booking_id'   => $booking['id'],
				'basis'        => 'gross_ticket_sales',
				'basis_points' => 2000,
				'currency'     => 'USD',
			),
			12
		);
		$this->assertSame( 'sales_report_integrity_failed', $tampered->get_error_code() );
		$GLOBALS['wpdb']->rows[ $sales ][ $report['id'] ]['gross_minor'] = $report['gross_minor'];

		$settlement = $this->service->finalize( $this->finalize_input( $preview ), 12 );
		$GLOBALS['wpdb']->rows[ $sales ][ $report['id'] ]['gross_minor'] = 999999;
		$this->assertSame( 'sales_report_integrity_failed', $this->service->finalize( $this->finalize_input( $preview ), 12 )->get_error_code() );
		$this->assertSame(
			'sales_report_integrity_failed',
			$this->service->void(
				array(
					'booking_id'               => $booking['id'],
					'expected_booking_version' => $preview['booking_version'],
					'expected_version'         => 1,
					'reason'                   => 'Must not void tampered evidence.',
				),
				12
			)->get_error_code()
		);
		$booking_row            =& $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ];
		$booking_row['status']  = 'completed';
		$booking_row['version'] = $booking_row['version'] + 1;
		$this->assertSame(
			'sales_report_integrity_failed',
			$this->service->mark_paid(
				array(
					'booking_id'               => $booking['id'],
					'expected_booking_version' => $booking_row['version'],
					'expected_version'         => 1,
					'payment_reference'        => 'must-not-pay-tampered-evidence',
				),
				12
			)->get_error_code()
		);
		$GLOBALS['wpdb']->rows[ $sales ][ $report['id'] ]['gross_minor']                                   = $report['gross_minor'];
		$GLOBALS['wpdb']->rows[ BookingSchema::settlements_table() ][ $settlement['id'] ]['evidence_hash'] = str_repeat( '0', 64 );
		$this->assertSame( 'settlement_integrity_failed', $this->service->finalize( $this->finalize_input( $preview ), 12 )->get_error_code() );
		$this->assertSame(
			'settlement_integrity_failed',
			$this->service->void(
				array(
					'booking_id'               => $booking['id'],
					'expected_booking_version' => $booking_row['version'],
					'expected_version'         => 1,
					'reason'                   => 'Must not transition corrupt evidence.',
				),
				12
			)->get_error_code()
		);
	}

	public function test_frozen_financial_snapshot_tampering_fails_before_return_or_payment(): void {
		$booking = $this->create_event_booking();
		$this->service->record_sales( $this->report_input( $booking['id'], 'financial-integrity' ), 12 );
		$preview    = $this->preview( $booking['id'] );
		$settlement = $this->service->finalize( $this->finalize_input( $preview ), 12 );
		$row        =& $GLOBALS['wpdb']->rows[ BookingSchema::settlements_table() ][ $settlement['id'] ];
		$mutations  = array(
			'amount_due_minor'   => $settlement['amount_due_minor'] + 1,
			'basis_amount_minor' => $settlement['basis_amount_minor'] + 1,
			'basis_points'       => $settlement['basis_points'] + 1,
			'basis'              => 'net_ticket_sales',
			'adjustment_minor'   => $settlement['adjustment_minor'] + 1,
			'formula_version'    => $settlement['formula_version'] + 1,
			'currency'           => 'EUR',
			'booking_version'    => $settlement['booking_version'] + 1,
		);
		foreach ( $mutations as $field => $tampered ) {
			$original      = $row[ $field ];
			$row[ $field ] = $tampered;
			$result        = $this->service->get( $booking['id'], 12 );
			$this->assertSame( 'settlement_integrity_failed', $result->get_error_code(), 'Tampered ' . $field . ' was returned.' );
			$row[ $field ] = $original;
		}

		$booking_row             =& $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ];
		$booking_row['status']   = 'completed';
		$booking_row['version']  = $booking_row['version'] + 1;
		$row['amount_due_minor'] = $row['amount_due_minor'] + 1;
		$payment                 = $this->service->mark_paid(
			array(
				'booking_id'               => $booking['id'],
				'expected_booking_version' => $booking_row['version'],
				'expected_version'         => 1,
				'payment_reference'        => 'must-not-pay-tampered-snapshot',
			),
			12
		);
		$this->assertSame( 'settlement_integrity_failed', $payment->get_error_code() );
	}

	public function test_finalization_rejects_stale_evidence_and_freezes_formula_and_rate(): void {
		$booking = $this->create_event_booking();
		$this->service->record_sales( $this->report_input( $booking['id'], 'first' ), 12 );
		$preview = $this->preview( $booking['id'] );
		$this->service->record_sales( $this->report_input( $booking['id'], 'late' ), 12 );
		$stale = $this->service->finalize( $this->finalize_input( $preview ), 12 );
		$this->assertSame( 'settlement_evidence_conflict', $stale->get_error_code() );

		$preview                        = $this->preview( $booking['id'] );
		$old_formula                    = $this->finalize_input( $preview );
		$old_formula['formula_version'] = 999;
		$this->assertSame( 'settlement_formula_version_conflict', $this->service->finalize( $old_formula, 12 )->get_error_code() );
		$settlement = $this->service->finalize( $this->finalize_input( $preview ), 12 );
		$this->assertSame( 'finalized', $settlement['status'] );
		$this->assertSame( 2000, $settlement['basis_points'] );
		$this->assertSame( TicketSettlementService::FORMULA_VERSION, $settlement['formula_version'] );
		$this->assertSame( $preview['included_report_ids'], $settlement['included_report_ids'] );
		$this->assertSame( $settlement['id'], $this->service->finalize( $this->finalize_input( $preview ), 12 )['id'] );
		$changed_rate                 = $this->finalize_input( $preview );
		$changed_rate['basis_points'] = 1500;
		$this->assertSame( 'settlement_already_finalized', $this->service->finalize( $changed_rate, 12 )->get_error_code() );

		$this->service->record_sales( $this->report_input( $booking['id'], 'after-finalization' ), 12 );
		$this->assertSame( $settlement['id'], $this->service->finalize( $this->finalize_input( $preview ), 12 )['id'], 'A lost-response retry must ignore later evidence.' );
		$this->bookings->update( $booking['id'], array( 'artist_name' => 'Revised After Finalization' ), $preview['booking_version'] );
		$this->assertSame( $settlement['id'], $this->service->finalize( $this->finalize_input( $preview ), 12 )['id'], 'A lost-response retry must ignore later booking revisions.' );
		$this->assertSame( 'settlement_already_finalized', $this->service->finalize( $changed_rate, 12 )->get_error_code(), 'Changed terms must still conflict after later evidence and booking revisions.' );
		$frozen = $this->service->get( $booking['id'], 12 );
		$this->assertSame( $settlement['included_report_ids'], $frozen['included_report_ids'] );
		$this->assertSame( $settlement['amount_due_minor'], $frozen['amount_due_minor'] );
	}

	public function test_stale_booking_and_terminal_payment_writes_are_rejected_and_audited(): void {
		$booking = $this->create_event_booking();
		$this->service->record_sales( $this->report_input( $booking['id'], 'payment' ), 12 );
		$preview = $this->preview( $booking['id'] );
		$this->bookings->update( $booking['id'], array( 'artist_name' => 'Changed Artist' ), $preview['booking_version'] );
		$this->assertSame( 'settlement_booking_version_conflict', $this->service->finalize( $this->finalize_input( $preview ), 12 )->get_error_code() );

		$preview                = $this->preview( $booking['id'] );
		$settlement             = $this->service->finalize( $this->finalize_input( $preview ), 12 );
		$booking_row            =& $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ];
		$booking_row['status']  = 'completed';
		$booking_row['version'] = $preview['booking_version'] + 1;
		$paid                   = $this->service->mark_paid(
			array(
				'booking_id'               => $booking['id'],
				'expected_booking_version' => $booking_row['version'],
				'expected_version'         => 1,
				'payment_reference'        => 'ach-2026-001',
			),
			12
		);
		$this->assertSame( 'paid', $paid['status'] );
		$this->assertSame( 2, $paid['version'] );
		$this->assertSame( 12, $paid['paid_by_user_id'] );
		$this->assertSame( 'ach-2026-001', $paid['payment_reference'] );
		$this->assertNotNull( $paid['paid_at'] );
		$this->assertSame(
			'settlement_version_conflict',
			$this->service->mark_paid(
				array(
					'booking_id'               => $booking['id'],
					'expected_booking_version' => $booking_row['version'],
					'expected_version'         => 1,
					'payment_reference'        => 'duplicate',
				),
				12
			)->get_error_code()
		);
		$this->assertSame(
			'settlement_status_conflict',
			$this->service->void(
				array(
					'booking_id'               => $booking['id'],
					'expected_booking_version' => $booking_row['version'],
					'expected_version'         => 2,
					'reason'                   => 'Cannot void paid settlement',
				),
				12
			)->get_error_code()
		);

		$activity = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$kinds    = array_column( $activity, 'kind' );
		$this->assertContains( 'booking_settlement_finalized', $kinds );
		$this->assertContains( 'booking_settlement_paid', $kinds );
		$this->assertSame( $settlement['amount_due_minor'], $paid['amount_due_minor'] );

		$void_booking = $this->create_event_booking();
		$this->service->record_sales( $this->report_input( $void_booking['id'], 'void-evidence' ), 12 );
		$void_preview = $this->preview( $void_booking['id'] );
		$this->service->finalize( $this->finalize_input( $void_preview ), 12 );
		$void_row            =& $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $void_booking['id'] ];
		$void_row['version'] = $void_row['version'] + 1;
		$this->assertSame(
			'settlement_booking_version_conflict',
			$this->service->void(
				array(
					'booking_id'               => $void_booking['id'],
					'expected_booking_version' => $void_preview['booking_version'],
					'expected_version'         => 1,
					'reason'                   => 'Stale void attempt.',
				),
				12
			)->get_error_code()
		);
		$voided = $this->service->void(
			array(
				'booking_id'               => $void_booking['id'],
				'expected_booking_version' => $void_row['version'],
				'expected_version'         => 1,
				'reason'                   => 'Certified report withdrawn.',
			),
			12
		);
		$this->assertSame( 'void', $voided['status'] );
		$this->assertSame( 12, $voided['voided_by_user_id'] );
		$this->assertSame( 'Certified report withdrawn.', $voided['void_reason'] );
		$this->assertNotNull( $voided['voided_at'] );
	}

	public function test_payment_revalidates_booking_lifecycle_under_lock(): void {
		$booking = $this->create_event_booking();
		$this->service->record_sales( $this->report_input( $booking['id'], 'race-payment' ), 12 );
		$preview = $this->preview( $booking['id'] );
		$this->service->finalize( $this->finalize_input( $preview ), 12 );
		$row =& $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ];
		$this->assertSame(
			'settlement_payment_booking_status_conflict',
			$this->service->mark_paid(
				array(
					'booking_id'               => $booking['id'],
					'expected_booking_version' => $row['version'],
					'expected_version'         => 1,
					'payment_reference'        => 'too-early',
				),
				12
			)->get_error_code()
		);

		$row['status']                       = 'confirmed';
		$row['version']                      = $row['version'] + 1;
		$expected                            = $row['version'];
		$db                                  = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']->after_booking_lock = static function () use ( $db, &$row ): void {
			$db->simulate_external_commit(
				static function () use ( &$row ): void {
					$row['status']  = 'cancelled';
					$row['version'] = $row['version'] + 1;
				}
			);
		};
		$this->assertSame(
			'settlement_booking_version_conflict',
			$this->service->mark_paid(
				array(
					'booking_id'               => $booking['id'],
					'expected_booking_version' => $expected,
					'expected_version'         => 1,
					'payment_reference'        => 'lost-race',
				),
				12
			)->get_error_code()
		);
		$this->assertSame( 'cancelled', $this->bookings->get( $booking['id'] )['status'] );
		$this->assertSame( 'finalized', $this->service->get( $booking['id'], 12 )['status'] );
	}

	public function test_finance_authority_and_ability_contracts_are_explicit(): void {
		$this->assertContains( VenueAuthorization::ACTION_MANAGE_FINANCES, VenueAuthorization::actions() );
		$abilities = new TicketSettlementAbilities( $this->service, $this->bookings, $this->authorization );
		$abilities->register();
		$names = array(
			'extrachill/record-booking-ticket-sales',
			'extrachill/list-booking-ticket-sales',
			'extrachill/calculate-booking-settlement',
			'extrachill/finalize-booking-settlement',
			'extrachill/mark-booking-settlement-paid',
			'extrachill/void-booking-settlement',
		);
		foreach ( $names as $name ) {
			$this->assertArrayHasKey( $name, $GLOBALS['ec_artist_test']['abilities'] );
			$this->assertTrue( $GLOBALS['ec_artist_test']['abilities'][ $name ]['meta']['show_in_rest'] );
		}
		$this->assertTrue( $GLOBALS['ec_artist_test']['abilities']['extrachill/finalize-booking-settlement']['meta']['annotations']['idempotent'] );
		$this->assertSame( array( 'manual', 'csv_certified' ), $GLOBALS['ec_artist_test']['abilities']['extrachill/record-booking-ticket-sales']['input_schema']['properties']['source_type']['enum'] );
		$this->assertContains( 'expected_booking_version', $GLOBALS['ec_artist_test']['abilities']['extrachill/mark-booking-settlement-paid']['input_schema']['required'] );

		$booking = $this->create_event_booking();
		$record  = $this->execute_ability( 'extrachill/record-booking-ticket-sales', $this->report_input( $booking['id'], 'ability-execution' ) );
		$list    = $this->execute_ability(
			'extrachill/list-booking-ticket-sales',
			array(
				'booking_id' => $booking['id'],
				'limit'      => 10,
				'offset'     => 0,
			)
		);
		$this->assertSame( $record['id'], $list[0]['id'] );
		$preview    = $this->execute_ability(
			'extrachill/calculate-booking-settlement',
			array(
				'booking_id'   => $booking['id'],
				'basis'        => 'gross_ticket_sales',
				'basis_points' => 2000,
				'currency'     => 'USD',
			)
		);
		$settlement = $this->execute_ability( 'extrachill/finalize-booking-settlement', $this->finalize_input( $preview ) );
		$this->assertSame( $preview['booking_version'], $settlement['booking_version'] );
		$booking_row            =& $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ];
		$booking_row['status']  = 'completed';
		$booking_row['version'] = $booking_row['version'] + 1;
		$paid                   = $this->execute_ability(
			'extrachill/mark-booking-settlement-paid',
			array(
				'booking_id'               => $booking['id'],
				'expected_booking_version' => $booking_row['version'],
				'expected_version'         => 1,
				'payment_reference'        => 'ability-payment',
			)
		);
		$this->assertSame( 'paid', $paid['status'] );

		$void_booking = $this->create_event_booking();
		$this->execute_ability( 'extrachill/record-booking-ticket-sales', $this->report_input( $void_booking['id'], 'ability-void-evidence' ) );
		$void_preview = $this->execute_ability(
			'extrachill/calculate-booking-settlement',
			array(
				'booking_id'   => $void_booking['id'],
				'basis'        => 'gross_ticket_sales',
				'basis_points' => 2000,
				'currency'     => 'USD',
			)
		);
		$this->execute_ability( 'extrachill/finalize-booking-settlement', $this->finalize_input( $void_preview ) );
		$voided = $this->execute_ability(
			'extrachill/void-booking-settlement',
			array(
				'booking_id'               => $void_booking['id'],
				'expected_booking_version' => $void_preview['booking_version'],
				'expected_version'         => 1,
				'reason'                   => 'Ability void proof.',
			)
		);
		$this->assertSame( 'void', $voided['status'] );

		$now = gmdate( 'Y-m-d H:i:s' );
		$GLOBALS['wpdb']->rows[ BookingSchema::memberships_table() ] = array(
			1 => $this->membership_row( 1, 55, 12, true, $now ),
			2 => $this->membership_row( 2, 55, 13, false, $now ),
			3 => $this->membership_row( 3, 56, 14, true, $now ),
		);
		$GLOBALS['ec_artist_test']['user_caps'][15]                  = array(
			'manage_options'      => true,
			'access_events_admin' => true,
		);
		$canonical_abilities = new TicketSettlementAbilities( $this->service, $this->bookings, new VenueAuthorization() );
		foreach ( array(
			12 => true,
			13 => false,
			14 => false,
			15 => false,
		) as $user_id => $expected ) {
			$GLOBALS['ec_artist_test']['current_user_id'] = $user_id;
			$result                                       = $canonical_abilities->can_manage_booking_finances( array( 'booking_id' => $booking['id'] ) );
			$this->assertSame( $expected, true === $result, 'Canonical finance policy mismatch for user ' . $user_id );
		}
		$GLOBALS['ec_artist_test']['current_user_id'] = 12;
	}

	private function create_event_booking(): array {
		$booking = $this->bookings->create(
			array(
				'venue_term_id' => 55,
				'artist_name'   => 'Settlement Artist',
				'intake'        => array(),
			)
		);
		return $this->bookings->claim_event( $booking['id'], 900, $booking['version'] );
	}

	private function report_input( int $booking_id, string $external_id ): array {
		return array(
			'booking_id'         => $booking_id,
			'provider'           => 'manual-certified',
			'external_report_id' => $external_id,
			'source_type'        => 'manual',
			'period_start'       => '2026-07-01 00:00:00',
			'period_end'         => '2026-07-31 23:59:59',
			'tickets_sold'       => 101,
			'tickets_refunded'   => 1,
			'gross_minor'        => 10001,
			'fees_minor'         => 1000,
			'tax_minor'          => 1,
			'refunds_minor'      => 1000,
			'net_minor'          => 8000,
			'currency'           => 'USD',
			'source'             => array( 'certificate' => $external_id ),
		);
	}

	private function preview( int $booking_id ): array {
		return $this->service->calculate(
			array(
				'booking_id'       => $booking_id,
				'basis'            => 'gross_ticket_sales',
				'basis_points'     => 2000,
				'currency'         => 'USD',
				'adjustment_minor' => 0,
			),
			12
		);
	}

	private function finalize_input( array $preview ): array {
		return array(
			'booking_id'               => $preview['booking_id'],
			'expected_booking_version' => $preview['booking_version'],
			'expected_report_ids'      => $preview['included_report_ids'],
			'basis'                    => $preview['basis'],
			'basis_points'             => $preview['basis_points'],
			'currency'                 => $preview['currency'],
			'formula_version'          => $preview['formula_version'],
			'adjustment_minor'         => $preview['adjustment_minor'],
		);
	}

	private function membership_row( int $id, int $venue_id, int $user_id, bool $is_owner, string $now ): array {
		return array(
			'id'                 => $id,
			'venue_term_id'      => $venue_id,
			'user_id'            => $user_id,
			'is_owner'           => $is_owner ? 1 : 0,
			'status'             => VenueAuthorization::STATUS_ACTIVE,
			'version'            => 1,
			'created_by_user_id' => 12,
			'created_at'         => $now,
			'updated_at'         => $now,
			'revoked_at'         => null,
		);
	}

	private function execute_ability( string $name, array $input ) {
		$definition = $GLOBALS['ec_artist_test']['abilities'][ $name ];
		$this->assertTrue( true === call_user_func( $definition['permission_callback'], $input ), $name . ' permission failed.' );
		$this->assertSchemaValue( $input, $definition['input_schema'], $name . ' input' );
		$output = call_user_func( $definition['execute_callback'], $input );
		$this->assertFalse( is_wp_error( $output ), is_wp_error( $output ) ? $output->get_error_code() : '' );
		$this->assertSchemaValue( $output, $definition['output_schema'], $name . ' output' );
		return $output;
	}

	private function assertSchemaValue( $value, array $schema, string $path ): void {
		$types   = (array) ( $schema['type'] ?? array() );
		$matches = false;
		foreach ( $types as $type ) {
			$matches = $matches || ( 'null' === $type && null === $value )
				|| ( 'integer' === $type && is_int( $value ) )
				|| ( 'string' === $type && is_string( $value ) )
				|| ( in_array( $type, array( 'object', 'array' ), true ) && is_array( $value ) );
		}
		$this->assertTrue( $matches, $path . ' has an invalid type.' );
		if ( null === $value ) {
			return;
		}
		if ( isset( $schema['enum'] ) ) {
			$this->assertContains( $value, $schema['enum'], $path . ' is outside its enum.' );
		}
		if ( is_int( $value ) ) {
			$this->assertGreaterThanOrEqual( $schema['minimum'] ?? PHP_INT_MIN, $value, $path . ' is below minimum.' );
			$this->assertLessThanOrEqual( $schema['maximum'] ?? PHP_INT_MAX, $value, $path . ' exceeds maximum.' );
		}
		if ( is_string( $value ) ) {
			$this->assertGreaterThanOrEqual( $schema['minLength'] ?? 0, mb_strlen( $value ), $path . ' is too short.' );
			$this->assertLessThanOrEqual( $schema['maxLength'] ?? PHP_INT_MAX, mb_strlen( $value ), $path . ' is too long.' );
			if ( isset( $schema['pattern'] ) ) {
				$this->assertSame( 1, preg_match( '~' . $schema['pattern'] . '~', $value ), $path . ' does not match its pattern.' );
			}
		}
		if ( is_array( $value ) && 'object' === ( $types[0] ?? '' ) ) {
			foreach ( $schema['required'] ?? array() as $required ) {
				$this->assertArrayHasKey( $required, $value, $path . ' is missing ' . $required . '.' );
			}
			if ( false === ( $schema['additionalProperties'] ?? true ) ) {
				$this->assertSame( array(), array_diff( array_keys( $value ), array_keys( $schema['properties'] ?? array() ) ), $path . ' has additional properties.' );
			}
			foreach ( $schema['properties'] ?? array() as $key => $property ) {
				if ( array_key_exists( $key, $value ) ) {
					$this->assertSchemaValue( $value[ $key ], $property, $path . '.' . $key );
				}
			}
		}
		if ( is_array( $value ) && 'array' === ( $types[0] ?? '' ) ) {
			$this->assertGreaterThanOrEqual( $schema['minItems'] ?? 0, count( $value ), $path . ' has too few items.' );
			$this->assertLessThanOrEqual( $schema['maxItems'] ?? PHP_INT_MAX, count( $value ), $path . ' has too many items.' );
			if ( ! empty( $schema['uniqueItems'] ) ) {
				$this->assertSame( count( $value ), count( array_unique( array_map( 'serialize', $value ) ) ), $path . ' has duplicate items.' );
			}
			foreach ( $value as $index => $item ) {
				$this->assertSchemaValue( $item, $schema['items'], $path . '[' . $index . ']' );
			}
		}
	}
}
