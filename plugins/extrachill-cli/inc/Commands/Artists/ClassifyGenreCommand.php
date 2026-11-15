<?php
/**
 * Classify genres onto unclaimed events-site artist terms (`_genres` termmeta).
 *
 * Command: `wp extrachill artists classify-genre`.
 *
 * Implements Extra-Chill/extrachill-events#830 (step 4 of #30). Genre is an
 * artist fact: this command proposes a genre set ONCE PER ARTIST — never per
 * event — for unclaimed events-site `artist` terms, and writes the same
 * `_genres` termmeta contract the genre projection (#828/#829) reads. The
 * write fires the projection's `updated_term_meta` fan-out, which syncs the
 * artist's events; this command never touches events itself and never writes
 * to main-site terms (those are owned by the claimed-profile mirror).
 *
 * Pipeline:
 *  1. Deterministic pass (zero cost, {@see \ExtraChillEvents\Core\ArtistGenreClassifier::deterministic()}):
 *     the artist name and performer attributes resolve through the network
 *     genre resolver (`extrachill_network_resolve_genres()`), and a co-billing
 *     prior fires when >= 3 co-billed artists carry genres that agree.
 *  2. Constrained AI pass for the remainder (skipped with --no-ai), modeled on
 *     extrachill-network's term-classification task: the prompt lists ONLY the
 *     active vocabulary names, requires JSON
 *     `{"<term_id>": {"genres": ["Name", ...max 3], "confidence": 0.0-1.0}}`,
 *     and every returned name is re-resolved through the network resolver —
 *     anything that does not resolve is dropped, so AI output structurally
 *     cannot introduce a non-vocabulary genre.
 *
 * Writes happen only with --apply, at confidence >= --min-confidence, and
 * only for artists with no existing `_genres` unless --overwrite:
 * `_genres` (JSON slug array), `_genres_source` (deterministic|ai), and
 * `_genres_confidence`. Dry-run is the default and runs the deterministic
 * pass for real; the AI pass may run read-only in dry-run (results printed,
 * nothing written) — pass --no-ai for a zero-cost structural run.
 *
 * The network genre vocabulary is NOT a hard runtime dependency of the reads:
 * proposals need it, so the command aborts with a clear error when
 * `extrachill_network_get_genre_vocabulary()` / `extrachill_network_resolve_genres()`
 * are unavailable. On a site where extrachill-network predates the genre
 * module, load the vocabulary through a read-only `wp eval-file` harness that
 * require_once's the network checkout's inc/taxonomy/genre.php before
 * invoking this command class.
 *
 * Always invoke with `--url=events.extrachill.com`.
 *
 * Thin adapter over ArtistGenreClassifier (extrachill-events).
 *
 * @package ExtraChill\CLI\Commands\Artists
 */

namespace ExtraChill\CLI\Commands\Artists;

use ExtraChill\CLI\OwnerSiteAbility;
use ExtraChillEvents\Core\ArtistGenreClassifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proposes `_genres` for unclaimed events-site artist terms.
 *
 * Dry-run by default; --apply commits the termmeta writes. See the file
 * docblock for the pipeline and the write semantics.
 */
class ClassifyGenreCommand {

	/** Review/proposal display cap for the default table output. */
	private const DISPLAY_CAP = 30;

