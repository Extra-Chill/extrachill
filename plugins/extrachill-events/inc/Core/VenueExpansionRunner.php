<?php
/**
 * Bounded per-city venue expansion orchestration.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Reuse the existing city, discovery, qualification, and venue abilities. */
class VenueExpansionRunner {

	public const REPORT_SCHEMA = 'extrachill-venue-expansion/v1';

	/** @var callable */
	private $ability_resolver;

	/** @var callable */
	private $latest_verdict;

	/** @var callable */
	private $flow_for_url;

	/** @var array<string,array>|null */
	private $flows_by_url = null;

	/** @var array<string,array> */
	private $new_flows_by_url = array();

	/**
	 * Inject narrow lookups so the orchestration remains independently testable.
	 */
	public function __construct( ?callable $ability_resolver = null, ?callable $latest_verdict = null, ?callable $flow_for_url = null ) {
		$this->ability_resolver = $ability_resolver ?? static function ( string $name ) {
			return function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		};
		$this->latest_verdict   = $latest_verdict ?? static function ( string $url ): ?array {
			return QualifyVerdictsTable::latest_for_url( $url );
		};
		$this->flow_for_url     = $flow_for_url ?? array( $this, 'findFlowForUrl' );
	}

	/**
	 * Expand one city. A retry safely resumes from persisted verdicts and flows.
	 */
	public function runCity( array $params ): array {
		$city                 = sanitize_text_field( (string) ( $params['city'] ?? '' ) );
		$max_venues           = max( 1, min( 20, (int) ( $params['max_venues'] ?? 10 ) ) );
		$qualification_budget = max( 0, min( $max_venues, (int) ( $params['qualification_budget'] ?? $max_venues ) ) );
		$skip_existing        = ! array_key_exists( 'skip_existing', $params ) || (bool) $params['skip_existing'];
		$country              = strtoupper( sanitize_text_field( (string) ( $params['country'] ?? '' ) ) );
		$request_delay_ms     = max( 0, min( 10000, (int) ( $params['request_delay_ms'] ?? 0 ) ) );

		$report = self::emptyReport( $city, $qualification_budget );
		if ( '' === $city ) {
			return $this->fail( $report, 'missing_city', 'City is required.' );
		}

		$add_city = $this->ability( 'extrachill/add-city' );
		$discover = $this->ability( 'extrachill/discover-venues' );
		$qualify  = $this->ability( 'extrachill/qualify-venue' );
		$add      = $this->ability( 'extrachill/add-venue' );
		if ( ! $add_city || ! $discover || ! $qualify || ! $add ) {
			return $this->fail( $report, 'missing_ability', 'A venue expansion primitive is unavailable.' );
		}

		$has_country_suffix = '' !== $country && 1 === preg_match( '/(?:^|,\s*)' . preg_quote( $country, '/' ) . '$/i', $city );
		$city_input         = '' !== $country && ! $has_country_suffix ? $city . ', ' . $country : $city;
		$city_result        = $add_city->execute(
			array(
				'city'     => $city_input,
				'radius'   => (string) ( $params['radius'] ?? '50' ),
				'interval' => (string) ( $params['interval'] ?? 'daily' ),
			)
		);
		if ( is_wp_error( $city_result ) ) {
			if ( 'city_exists' !== $city_result->get_error_code() ) {
				return $this->fail( $report, $city_result->get_error_code(), $city_result->get_error_message() );
			}
			$error_data                   = (array) $city_result->get_error_data();
			$report['pipeline']['status'] = 'existing';
			$report['pipeline']['id']     = (int) ( $error_data['pipeline_id'] ?? 0 );
		} else {
			$report['pipeline']['status'] = 'created';
			$report['pipeline']['id']     = (int) ( $city_result['pipeline_id'] ?? 0 );
		}

		if ( $report['pipeline']['id'] <= 0 ) {
			return $this->fail( $report, 'missing_pipeline', 'City primitive did not return a pipeline ID.' );
		}

		$query = 'music venues in ' . $city;
		if ( '' !== $country ) {
			$query .= ', ' . $country;
		}
		$discovery = $discover->execute(
			array(
				'city'          => $city,
				'query'         => $query,
				'include_known' => true,
			)
		);
		if ( is_wp_error( $discovery ) ) {
			return $this->fail( $report, $discovery->get_error_code(), $discovery->get_error_message() );
		}

		$venues                           = array_slice( (array) ( $discovery['venues'] ?? array() ), 0, $max_venues );
		$report['counts']['discovered']   = count( $venues );
		$report['rate']['discovery_used'] = 1;

		foreach ( $venues as $venue ) {
			$name    = sanitize_text_field( (string) ( $venue['name'] ?? '' ) );
			$website = esc_url_raw( (string) ( $venue['website'] ?? '' ) );
			$row     = array(
				'name'       => $name,
				'url'        => $website,
				'status'     => '',
				'verdict'    => '',
				'flow_id'    => 0,
				'events_url' => '',
			);

			if ( '' === $website ) {
				$this->reject( $report, $row, 'no_website' );
				continue;
			}
			$existing_flow = $this->findExistingFlow( $website );
			if ( is_array( $existing_flow ) ) {
				$row['status']      = 'skipped_existing_flow';
				$row['flow_id']     = (int) ( $existing_flow['flow_id'] ?? 0 );
				$report['venues'][] = $row;
				++$report['counts']['skipped'];
				continue;
			}
			if ( $skip_existing && ! empty( $venue['is_known'] ) ) {
				$row['status']      = 'skipped_existing_venue';
				$report['venues'][] = $row;
				++$report['counts']['skipped'];
				continue;
			}

			$prior = ( $this->latest_verdict )( $website );
			if ( is_array( $prior ) && $this->isReusableVerdict( $prior, (int) ( $params['max_verdict_age_days'] ?? 30 ) ) ) {
				$qualification = $prior;
				$row['status'] = 'resumed_verdict';
			} else {
				if ( $report['rate']['qualification_used'] >= $qualification_budget ) {
					$this->reject( $report, $row, 'rate_budget_exhausted' );
					continue;
				}
				++$report['rate']['qualification_used'];
				if ( $report['rate']['qualification_used'] > 1 && $request_delay_ms > 0 ) {
					usleep( $request_delay_ms * 1000 );
				}
				$qualification = $qualify->execute(
					array(
						'url'             => $website,
						'name'            => $name,
						'persist_verdict' => true,
					)
				);
				if ( is_wp_error( $qualification ) ) {
					return $this->fail( $report, $qualification->get_error_code(), $qualification->get_error_message() );
				}
			}

			$verdict           = (string) ( $qualification['verdict'] ?? '' );
			$row['verdict']    = $verdict;
			$row['events_url'] = esc_url_raw( (string) ( $qualification['events_url'] ?? $website ) );
			if ( QualifyVerdict::QUALIFIED_STRUCTURED !== $verdict ) {
				$this->reject( $report, $row, '' !== $verdict ? $verdict : 'missing_verdict' );
				continue;
			}
			$events_flow = $this->findExistingFlow( $row['events_url'] );
			if ( is_array( $events_flow ) ) {
				$row['status']      = 'skipped_existing_flow';
				$row['flow_id']     = (int) ( $events_flow['flow_id'] ?? 0 );
				$report['venues'][] = $row;
				++$report['counts']['qualified'];
				++$report['counts']['skipped'];
				continue;
			}

			++$report['counts']['qualified'];
			$add_result = $add->execute(
				array(
					'pipeline_id' => $report['pipeline']['id'],
					'name'        => $name,
					'url'         => $row['events_url'],
					'website'     => $website,
					'address'     => sanitize_text_field( (string) ( $venue['address'] ?? '' ) ),
					'city'        => $city,
					'interval'    => (string) ( $params['interval'] ?? 'daily' ),
				)
			);
			if ( is_wp_error( $add_result ) ) {
				if ( 'venue_exists' !== $add_result->get_error_code() ) {
					return $this->fail( $report, $add_result->get_error_code(), $add_result->get_error_message() );
				}
				$error_data     = (array) $add_result->get_error_data();
				$row['status']  = 'skipped_existing_flow';
				$row['flow_id'] = (int) ( $error_data['flow_id'] ?? 0 );
				++$report['counts']['skipped'];
			} else {
				$row['status']  = 'added';
				$row['flow_id'] = (int) ( $add_result['flow_id'] ?? 0 );
				$this->rememberFlow( $website, $row );
				$this->rememberFlow( $row['events_url'], $row );
				++$report['counts']['added'];
			}
			$report['venues'][] = $row;
		}

		$report['status'] = 'completed';
		return $report;
	}

