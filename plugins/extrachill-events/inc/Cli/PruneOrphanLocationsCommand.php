<?php
/**
 * `wp extrachill events locations prune-orphans` — delete empty orphan
 * `location` terms left behind by the venue-name pollution bug.
 *
 * Scope is intentionally narrow: this command ONLY deletes terms where
 *
 *   - taxonomy = location
 *   - parent = 0 (top-level)
 *   - count = 0 (no posts attached)
 *   - the term is NOT a parent of any other location term (so we don't
 *     accidentally delete a country/state node that has been emptied
 *     temporarily during a migration)
 *
 * Terms with count > 0 are reported but NOT deleted — operator must
 * reassign the attached posts first via
 *
 *   wp extrachill events fix-locations --yes --url=events.extrachill.com
 *
 * which delegates to extrachill/reconcile-event-locations.
 *
 * Companion to RepairFlowLocationsCommand for Extra-Chill/extrachill-events#98.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PruneOrphanLocationsCommand {

	/**
	 * Delete empty orphan location terms (parent=0, count=0, no
	 * children). Reports — but does not touch — orphan terms that still
	 * have posts attached.
	 *
	 * ## OPTIONS
	 *
	 * [--commit]
	 * : Actually delete the terms. Default is dry-run.
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
	 *     wp extrachill events locations prune-orphans --url=events.extrachill.com
	 *     wp extrachill events locations prune-orphans --url=events.extrachill.com --commit
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		if ( ! taxonomy_exists( 'location' ) ) {
			\WP_CLI::error( 'The location taxonomy is not registered on this site. Run with --url=events.extrachill.com.' );
			return;
		}

		$commit = ! empty( $assoc_args['commit'] );
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		global $wpdb;

		// All orphan candidates: top-level location terms whose term_id
		// is not used as a `parent` by any other location term.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidates = $wpdb->get_results(
			"SELECT tt.term_id, tt.term_taxonomy_id, tt.count, t.name, t.slug
			FROM {$wpdb->term_taxonomy} tt
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE tt.taxonomy = 'location'
			AND tt.parent = 0
			AND tt.term_id NOT IN (
				SELECT DISTINCT parent FROM {$wpdb->term_taxonomy}
				WHERE taxonomy = 'location' AND parent > 0
			)
			ORDER BY tt.count DESC, t.name ASC",
			ARRAY_A
		);

		if ( empty( $candidates ) ) {
			\WP_CLI::log( 'No orphan location terms found.' );
			return;
		}

		$report = array();
		$totals = array(
			'scanned'   => 0,
			'deletable' => 0,
			'deleted'   => 0,
			'has_posts' => 0,
			'failed'    => 0,
		);

		foreach ( $candidates as $row ) {
			++$totals['scanned'];
			$term_id = (int) $row['term_id'];
			$count   = (int) $row['count'];
			$name    = (string) $row['name'];
			$slug    = (string) $row['slug'];

			if ( $count > 0 ) {
				++$totals['has_posts'];
				$report[] = array(
					'term_id' => $term_id,
					'name'    => $name,
					'slug'    => $slug,
					'count'   => $count,
					'status'  => 'skipped',
					'reason'  => 'has_posts_run_fix_locations_first',
				);
				continue;
			}

			++$totals['deletable'];

			if ( ! $commit ) {
				$report[] = array(
					'term_id' => $term_id,
					'name'    => $name,
					'slug'    => $slug,
					'count'   => $count,
					'status'  => 'would_delete',
					'reason'  => 'dry_run',
				);
				continue;
			}

			$result = wp_delete_term( $term_id, 'location' );
			if ( true === $result ) {
				++$totals['deleted'];
				$report[] = array(
					'term_id' => $term_id,
					'name'    => $name,
					'slug'    => $slug,
					'count'   => $count,
					'status'  => 'deleted',
					'reason'  => 'wp_delete_term_ok',
				);
			} else {
				++$totals['failed'];
				$reason   = is_wp_error( $result ) ? $result->get_error_code() : 'wp_delete_term_returned_' . var_export( $result, true );
				$report[] = array(
					'term_id' => $term_id,
					'name'    => $name,
					'slug'    => $slug,
					'count'   => $count,
					'status'  => 'failed',
					'reason'  => (string) $reason,
				);
			}
		}

		\WP_CLI\Utils\format_items(
			$format,
			$report,
			array( 'term_id', 'name', 'slug', 'count', 'status', 'reason' )
		);

		\WP_CLI::log( '' );
		\WP_CLI::log(
			sprintf(
				'Summary: scanned=%d deletable=%d deleted=%d has_posts=%d failed=%d',
				$totals['scanned'],
				$totals['deletable'],
				$totals['deleted'],
				$totals['has_posts'],
				$totals['failed']
			)
		);

		if ( ! $commit ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Dry run — no terms deleted. Re-run with --commit to apply.' );
		}

		if ( $totals['has_posts'] > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning(
				sprintf(
					'%d orphan term(s) still have posts attached. Run `wp extrachill events fix-locations --yes --url=events.extrachill.com` to reassign them, then re-run prune-orphans.',
					$totals['has_posts']
				)
			);
		}
	}
}