	/**
	 * Classify genres for unclaimed artist terms.
	 *
	 * Dry-run by default; pass --apply to commit termmeta writes.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Commit proposals at or above --min-confidence. Default is dry-run.
	 *
	 * [--min-confidence=<0-1>]
	 * : Minimum confidence to write. Proposals below the threshold go to the
	 *   review list and are never written.
	 * ---
	 * default: 0.7
	 * ---
	 *
	 * [--min-events=<n>]
	 * : Artist terms with fewer events are never classified (term count
	 *   filter; an explicit --artist bypasses it).
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--limit=<n>]
	 * : Consider at most n artists, ordered by event count descending.
	 *   0 means no limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--offset=<n>]
	 * : Skip the first n artists (by the same ordering) before --limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--artist=<term_id|slug>]
	 * : Run for one artist term only (bypasses --min-events; still respects
	 *   the --overwrite rule).
	 *
	 * [--overwrite]
	 * : Allow replacing an existing `_genres` value. Default off — the
	 *   command runs once per artist; claimed-profile mirrors and prior
	 *   runs always win.
	 *
	 * [--ai]
	 * : Run the constrained AI pass for artists the deterministic pass could
	 *   not resolve. On by default. Declared so WP-CLI accepts `--no-ai`:
	 *   it resolves a negation to `ai => false` and rejects it outright
	 *   unless the positive flag exists, so declaring only `[--no-ai]`
	 *   made the zero-cost run fail with "unknown --ai parameter".
	 *
	 * [--no-ai]
	 * : Skip the AI pass entirely (deterministic proposals only; zero AI
	 *   cost). Remaining artists land on the review list.
	 *
	 * [--batch-size=<n>]
	 * : Artists per AI request.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Format for the review list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill artists classify-genre --url=events.extrachill.com --limit=100 --no-ai
	 *     wp extrachill artists classify-genre --url=events.extrachill.com --limit=100 --batch-size=20
	 *     wp extrachill artists classify-genre --url=events.extrachill.com --artist=123
	 *     wp extrachill artists classify-genre --url=events.extrachill.com --apply --limit=500
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		OwnerSiteAbility::require_service( 'events', 'Events', ArtistGenreClassifier::class );

		if ( ! ArtistGenreClassifier::vocabulary_available() ) {
			\WP_CLI::error(
				'The genre vocabulary resolver (extrachill-network genre module) is not available on this site. '
				. 'Upgrade extrachill-network, or load inc/taxonomy/genre.php from the extrachill-network checkout '
				. 'through a read-only `wp eval-file` harness before invoking this command.'
			);
			return;
		}

		$apply          = ! empty( $assoc_args['apply'] );
		$overwrite      = ! empty( $assoc_args['overwrite'] );
		$no_ai          = ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'ai', true );
		$min_confidence = isset( $assoc_args['min-confidence'] ) ? (float) $assoc_args['min-confidence'] : 0.7;
		$min_events     = isset( $assoc_args['min-events'] ) ? (int) $assoc_args['min-events'] : 5;
		$limit          = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
		$offset         = isset( $assoc_args['offset'] ) ? max( 0, (int) $assoc_args['offset'] ) : 0;
		$batch_size     = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 20;
		$format         = (string) ( $assoc_args['format'] ?? 'table' );
		$artist_arg     = isset( $assoc_args['artist'] ) ? (string) $assoc_args['artist'] : '';

		if ( $min_confidence < 0 || $min_confidence > 1 ) {
			\WP_CLI::error( '--min-confidence must be between 0 and 1.' );
			return;
		}

		if ( $min_events < 1 ) {
			\WP_CLI::error( '--min-events must be at least 1.' );
			return;
		}

		if ( ! in_array( $format, array( 'table', 'csv', 'json' ), true ) ) {
			\WP_CLI::error( 'Unknown --format. Use table, csv, or json.' );
			return;
		}

		$vocabulary = extrachill_network_get_genre_vocabulary();
		if ( empty( $vocabulary ) ) {
			\WP_CLI::error( 'The active genre vocabulary is empty — nothing to classify against.' );
			return;
		}

		// Select the artists in scope.
		$classifier = new ArtistGenreClassifier();
		$candidates = $classifier->scope( $artist_arg, $min_events, $limit, $offset, $overwrite );
		if ( is_wp_error( $candidates ) ) {
			\WP_CLI::error( $candidates->get_error_message() );
			return;
		}

		if ( empty( $candidates ) ) {
			\WP_CLI::log( 'No artist terms in scope — nothing to classify.' );
			return;
		}

		\WP_CLI::log(
			sprintf(
				'%s artist genre classification — artists=%d min-confidence=%.2f min-events=%d batch-size=%d ai=%s%s',
				$apply ? 'APPLY' : 'DRY RUN',
				count( $candidates ),
				$min_confidence,
				$min_events,
				$batch_size,
				$no_ai ? 'off' : 'on',
				$overwrite ? ' overwrite=yes' : ''
			)
		);

		// Deterministic pass first (zero cost), then the constrained AI pass
		// for the deterministic leftovers.
		$run = $classifier->classify( $candidates, $vocabulary, ! $no_ai, $batch_size );
		foreach ( $classifier->warnings() as $warning ) {
			\WP_CLI::warning( $warning );
		}

		// Report before any writes.
		self::print_report( $apply, $run['proposals'], $run['det_count'], $run['ai_counts'], $min_confidence, $format, $vocabulary );

		if ( ! $apply ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Dry run — nothing was written. Re-run with --apply to commit proposals at or above the confidence threshold.' );
			return;
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Applying proposals:' );

		$applied = $classifier->apply_proposals( $run['proposals'], $min_confidence, $overwrite, $vocabulary );
		foreach ( $applied['messages'] as $message ) {
			if ( 'warning' === $message['level'] ) {
				\WP_CLI::warning( $message['message'] );
				continue;
			}
			\WP_CLI::log( $message['message'] );
		}

		\WP_CLI::log( '' );
		\WP_CLI::log(
			sprintf(
				'Apply complete — written: %d | skipped: %d | left for review: %d | errors: %d',
				$applied['written'],
				$applied['skipped'],
				$applied['review'],
				$applied['errors']
			)
		);
		\WP_CLI::log( 'The _genres writes fire the genre projection fan-out (#828/#829); events were not touched directly.' );
	}

	/**
	 * Print the run report.
	 *
	 * @param bool                 $apply          Whether this is an apply run.
	 * @param array<int,array>     $proposals      term_id => proposal.
	 * @param int                  $det_count      Deterministic proposals.
	 * @param array<string,int>    $ai_counts      AI counters.
	 * @param float                $min_confidence Threshold.
	 * @param string               $format         table|csv|json (review list).
	 * @param array<string,string> $vocabulary     Active vocabulary slug => name.
	 */
	private static function print_report( bool $apply, array $proposals, int $det_count, array $ai_counts, float $min_confidence, string $format, array $vocabulary ): void {
		$tally     = ArtistGenreClassifier::tally( $proposals, $min_confidence );
		$review    = $tally['review'];
		$histogram = $tally['histogram'];
		$per_genre = $tally['per_genre'];

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( '=== Artist genre classification report (%s) ===', $apply ? 'APPLY' : 'DRY RUN' ) );