	/** Build the stable report envelope. */
	public static function emptyReport( string $city, int $qualification_budget ): array {
		return array(
			'schema'            => self::REPORT_SCHEMA,
			'city'              => $city,
			'status'            => 'processing',
			'pipeline'          => array(
				'id'     => 0,
				'status' => 'unknown',
			),
			'counts'            => array(
				'discovered' => 0,
				'qualified'  => 0,
				'added'      => 0,
				'rejected'   => 0,
				'skipped'    => 0,
			),
			'rejection_reasons' => array(),
			'rate'              => array(
				'discovery_used'       => 0,
				'qualification_budget' => $qualification_budget,
				'qualification_used'   => 0,
			),
			'venues'            => array(),
		);
	}

	/** Resolve an existing ability. */
	private function ability( string $name ) {
		return ( $this->ability_resolver )( $name );
	}

	/** Add a truthful rejection to the report. */
	private function reject( array &$report, array $row, string $reason ): void {
		$row['status'] = 'rejected';
		if ( '' === $row['verdict'] ) {
			$row['verdict'] = $reason;
		}
		$report['venues'][] = $row;
		++$report['counts']['rejected'];
		$report['rejection_reasons'][ $reason ] = 1 + (int) ( $report['rejection_reasons'][ $reason ] ?? 0 );
	}

