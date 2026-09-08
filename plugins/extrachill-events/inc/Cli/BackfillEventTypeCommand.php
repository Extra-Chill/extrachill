<?php
/**
 * Backfill event_type taxonomy terms across the event catalog.
 *
 * Command: `wp extrachill-events backfill-event-type`.
 *
 * data-machine-events#761 promoted the former `eventType` block attribute to
 * a real, closed-vocabulary taxonomy. This command backfills the existing
 * catalog (Extra-Chill/extrachill-events#802): ~121k published events carry
 * either a legacy Schema.org term, a legacy `eventType` block attribute
 * inside the `data-machine-events/event-details` block comment, or nothing.
 *
 * Resolution order per event:
 *   1. Already carries a vocabulary term  -> skipped ("already classified").
 *   2. Legacy `event_type` term, else the `eventType` block attribute:
 *      MusicEvent -> Concert, ComedyEvent -> Comedy, Festival -> Concert,
 *      DanceEvent -> Dance Party; TheaterEvent / SportsEvent /
 *      ExhibitionEvent / Event fall through to heuristics.
 *   3. Title keyword heuristics ({@see BackfillEventTypeCommand::classify_title()}).
 *   4. Default -> the vocabulary's declared default term (Concert).
 *
 * Writes are a direct `wp_set_object_terms()` term assignment — the AI and
 * upsert handler paths are deliberately bypassed. The vocabulary itself is
 * read live through `data_machine_events_event_type_vocabulary`, so this
 * command self-verifies against whatever vocabulary the site actually
 * resolves and aborts when the Extra Chill terms cannot be resolved.
 *
 * Legacy Schema.org terms that end up with zero assignments can be deleted
 * with `--prune-legacy` (only meaningful together with `--apply`); partial
 * runs leave still-assigned legacy terms in place and the prune skips them.
 *
 * Always invoke with `--url=events.extrachill.com`.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfills each event with exactly one event_type term.
 *
 * Dry-run by default; --apply commits. See the file docblock for the
 * resolution order and the legacy-term handling.
 */
class BackfillEventTypeCommand {

	/** Event taxonomy slug owned by data-machine-events. */
	public const TAXONOMY = 'event_type';

	/** Post type the taxonomy is registered for. */
	public const POST_TYPE = 'data_machine_events';

	/** Vocabulary term assigned when nothing else resolves. */
	private const DEFAULT_TERM = 'Concert';

	/**
	 * Legacy Schema.org terms seeded before the Extra Chill vocabulary
	 * replaced the default list. Events assigned to these are remapped;
	 * zero-assignment leftovers are pruned with `--prune-legacy`.
	 */
	private const LEGACY_TERM_SLUGS = array(
		'event',
		'musicevent',
		'festival',
		'comedyevent',
		'danceevent',
		'theaterevent',
		'sportsevent',
		'exhibitionevent',
	);

	/** Number of rows fetched per database batch. */
	private const BATCH_SIZE = 500;

