<?php
/** Per-city venue expansion behavior tests. */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Abilities\VenueDiscoveryAbilities;
use ExtraChillEvents\Core\QualifyVerdict;
use ExtraChillEvents\Core\VenueExpansionRunner;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/VenueExpansionRunner.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/VenueDiscoveryAbilities.php';

class VenueExpansionRunnerTest extends TestCase {
	public function test_discovery_returns_the_matched_known_term_id(): void {
		$method = new \ReflectionMethod( VenueDiscoveryAbilities::class, 'findKnownVenueId' );
		$method->setAccessible( true );

		$this->assertSame( 55, $method->invoke( new VenueDiscoveryAbilities(), 'The Lizard Lounge Boston', array( 'lizard lounge' => 55 ) ) );
	}

	public function test_existing_city_venue_flow_and_verdict_are_idempotent(): void {
		$calls     = array();
		$abilities = $this->abilities(
			$calls,
			array(
				array(
					'name'          => 'Known',
					'website'       => 'https://known.test',
					'is_known'      => true,
					'known_term_id' => 55,
				),
				array(
					'name'    => 'Flow',
					'website' => 'https://flow.test',
				),
				array(
					'name'    => 'Resume',
					'website' => 'https://resume.test',
				),
				array(
					'name'    => 'Unsupported',
					'website' => 'https://unsupported.test',
				),
				array(
					'name'    => 'No Site',
					'website' => '',
				),
			)
		);
		$runner    = new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ]; },
			static function ( string $url ) {
				if ( 'https://resume.test' === $url ) {
					return array(
						'verdict'           => QualifyVerdict::QUALIFIED_STRUCTURED,
						'events_url'        => 'https://resume.test/events',
						'qualified_at'      => gmdate( 'Y-m-d H:i:s' ),
						'qualifier_version' => self::qualifierVersion(),
					);
				}
				if ( 'https://unsupported.test' === $url ) {
					return array(
						'verdict'           => QualifyVerdict::UNSUPPORTED_SOURCE,
						'qualified_at'      => gmdate( 'Y-m-d H:i:s' ),
						'qualifier_version' => self::qualifierVersion(),
					);
				}
				return null;
			},
			static function ( string $url ) {
				if ( 'https://known.test' === $url ) {
					return array( 'flow_id' => 76 );
				}
				if ( 'https://flow.test' === $url ) {
					return array( 'flow_id' => 77 );
				}
				return 'https://resume.test/events' === $url ? array( 'flow_id' => 78 ) : null;
			},
			static function () {
				return array( 'updated_fields' => array() );
			}
		);
		$report    = $runner->runCity(
			array(
				'city'                 => 'Austin, TX',
				'max_venues'           => 5,
				'qualification_budget' => 5,
			)
		);

		$this->assertSame( 'completed', $report['status'] );
		$this->assertSame( 'existing', $report['pipeline']['status'] );
		$this->assertSame( 9, $report['pipeline']['id'] );
		$this->assertSame( 0, $calls['qualify'] );
		$this->assertSame( 0, $calls['add'] );
		$this->assertSame( 3, $report['counts']['skipped'] );
		$this->assertSame( 0, $report['counts']['added'] );
		$this->assertSame( 2, $report['counts']['rejected'] );
		$this->assertSame( 1, $report['rejection_reasons'][ QualifyVerdict::UNSUPPORTED_SOURCE ] );
		$this->assertSame( 1, $report['rejection_reasons']['no_website'] );
	}

	public function test_known_venue_missing_website_is_enriched_and_qualified_without_duplicate_term(): void {
		$calls     = array();
		$metadata  = array();
		$term_id   = 0;
		$add_input = array();
		$abilities = $this->abilities(
			$calls,
			array(
				array(
					'name'          => 'Known Room',
					'website'       => 'https://known-room.test',
					'address'       => '123 Main St',
					'city'          => 'Boston',
					'state'         => 'MA',
					'zip'           => '02118',
					'country'       => 'US',
					'latitude'      => 42.34,
					'longitude'     => -71.07,
					'is_known'      => true,
					'known_term_id' => 55,
				),
			)
		);
		$abilities['extrachill/add-venue'] = new VenueExpansionFakeAbility(
			static function ( array $input ) use ( &$calls, &$add_input ) {
				++$calls['add'];
				$add_input = $input;
				return array(
					'flow_id'       => 42,
					'venue_term_id' => 55,
				);
			}
		);
		$report    = ( new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ];
			},
			static function () {
				return null;
			},
			static function () {
				return null;
			},
			static function ( int $known_term_id, array $candidate ) use ( &$term_id, &$metadata ) {
				$term_id  = $known_term_id;
				$metadata = $candidate;
				return array( 'updated_fields' => array_keys( $candidate ) );
			}
		) )->runCity( array( 'city' => 'Boston, MA', 'qualification_budget' => 1 ) );

		$this->assertSame( 55, $term_id );
		$this->assertSame( 'https://known-room.test', $metadata['website'] );
		$this->assertSame( '42.34,-71.07', $metadata['coordinates'] );
		$this->assertSame( 1, $calls['qualify'] );
		$this->assertSame( 1, $calls['add'] );
		$this->assertSame( 55, $add_input['venue_term_id'] );
		$this->assertSame( 1, $report['counts']['enriched'] );
		$this->assertSame( 'known_qualified', $report['venues'][0]['status'] );
		$this->assertSame( 55, $report['venues'][0]['known_term_id'] );
	}

	public function test_known_venue_qualification_spends_the_existing_budget(): void {
		$calls     = array();
		$abilities = $this->abilities(
			$calls,
			array(
				array( 'name' => 'One', 'website' => 'https://one.test', 'is_known' => true, 'known_term_id' => 1 ),
				array( 'name' => 'Two', 'website' => 'https://two.test', 'is_known' => true, 'known_term_id' => 2 ),
			)
		);
		$report    = ( new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ];
			},
			static function () {
				return null;
			},
			static function () {
				return null;
			},
			static function () {
				return array( 'updated_fields' => array( 'website' ) );
			}
		) )->runCity( array( 'city' => 'A', 'max_venues' => 2, 'qualification_budget' => 1 ) );

		$this->assertSame( 1, $calls['qualify'] );
		$this->assertSame( 1, $report['rate']['qualification_used'] );
		$this->assertSame( 1, $report['rejection_reasons']['rate_budget_exhausted'] );
		$this->assertSame( 'known_enriched', $report['venues'][1]['status'] );
	}

	public function test_known_venue_with_fresh_verdict_skips_requalification(): void {
		$calls     = array();
		$abilities = $this->abilities( $calls, array( array( 'name' => 'Current', 'website' => 'https://current.test', 'is_known' => true, 'known_term_id' => 8 ) ) );
		$report    = ( new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ];
			},
			static function () {
				return array(
					'verdict'           => QualifyVerdict::EXTRACTION_GAP,
					'qualified_at'      => gmdate( 'Y-m-d H:i:s' ),
					'qualifier_version' => self::qualifierVersion(),
				);
			},
			static function () {
				return null;
			},
			static function () {
				return array( 'updated_fields' => array() );
			}
		) )->runCity( array( 'city' => 'A', 'qualification_budget' => 1 ) );

		$this->assertSame( 0, $calls['qualify'] );
		$this->assertSame( 0, $report['rate']['qualification_used'] );
		$this->assertSame( 'known_skipped_current', $report['venues'][0]['status'] );
		$this->assertSame( 'fresh_verdict', $report['venues'][0]['current_reason'] );
	}

	public function test_known_venue_with_existing_flow_is_skipped_after_fill_empty_enrichment(): void {
		$calls     = array();
		$abilities = $this->abilities( $calls, array( array( 'name' => 'Covered', 'website' => 'https://covered.test', 'is_known' => true, 'known_term_id' => 9 ) ) );
		$report    = ( new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ];
			},
			static function () {
				return null;
			},
			static function () {
				return array( 'flow_id' => 99 );
			},
			static function () {
				return array( 'updated_fields' => array() );
			}
		) )->runCity( array( 'city' => 'A', 'qualification_budget' => 1 ) );

		$this->assertSame( 0, $calls['qualify'] );
		$this->assertSame( 0, $calls['add'] );
		$this->assertSame( 'known_skipped_current', $report['venues'][0]['status'] );
		$this->assertSame( 'existing_flow', $report['venues'][0]['current_reason'] );
		$this->assertSame( 99, $report['venues'][0]['flow_id'] );
	}

	public function test_partial_failure_can_resume_from_persisted_verdict(): void {
		$calls                                 = array();
		$abilities                             = $this->abilities(
			$calls,
			array(
				array(
					'name'    => 'Retry',
					'website' => 'https://retry.test',
				),
			)
		);
		$abilities['extrachill/qualify-venue'] = new VenueExpansionFakeAbility(
			static function () use ( &$calls ) {
				++$calls['qualify'];
				return new \WP_Error( 'http_timeout', 'Timed out.' );
			}
		);
		$first                                 = ( new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ];
			},
			static function () {
				return null;
			},
			static function () {
				return null; }
		) )
			->runCity(
				array(
					'city'                 => 'A',
					'qualification_budget' => 1,
				)
			);
		$this->assertTrue( $first['retryable_failure'] );

		$resumed = ( new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ]; },
			static function () {
				return array(
					'verdict'           => QualifyVerdict::QUALIFIED_STRUCTURED,
					'events_url'        => 'https://retry.test/events',
					'qualified_at'      => gmdate( 'Y-m-d H:i:s' ),
					'qualifier_version' => self::qualifierVersion(),
				); },
			static function () {
				return null; }
		) )->runCity(
			array(
				'city'                 => 'A',
				'qualification_budget' => 1,
			)
		);
		$this->assertSame( 'completed', $resumed['status'] );
		$this->assertSame( 1, $resumed['counts']['added'] );
		$this->assertSame( 1, $calls['qualify'], 'Resume must reuse the persisted verdict instead of spending another qualification operation.' );
	}

	public function test_rate_exhaustion_stops_qualification_without_creating_flow(): void {
		$calls     = array();
		$abilities = $this->abilities(
			$calls,
			array(
				array(
					'name'    => 'One',
					'website' => 'https://one.test',
				),
				array(
					'name'    => 'Two',
					'website' => 'https://two.test',
				),
			)
		);
		$report    = ( new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ];
			},
			static function () {
				return null;
			},
			static function () {
				return null; }
		) )
			->runCity(
				array(
					'city'                 => 'A',
					'max_venues'           => 2,
					'qualification_budget' => 1,
				)
			);
		$this->assertSame( 1, $calls['qualify'] );
		$this->assertSame( 1, $calls['add'] );
		$this->assertSame( 1, $report['rejection_reasons']['rate_budget_exhausted'] );
		$this->assertSame( 1, $report['rate']['qualification_used'] );
	}

	public function test_stale_verdict_is_requalified_before_flow_creation(): void {
		$calls     = array();
		$abilities = $this->abilities( $calls, array( array( 'name' => 'Stale', 'website' => 'https://stale.test' ) ) );
		$report    = ( new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ];
			},
			static function () {
				return array(
					'verdict'           => QualifyVerdict::QUALIFIED_STRUCTURED,
					'events_url'        => 'https://stale.test/old-events',
					'qualified_at'      => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ),
					'qualifier_version' => self::qualifierVersion(),
				);
			},
			static function () {
				return null;
			}
		) )->runCity( array( 'city' => 'A', 'max_verdict_age_days' => 30 ) );

		$this->assertSame( 1, $calls['qualify'] );
		$this->assertSame( 1, $report['counts']['added'] );
	}

	public function test_add_venue_conflict_is_an_idempotent_rerun_skip(): void {
		$calls     = array();
		$abilities = $this->abilities( $calls, array( array( 'name' => 'Existing', 'website' => 'https://existing.test' ) ) );
		$abilities['extrachill/add-venue'] = new VenueExpansionFakeAbility(
			static function () use ( &$calls ) {
				++$calls['add'];
				return new \WP_Error( 'venue_exists', 'Exists.', array( 'flow_id' => 81 ) );
			}
		);
		$report = ( new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ];
			},
			static function () {
				return array(
					'verdict'           => QualifyVerdict::QUALIFIED_STRUCTURED,
					'events_url'        => 'https://existing.test/events',
					'qualified_at'      => gmdate( 'Y-m-d H:i:s' ),
					'qualifier_version' => self::qualifierVersion(),
				);
			},
			static function () {
				return null;
			}
		) )->runCity( array( 'city' => 'A' ) );

		$this->assertSame( 'completed', $report['status'] );
		$this->assertSame( 0, $report['counts']['added'] );
		$this->assertSame( 1, $report['counts']['skipped'] );
		$this->assertSame( 81, $report['venues'][0]['flow_id'] );
	}

	public function test_duplicate_candidate_url_is_skipped_after_same_run_creation(): void {
		$calls     = array();
		$abilities = $this->abilities(
			$calls,
			array(
				array( 'name' => 'First Name', 'website' => 'https://duplicate.test' ),
				array( 'name' => 'Second Name', 'website' => 'https://duplicate.test' ),
			)
		);
		$report = ( new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ];
			},
			static function () {
				return null;
			},
			static function () {
				return null;
			}
		) )->runCity( array( 'city' => 'A', 'qualification_budget' => 2 ) );

		$this->assertSame( 1, $calls['add'] );
		$this->assertSame( 1, $report['counts']['added'] );
		$this->assertSame( 1, $report['counts']['skipped'] );
	}

	/** @dataProvider rejectedVerdicts */
	public function test_rejection_classes_never_create_flows( string $verdict ): void {
		$calls     = array();
		$abilities = $this->abilities(
			$calls,
			array(
				array(
					'name'    => 'Reject',
					'website' => 'https://reject.test',
				),
			)
		);
		$report    = ( new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ]; },
			static function () use ( $verdict ) {
				return array(
					'verdict'           => $verdict,
					'qualified_at'      => gmdate( 'Y-m-d H:i:s' ),
					'qualifier_version' => self::qualifierVersion(),
				); },
			static function () {
				return null; }
		) )->runCity( array( 'city' => 'A' ) );
		$this->assertSame( 0, $calls['add'] );
		$this->assertSame( 1, $report['rejection_reasons'][ $verdict ] );
	}

	public function rejectedVerdicts(): array {
		return array(
			'flyer only'  => array( QualifyVerdict::QUALIFIED_FOR_FLYER ),
			'unsupported' => array( QualifyVerdict::UNSUPPORTED_SOURCE ),
			'no evidence' => array( QualifyVerdict::EXTRACTION_GAP ),
			'covered'     => array( QualifyVerdict::COVERED_ELSEWHERE ),
		);
	}

	public function test_country_and_radius_match_add_city_contract(): void {
		$city_input = array();
		$calls      = array();
		$abilities  = $this->abilities( $calls, array() );
		$abilities['extrachill/add-city'] = new VenueExpansionFakeAbility(
			static function ( array $input ) use ( &$city_input ) {
				$city_input = $input;
				return array( 'pipeline_id' => 12 );
			}
		);
		( new VenueExpansionRunner(
			static function ( string $name ) use ( $abilities ) {
				return $abilities[ $name ];
			},
			static function () {
				return null;
			},
			static function () {
				return null;
			}
		) )->runCity( array( 'city' => 'Berlin', 'country' => 'IN', 'radius' => 75 ) );

		$this->assertSame( 'Berlin, IN', $city_input['city'] );
		$this->assertSame( '75', $city_input['radius'] );
	}

	private function abilities( array &$calls, array $venues ): array {
		$calls = array(
			'qualify' => 0,
			'add'     => 0,
		);
		return array(
			'extrachill/add-city'        => new VenueExpansionFakeAbility(
				static function () {
					return new \WP_Error( 'city_exists', 'Exists.', array( 'pipeline_id' => 9 ) ); }
			),
			'extrachill/discover-venues' => new VenueExpansionFakeAbility(
				static function () use ( $venues ) {
					return array( 'venues' => $venues ); }
			),
			'extrachill/qualify-venue'   => new VenueExpansionFakeAbility(
				static function () use ( &$calls ) {
					++$calls['qualify'];
					return array(
						'verdict'    => QualifyVerdict::QUALIFIED_STRUCTURED,
						'events_url' => 'https://events.test',
					);
				}
			),
			'extrachill/add-venue'       => new VenueExpansionFakeAbility(
				static function () use ( &$calls ) {
					++$calls['add'];
					return array(
						'flow_id'       => 42,
						'venue_term_id' => 8,
					);
				}
			),
		);
	}

	private static function qualifierVersion(): string {
		return defined( 'EXTRACHILL_EVENTS_VERSION' ) ? (string) EXTRACHILL_EVENTS_VERSION : '';
	}
}

class VenueExpansionFakeAbility {
	/** @var callable */
	private $callback;

	public function __construct( callable $callback ) {
		$this->callback = $callback;
	}

	public function execute( array $input ) {
		return ( $this->callback )( $input );
	}
}