	/** Mark a task-level failure so Data Machine can retry the city safely. */
	private function fail( array $report, string $code, string $message ): array {
		$report['status']            = 'failed';
		$report['retryable_failure'] = true;
		$report['error']             = array(
			'code'    => $code,
			'message' => $message,
		);
		return $report;
	}

	/** Resolve persisted and same-run flows through one URL identity path. */
	private function findExistingFlow( string $url ): ?array {
		$canonical = QualifyVerdict::canonicalize_url( $url );
		if ( isset( $this->new_flows_by_url[ $canonical ] ) ) {
			return $this->new_flows_by_url[ $canonical ];
		}
		return ( $this->flow_for_url )( $url );
	}

	/** Remember a newly created flow before the next candidate is processed. */
	private function rememberFlow( string $url, array $row ): void {
		$canonical = QualifyVerdict::canonicalize_url( $url );
		if ( '' !== $canonical ) {
			$this->new_flows_by_url[ $canonical ] = array(
				'flow_id'   => (int) ( $row['flow_id'] ?? 0 ),
				'flow_name' => (string) ( $row['name'] ?? '' ),
			);
		}
	}

	/** Correlate a candidate URL with existing universal scraper flows. */
	private function findFlowForUrl( string $url ): ?array {
		$canonical = QualifyVerdict::canonicalize_url( $url );
		if ( null === $this->flows_by_url ) {
			global $wpdb;
			$table = $wpdb->prefix . 'datamachine_flows';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name; candidate filtering is finalized by canonical URL comparison.
			$rows               = $wpdb->get_results( "SELECT flow_id, flow_name, flow_config FROM {$table} WHERE flow_config LIKE '%universal_web_scraper%'", ARRAY_A );
			$this->flows_by_url = array();
			foreach ( (array) $rows as $row ) {
				$config = json_decode( (string) ( $row['flow_config'] ?? '' ), true );
				foreach ( (array) $config as $step ) {
					$source     = (string) ( $step['handler_config']['source_url'] ?? $step['handler_configs']['universal_web_scraper']['source_url'] ?? '' );
					$source_key = QualifyVerdict::canonicalize_url( $source );
					if ( '' !== $source_key ) {
						$this->flows_by_url[ $source_key ] = array(
							'flow_id'   => (int) $row['flow_id'],
							'flow_name' => (string) $row['flow_name'],
						);
					}
				}
			}
		}
		return $this->flows_by_url[ $canonical ] ?? null;
	}

	/** Reuse only current, recent verdicts as safe resumable checkpoints. */
	private function isReusableVerdict( array $verdict, int $max_age_days ): bool {
		$qualified_at = strtotime( (string) ( $verdict['qualified_at'] ?? '' ) );
		if ( false === $qualified_at || $qualified_at < time() - max( 1, $max_age_days ) * DAY_IN_SECONDS ) {
			return false;
		}
		if ( defined( 'EXTRACHILL_EVENTS_VERSION' ) && (string) EXTRACHILL_EVENTS_VERSION !== (string) ( $verdict['qualifier_version'] ?? '' ) ) {
			return false;
		}
		return true;
	}
}