	/**
	 * Backfill `event_type` terms across the event catalog.
	 *
	 * Dry-run by default; pass --apply to commit writes.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Commit term assignments (and prune with --prune-legacy). Default is dry-run.
	 *
	 * [--limit=<n>]
	 * : Process at most n events (after --offset). 0 means no limit.
	 *
	 * [--offset=<n>]
	 * : Skip the first n matching events before processing. Useful to resume
	 * a partial run; events skipped by the offset still count as unprocessed.
	 *
	 * [--post-status=<status>]
	 * : Post status to backfill.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--prune-legacy]
	 * : With --apply, delete legacy Schema.org `event_type` terms once they
	 * have zero assignments. Without --apply it is ignored (reported only).
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill-events backfill-event-type --url=events.extrachill.com
	 *     wp extrachill-events backfill-event-type --url=events.extrachill.com --limit=500
	 *     wp extrachill-events backfill-event-type --url=events.extrachill.com --apply --prune-legacy
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		if ( ! class_exists( '\DataMachineEvents\Core\Event_Type_Taxonomy' ) ) {
			\WP_CLI::error( 'data-machine-events is not active — the event_type vocabulary cannot be resolved. Run with --url=events.extrachill.com.' );
			return;
		}

		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			\WP_CLI::error( 'The ' . self::TAXONOMY . ' taxonomy is not registered on this site. Run with --url=events.extrachill.com.' );
			return;
		}

		$apply       = ! empty( $assoc_args['apply'] );
		$prune       = ! empty( $assoc_args['prune-legacy'] );
		$limit       = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
		$offset      = isset( $assoc_args['offset'] ) ? max( 0, (int) $assoc_args['offset'] ) : 0;
		$post_status = sanitize_key( (string) ( $assoc_args['post-status'] ?? 'publish' ) );

		if ( '' === $post_status || ! in_array( $post_status, get_post_stati(), true ) ) {
			\WP_CLI::error( sprintf( 'Unknown post status "%s".', $post_status ) );
			return;
		}

		$vocabulary = \DataMachineEvents\Core\Event_Type_Taxonomy::get_vocabulary();
		if ( empty( $vocabulary ) ) {
			\WP_CLI::error( 'The resolved event_type vocabulary is empty — refusing to run.' );
			return;
		}

		// Resolve each vocabulary term to its term ID without creating
		// anything. In dry-run a missing term is reported as "would be
		// created by vocabulary seeding"; in apply mode seeding runs first
		// and a still-missing term aborts the run before any write.
		$term_ids        = array();
		$missing_terms   = array();
		$existing_slugs  = array();
		$existing_lookup = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_array( $existing_lookup ) ) {
			foreach ( $existing_lookup as $existing_term ) {
				$existing_slugs[ $existing_term->slug ] = (int) $existing_term->term_id;
			}
		}

		if ( $apply ) {
			\DataMachineEvents\Core\Event_Type_Taxonomy::seed_vocabulary( $vocabulary );
		}

		foreach ( $vocabulary as $entry ) {
			if ( isset( $existing_slugs[ $entry['slug'] ] ) ) {
				$term_ids[ $entry['name'] ] = $existing_slugs[ $entry['slug'] ];
				continue;
			}

			if ( $apply ) {
				// resolve_term_id() matches the vocabulary entry and never
				// invents terms; seeding above guarantees existence.
				$term_id = \DataMachineEvents\Core\Event_Type_Taxonomy::resolve_term_id( $entry['name'] );
				if ( $term_id > 0 ) {
					$term_ids[ $entry['name'] ] = $term_id;
					continue;
				}
			}

			$missing_terms[] = $entry;
		}

		$default_term_name = '';
		foreach ( $vocabulary as $entry ) {
			if ( ! empty( $entry['default'] ) ) {
				$default_term_name = $entry['name'];
				break;
			}
		}

		if ( $apply ) {
			if ( ! empty( $missing_terms ) ) {
				\WP_CLI::error(
					'Could not resolve vocabulary term(s): '
					. implode( ', ', wp_list_pluck( $missing_terms, 'name' ) )
					. ' — the data_machine_events_event_type_vocabulary filter may not be active. Aborting before any write.'
				);
				return;
			}
			if ( ! isset( $term_ids[ $default_term_name ] ) ) {
				\WP_CLI::error( 'The vocabulary has no resolvable default term — refusing to run with --apply.' );
				return;
			}
		}

		// Legacy terms that may hold assignments today.
		$legacy_terms = array();
		foreach ( self::LEGACY_TERM_SLUGS as $legacy_slug ) {
			$legacy_term = get_term_by( 'slug', $legacy_slug, self::TAXONOMY );
			if ( $legacy_term instanceof \WP_Term ) {
				$legacy_terms[ $legacy_slug ] = array(
					'term_id'      => (int) $legacy_term->term_id,
					'name'         => $legacy_term->name,
					'count_before' => (int) $legacy_term->count,
					'reassigned'   => 0,
				);
			}
		}

		$total = $this->count_events( $post_status );

		\WP_CLI::log(
			sprintf(
				'%s event_type backfill — status=%s limit=%s offset=%d — %d matching event(s), %d vocabulary term(s).',
				$apply ? 'APPLY' : 'DRY RUN',
				$post_status,
				$limit > 0 ? (string) $limit : 'none',
				$offset,
				$total,
				count( $vocabulary )
			)
		);

		if ( ! empty( $missing_terms ) ) {
			\WP_CLI::log(
				'Note: vocabulary term(s) not yet in the taxonomy (created by seeding on --apply): '
				. implode( ', ', wp_list_pluck( $missing_terms, 'name' ) )
			);
		}

		$totals   = array(
			'scanned'          => 0,
			'already'          => 0,
			'legacy_term'      => 0,
			'legacy_attribute' => 0,
			'heuristic'        => 0,
			'default'          => 0,
			'would_write'      => 0,
			'written'          => 0,
			'errors'           => 0,
		);
		$per_term = array();
		foreach ( $vocabulary as $entry ) {
			$per_term[ $entry['name'] ] = 0;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning events', $total );

		global $wpdb;
		$last_id = 0;
		$skipped = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk catalog walk; per-row WP_Query would be orders of magnitude slower and the CLI runs once.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts is a trusted core table property; every variable value goes through %s/%d placeholders.
					'SELECT ID, post_title, post_content FROM ' . $wpdb->posts . ' WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d',
					self::POST_TYPE,
					$post_status,
					$last_id,
					self::BATCH_SIZE
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$post_id = (int) $row['ID'];
				$last_id = $post_id;

				if ( $offset > 0 && $skipped < $offset ) {
					++$skipped;
					continue;
				}

				if ( $limit > 0 && $totals['scanned'] >= $limit ) {
					break 2;
				}

				++$totals['scanned'];
				if ( $progress ) {
					$progress->tick();
				}

				$assigned_slugs = wp_get_object_terms( $post_id, self::TAXONOMY, array( 'fields' => 'slugs' ) );
				if ( is_wp_error( $assigned_slugs ) ) {
					++$totals['errors'];
					continue;
				}
				$assigned_slugs = (array) $assigned_slugs;

				if ( array_intersect( $assigned_slugs, wp_list_pluck( $vocabulary, 'slug' ) ) ) {
					++$totals['already'];
					continue;
				}

				$legacy_slug = '';
				foreach ( $assigned_slugs as $assigned_slug ) {
					if ( in_array( $assigned_slug, self::LEGACY_TERM_SLUGS, true ) ) {
						$legacy_slug = $assigned_slug;
						break;
					}
				}
				$legacy_attr = $this->extract_block_event_type( (string) $row['post_content'] );

				$name = self::classify( (string) $row['post_title'], $legacy_slug, $legacy_attr );

				// Attribute the resolution source for the report.
				if ( '' !== $legacy_slug && '' !== self::resolve_legacy( $legacy_slug ) ) {
					$source = 'legacy_term';
					if ( isset( $legacy_terms[ $legacy_slug ] ) ) {
						++$legacy_terms[ $legacy_slug ]['reassigned'];
					}
				} elseif ( '' !== self::resolve_legacy( $legacy_attr ) ) {
					$source = 'legacy_attribute';
				} elseif ( '' !== self::classify_title( (string) $row['post_title'] ) ) {
					$source = 'heuristic';
				} else {
					$source = 'default';
				}

				++$totals[ $source ];
				if ( isset( $per_term[ $name ] ) ) {
					++$per_term[ $name ];
				}

				if ( ! $apply ) {
					++$totals['would_write'];
					continue;
				}

				if ( ! isset( $term_ids[ $name ] ) ) {
					++$totals['errors'];
					continue;
				}

				$result = wp_set_object_terms( $post_id, array( $term_ids[ $name ] ), self::TAXONOMY, false );
				if ( is_wp_error( $result ) ) {
					++$totals['errors'];
					\WP_CLI::warning(
						sprintf( 'Post %d: failed to set term "%s": %s', $post_id, $name, $result->get_error_message() )
					);
					continue;
				}

				++$totals['written'];
			}

			if ( count( $rows ) < self::BATCH_SIZE ) {
				break;
			}
		}

		if ( $progress ) {
			$progress->finish();
		}

		if ( $apply ) {
			// One flush after the run instead of per-row cache churn.
			wp_cache_flush();
		}

		$this->print_report(
			$apply,
			$prune,
			$totals,
			$per_term,
			$default_term_name,
			$legacy_terms
		);

		if ( $apply && $prune ) {
			$this->prune_legacy_terms( $legacy_terms );
		}
	}

	/**
	 * Classify an event into a vocabulary term name.
	 *
	 * Pure static method (no WordPress dependencies) so the heuristic rules
	 * are unit-testable in isolation. Resolution order:
	 *   1. $legacy_type (canonical: the assigned legacy term slug).
	 *   2. $secondary_legacy_type (derived: the eventType block attribute).
	 *   3. Title keyword heuristics.
	 *   4. The default term.
	 *
	 * @param string $title                 Event title.
	 * @param string $legacy_type           Legacy Schema.org type or term slug ('', when none).
	 * @param string $secondary_legacy_type Fallback legacy value used when $legacy_type falls through.
	 * @return string Vocabulary term name (e.g. 'Concert', 'Karaoke', 'Trivia & Games').
	 */
	public static function classify( string $title, string $legacy_type = '', string $secondary_legacy_type = '' ): string {
		foreach ( array( $legacy_type, $secondary_legacy_type ) as $legacy ) {
			$resolved = self::resolve_legacy( $legacy );
			if ( '' !== $resolved ) {
				return $resolved;
			}
		}

		$matched = self::classify_title( $title );

		return '' !== $matched ? $matched : self::DEFAULT_TERM;
	}

