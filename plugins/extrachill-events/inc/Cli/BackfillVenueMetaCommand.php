<?php
/**
 * `wp extrachill events venues backfill-meta` — copy venue address
 * fields from `universal_web_scraper` flow configs onto the venue
 * taxonomy term meta.
 *
 * Why this exists: a chunk of the venue terms on events.extrachill.com
 * have no `_venue_city` / `_venue_state` / `_venue_zip` /
 * `_venue_address` meta, which means the location-normalizer can't
 * resolve them to a market. The address data DOES exist — it's stored
 * on the scraper flow that created the venue (set by
 * `VenueAddAbilities::patchFlowSteps`), it just never got written back
 * to the term itself.
 *
 * This CLI walks every `universal_web_scraper` flow, reads
 * `handler_configs.universal_web_scraper.venue` (the term ID) and the
 * sibling `venue_address|venue_city|venue_state|venue_zip` fields, and
 * writes them to the term as `_venue_address|_venue_city|_venue_state|
 * _venue_zip`. Only writes when the meta key is currently empty — never
 * clobbers existing data.
 *
 * Part of the cleanup chain for Extra-Chill/extrachill-events#98.
 * Correct run order:
 *
 *   1. wp extrachill events flows audit-pipelines --commit   (#101)
 *   2. wp extrachill events flows repair-locations --commit  (#100)
 *   3. wp extrachill events venues backfill-meta --commit    (this)
 *   4. wp extrachill events fix-locations --yes              (existing)
 *   5. wp extrachill events locations prune-orphans --commit (#100)
 *
 * Always invoke with `--url=events.extrachill.com` so $wpdb->prefix
 * resolves to `c8c_7_`.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackfillVenueMetaCommand {

	/**
	 * Backfill `_venue_address|_venue_city|_venue_state|_venue_zip`
	 * meta on venue terms from the `universal_web_scraper` flow that
	 * created them.
	 *
	 * Idempotent — only writes when the existing term meta is empty.
	 *
	 * ## OPTIONS
	 *
	 * [--commit]
	 * : Actually write the meta. Default is dry-run.
	 *
	 * [--venue-id=<id>]
	 * : Limit to a single venue term ID. Useful for spot-fix work.
	 *
	 * [--format=<format>]
	 * : Output format for the per-flow report.
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
	 *     wp extrachill events venues backfill-meta --url=events.extrachill.com
	 *     wp extrachill events venues backfill-meta --url=events.extrachill.com --commit
	 *     wp extrachill events venues backfill-meta --url=events.extrachill.com --venue-id=51395
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		if ( ! taxonomy_exists( 'venue' ) ) {
			\WP_CLI::error( 'The venue taxonomy is not registered on this site. Run with --url=events.extrachill.com.' );
			return;
		}

		$commit    = ! empty( $assoc_args['commit'] );
		$venue_id  = isset( $assoc_args['venue-id'] ) ? (int) $assoc_args['venue-id'] : 0;
		$format    = (string) ( $assoc_args['format'] ?? 'table' );

		global $wpdb;
		$flows_table = $wpdb->prefix . 'datamachine_flows';

		// Pull every flow that has a universal_web_scraper step. We
		// fetch the raw JSON column and parse per-row so we can read
		// the venue address fields without a complex JOIN.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT flow_id, flow_name, flow_config FROM {$flows_table}
			WHERE flow_config LIKE '%universal_web_scraper%'
			ORDER BY flow_id ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			\WP_CLI::log( 'No universal_web_scraper flows found.' );
			return;
		}

		// Meta keys we care about, in the order: flow field => term meta key.
		$field_map = array(
			'venue_address' => '_venue_address',
			'venue_city'    => '_venue_city',
			'venue_state'   => '_venue_state',
			'venue_zip'     => '_venue_zip',
		);

		$report = array();
		$totals = array(
			'flows_scanned'   => 0,
			'flows_no_venue'  => 0,
			'flows_no_addr'   => 0,
			'terms_seen'      => 0,
			'fields_would_write' => 0,
			'fields_written'  => 0,
			'fields_skipped'  => 0, // already populated
			'fields_failed'   => 0,
		);

		// De-dupe at the term level — multiple flows can target the
		// same venue (rare but possible). First seen wins; later flows
		// can only contribute to fields that are still empty.
		$seen_terms = array();

		foreach ( $rows as $row ) {
			$totals['flows_scanned']++;

			$flow_id   = (int) $row['flow_id'];
			$flow_name = (string) $row['flow_name'];

			$config = json_decode( (string) $row['flow_config'], true );
			if ( ! is_array( $config ) ) {
				continue;
			}

			// Find the universal_web_scraper handler config.
			$uws = $this->extractUniversalWebScraperConfig( $config );
			if ( ! $uws ) {
				continue;
			}

			$term_id = isset( $uws['venue'] ) ? (int) $uws['venue'] : 0;
			if ( $term_id <= 0 ) {
				$totals['flows_no_venue']++;
				continue;
			}

			if ( $venue_id > 0 && $term_id !== $venue_id ) {
				continue;
			}

			// Confirm the term exists and is a venue.
			$term = get_term( $term_id, 'venue' );
			if ( ! $term || is_wp_error( $term ) ) {
				$report[] = array(
					'flow_id'   => $flow_id,
					'flow_name' => $flow_name,
					'venue_id'  => $term_id,
					'venue'     => '',
					'field'     => '',
					'value'     => '',
					'status'    => 'skipped',
					'reason'    => 'venue_term_not_found',
				);
				continue;
			}

			$venue_label = $term->name;

			// Does the flow actually have any address info to offer?
			$has_addr = false;
			foreach ( $field_map as $flow_field => $_meta_key ) {
				if ( ! empty( $uws[ $flow_field ] ) ) {
					$has_addr = true;
					break;
				}
			}
			if ( ! $has_addr ) {
				$totals['flows_no_addr']++;
				continue;
			}

			if ( ! isset( $seen_terms[ $term_id ] ) ) {
				$seen_terms[ $term_id ] = true;
				$totals['terms_seen']++;
			}

			foreach ( $field_map as $flow_field => $meta_key ) {
				$incoming = isset( $uws[ $flow_field ] ) ? trim( (string) $uws[ $flow_field ] ) : '';
				if ( '' === $incoming ) {
					continue;
				}

				$existing = (string) get_term_meta( $term_id, $meta_key, true );
				if ( '' !== trim( $existing ) ) {
					$totals['fields_skipped']++;
					$report[] = array(
						'flow_id'   => $flow_id,
						'flow_name' => $flow_name,
						'venue_id'  => $term_id,
						'venue'     => $venue_label,
						'field'     => $meta_key,
						'value'     => $incoming,
						'status'    => 'skipped',
						'reason'    => 'already_set:' . $this->truncate( $existing ),
					);
					continue;
				}

				if ( ! $commit ) {
					$totals['fields_would_write']++;
					$report[] = array(
						'flow_id'   => $flow_id,
						'flow_name' => $flow_name,
						'venue_id'  => $term_id,
						'venue'     => $venue_label,
						'field'     => $meta_key,
						'value'     => $incoming,
						'status'    => 'would_write',
						'reason'    => 'dry_run',
					);
					continue;
				}

				$ok = update_term_meta( $term_id, $meta_key, $incoming );
				if ( false === $ok ) {
					$totals['fields_failed']++;
					$report[] = array(
						'flow_id'   => $flow_id,
						'flow_name' => $flow_name,
						'venue_id'  => $term_id,
						'venue'     => $venue_label,
						'field'     => $meta_key,
						'value'     => $incoming,
						'status'    => 'failed',
						'reason'    => 'update_term_meta_returned_false',
					);
				} else {
					$totals['fields_written']++;
					$report[] = array(
						'flow_id'   => $flow_id,
						'flow_name' => $flow_name,
						'venue_id'  => $term_id,
						'venue'     => $venue_label,
						'field'     => $meta_key,
						'value'     => $incoming,
						'status'    => 'written',
						'reason'    => 'ok',
					);
				}
			}
		}

		// Default table view hides already_set rows because they're
		// noise — the operator wants to see what changed or would
		// change. Single-venue mode shows everything.
		$visible = ( $venue_id > 0 )
			? $report
			: array_values(
				array_filter(
					$report,
					static fn( $r ) => 'skipped' !== $r['status'] || 'venue_term_not_found' === $r['reason']
				)
			);

		if ( empty( $visible ) ) {
			\WP_CLI::log( 'Nothing to backfill — every relevant venue meta key is already populated.' );
		} else {
			\WP_CLI\Utils\format_items(
				$format,
				$visible,
				array( 'flow_id', 'flow_name', 'venue_id', 'venue', 'field', 'value', 'status', 'reason' )
			);
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf(
			'Summary: flows_scanned=%d flows_no_venue=%d flows_no_addr=%d terms_seen=%d would_write=%d written=%d already_set=%d failed=%d',
			$totals['flows_scanned'],
			$totals['flows_no_venue'],
			$totals['flows_no_addr'],
			$totals['terms_seen'],
			$totals['fields_would_write'],
			$totals['fields_written'],
			$totals['fields_skipped'],
			$totals['fields_failed']
		) );

		if ( ! $commit ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Dry run — no term meta written. Re-run with --commit to apply.' );
		}
	}

	/**
	 * Walk a flow_config array and return the first
	 * `universal_web_scraper` handler config encountered, or null.
	 */
	private function extractUniversalWebScraperConfig( array $flow_config ): ?array {
		foreach ( $flow_config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$handler_configs = $step['handler_configs'] ?? array();
			if ( ! is_array( $handler_configs ) || empty( $handler_configs['universal_web_scraper'] ) ) {
				continue;
			}
			$uws = $handler_configs['universal_web_scraper'];
			return is_array( $uws ) ? $uws : null;
		}
		return null;
	}

	/**
	 * Trim long strings for the "already_set" reason column so the
	 * table doesn't wrap to nine lines per row.
	 */
	private function truncate( string $value, int $max = 40 ): string {
		$value = trim( $value );
		if ( strlen( $value ) <= $max ) {
			return $value;
		}
		return substr( $value, 0, $max - 1 ) . '…';
	}
}
