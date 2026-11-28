<?php
/**
 * Operator re-qualification of venue URLs and live scraper flows.
 *
 * Backs three extrachill-cli adapters:
 *  - `wp extrachill venues requalify-pending`: re-run qualify for URLs whose
 *    latest logged verdict matches a filter (e.g. after shipping an extractor).
 *  - `wp extrachill venues requalify-flow`: re-qualify one or every live
 *    universal_web_scraper flow and optionally pause the ones that no longer
 *    qualify.
 *  - `wp extrachill venues unqualifiable-flows`: audit zero-yield flows,
 *    persist their verdicts, and pause or repair them.
 *
 * Every qualification goes through the extrachill/qualify-venue ability;
 * pauses and source_url repairs go through {@see FlowOps}.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Re-qualifies venue URLs and live flows and applies the resulting actions. */
class VenueRequalifier {

	use FlowHelpers;

	/**
	 * Resolve the qualify-venue ability.
	 *
	 * @return \WP_Ability|null Ability, or null when unavailable.
	 */
	protected function qualify_ability() {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/qualify-venue' ) : null;
		return $ability ? $ability : null;
	}

	/**
	 * Fetch latest-verdict-per-URL rows matching the filter.
	 *
	 * @param string $verdict  Verdict to filter on.
	 * @param string $platform Platform slug to require in fingerprint, or '' for any.
	 * @param int    $limit    Max rows.
	 * @return array<int,array{url:string,verdict:string,platforms:array<int,string>,qualified_at:string}>
	 */
	public function pending_candidates( string $verdict, string $platform, int $limit ): array {
		global $wpdb;
		$table = QualifyVerdictsTable::table_name();
		$limit = max( 1, $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a trusted internal identifier built from $wpdb->prefix.
			$wpdb->prepare(
				"SELECT v.url, v.verdict, v.fingerprint, v.qualified_at
				FROM {$table} v
				INNER JOIN (
					SELECT url_hash, MAX(id) AS max_id
					FROM {$table}
					GROUP BY url_hash
				) latest ON latest.max_id = v.id
				WHERE v.verdict = %s
				ORDER BY v.qualified_at DESC
				LIMIT %d",
				$verdict,
				// Over-fetch when platform filter is set so the post-filter
				// still hits --limit. Cap at a sane upper bound.
				'' === $platform ? $limit : min( $limit * 10, 1000 )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$decoded   = json_decode( (string) ( $row['fingerprint'] ?? '' ), true );
			$platforms = is_array( $decoded ) && isset( $decoded['platforms_detected'] ) && is_array( $decoded['platforms_detected'] )
				? array_values( array_map( 'strval', $decoded['platforms_detected'] ) )
				: array();

			if ( '' !== $platform && ! in_array( $platform, $platforms, true ) ) {
				continue;
			}

			$out[] = array(
				'url'          => (string) $row['url'],
				'verdict'      => (string) $row['verdict'],
				'platforms'    => $platforms,
				'qualified_at' => (string) $row['qualified_at'],
			);

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Re-run qualify for each pending candidate.
	 *
	 * @param array<int,array{url:string,verdict:string}> $candidates Rows from pending_candidates().
	 * @param callable|null                               $tick       Called once per URL (progress).
	 * @return array{results:array<int,array<string,mixed>>,promotions:array<int,array<string,mixed>>}|\WP_Error
	 */
	public function requalify_pending( array $candidates, ?callable $tick = null ) {
		$ability = $this->qualify_ability();
		if ( ! $ability ) {
			return new \WP_Error( 'qualify_ability_unavailable', 'extrachill/qualify-venue ability not available.' );
		}

		$results = array();
		foreach ( $candidates as $c ) {
			$result = $ability->execute( array( 'url' => $c['url'] ) );
			if ( null !== $tick ) {
				$tick();
			}

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'url'            => $c['url'],
					'old_verdict'    => $c['verdict'],
					'new_verdict'    => 'error',
					'agent_guidance' => '',
					'event_count'    => 0,
					'flipped'        => 'no',
				);
				continue;
			}

			$new_verdict = (string) ( $result['verdict'] ?? '' );
			$flipped     = ( $new_verdict !== $c['verdict'] ) ? 'YES' : 'no';

			$results[] = array(
				'url'            => $c['url'],
				'old_verdict'    => $c['verdict'],
				'new_verdict'    => $new_verdict,
				'agent_guidance' => (string) ( $result['agent_guidance'] ?? '' ),
				'event_count'    => (int) ( $result['event_count'] ?? 0 ),
				'flipped'        => $flipped,
			);
		}

		// Promotions (verdict flipped to QUALIFIED_STRUCTURED) are what the
		// operator can act on.
		$promotions = array_values(
			array_filter(
				$results,
				static function ( $r ) {
					return QualifyVerdict::QUALIFIED_STRUCTURED === $r['new_verdict'];
				}
			)
		);

		return array(
			'results'    => $results,
			'promotions' => $promotions,
		);
	}

	/**
	 * Load the flows targeted by requalify-flow.
	 *
	 * @param string $target Flow ID or "all".
	 * @param int    $limit  Maximum flows when $target is "all".
	 * @return array<int,array<string,mixed>>|null Flows, or null when a single flow is not found.
	 */
	public function target_flows( string $target, int $limit ): ?array {
		if ( 'all' === $target ) {
			return array_slice( $this->load_all_web_scraper_flows(), 0, max( 1, $limit ) );
		}

		$flow = $this->load_web_scraper_flow( (int) $target );
		return $flow ? array( $flow ) : null;
	}

	/**
	 * Re-qualify live flows and decide (or apply) the follow-up action.
	 *
	 * @param array<int,array<string,mixed>> $flows      Flows from target_flows().
	 * @param bool                           $dry_run    Report only; do not qualify or pause.
	 * @param bool                           $auto_pause Pause flows that no longer qualify.
	 * @return array<int,array<string,mixed>>|\WP_Error Result rows.
	 */
	public function requalify_flows( array $flows, bool $dry_run, bool $auto_pause ) {
		$ability = $this->qualify_ability();
		if ( ! $ability && ! $dry_run ) {
			return new \WP_Error( 'qualify_ability_unavailable', 'extrachill/qualify-venue ability not available.' );
		}

		$results = array();
		foreach ( $flows as $flow ) {
			$row = array(
				'flow_id'        => $flow['flow_id'],
				'flow_name'      => $flow['flow_name'],
				'source_url'     => $flow['source_url'],
				'new_verdict'    => '',
				'event_count'    => 0,
				'action'         => 'none',
				'agent_guidance' => '',
			);

			if ( $dry_run ) {
				$row['action'] = 'would_requalify';
				$results[]     = $row;
				continue;
			}

			$result = $ability->execute(
				array(
					'url'     => $flow['source_url'],
					'flow_id' => (int) $flow['flow_id'],
				)
			);
			if ( is_wp_error( $result ) ) {
				$row['new_verdict'] = 'error';
				$row['action']      = 'error: ' . $result->get_error_message();
				$results[]          = $row;
				continue;
			}

			$row['new_verdict']    = (string) ( $result['verdict'] ?? '' );
			$row['event_count']    = (int) ( $result['event_count'] ?? 0 );
			$row['agent_guidance'] = (string) ( $result['agent_guidance'] ?? '' );

			if ( QualifyVerdict::QUALIFIED_STRUCTURED === $row['new_verdict'] ) {
				$row['action'] = 'keep';
			} elseif ( QualifyVerdict::is_qualified( $row['new_verdict'] ) ) {
				// QUALIFIED_FOR_FLYER — still qualified but needs review;
				// recommend operator inspection rather than auto-pause.
				$row['action'] = 'review_recommended';
			} elseif ( $auto_pause ) {
				// Not qualified anymore and auto-pause is set — pause the flow.
				$ok            = $this->pause_requalified_flow( $flow, $row['new_verdict'] );
				$row['action'] = $ok ? 'paused' : 'pause_failed';
			} else {
				// Not qualified anymore — recommend pause without acting.
				$row['action'] = 'recommend_pause';
			}

			$results[] = $row;
		}

		return $results;
	}

	/**
	 * Pause a requalified flow while preserving the URL needed by rechecks.
	 *
	 * @param array  $flow    Flow metadata from FlowHelpers.
	 * @param string $verdict New qualification verdict.
	 * @return bool True when the flow was paused.
	 */
	protected function pause_requalified_flow( array $flow, string $verdict ): bool {
		return $this->pause_flow_by_verdict( $flow['flow_id'], $verdict, $flow['source_url'] );
	}

	/**
	 * Select active flows whose recent runs make them stable zero-yield.
	 *
	 * @param int  $min_runs        Minimum recent runs required.
	 * @param bool $zero_yield_only Require every recent run to be zero-yield.
	 * @param int  $limit           Maximum candidates.
	 * @return array<int,array<string,mixed>>|null Candidates, or null when no scraper flows exist.
	 */
	public function zero_yield_candidates( int $min_runs, bool $zero_yield_only, int $limit ): ?array {
		$min_runs = max( 1, $min_runs );
		$limit    = max( 1, $limit );

		$flows = $this->load_all_web_scraper_flows();
		if ( empty( $flows ) ) {
			return null;
		}

		$candidates = array();
		foreach ( $flows as $flow ) {
			// Skip already-paused flows — they were either paused manually
			// or by an earlier audit pass; either way, no need to re-audit.
			$interval = (string) ( $flow['scheduling_config']['interval'] ?? '' );
			if ( 'manual' === $interval ) {
				continue;
			}

			$counts = $this->count_recent_runs( $flow['flow_id'], max( $min_runs, 25 ) );

			if ( $counts['recent'] < $min_runs ) {
				continue;
			}

			if ( $zero_yield_only ) {
				// Strict: zero_yield must equal the run count exactly.
				if ( $counts['zero_yield'] !== $counts['recent'] ) {
					continue;
				}
			} else {
				// Default: any flow whose completed runs are all zero-yield
				// (allowing failed/skipped runs to coexist).
				if ( 0 === $counts['any_completed'] ) {
					continue;
				}
				if ( $counts['zero_yield'] < $counts['any_completed'] ) {
					continue;
				}
			}

			$candidates[] = array(
				'flow_id'     => $flow['flow_id'],
				'flow_name'   => $flow['flow_name'],
				'source_url'  => $flow['source_url'],
				'recent_runs' => $counts['recent'],
				'zero_yield'  => $counts['zero_yield'],
			);

			if ( count( $candidates ) >= $limit ) {
				break;
			}
		}

		return $candidates;
	}

	/**
	 * Validate unqualifiable-flows options before any flow is inspected.
	 *
	 * @param bool $apply_repair Apply source_url repair proposals.
	 * @param bool $dry_run      Diagnostics only.
	 * @param bool $confirmed    Operator confirmed repairs.
	 * @return true|\WP_Error
	 */
	public static function validate_audit_options( bool $apply_repair, bool $dry_run, bool $confirmed ) {
		if ( $apply_repair && ( $dry_run || ! $confirmed ) ) {
			return new \WP_Error( 'apply_repair_requires_confirmation', '--apply-repair requires --yes and cannot be combined with --dry-run.' );
		}
		return true;
	}

	/**
	 * Qualify zero-yield candidates and pause, repair, or recommend.
	 *
	 * @param array<int,array<string,mixed>> $candidates Rows from zero_yield_candidates().
	 * @param array{auto_pause?:bool,force?:bool,dry_run?:bool,apply_repair?:bool} $options Audit options.
	 * @param callable|null                  $tick       Called once per flow (progress).
	 * @return array<int,array<string,mixed>>|\WP_Error Result rows.
	 */
	public function audit_zero_yield( array $candidates, array $options, ?callable $tick = null ) {
		$auto         = ! empty( $options['auto_pause'] );
		$force        = ! empty( $options['force'] );
		$dry_run      = ! empty( $options['dry_run'] );
		$apply_repair = ! empty( $options['apply_repair'] );

		$ability = $this->qualify_ability();
		if ( ! $ability ) {
			return new \WP_Error( 'qualify_ability_unavailable', 'extrachill/qualify-venue ability not available.' );
		}

		// Network option lets operators disable confirmation globally
		// (restores pre-v0.21 "pause on first failing verdict" behavior).
		$confirmation_enabled = (bool) get_site_option( 'dme_qualify_pause_confirmation', true );

		$verdicts_table = new QualifyVerdictsTable();
		$results        = array();
		foreach ( $candidates as $c ) {
			$result = $ability->execute(
				array(
					'url'             => $c['source_url'],
					'flow_id'         => (int) $c['flow_id'],
					'persist_verdict' => ! $dry_run,
				)
			);
			if ( null !== $tick ) {
				$tick();
			}

			$row = array(
				'flow_id'               => $c['flow_id'],
				'flow_name'             => $c['flow_name'],
				'source_url'            => $c['source_url'],
				'new_verdict'           => '',
				'event_count'           => 0,
				'raw_extracted'         => 0,
				'unique_source'         => 0,
				'processed'             => null,
				'active_claim'          => null,
				'reprocess_eligible'    => null,
				'selected_by_max_items' => null,
				'production_eligible'   => null,
				'context_supplied'      => false,
				'complete'              => false,
				'identifier_source'     => '',
				'diagnostic_error'      => '',
				'repair_proposal'       => null,
				'action'                => 'none',
			);

			if ( is_wp_error( $result ) ) {
				$row['new_verdict'] = 'error';
				$row['action']      = 'error: ' . $result->get_error_message();
				$results[]          = $row;
				continue;
			}

			$row['new_verdict']           = (string) ( $result['verdict'] ?? '' );
			$row['event_count']           = (int) ( $result['event_count'] ?? 0 );
			$production                   = is_array( $result['production_context'] ?? null ) ? $result['production_context'] : array();
			$row['raw_extracted']         = (int) ( $production['raw_extracted'] ?? 0 );
			$row['unique_source']         = (int) ( $production['unique_source'] ?? 0 );
			$row['processed']             = $production['processed'] ?? null;
			$row['active_claim']          = $production['active_claim'] ?? null;
			$row['reprocess_eligible']    = $production['reprocess_eligible'] ?? null;
			$row['selected_by_max_items'] = $production['selected_by_max_items'] ?? null;
			$row['production_eligible']   = $production['production_eligible'] ?? null;
			$row['context_supplied']      = ! empty( $production['context_supplied'] );
			$row['complete']              = true === ( $production['complete'] ?? false );
			$row['identifier_source']     = (string) ( $production['identifier_source'] ?? '' );
			$row['diagnostic_error']      = (string) ( $production['error'] ?? '' );
			$row['repair_proposal']       = $result['repair_proposal'] ?? null;

			if ( QualifyVerdict::QUALIFIED_STRUCTURED === $row['new_verdict'] ) {
				$row['action'] = $this->classify_qualified_action( $production, $row['repair_proposal'] );
				if ( 'repair_proposed' === $row['action'] ) {
					if ( $apply_repair && ! empty( $row['repair_proposal']['same_host'] ) ) {
						$repaired      = FlowOps::repair_flow_source_url(
							(int) $c['flow_id'],
							(string) $row['repair_proposal']['current'],
							(string) $row['repair_proposal']['proposed']
						);
						$row['action'] = $repaired ? 'repaired' : 'repair_failed';
					} else {
						$row['action'] = 'repair_proposed';
					}
				}
			} elseif ( $auto && ! $dry_run ) {
				// Confirmation gate — unless force or the network option has
				// disabled it, the candidate verdict must clear the
				// per-verdict CONFIRMATION_RULES threshold before we pause.
				if ( ! $force && $confirmation_enabled ) {
					$url_hash = QualifyVerdict::url_hash( $c['source_url'] );
					if ( '' === $url_hash || ! $verdicts_table->meets_pause_confirmation( $url_hash, $row['new_verdict'] ) ) {
						$row['action'] = 'awaiting_confirmation';
						$results[]     = $row;
						continue;
					}
				}
				$ok            = $this->pause_flow_by_verdict( $c['flow_id'], $row['new_verdict'], $c['source_url'] );
				$row['action'] = $ok ? 'paused' : 'pause_failed';
			} else {
				$row['action'] = 'recommend_pause';
			}

			$results[] = $row;
		}

		return $results;
	}

	/**
	 * Interpret a structured extraction using production lifecycle evidence.
	 *
	 * @param array      $production      Production diagnostics.
	 * @param array|null $repair_proposal Optional source URL repair proposal.
	 * @return string Action.
	 */
	protected function classify_qualified_action( array $production, ?array $repair_proposal ): string {
		if ( ! empty( $repair_proposal ) ) {
			return 'repair_proposed';
		}
		if ( true === ( $production['complete'] ?? false )
			&& 0 === ( $production['production_eligible'] ?? null ) ) {
			return 'expected_zero';
		}
		return 'unexpected_pass';
	}
}