		\WP_CLI::log(
			sprintf(
				'Scanned: %1$d | deterministic: %2$d | ai: %3$d | below threshold or empty (review): %4$d | AI requests: %5$d | AI errors: %6$d%7$s',
				count( $proposals ),
				$det_count,
				(int) $ai_counts['proposals'],
				$review,
				(int) $ai_counts['requests'],
				(int) $ai_counts['errors'],
				(int) $ai_counts['total'] > 0
					? sprintf( ' | AI tokens (prompt/completion/total): %d/%d/%d', $ai_counts['prompt'], $ai_counts['completion'], $ai_counts['total'] )
					: ''
			)
		);

		$genre_rows = array();
		foreach ( $vocabulary as $slug => $name ) {
			$genre_rows[] = array(
				'genre'        => sprintf( '%s (%s)', $slug, $name ),
				'artist_count' => (int) ( $per_genre[ $slug ] ?? 0 ),
			);
		}
		$extra = array_diff( array_keys( $per_genre ), array_keys( $vocabulary ) );
		foreach ( $extra as $slug ) {
			$genre_rows[] = array(
				'genre'        => sprintf( '%s (OUT OF VOCABULARY)', $slug ),
				'artist_count' => (int) $per_genre[ $slug ],
			);
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Per-genre artist proposal counts:' );
		\WP_CLI\Utils\format_items( 'table', $genre_rows, array( 'genre', 'artist_count' ) );

		$hist_rows = array();
		foreach ( $histogram as $bucket => $count ) {
			$hist_rows[] = array(
				'confidence' => $bucket,
				'artists'    => $count,
			);
		}
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Confidence histogram (all proposals, including empty):' );
		\WP_CLI\Utils\format_items( 'table', $hist_rows, array( 'confidence', 'artists' ) );

		// Top proposals (display cap; the review list below carries --format).
		$with_proposal = array_filter(
			$proposals,
			static fn( array $proposal ): bool => '' !== $proposal['source']
		);
		$top           = array_slice( $with_proposal, 0, self::DISPLAY_CAP, true );

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( 'Proposals (first %d of %d with any proposal):', count( $top ), count( $with_proposal ) ) );

