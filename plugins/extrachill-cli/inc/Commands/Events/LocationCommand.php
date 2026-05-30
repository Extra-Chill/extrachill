<?php
/**
 * Events Location CLI Commands
 *
 * Wraps event-location alignment abilities from extrachill-events.
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LocationCommand {

	/**
	 * Audit event location assignments against venue city.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Optional comma-separated event post IDs.
	 *
	 * [--limit=<limit>]
	 * : Maximum events to scan. Use 0 for all.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--offset=<offset>]
	 * : Offset for batched audits.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--include-matches]
	 * : Include already-correct events in the output.
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
	 *     wp extrachill events audit-locations
	 *     wp extrachill events audit-locations --limit=0 --format=json
	 *     wp extrachill events audit-locations --ids=9936,9919,9842
	 *
	 * @subcommand audit-locations
	 * @when after_wp_load
	 */
	public function audit_locations( $args, $assoc_args ) {
		$result = $this->run_alignment_ability( $assoc_args, false );
		$this->render_result( $result, $assoc_args['format'] ?? 'table' );
	}

	/**
	 * Fix event location assignments to match venue city.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Optional comma-separated event post IDs.
	 *
	 * [--limit=<limit>]
	 * : Maximum events to scan. Use 0 for all.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--offset=<offset>]
	 * : Offset for batched repairs.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--include-matches]
	 * : Include already-correct events in the output.
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
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events fix-locations --ids=9936,9919,9842 --yes
	 *     wp extrachill events fix-locations --limit=0 --yes --format=json
	 *
	 * @subcommand fix-locations
	 * @when after_wp_load
	 */
	public function fix_locations( $args, $assoc_args ) {
		if ( empty( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( 'This will update event location terms to match venue city. Continue?' );
		}

		$result = $this->run_alignment_ability( $assoc_args, true );
		$this->render_result( $result, $assoc_args['format'] ?? 'table' );
	}

	/**
	 * Run the alignment ability.
	 *
	 * @param array $assoc_args CLI args.
	 * @param bool  $apply      Whether to apply fixes.
	 * @return array
	 */
	private function run_alignment_ability( array $assoc_args, bool $apply ): array {
		// CLI commands run as admin — set current user so ability permission check passes.
		wp_set_current_user( 1 );

		$ability = wp_get_ability( 'extrachill/reconcile-event-locations' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/reconcile-event-locations ability not available. Is extrachill-events active?' );
		}

		$post_ids = array();
		if ( ! empty( $assoc_args['ids'] ) ) {
			$post_ids = array_values(
				array_filter(
					array_map( 'absint', explode( ',', (string) $assoc_args['ids'] ) )
				)
			);
		}

		$result = $ability->execute(
			array(
				'apply'           => $apply,
				'post_ids'        => $post_ids,
				'limit'           => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 500,
				'offset'          => isset( $assoc_args['offset'] ) ? (int) $assoc_args['offset'] : 0,
				'include_matches' => ! empty( $assoc_args['include-matches'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		return $result;
	}

	/**
	 * Generate a market overview report for event calendar locations.
	 *
	 * Combines event/venue counts, flow breakdown (venue scrapers / TM / Dice),
	 * GA4 traffic, and GSC search data into a single view. Use --sort=opportunity
	 * to find cities where adding venue scrapers would have the biggest impact.
	 *
	 * ## OPTIONS
	 *
	 * [--location=<slug>]
	 * : Filter to a single location by slug.
	 *
	 * [--days=<days>]
	 * : Days of analytics data to include.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Max locations to show.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--sort=<field>]
	 * : Sort by: opportunity, events, venues, sessions, impressions, scrapers.
	 * ---
	 * default: opportunity
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
	 *     wp extrachill events market-report --url=events.extrachill.com
	 *     wp extrachill events market-report --location=nashville --url=events.extrachill.com
	 *     wp extrachill events market-report --sort=sessions --days=14 --url=events.extrachill.com
	 *     wp extrachill events market-report --sort=scrapers --url=events.extrachill.com
	 *     wp extrachill events market-report --format=json --url=events.extrachill.com
	 *
	 * @subcommand market-report
	 * @when after_wp_load
	 */
	public function market_report( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/market-report' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/market-report ability not available. Is extrachill-events active on this site?' );
		}

		$input = array(
			'days'  => (int) ( $assoc_args['days'] ?? 7 ),
			'limit' => (int) ( $assoc_args['limit'] ?? 30 ),
			'sort'  => $assoc_args['sort'] ?? 'opportunity',
		);

		if ( ! empty( $assoc_args['location'] ) ) {
			$input['location'] = $assoc_args['location'];
		}

		WP_CLI::log( 'Generating market report...' );

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		// Build table rows.
		$rows = array();
		foreach ( $result['locations'] as $loc ) {
			$rows[] = array(
				'location'    => $loc['name'],
				'events'      => $loc['events'],
				'upcoming'    => $loc['upcoming_events'],
				'venues'      => $loc['venues'],
				'scrapers'    => $loc['flows']['venue_scrapers'],
				'tm'          => $loc['flows']['ticketmaster'],
				'dice'        => $loc['flows']['dice'],
				'ga_sessions' => $loc['ga']['sessions'],
				'gsc_impr'    => $loc['gsc']['impressions'],
				'gsc_clicks'  => $loc['gsc']['clicks'],
				'opportunity' => $loc['opportunity_score'],
			);
		}

		if ( 'table' === $format ) {
			$summary = $result['summary'];
			WP_CLI::log( sprintf(
				'Market Report — %d locations, %s events, %s venues, %d flows (%d days analytics)',
				$summary['total_locations'],
				number_format( $summary['total_events'] ),
				number_format( $summary['total_venues'] ),
				$summary['total_flows'],
				$input['days']
			) );
			WP_CLI::log( sprintf( 'Sorted by: %s', $input['sort'] ) );
			WP_CLI::log( str_repeat( '─', 110 ) );
		}

		Utils\format_items(
			$format,
			$rows,
			array( 'location', 'events', 'upcoming', 'venues', 'scrapers', 'tm', 'dice', 'ga_sessions', 'gsc_impr', 'gsc_clicks', 'opportunity' )
		);

		if ( 'table' === $format && ! empty( $result['locations'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Legend: scrapers=venue website scrapers | tm=Ticketmaster | dice=Dice.fm' );
			WP_CLI::log( 'Opportunity = (sessions×5 + impressions×0.5 + events×0.1) × (10 / (scrapers+1))' );
		}
	}

	/**
	 * Audit event times for timezone mismatches and suspicious values.
	 *
	 * Scans events for: UTC timezone on US venues, missing venue timezone,
	 * timezone mismatch with location hierarchy, suspicious show times (1-6 AM).
	 *
	 * ## OPTIONS
	 *
	 * [--flow=<flow_id>]
	 * : Filter to events from a specific flow.
	 *
	 * [--location=<slug_or_id>]
	 * : Filter by location term slug or ID.
	 *
	 * [--venue=<slug_or_id>]
	 * : Filter by venue term slug or ID.
	 *
	 * [--limit=<limit>]
	 * : Maximum events to scan. Use 0 for all.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--offset=<offset>]
	 * : Offset for batched audits.
	 * ---
	 * default: 0
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
	 *     wp extrachill events audit-times --url=events.extrachill.com
	 *     wp extrachill events audit-times --flow=704 --url=events.extrachill.com
	 *     wp extrachill events audit-times --location=salt-lake-city --url=events.extrachill.com
	 *     wp extrachill events audit-times --limit=0 --format=json --url=events.extrachill.com
	 *
	 * @subcommand audit-times
	 * @when after_wp_load
	 */
	public function audit_times( $args, $assoc_args ) {
		wp_set_current_user( 1 );

		$ability = wp_get_ability( 'extrachill/audit-event-times' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/audit-event-times ability not available. Is extrachill-events active?' );
		}

		$input = array(
			'limit'  => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 500,
			'offset' => isset( $assoc_args['offset'] ) ? (int) $assoc_args['offset'] : 0,
		);

		if ( ! empty( $assoc_args['flow'] ) ) {
			$input['flow_id'] = (int) $assoc_args['flow'];
		}
		if ( ! empty( $assoc_args['location'] ) ) {
			$input['location'] = $assoc_args['location'];
		}
		if ( ! empty( $assoc_args['venue'] ) ) {
			$input['venue'] = $assoc_args['venue'];
		}

		WP_CLI::log( 'Auditing event times...' );

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( ! empty( $result['results'] ) ) {
			Utils\format_items(
				$format,
				$result['results'],
				array( 'post_id', 'title', 'venue', 'start_time', 'venue_tz', 'expected_tz', 'location', 'flow_id', 'issues' )
			);
		} elseif ( 'table' === $format ) {
			WP_CLI::success( 'No time issues found.' );
		}

		if ( 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Checked: %d', (int) $result['checked_count'] ) );
			WP_CLI::log( sprintf( 'Flagged: %d', (int) $result['flagged_count'] ) );
		}
	}

	/**
	 * Fix event times by converting between timezones.
	 *
	 * Finds events with venues in the --from timezone and converts their
	 * block attribute times to the --to timezone. Updates post content and
	 * venue timezone meta.
	 *
	 * ## OPTIONS
	 *
	 * --from=<timezone>
	 * : Source timezone (the wrong one currently stored).
	 *
	 * --to=<timezone>
	 * : Target timezone (the correct one to convert to).
	 *
	 * [--flow=<flow_id>]
	 * : Scope to events from a specific flow.
	 *
	 * [--limit=<limit>]
	 * : Maximum events to fix. Use 0 for all.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--dry-run]
	 * : Preview changes without applying.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
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
	 *     wp extrachill events fix-times --from=UTC --to=America/Denver --dry-run --url=events.extrachill.com
	 *     wp extrachill events fix-times --from=UTC --to=America/Denver --flow=704 --yes --url=events.extrachill.com
	 *     wp extrachill events fix-times --from=America/Chicago --to=America/New_York --dry-run --url=events.extrachill.com
	 *
	 * @subcommand fix-times
	 * @when after_wp_load
	 */
	public function fix_times( $args, $assoc_args ) {
		wp_set_current_user( 1 );

		$ability = wp_get_ability( 'extrachill/fix-event-times' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/fix-event-times ability not available. Is extrachill-events active?' );
		}

		$from    = $assoc_args['from'] ?? '';
		$to      = $assoc_args['to'] ?? '';
		$dry_run = ! empty( $assoc_args['dry-run'] );

		if ( empty( $from ) || empty( $to ) ) {
			WP_CLI::error( 'Both --from and --to timezone parameters are required.' );
		}

		if ( ! $dry_run && empty( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'This will convert event times from %s to %s and update venue timezone meta. Continue?', $from, $to ) );
		}

		$input = array(
			'from'    => $from,
			'to'      => $to,
			'dry_run' => $dry_run,
			'limit'   => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 500,
		);

		if ( ! empty( $assoc_args['flow'] ) ) {
			$input['flow_id'] = (int) $assoc_args['flow'];
		}

		WP_CLI::log( sprintf( '%s event times: %s → %s', $dry_run ? 'Previewing' : 'Fixing', $from, $to ) );

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( ! empty( $result['results'] ) ) {
			// Build display rows with old→new columns.
			$rows    = array();
			$columns = array( 'post_id', 'title', 'venue', 'status' );

			// Detect which time fields changed to build dynamic columns.
			$time_fields = array( 'startDate', 'startTime', 'endDate', 'endTime' );
			$active_cols = array();
			foreach ( $result['results'] as $item ) {
				foreach ( $time_fields as $tf ) {
					if ( isset( $item[ $tf . '_old' ] ) ) {
						$active_cols[ $tf ] = true;
					}
				}
			}

			foreach ( $active_cols as $tf => $v ) {
				$columns[] = $tf . '_old';
				$columns[] = $tf . '_new';
			}

			Utils\format_items( $format, $result['results'], $columns );
		} elseif ( 'table' === $format ) {
			WP_CLI::success( 'No events found with the specified timezone.' );
		}

		if ( 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Checked: %d', (int) $result['checked_count'] ) );
			WP_CLI::log( sprintf( '%s: %d', $dry_run ? 'Would fix' : 'Fixed', (int) $result['fixed_count'] ) );
			if ( $dry_run && $result['fixed_count'] > 0 ) {
				WP_CLI::log( '' );
				WP_CLI::log( 'Run without --dry-run and with --yes to apply changes.' );
			}
		}
	}

	/**
	 * Render ability result.
	 *
	 * @param array  $result Ability result.
	 * @param string $format Output format.
	 * @return void
	 */
	private function render_result( array $result, string $format ): void {
		$rows = array();
		foreach ( $result['results'] as $item ) {
			$rows[] = array(
				'post_id'              => $item['post_id'],
				'title'                => $item['title'],
				'venue'                => $item['venue'],
				'venue_city'           => $item['venue_city'],
				'assigned_location'    => $item['assigned_location'],
				'expected_location'    => $item['expected_location'],
				'flow_id'              => $item['flow_id'],
				'flow_config_location' => $item['flow_config_location'],
				'status'               => $item['status'],
				'reason'               => $item['reason'],
			);
		}

		if ( 'table' === $format ) {
			WP_CLI::log( $result['message'] );
			WP_CLI::log( str_repeat( '─', 100 ) );
		}

		if ( ! empty( $rows ) ) {
			Utils\format_items(
				$format,
				$rows,
				array(
					'post_id',
					'title',
					'venue_city',
					'assigned_location',
					'expected_location',
					'flow_id',
					'flow_config_location',
					'status',
					'reason',
				)
			);
		} elseif ( 'table' === $format ) {
			WP_CLI::success( 'No affected events found for the selected scope.' );
		}

		if ( 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Checked: %d', (int) $result['checked_count'] ) );
			WP_CLI::log( sprintf( 'Mismatches: %d', (int) $result['mismatch_count'] ) );
			WP_CLI::log( sprintf( 'Fixed: %d', (int) $result['fixed_count'] ) );
			WP_CLI::log( sprintf( 'Unresolved: %d', (int) $result['unresolved_count'] ) );
		}
	}

	/**
	 * Generate an event roundup carousel graphic for a date range.
	 *
	 * Renders Instagram-portrait (1080x1350) carousel slides listing events
	 * grouped by day, branded with Extra Chill colors and fonts. Works for
	 * any date range — tonight, this weekend, a custom 5-day stretch — and
	 * can be filtered to a single location.
	 *
	 * Wraps the extrachill/event-roundup-build ability.
	 *
	 * ## OPTIONS
	 *
	 * [--date-start=<date>]
	 * : Start date (Y-m-d) or "today". Defaults to today.
	 *
	 * [--date-end=<date>]
	 * : End date (Y-m-d). Defaults to date-start (single-day roundup).
	 *
	 * [--scope=<scope>]
	 * : Named date scope: today, tonight, this-weekend, or this-week. Ignored when date-start is provided.
	 *
	 * [--week-start-day=<day>]
	 * : Weekday-name shortcut (e.g. "thursday"). Resolves to the next occurrence.
	 * Mutually exclusive with date-start.
	 *
	 * [--week-end-day=<day>]
	 * : Weekday-name shortcut (e.g. "sunday"). Used with week-start-day.
	 *
	 * [--location=<slug-or-id>]
	 * : Location taxonomy term slug or numeric term ID. Optional.
	 *
	 * [--title=<string>]
	 * : Title shown on the first slide. Optional.
	 *
	 * [--output=<dir>]
	 * : Directory to copy generated slides into. When omitted, prints the
	 * temp file paths produced by the ability (caller must handle cleanup).
	 *
	 * [--format=<format>]
	 * : Output format for the result summary.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Tonight in Charleston
	 *     wp extrachill events roundup --location=charleston --title="Tonight in Charleston" --url=events.extrachill.com
	 *
	 *     # This weekend (next Fri-Sun) in Charleston, save to a folder
	 *     wp extrachill events roundup --week-start-day=friday --week-end-day=sunday \
	 *       --location=charleston --title="This Weekend in Charleston" \
	 *       --output=/tmp/charleston-weekend --url=events.extrachill.com
	 *
	 *     # Custom 5-day stretch, all locations, JSON output for piping
	 *     wp extrachill events roundup --date-start=2026-04-25 --date-end=2026-04-30 \
	 *       --title="End of April Lineup" --format=json --url=events.extrachill.com
	 *
	 *     # Single day, by date
	 *     wp extrachill events roundup --date-start=2026-05-15 \
	 *       --location=austin --title="Friday in Austin" --url=events.extrachill.com
	 *
	 * @subcommand roundup
	 * @when after_wp_load
	 */
	public function roundup( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/event-roundup-build' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/event-roundup-build ability not available. Is extrachill-events active on this site?' );
		}

		// Map CLI flag names (kebab) → ability input keys (snake). Empty
		// inputs are intentionally NOT passed so the ability uses its own
		// defaults (e.g. date_start defaults to today).
		//
		// Date inputs are resolved at the CLI layer (natural language like
		// "today", "tomorrow", "+3 days", or "next friday"). The ability
		// keeps a strict Y-m-d contract for machine callers.
		$input = array();

		foreach (
			array(
				'date-start'     => 'date_start',
				'date-end'       => 'date_end',
			) as $cli_key => $ability_key
		) {
			if ( isset( $assoc_args[ $cli_key ] ) && '' !== $assoc_args[ $cli_key ] ) {
				$resolved = $this->resolve_date_input( (string) $assoc_args[ $cli_key ] );
				if ( null === $resolved ) {
					WP_CLI::error( sprintf( 'Could not parse %s value: %s', $cli_key, $assoc_args[ $cli_key ] ) );
				}
				$input[ $ability_key ] = $resolved;
			}
		}

		foreach (
			array(
				'week-start-day' => 'week_start_day',
				'week-end-day'   => 'week_end_day',
				'scope'          => 'scope',
				'location'       => 'location',
				'title'          => 'title',
			) as $cli_key => $ability_key
		) {
			if ( isset( $assoc_args[ $cli_key ] ) && '' !== $assoc_args[ $cli_key ] ) {
				$input[ $ability_key ] = (string) $assoc_args[ $cli_key ];
			}
		}

		WP_CLI::log( 'Building event roundup...' );

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$image_paths = (array) ( $result['image_paths'] ?? array() );
		$output_dir  = isset( $assoc_args['output'] ) ? rtrim( (string) $assoc_args['output'], '/' ) : '';
		$saved_paths = array();

		if ( '' !== $output_dir && ! empty( $image_paths ) ) {
			if ( ! is_dir( $output_dir ) ) {
				if ( ! wp_mkdir_p( $output_dir ) ) {
					WP_CLI::error( sprintf( 'Could not create output directory: %s', $output_dir ) );
				}
			}

			foreach ( $image_paths as $i => $src ) {
				if ( ! file_exists( $src ) ) {
					WP_CLI::warning( sprintf( 'Slide %d source missing: %s', $i + 1, $src ) );
					continue;
				}

				$ext  = pathinfo( $src, PATHINFO_EXTENSION );
				$ext  = $ext ? $ext : 'png';
				$dest = sprintf( '%s/roundup-slide-%d.%s', $output_dir, $i + 1, $ext );

				if ( ! copy( $src, $dest ) ) {
					WP_CLI::warning( sprintf( 'Could not copy slide %d to %s', $i + 1, $dest ) );
					continue;
				}

				$saved_paths[] = $dest;
			}
		} else {
			$saved_paths = $image_paths;
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::print_value(
				array(
					'success'       => (bool) ( $result['success'] ?? false ),
					'date_start'    => (string) ( $result['date_start'] ?? '' ),
					'date_end'      => (string) ( $result['date_end'] ?? '' ),
					'location'      => (string) ( $result['location_name'] ?? '' ),
					'total_events'  => (int) ( $result['total_events'] ?? 0 ),
					'slide_count'   => (int) ( $result['slide_count'] ?? 0 ),
					'image_paths'   => $saved_paths,
					'event_summary' => (string) ( $result['event_summary'] ?? '' ),
					'message'       => (string) ( $result['message'] ?? '' ),
				),
				array( 'format' => 'json' )
			);
			return;
		}

		// Table format.
		if ( empty( $result['success'] ) ) {
			WP_CLI::warning( $result['message'] ?? 'No slides generated.' );
			WP_CLI::log( sprintf( 'Date range: %s → %s', $result['date_start'] ?? '?', $result['date_end'] ?? '?' ) );
			WP_CLI::log( sprintf( 'Location:   %s', $result['location_name'] ?? '?' ) );
			return;
		}

		WP_CLI::success( sprintf( 'Generated %d slide%s', $result['slide_count'], 1 === (int) $result['slide_count'] ? '' : 's' ) );
		WP_CLI::log( sprintf( 'Date range:   %s → %s', $result['date_start'], $result['date_end'] ) );
		WP_CLI::log( sprintf( 'Location:     %s', $result['location_name'] ) );
		WP_CLI::log( sprintf( 'Total events: %d', (int) $result['total_events'] ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Slides:' );
		foreach ( $saved_paths as $i => $p ) {
			WP_CLI::log( sprintf( '  %d. %s', $i + 1, $p ) );
		}
	}

	/**
	 * Resolve a natural-language date input into Y-m-d.
	 *
	 * Accepts:
	 *   - Exact Y-m-d strings (passed through verbatim)
	 *   - "today", "tomorrow", "yesterday"
	 *   - PHP relative date strings: "+3 days", "next friday", "last monday"
	 *
	 * Resolution uses the WordPress site timezone so "today" matches what
	 * an editor would expect, not server UTC.
	 *
	 * @param string $input Raw date input from the CLI flag.
	 * @return string|null Y-m-d string on success, null on parse failure.
	 */
	private function resolve_date_input( string $input ): ?string {
		$input = trim( $input );
		if ( '' === $input ) {
			return null;
		}

		// Exact Y-m-d → pass through (cheap path; avoids the DateTime parser
		// reinterpreting "2026-01-02" as something weird).
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $input ) ) {
			$check = \DateTime::createFromFormat( 'Y-m-d', $input );
			if ( $check && $check->format( 'Y-m-d' ) === $input ) {
				return $input;
			}
			return null;
		}

		try {
			$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$date = new \DateTime( $input, $tz );
			return $date->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
