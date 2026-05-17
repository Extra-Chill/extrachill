<?php
/**
 * `wp extrachill venues unqualifiable-flows` — audit zero-yield flows.
 *
 * Walks every active universal_web_scraper flow, counts its last N runs in
 * datamachine_jobs, and re-qualifies the source_url for any flow whose recent
 * runs all returned zero events. Persists the verdict so a future
 * requalify-pending can automatically resume the flow when a new extractor
 * lands.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use ExtraChillEvents\Core\QualifyVerdict;
use ExtraChillEvents\Core\QualifyVerdictsTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UnqualifiableFlowsCommand {

	use FlowHelpers;

	/**
	 * Audit zero-yield universal_web_scraper flows and classify them.
	 *
	 * For each active flow whose recent runs all ended in completed_no_items
	 * (or worse), the command runs qualify against the flow's source_url and
	 * classifies the failure under the qualify v2 verdict taxonomy. Persisting
	 * the verdict lets a future requalify-pending automatically promote the
	 * flow when an extractor that fixes its fingerprint pattern ships.
	 *
	 * ## OPTIONS
	 *
	 * [--min-runs=<n>]
	 * : Minimum recent runs required before a flow is considered "stable
	 * zero-yield". Flows with fewer runs are skipped (not enough signal).
	 * ---
	 * default: 14
	 * ---
	 *
	 * [--zero-yield-only]
	 * : Only audit flows where ALL recent runs returned zero events. With
	 * this flag, any non-zero-yield run disqualifies the flow from audit.
	 *
	 * [--auto-pause]
	 * : Pause flows whose new verdict is anything other than
	 * QUALIFIED_STRUCTURED — subject to the per-verdict confirmation rules
	 * in QualifyVerdict::CONFIRMATION_RULES. Flows that haven't built up
	 * enough corroborating verdict history are left active; the verdict row
	 * persists so a future audit can hit confirmation.
	 *
	 * [--force]
	 * : Bypass confirmation entirely and pause on the first failing verdict.
	 * Use this for operator-driven manual pauses where you already know the
	 * venue is dead. Requires --auto-pause.
	 *
	 * [--dry-run]
	 * : List candidate flows but do not run qualify or pause anything.
	 *
	 * [--limit=<n>]
	 * : Maximum flows to audit.
	 * ---
	 * default: 200
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill venues unqualifiable-flows --dry-run
	 *     wp extrachill venues unqualifiable-flows --zero-yield-only --min-runs=20
	 *     wp extrachill venues unqualifiable-flows --auto-pause
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		$min_runs        = max( 1, (int) ( $assoc_args['min-runs'] ?? 14 ) );
		$zero_yield_only = ! empty( $assoc_args['zero-yield-only'] );
		$auto            = ! empty( $assoc_args['auto-pause'] );
		$force           = ! empty( $assoc_args['force'] );
		$dry_run         = ! empty( $assoc_args['dry-run'] );
		$limit           = max( 1, (int) ( $assoc_args['limit'] ?? 200 ) );
		$format          = $assoc_args['format'] ?? 'table';

		// Network option lets operators disable confirmation globally
		// (restores pre-v0.21 "pause on first failing verdict" behavior).
		$confirmation_enabled = (bool) get_site_option( 'dme_qualify_pause_confirmation', true );

		$flows = $this->load_all_web_scraper_flows();
		if ( empty( $flows ) ) {
			\WP_CLI::log( 'No universal_web_scraper flows found.' );
			return;
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
				'flow_id'       => $flow['flow_id'],
				'flow_name'     => $flow['flow_name'],
				'source_url'    => $flow['source_url'],
				'recent_runs'   => $counts['recent'],
				'zero_yield'    => $counts['zero_yield'],
			);

			if ( count( $candidates ) >= $limit ) {
				break;
			}
		}

		if ( empty( $candidates ) ) {
			\WP_CLI::log( 'No zero-yield flows meet the audit criteria.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Auditing %d zero-yield flow(s)%s.', count( $candidates ), $dry_run ? ' (dry run)' : '' ) );

		if ( $dry_run ) {
			\WP_CLI\Utils\format_items(
				$format,
				$candidates,
				array( 'flow_id', 'flow_name', 'source_url', 'recent_runs', 'zero_yield' )
			);
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Run without --dry-run to qualify and (optionally) pause.' );
			return;
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/qualify-venue' ) : null;
		if ( ! $ability ) {
			\WP_CLI::error( 'extrachill/qualify-venue ability not available.' );
			return;
		}

		$verdicts_table = new QualifyVerdictsTable();
		$results        = array();
		$progress       = \WP_CLI\Utils\make_progress_bar( 'Auditing', count( $candidates ) );
		foreach ( $candidates as $c ) {
			$result = $ability->execute( array( 'url' => $c['source_url'] ) );
			$progress->tick();

			$row = array(
				'flow_id'     => $c['flow_id'],
				'flow_name'   => $c['flow_name'],
				'source_url'  => $c['source_url'],
				'new_verdict' => '',
				'event_count' => 0,
				'action'      => 'none',
			);

			if ( is_wp_error( $result ) ) {
				$row['new_verdict'] = 'error';
				$row['action']      = 'error: ' . $result->get_error_message();
				$results[]          = $row;
				continue;
			}

			$row['new_verdict'] = (string) ( $result['verdict'] ?? '' );
			$row['event_count'] = (int) ( $result['event_count'] ?? 0 );

			if ( QualifyVerdict::QUALIFIED_STRUCTURED === $row['new_verdict'] ) {
				$row['action'] = 'unexpected_pass';
			} elseif ( $auto ) {
				// Confirmation gate — unless --force or the network option
				// has disabled it, the candidate verdict must clear the
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
		$progress->finish();

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$results,
			array( 'flow_id', 'flow_name', 'new_verdict', 'event_count', 'action' )
		);

		$paused      = array_filter( $results, fn( $r ) => 'paused' === $r['action'] );
		$awaiting    = array_filter( $results, fn( $r ) => 'awaiting_confirmation' === $r['action'] );
		$recommended = array_filter( $results, fn( $r ) => 'recommend_pause' === $r['action'] );
		$unexpected  = array_filter( $results, fn( $r ) => 'unexpected_pass' === $r['action'] );

		if ( ! empty( $paused ) ) {
			\WP_CLI::success( sprintf( 'Paused %d flow(s).', count( $paused ) ) );
		}
		if ( ! empty( $awaiting ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( sprintf(
				'%d flow(s) awaiting confirmation — verdict recorded but pause threshold not yet met. Re-run later, or pass --force to bypass.',
				count( $awaiting )
			) );
		}
		if ( ! empty( $recommended ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( sprintf( '%d flow(s) recommend pausing. Re-run with --auto-pause to apply.', count( $recommended ) ) );
		}
		if ( ! empty( $unexpected ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( sprintf( '%d zero-yield flow(s) now qualify as STRUCTURED — investigate why they were yielding zero.', count( $unexpected ) ) );
		}
	}
}