	/**
	 * Map a legacy Schema.org value to a vocabulary term name.
	 *
	 * Accepts term slugs ('musicevent'), Schema.org names ('MusicEvent'),
	 * and humanized variants ('Music Event') — comparison strips everything
	 * but letters and lowercases. Types with no meaningful editorial mapping
	 * (TheaterEvent, SportsEvent, ExhibitionEvent, the generic Event) return
	 * '' so the caller falls through to title heuristics.
	 *
	 * @param string $legacy Legacy value.
	 * @return string Vocabulary term name, or '' to fall through.
	 */
	public static function resolve_legacy( string $legacy ): string {
		$normalized = strtolower( preg_replace( '/[^a-z]/i', '', $legacy ) );

		switch ( $normalized ) {
			case 'musicevent':
			case 'festival':
				return 'Concert';
			case 'comedyevent':
				return 'Comedy';
			case 'danceevent':
				return 'Dance Party';
			default:
				return '';
		}
	}

	/**
	 * Match an event title against the keyword heuristics.
	 *
	 * Pure static method. Rules run in priority order — a title matching
	 * several rules lands in the earliest bucket — and matching is
	 * case-insensitive with word boundaries where substrings would
	 * otherwise overreach (dj, rave, film, drag, market).
	 *
	 * @param string $title Event title.
	 * @return string Vocabulary term name, or '' when no rule matches.
	 */
	public static function classify_title( string $title ): string {
		$title = strtolower( $title );

		if ( false !== strpos( $title, 'karaoke' ) ) {
			return 'Karaoke';
		}

		if ( preg_match( '/trivia|bingo/', $title ) ) {
			return 'Trivia & Games';
		}

		if ( preg_match( '/open[ -]mic|jam\s+(?:session|night)/', $title ) ) {
			return 'Open Mic';
		}

		if ( preg_match( '/dance\s+party/', $title ) ) {
			return 'Dance Party';
		}

		if ( preg_match( '/\bdj\b|\brave\b|club\s+night/', $title ) ) {
			return 'DJ Set';
		}

		if ( preg_match( '/comedy|comedian|stand[ -]?up/', $title ) ) {
			return 'Comedy';
		}

		if ( preg_match( '/brunch|theater|theatre|\bfilm\b|screening|\bdrag\b|\bmarkets?\b|workshop/', $title ) ) {
			return 'Other';
		}

		return '';
	}

