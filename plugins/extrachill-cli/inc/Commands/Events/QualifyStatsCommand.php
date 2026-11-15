<?php
/**
 * `wp extrachill venues qualify-stats` — verdict histogram.
 *
 * Thin adapter over QualifyStatsReport (extrachill-events), which aggregates
 * the latest verdict per canonical URL from <prefix>_dme_qualify_verdicts.
 * The JSON format surfaces full verdict counts plus agent_guidance so
 * external agents can read the output programmatically.
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use ExtraChillEvents\Core\QualifyCohortDeriver;
use ExtraChillEvents\Core\QualifyStatsReport;
use ExtraChillEvents\Core\QualifyVerdictsTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QualifyStatsCommand {

	/**
	 * Show a verdict histogram from the persistent verdict log.
	 *
	 * Aggregates over the LATEST verdict per canonical URL so historical
	 * verdicts do not double-count. Use --days to limit how far back to look.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Only include verdicts from the last N days.
	 * ---
	 * default: 30
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
	 *     wp extrachill venues qualify-stats
	 *     wp extrachill venues qualify-stats --days=7
	 *     wp extrachill venues qualify-stats --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		OwnerSiteAbility::require_service( 'events', 'Events', QualifyStatsReport::class );

		if ( ! QualifyVerdictsTable::table_exists() ) {
			\WP_CLI::error( 'Qualify verdicts table does not exist on this site. Deactivate/reactivate extrachill-events to install it.' );
			return;
		}

		$format  = $assoc_args['format'] ?? 'table';
		$report  = ( new QualifyStatsReport() )->build( (int) ( $assoc_args['days'] ?? 30 ) );
		$days    = $report['days'];
		$rows    = $report['verdicts'];
		$cohorts = $report['cohorts'];

		if ( 'json' === $format ) {
			\WP_CLI::log(
				(string) wp_json_encode(
					array(
						'days'                     => $days,
						'total_urls'               => array_sum( array_column( $rows, 'count' ) ),
						'representative_url_limit' => QualifyCohortDeriver::representative_url_limit(),
						'verdicts'                 => array_values( $rows ),
						'cohorts'                  => $cohorts,
						'guidance_index'           => QualifyStatsReport::guidance_index(),
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				)
			);
			return;
		}

		$ordered = QualifyStatsReport::ordered( $rows );

		$table_rows = array();
		foreach ( $ordered as $row ) {
			$table_rows[] = array(
				'verdict'       => $row['verdict'],
				'count'         => $row['count'],
				'top_platforms' => self::format_platforms( $row['platforms'] ),
			);
		}

		\WP_CLI::log( sprintf( 'Verdicts in the last %d day(s): %d URLs', $days, array_sum( array_column( $ordered, 'count' ) ) ) );
		\WP_CLI::log( '' );

		if ( empty( $table_rows ) ) {
			\WP_CLI::log( 'No verdicts logged yet.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $table_rows, array( 'verdict', 'count', 'top_platforms' ) );

		if ( empty( $cohorts ) ) {
			return;
		}

		$cohort_rows = array_map(
			static function ( array $cohort ): array {
				$cohort['representative_urls'] = implode( ', ', $cohort['representative_urls'] );
				return $cohort;
			},
			$cohorts
		);
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Remediation cohorts (latest verdict per URL):' );
		\WP_CLI::log( sprintf( 'Representative URLs are capped at %d per cohort.', QualifyCohortDeriver::representative_url_limit() ) );
		\WP_CLI::log( '' );
		\WP_CLI\Utils\format_items(
			$format,
			$cohort_rows,
			array( 'category', 'platform', 'structured_signal', 'page_shape', 'extractor', 'reason', 'count', 'representative_urls' )
		);
	}

	/**
	 * Compact "top platforms" string for table output. Caps at 3 entries plus
	 * an "(other)" bucket so the column stays readable.
	 *
	 * @param array $platforms Platform counts.
	 */
	public static function format_platforms( array $platforms ): string {
		if ( empty( $platforms ) ) {
			return '(n/a)';
		}

		$parts = array();
		$shown = 0;
		$other = 0;
		foreach ( $platforms as $name => $count ) {
			if ( $shown < 3 ) {
				$parts[] = $name . '(' . $count . ')';
				++$shown;
			} else {
				$other += $count;
			}
		}
		if ( $other > 0 ) {
			$parts[] = '(other)(' . $other . ')';
		}
		return implode( ' ', $parts );
	}
}