		$top_rows = array();
		foreach ( $top as $proposal ) {
			$top_rows[] = self::proposal_row( $proposal );
		}
		\WP_CLI\Utils\format_items( 'table', $top_rows, array( 'term_id', 'artist', 'events', 'source', 'proposed', 'confidence' ) );

		self::print_review_list( $proposals, $min_confidence, $format );
	}

	/**
	 * One table row for a proposal.
	 *
	 * @param array $proposal Proposal.
	 * @return array<string,string|int>
	 */
	private static function proposal_row( array $proposal ): array {
		return array(
			'term_id'    => (int) $proposal['term_id'],
			'artist'     => (string) $proposal['name'],
			'events'     => (int) $proposal['events'],
			'source'     => '' !== $proposal['source'] ? $proposal['source'] : '(none)',
			'proposed'   => '' !== $proposal['source'] ? implode( ', ', $proposal['genres'] ) : '(none)',
			'confidence' => sprintf( '%.2f', (float) $proposal['confidence'] ),
		);
	}

	/**
	 * Print the review list: artists without a proposal or below threshold.
	 *
	 * Display is capped at {@see ClassifyGenreCommand::DISPLAY_CAP} rows for
	 * the table format; use --format=csv|json for the complete list.
	 *
	 * @param array<int,array> $proposals      term_id => proposal.
	 * @param float            $min_confidence Threshold.
	 * @param string           $format         table|csv|json.
	 */
	private static function print_review_list( array $proposals, float $min_confidence, string $format ): void {
		$rows = array();

		foreach ( $proposals as $proposal ) {
			if ( '' !== $proposal['source'] && $proposal['confidence'] >= $min_confidence ) {
				continue;
			}

			$row              = self::proposal_row( $proposal );
			$row['top_title'] = (string) ( $proposal['titles'][0] ?? '' );
			$rows[]           = $row;
		}

		\WP_CLI::log( '' );

		if ( empty( $rows ) ) {
			\WP_CLI::log( 'Review list: empty — every artist has a proposal at or above the confidence threshold.' );
			return;
		}

		$columns = array( 'term_id', 'artist', 'events', 'proposed', 'confidence', 'top_title' );
		$capped  = 'table' === $format ? array_slice( $rows, 0, self::DISPLAY_CAP ) : $rows;
		$note    = 'table' === $format && count( $rows ) > count( $capped )
			? sprintf( ' (showing first %d of %d — use --format=csv|json for the complete list)', count( $capped ), count( $rows ) )
			: '';

		\WP_CLI::log( sprintf( 'Review list (%d artist(s) below the confidence threshold or without a proposal)%s:', count( $rows ), $note ) );
		\WP_CLI\Utils\format_items( $format, $capped, $columns );
	}
}