	/**
	 * Extract the legacy `eventType` attribute from the event-details block.
	 *
	 * The attribute lives in the JSON of the
	 * `<!-- wp:data-machine-events/event-details {...} -->` comment. The
	 * attribute JSON only ever carries Schema.org type names (a closed
	 * value set), so extracting and json_decoding the block comment is
	 * reliable without a full parse_blocks() walk.
	 *
	 * @param string $post_content Raw post content.
	 * @return string Attribute value, or '' when absent/unparseable.
	 */
	private function extract_block_event_type( string $post_content ): string {
		if ( '' === $post_content || false === strpos( $post_content, 'eventType' ) ) {
			return '';
		}

		if ( ! preg_match( '/<!--\s+wp:data-machine-events\/event-details\s+(\{.*?\})\s*-->/', $post_content, $matches ) ) {
			return '';
		}

		$attributes = json_decode( $matches[1], true );
		if ( ! is_array( $attributes ) || ! isset( $attributes['eventType'] ) || ! is_string( $attributes['eventType'] ) ) {
			return '';
		}

		return trim( $attributes['eventType'] );
	}

	/**
	 * Count events matching the backfill query.
	 *
	 * @param string $post_status Post status.
	 * @return int
	 */
	private function count_events( string $post_status ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot CLI count used for the progress bar.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(ID) FROM ' . $wpdb->posts . ' WHERE post_type = %s AND post_status = %s',
				self::POST_TYPE,
				$post_status
			)
		);
	}

	/**
	 * Print the run report: source counts, per-term counts, legacy terms.
	 *
	 * @param bool   $apply             Whether this was an apply run.
	 * @param bool   $prune             Whether --prune-legacy was requested.
	 * @param array  $totals            Per-source counters.
	 * @param array  $per_term          Assignment counts keyed by term name.
	 * @param string $default_term_name The vocabulary's default term name.
	 * @param array  $legacy_terms      Legacy term bookkeeping.
	 */
	private function print_report( bool $apply, bool $prune, array $totals, array $per_term, string $default_term_name, array $legacy_terms ): void {
		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( '=== Event type backfill report (%s) ===', $apply ? 'APPLY' : 'DRY RUN' ) );
		\WP_CLI::log(
			sprintf(
				'Scanned: %1$d | already classified: %2$d | legacy term: %3$d | legacy attribute: %4$d | heuristic: %5$d | default (%6$s): %7$d | %8$s: %9$d | errors: %10$d',
				$totals['scanned'],
				$totals['already'],
				$totals['legacy_term'],
				$totals['legacy_attribute'],
				$totals['heuristic'],
				$default_term_name,
				$per_term[ $default_term_name ] ?? 0,
				$apply ? 'written' : 'would write',
				$apply ? $totals['written'] : $totals['would_write'],
				$totals['errors']
			)
		);

		$term_rows = array();
		foreach ( $per_term as $name => $count ) {
			$term_rows[] = array(
				'term'    => $name,
				'count'   => $count,
				'default' => $name === $default_term_name ? 'yes' : '',
			);
		}
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Per-term assignment counts:' );
		\WP_CLI\Utils\format_items( 'table', $term_rows, array( 'term', 'count', 'default' ) );

		if ( empty( $legacy_terms ) ) {
			\WP_CLI::log( 'No legacy Schema.org event_type terms present — nothing to remap or prune.' );
			return;
		}

		$legacy_rows = array();
		foreach ( $legacy_terms as $slug => $info ) {
			$remaining = max( 0, $info['count_before'] - $info['reassigned'] );
			if ( $apply ) {
				$fresh = get_term( $info['term_id'], self::TAXONOMY );
				if ( $fresh instanceof \WP_Term ) {
					$remaining = (int) $fresh->count;
				}
			}

			$action = 'kept';
			if ( 0 === $remaining ) {
				$action = ( $apply && $prune )
					? 'pruned below'
					: ( $apply ? 'run again with --prune-legacy to delete' : 'would be prunable with --apply --prune-legacy' );
			}

			$legacy_rows[] = array(
				'legacy_term' => $slug,
				'before'      => $info['count_before'],
				'remapped'    => $info['reassigned'],
				'remaining'   => $remaining,
				'action'      => $action,
			);
		}
		\WP_CLI::log( 'Legacy Schema.org terms:' );
		\WP_CLI\Utils\format_items( 'table', $legacy_rows, array( 'legacy_term', 'before', 'remapped', 'remaining', 'action' ) );
	}

	/**
	 * Delete legacy event_type terms that have zero assignments left.
	 *
	 * Only runs with --apply --prune-legacy. Terms still assigned (a partial
	 * backfill, or assignments outside the processed post status) are kept
	 * and reported.
	 *
	 * @param array $legacy_terms Legacy term bookkeeping.
	 */
	private function prune_legacy_terms( array $legacy_terms ): void {
		\WP_CLI::log( '' );

		foreach ( $legacy_terms as $slug => $info ) {
			$fresh = get_term( $info['term_id'], self::TAXONOMY );
			if ( ! $fresh instanceof \WP_Term ) {
				\WP_CLI::log( sprintf( 'Legacy term "%s" already gone.', $slug ) );
				continue;
			}

			if ( (int) $fresh->count > 0 ) {
				\WP_CLI::log( sprintf( 'Keeping legacy term "%s" — still assigned to %d event(s).', $slug, (int) $fresh->count ) );
				continue;
			}

			$deleted = wp_delete_term( $info['term_id'], self::TAXONOMY );
			if ( is_wp_error( $deleted ) ) {
				\WP_CLI::warning( sprintf( 'Failed to delete legacy term "%s": %s', $slug, $deleted->get_error_message() ) );
				continue;
			}

			\WP_CLI::log( sprintf( 'Deleted legacy term "%s" (term %d) — zero assignments remain.', $slug, $info['term_id'] ) );
		}
	}
}
