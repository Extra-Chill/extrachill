<?php
/**
 * `wp extrachill events backfill-authorship` — reattribute historical
 * automation that was misauthored to a human account (uid 1 by default)
 * back onto the network bot account, so per-author views (heatmap, points,
 * profile article counts) reflect genuine human authorship.
 *
 * Targets (issue #207 Phase 3):
 *   - blog 7  data_machine_events  post_author = 1 → bot id
 *       EXCEPT rows carrying `_datamachine_submitted_by` meta — those are
 *       genuine user submissions and are attributed to that submitter.
 *   - blog 11 festival_wire         post_author = 1 → bot id
 *
 * Blog 1 `post` is NEVER touched — that is genuine human editorial.
 *
 * Why a CLI in this repo (not extrachill-cli or a one-off script): the
 * command operates on events + wire authorship data, the events repo already
 * owns the `wp extrachill events ...` operator CLI surface and its dry-run /
 * --commit conventions (BackfillVenueMetaCommand, RepairFlowLocationsCommand),
 * and shipping it here keeps the honest-authorship work in one reviewable
 * place alongside the Phase 1 submission changes. The bot id is resolved via
 * `ec_get_network_bot_user_id()` (extrachill-users), so the command is
 * config-driven rather than hardcoding 32.
 *
 * Idempotent: only posts where post_author equals --from (default 1) are
 * candidates. Once reattributed, they no longer match on a re-run.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackfillAuthorshipCommand {

	/**
	 * Default targets: blog_id => post_type.
	 *
	 * @var array<int, string>
	 */
	private const DEFAULT_TARGETS = array(
		7  => 'data_machine_events',
		11 => 'festival_wire',
	);

	/**
	 * Reattribute misauthored automation onto the network bot account.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Actually mutate post_author. Default is dry-run (no changes).
	 *
	 * [--commit]
	 * : Alias for --apply (matches the rest of the `wp extrachill events`
	 *   operator CLI surface).
	 *
	 * [--from=<user_id>]
	 * : Source author id to reattribute away from. Default: 1.
	 *
	 * [--blog=<id>]
	 * : Limit to a single target blog id (7 or 11). Default: both.
	 *
	 * [--status=<status>]
	 * : Comma-separated post statuses to reattribute (e.g. `publish` or
	 *   `publish,draft`). Default: publish,pending,draft,future,private (all
	 *   non-trash). Use `--status=publish` to match the issue's published-only
	 *   figure (~2979 events).
	 *
	 * [--format=<format>]
	 * : Output format for the per-target report.
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
	 *     # Preview (dry run)
	 *     wp extrachill events backfill-authorship
	 *
	 *     # Apply
	 *     wp extrachill events backfill-authorship --apply
	 *
	 *     # Events subsite only
	 *     wp extrachill events backfill-authorship --blog=7 --apply
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		$apply = ! empty( $assoc_args['apply'] ) || ! empty( $assoc_args['commit'] );
		$from  = isset( $assoc_args['from'] ) ? (int) $assoc_args['from'] : 1;
		$blog  = isset( $assoc_args['blog'] ) ? (int) $assoc_args['blog'] : 0;
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		// Post-status filter. Default: all non-trash statuses so every bit of
		// misauthored automation (publish + draft + pending) is corrected. Pass
		// --status=publish to scope to published posts only.
		$statuses = isset( $assoc_args['status'] )
			? array_filter( array_map( 'sanitize_key', explode( ',', (string) $assoc_args['status'] ) ) )
			: array( 'publish', 'pending', 'draft', 'future', 'private' );
		if ( empty( $statuses ) ) {
			$statuses = array( 'publish' );
		}

		if ( ! function_exists( 'ec_get_network_bot_user_id' ) ) {
			\WP_CLI::error( 'ec_get_network_bot_user_id() is unavailable — extrachill-users is not active.' );
			return;
		}

		$bot_id = (int) ec_get_network_bot_user_id();
		if ( $bot_id <= 0 ) {
			\WP_CLI::error( 'Network bot user id resolved to 0 — aborting.' );
			return;
		}

		if ( $from === $bot_id ) {
			\WP_CLI::error( sprintf( '--from (%d) equals the bot id (%d) — nothing to reattribute.', $from, $bot_id ) );
			return;
		}

		$targets = $blog > 0
			? array( $blog => self::DEFAULT_TARGETS[ $blog ] ?? null )
			: self::DEFAULT_TARGETS;

		$targets = array_filter( $targets, static fn( $t ) => null !== $t );

		if ( empty( $targets ) ) {
			\WP_CLI::error( 'No valid target blogs selected. Use --blog=7 or --blog=11, or omit for both.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Backfilling authorship: from uid %d → bot uid %d (%s)', $from, $bot_id, $apply ? 'APPLY' : 'DRY RUN' ) );
		\WP_CLI::log( '' );

		$report = array();
		$totals = array(
			'candidates'        => 0,
			'to_bot'            => 0,
			'to_submitter'      => 0,
			'skipped_bad_user'  => 0,
			'reattributed'      => 0,
			'failed'            => 0,
		);

		foreach ( $targets as $blog_id => $post_type ) {
			$this->processTarget( (int) $blog_id, (string) $post_type, $from, $bot_id, $apply, $format, $statuses, $report, $totals );
		}

		if ( ! empty( $report ) ) {
			\WP_CLI\Utils\format_items(
				$format,
				$report,
				array( 'blog', 'post_type', 'current_author', 'count', 'plan' )
			);
			\WP_CLI::log( '' );
		} else {
			\WP_CLI::log( 'Nothing to reattribute — no posts match the source author on any target.' );
			\WP_CLI::log( '' );
		}

		\WP_CLI::log(
			sprintf(
				'Summary: candidates=%d to_bot=%d to_submitter=%d skipped_bad_user=%d reattributed=%d failed=%d',
				$totals['candidates'],
				$totals['to_bot'],
				$totals['to_submitter'],
				$totals['skipped_bad_user'],
				$totals['reattributed'],
				$totals['failed']
			)
		);

		if ( ! $apply ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Dry run — no posts changed. Re-run with --apply (or --commit) to reattribute.' );
		}
	}

	/**
	 * Process a single blog/post_type target.
	 *
	 * Builds the per-author breakdown, the reattribution plan, and (when
	 * applying) executes the updates via switch_to_blog so $wpdb->prefix is
	 * correct for the target subsite.
	 *
	 * @param int    $blog_id    Target blog id.
	 * @param string $post_type  Target post type.
	 * @param int    $from       Source author id.
	 * @param int    $bot_id     Bot author id.
	 * @param bool   $apply      Whether to mutate.
	 * @param string $format     Output format.
	 * @param array  $statuses   Post statuses to target.
	 * @param array  $report     Report rows (appended).
	 * @param array  $totals     Totals (mutated).
	 */
	private function processTarget( int $blog_id, string $post_type, int $from, int $bot_id, bool $apply, string $format, array $statuses, array &$report, array &$totals ): void {
		switch_to_blog( $blog_id );

		global $wpdb;
		$posts_table = $wpdb->posts;

		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$status_args         = array_merge( array( $post_type ), $statuses );

		// Per-author breakdown of this post type (the "is authorship honest?"
		// snapshot the issue asks for).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$authors = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_author, COUNT(*) AS cnt FROM {$posts_table} WHERE post_type = %s AND post_status IN ({$status_placeholders}) GROUP BY post_author ORDER BY cnt DESC LIMIT 20",
				$status_args
			),
			ARRAY_A
		);

		\WP_CLI::log( sprintf( 'Blog %d (%s) — top authors:', $blog_id, $post_type ) );
		if ( ! empty( $authors ) ) {
			\WP_CLI\Utils\format_items(
				$format,
				array_map(
					static function ( $row ) {
						return array(
							'blog'          => '',
							'post_type'     => '',
							'current_author' => (string) $row['post_author'],
							'count'         => (string) $row['cnt'],
							'plan'          => '',
						);
					},
					$authors
				),
				array( 'current_author', 'count' )
			);
		}
		\WP_CLI::log( '' );

		// Candidate posts authored by --from.
		$candidate_args = array_merge( array( $post_type, $from ), $statuses );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$posts_table} WHERE post_type = %s AND post_author = %d AND post_status IN ({$status_placeholders}) ORDER BY ID ASC",
				$candidate_args
			)
		);

		$candidate_count = count( $candidates );
		$to_bot          = 0;
		$to_submitter    = 0;

		if ( 0 === $candidate_count ) {
			$report[] = $this->reportRow( $blog_id, $post_type, $from, 0, 'none' );
			restore_current_blog();
			return;
		}

		// Partition candidates: genuine submissions (carry
		// _datamachine_submitted_by) → attribute to the submitter; the rest
		// → bot. The submitter-attribution branch only applies to event CPT
		// (blog 7); the wire has no submission meta.
		foreach ( $candidates as $post_id ) {
			$post_id     = (int) $post_id;
			$submitter   = (int) get_post_meta( $post_id, '_datamachine_submitted_by', true );

			if ( $submitter > 0 && get_userdata( $submitter ) ) {
				++$to_submitter;
				if ( $apply ) {
					$this->reattribute( $post_id, $submitter, $totals, 'submitter' );
				}
				continue;
			}

			// Submitter meta present but stale (user deleted) → fall back to
			// the bot rather than leaving it on the human. Counted as to_bot.
			++$to_bot;
			if ( $apply ) {
				$this->reattribute( $post_id, $bot_id, $totals, 'bot' );
			}
		}

		$plan = sprintf( 'bot:%d submitter:%d', $to_bot, $to_submitter );

		$report[] = $this->reportRow( $blog_id, $post_type, $from, $candidate_count, $plan );

		$totals['candidates']   += $candidate_count;
		$totals['to_bot']       += $to_bot;
		$totals['to_submitter'] += $to_submitter;

		restore_current_blog();

		\WP_CLI::log( sprintf( '  → %d candidate(s): %s', $candidate_count, $apply ? 'processed' : 'planned (dry run)' ) );
		\WP_CLI::log( '' );
	}

	/**
	 * Reattribute a single post to a new author via the WP API so hooks fire.
	 *
	 * @param int    $post_id  Post id.
	 * @param int    $author   New author id.
	 * @param array  $totals   Totals (mutated).
	 * @param string $label    'bot' or 'submitter' — for the failure log.
	 */
	private function reattribute( int $post_id, int $author, array &$totals, string $label ): void {
		global $wpdb;
		// Direct UPDATE is intentional for a bulk backfill: wp_update_post()
		// would re-fire save_post / re-generate content hashes / re-sync tax
		// for thousands of rows. Author change has no downstream side effects
		// that the WP API would handle differently.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update(
			$wpdb->posts,
			array( 'post_author' => $author ),
			array( 'ID' => $post_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $ok ) {
			++$totals['failed'];
			\WP_CLI::warning( sprintf( 'Failed to reattribute post %d → %s (%d).', $post_id, $label, $author ) );
		} else {
			++$totals['reattributed'];
		}
	}

	/**
	 * Build a report row.
	 *
	 * @param int    $blog_id        Blog id.
	 * @param string $post_type      Post type.
	 * @param int    $current_author Source author.
	 * @param int    $count          Candidate count.
	 * @param string $plan           Plan label.
	 * @return array Row for format_items.
	 */
	private function reportRow( int $blog_id, string $post_type, int $current_author, int $count, string $plan ): array {
		return array(
			'blog'           => (string) $blog_id,
			'post_type'      => $post_type,
			'current_author' => (string) $current_author,
			'count'          => (string) $count,
			'plan'           => $plan,
		);
	}
}
