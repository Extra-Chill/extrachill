<?php
/**
 * Classify genres onto unclaimed events-site artist terms (`_genres` termmeta).
 *
 * Implements Extra-Chill/extrachill-events#830 (step 4 of #30). Genre is an
 * artist fact: this classifier proposes a genre set ONCE PER ARTIST — never
 * per event — for unclaimed events-site `artist` terms, and writes the same
 * `_genres` termmeta contract the genre projection (#828/#829) reads. The
 * write fires the projection's `updated_term_meta` fan-out, which syncs the
 * artist's events; this classifier never touches events itself and never
 * writes to main-site terms (those are owned by the claimed-profile mirror).
 *
 * Pipeline:
 *  1. Deterministic pass (zero cost, {@see ArtistGenreClassifier::deterministic()}):
 *     the artist name and performer attributes resolve through the network
 *     genre resolver (`extrachill_network_resolve_genres()`), and a co-billing
 *     prior fires when >= 3 co-billed artists carry genres that agree.
 *  2. Constrained AI pass for the remainder (optional), modeled on
 *     extrachill-network's term-classification task: the prompt lists ONLY the
 *     active vocabulary names, requires JSON
 *     `{"<term_id>": {"genres": ["Name", ...max 3], "confidence": 0.0-1.0}}`,
 *     and every returned name is re-resolved through the network resolver —
 *     anything that does not resolve is dropped, so AI output structurally
 *     cannot introduce a non-vocabulary genre.
 *
 * Writes ({@see ArtistGenreClassifier::apply_proposals()}) happen only at
 * confidence >= the threshold, and only for artists with no existing
 * `_genres` unless overwriting: `_genres` (JSON slug array), `_genres_source`
 * (deterministic|ai), and `_genres_confidence`.
 *
 * Proposals need the network genre vocabulary
 * (`extrachill_network_get_genre_vocabulary()` /
 * `extrachill_network_resolve_genres()`); see
 * {@see ArtistGenreClassifier::vocabulary_available()}.
 *
 * Consumed by the `wp extrachill artists classify-genre` adapter in
 * extrachill-cli, which owns argument parsing and report formatting.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proposes and writes `_genres` for unclaimed events-site artist terms.
 * See the file docblock for the pipeline and the write semantics.
 */
class ArtistGenreClassifier {

	/** Term meta holding the JSON genre slug array (the #828/#829 contract). */
	public const GENRES_META_KEY = '_genres';

	/** Term meta holding the proposal source: deterministic|ai. */
	public const SOURCE_META_KEY = '_genres_source';

	/** Term meta holding the proposal confidence (0-1 float). */
	public const CONFIDENCE_META_KEY = '_genres_confidence';

	/** Event post type owned by data-machine-events. */
	public const POST_TYPE = 'data_machine_events';

	/** Taxonomy classified by this command. */
	public const TAXONOMY = 'artist';

	/** Distinct event titles aggregated per artist for the signals. */
	private const MAX_TITLES = 15;

	/** Distinct performer attributes aggregated per artist. */
	private const MAX_PERFORMER_TEXTS = 5;

	/** Distinct venues (with tiers) shown per artist in the AI prompt. */
	private const MAX_VENUES = 8;

	/** Co-billed pairs below this many shared events are noise and ignored. */
	private const MIN_SHARED_EVENTS = 2;

	/** External link hosts surfaced as text hints to the AI prompt. */
	private const EXTERNAL_LINK_PATTERN = 'spotify|bandcamp|soundcloud|music\\.apple|deezer';

	/** Confidence histogram buckets, label => [inclusive_min, exclusive_max]. */
	private const HISTOGRAM_BUCKETS = array(
		'0.0-0.5' => array( 0.0, 0.5 ),
		'0.5-0.6' => array( 0.5, 0.6 ),
		'0.6-0.7' => array( 0.6, 0.7 ),
		'0.7-0.8' => array( 0.7, 0.8 ),
		'0.8-0.9' => array( 0.8, 0.9 ),
		'0.9-1.0' => array( 0.9, 1.01 ),
	);

	/** Genre slugs accepted per artist (the network cap). */
	private const GENRE_CAP = 3;

	/** Generic band nouns ignored by the deterministic name/description scan. */
	private const SCAN_STOP_WORDS = array(
		'the',
		'a',
		'an',
		'and',
		'of',
		'band',
		'trio',
		'duo',
		'quartet',
		'quintet',
		'group',
		'orchestra',
		'collective',
		'project',
		'live',
		'music',
		'dj',
		'duo',
		'ft',
		'feat',
	);

	/**
	 * Non-fatal degradations recorded by the last run_ai_pass() call.
	 *
	 * @var string[]
	 */
	private array $warnings = array();

	/**
	 * Non-fatal degradations recorded by the last run_ai_pass() call.
	 *
	 * @return string[]
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Whether the network genre vocabulary resolver is loaded on this site.
	 *
	 * @return bool
	 */
	public static function vocabulary_available(): bool {
		return function_exists( 'extrachill_network_get_genre_vocabulary' ) && function_exists( 'extrachill_network_resolve_genres' );
	}

	/**
	 * Select the artist terms in scope.
	 *
	 * An explicit artist bypasses $min_events but still respects $overwrite.
	 *
	 * @param string $artist     Artist term ID or slug ('' = catalog scope).
	 * @param int    $min_events Minimum term count (catalog scope only).
	 * @param int    $limit      Max artists (0 = no limit).
	 * @param int    $offset     Artists to skip.
	 * @param bool   $overwrite  Include artists that already have `_genres`.
	 * @return array<int,array{term_id:int,name:string,count:int}>|\WP_Error
	 */
	public function scope( string $artist, int $min_events, int $limit, int $offset, bool $overwrite ) {
		if ( '' === $artist ) {
			return $this->select_candidates( $min_events, $limit, $offset, $overwrite );
		}

		$term_id = $this->resolve_artist_term( $artist );
		if ( $term_id <= 0 ) {
			return new \WP_Error( 'artist_not_found', sprintf( 'Artist term "%s" not found on this site.', $artist ) );
		}

		$existing = get_term_meta( $term_id, self::GENRES_META_KEY, true );
		if ( '' !== (string) $existing && ! $overwrite ) {
			return new \WP_Error(
				'artist_already_classified',
				sprintf(
					'Artist term %d already has %s. Re-run with --overwrite to replace it.',
					$term_id,
					self::GENRES_META_KEY
				)
			);
		}

		$term_name = get_term_field( 'name', $term_id, self::TAXONOMY );
		if ( is_wp_error( $term_name ) || null === $term_name ) {
			$term_name = '';
		}

		$term_count = get_term_field( 'count', $term_id, self::TAXONOMY );
		if ( is_wp_error( $term_count ) || null === $term_count ) {
			$term_count = 0;
		}

		return array(
			array(
				'term_id' => $term_id,
				'name'    => (string) $term_name,
				'count'   => (int) $term_count,
			),
		);
	}

	/**
	 * Propose genres for every candidate: deterministic pass, then the
	 * constrained AI pass for the leftovers.
	 *
	 * @param array<int,array{term_id:int,name:string,count:int}> $candidates Rows from scope().
	 * @param array<string,string>                                $vocabulary Active vocabulary slug => name.
	 * @param bool                                                $use_ai     Run the AI pass.
	 * @param int                                                 $batch_size Artists per AI request.
	 * @return array{proposals:array<int,array>,det_count:int,ai_counts:array<string,int>}
	 */
	public function classify( array $candidates, array $vocabulary, bool $use_ai, int $batch_size ): array {
		$this->warnings = array();

		// Aggregate signals for every candidate with set-based queries.
		$signals = $this->collect_signals( $candidates );

		// Deterministic pass first (zero cost).
		$proposals    = array();
		$needs_ai     = array();
		$det_count    = 0;
		$existing_map = $this->fetch_term_meta_map( array_map( static fn( array $c ): int => (int) $c['term_id'], $candidates ), self::GENRES_META_KEY );

		foreach ( $candidates as $candidate ) {
			$term_id    = (int) $candidate['term_id'];
			$artist_sig = $signals[ $term_id ] ?? null;

			if ( null === $artist_sig ) {
				continue;
			}

			$result = self::deterministic(
				array(
					'name'            => (string) $candidate['name'],
					'performer_texts' => $artist_sig['performer_texts'],
					'cobilled_genres' => $artist_sig['cobilled_genres'],
				)
			);

			$has_genres = ! empty( $result['genres'] );

			$proposals[ $term_id ] = array(
				'term_id'    => $term_id,
				'name'       => (string) $candidate['name'],
				'events'     => (int) $artist_sig['events'],
				'titles'     => $artist_sig['titles'],
				'source'     => $has_genres ? 'deterministic' : '',
				'genres'     => $result['genres'],
				'confidence' => $result['confidence'],
				'reasons'    => $result['reasons'],
				'existing'   => isset( $existing_map[ $term_id ] ) && '' !== (string) $existing_map[ $term_id ],
			);

			if ( $has_genres ) {
				++$det_count;
			} else {
				$needs_ai[ $term_id ] = true;
			}
		}

		// Constrained AI pass for the deterministic leftovers.
		$ai_counts = array(
			'proposals'  => 0,
			'requests'   => 0,
			'errors'     => 0,
			'prompt'     => 0,
			'completion' => 0,
			'total'      => 0,
		);

		if ( $use_ai && ! empty( $needs_ai ) ) {
			$ai_counts = $this->run_ai_pass(
				array_keys( $needs_ai ),
				$signals,
				$vocabulary,
				$batch_size,
				$proposals
			);
		}

		return array(
			'proposals' => $proposals,
			'det_count' => $det_count,
			'ai_counts' => $ai_counts,
		);
	}

	/**
	 * Tally proposals: writable/review counts, confidence histogram, and
	 * per-genre artist counts.
	 *
	 * @param array<int,array> $proposals      term_id => proposal.
	 * @param float            $min_confidence Threshold.
	 * @return array{writable:int,review:int,histogram:array<string,int>,per_genre:array<string,int>}
	 */
	public static function tally( array $proposals, float $min_confidence ): array {
		$writable  = 0;
		$review    = 0;
		$histogram = array_fill_keys( array_keys( self::HISTOGRAM_BUCKETS ), 0 );
		$per_genre = array();

		foreach ( $proposals as $proposal ) {
			foreach ( $proposal['genres'] as $slug ) {
				$per_genre[ $slug ] = ( $per_genre[ $slug ] ?? 0 ) + 1;
			}
			++$histogram[ self::confidence_bucket( (float) $proposal['confidence'] ) ];

			if ( '' !== $proposal['source'] && $proposal['confidence'] >= $min_confidence ) {
				++$writable;
			} else {
				++$review;
			}
		}

		ksort( $per_genre );

		return array(
			'writable'  => $writable,
			'review'    => $review,
			'histogram' => $histogram,
			'per_genre' => $per_genre,
		);
	}

	/**
	 * Write qualifying proposals as artist termmeta.
	 *
	 * Writes `_genres` (JSON slug array) plus `_genres_source` and
	 * `_genres_confidence` only for proposals at or above the confidence
	 * threshold, only for artists without existing `_genres` unless
	 * $overwrite. Every slug is re-validated against the active vocabulary
	 * before the write. The `_genres` write fires the genre projection's
	 * `updated_term_meta` fan-out (#828/#829) — this method deliberately
	 * does nothing else.
	 *
	 * @param array<int,array>     $proposals      term_id => proposal.
	 * @param float                $min_confidence Threshold.
	 * @param bool                 $overwrite      Allow replacing existing values.
	 * @param array<string,string> $vocabulary     Active vocabulary slug => name.
	 * @return array{messages:array<int,array{level:string,message:string}>,written:int,skipped:int,review:int,errors:int}
	 */
	public function apply_proposals( array $proposals, float $min_confidence, bool $overwrite, array $vocabulary ): array {
		$messages = array();
		$written  = 0;
		$skipped  = 0;
		$review   = 0;
		$errors   = 0;

		foreach ( $proposals as $proposal ) {
			if ( '' === $proposal['source'] || $proposal['confidence'] < $min_confidence ) {
				++$review;
				continue;
			}

			if ( $proposal['existing'] && ! $overwrite ) {
				++$skipped;
				$messages[] = self::message( 'log', sprintf( '  skip (existing %s): %s', self::GENRES_META_KEY, $proposal['name'] ) );
				continue;
			}

			// Re-validate every slug against the live vocabulary.
			$slugs = array();
			foreach ( $proposal['genres'] as $slug ) {
				if ( isset( $vocabulary[ $slug ] ) ) {
					$slugs[] = $slug;
				}
			}

			if ( empty( $slugs ) ) {
				++$errors;
				$messages[] = self::message( 'warning', sprintf( '  skip (no slugs left in the active vocabulary): %s', $proposal['name'] ) );
				continue;
			}

			$updated = update_term_meta( (int) $proposal['term_id'], self::GENRES_META_KEY, wp_json_encode( $slugs ) );
			if ( false === $updated ) {
				++$errors;
				$messages[] = self::message( 'warning', sprintf( '  write FAILED: %s (term %d)', $proposal['name'], $proposal['term_id'] ) );
				continue;
			}

			update_term_meta( (int) $proposal['term_id'], self::SOURCE_META_KEY, (string) $proposal['source'] );
			update_term_meta( (int) $proposal['term_id'], self::CONFIDENCE_META_KEY, (float) $proposal['confidence'] );

			++$written;
			$messages[] = self::message(
				'log',
				sprintf(
					'  write %s=%s conf=%.2f source=%s: %s (term %d)',
					self::GENRES_META_KEY,
					implode( ',', $slugs ),
					(float) $proposal['confidence'],
					(string) $proposal['source'],
					$proposal['name'],
					(int) $proposal['term_id']
				)
			);
		}

		return array(
			'messages' => $messages,
			'written'  => $written,
			'skipped'  => $skipped,
			'review'   => $review,
			'errors'   => $errors,
		);
	}

	/**
	 * Histogram bucket label for a confidence value.
	 *
	 * @param float $confidence 0-1.
	 * @return string Bucket label.
	 */
	public static function confidence_bucket( float $confidence ): string {
		foreach ( self::HISTOGRAM_BUCKETS as $label => $range ) {
			if ( $confidence >= $range[0] && $confidence < $range[1] ) {
				return $label;
			}
		}

		return '0.9-1.0';
	}

	/**
	 * Build one ordered report message.
	 *
	 * @param string $level   log|warning.
	 * @param string $message Message text.
	 * @return array{level:string,message:string}
	 */
	private static function message( string $level, string $message ): array {
		return array(
			'level'   => $level,
			'message' => $message,
		);
	}

	/**
	 * Pure deterministic classifier for one artist's aggregate signals.
	 *
	 * Rules, in priority order:
	 *  a. Name/description resolution: the artist name, then the aggregated
	 *     performer attributes, resolve through the genre resolver (a resolver
	 *     callable is injected for testability). Whole-string matches start at
	 *     0.75; a narrower word-window match ("The Bluegrass Boys" ->
	 *     "bluegrass") starts at 0.70. When both the name and the performer
	 *     text resolve, the genre sets merge (name order wins, cap 3) and the
	 *     agreement corroborates: +0.05, capped at 0.85.
	 *  b. Co-billing prior: with >= 3 co-billed artists carrying genres and no
	 *     more than 2 distinct genres among them, a genre shared by >= 60% of
	 *     them is proposed at 0.70, +0.05 per extra agreeing artist, capped at
	 *     0.85.
	 *  c. Nothing fires: empty genres, confidence 0.0 — the artist goes to the
	 *     AI pass / review list, never a silent guess.
	 *
	 * @param array         $signals  Signals: name (string), performer_texts
	 *                                (string[]), cobilled_genres (string[][]).
	 * @param callable|null $resolver Value resolver, signature
	 *                                fn(string $value, int $cap): string[].
	 *                                Defaults to extrachill_network_resolve_genres().
	 * @return array{genres:string[],confidence:float,reasons:string[]}
	 */
	public static function deterministic( array $signals, ?callable $resolver = null ): array {
		$resolver = $resolver ?? static fn( string $value, int $cap = 3 ): array => extrachill_network_resolve_genres( $value, $cap );

		$name      = (string) ( $signals['name'] ?? '' );
		$performer = is_array( $signals['performer_texts'] ?? null ) ? $signals['performer_texts'] : array();
		$cobilled  = is_array( $signals['cobilled_genres'] ?? null ) ? $signals['cobilled_genres'] : array();

		$genres  = array();
		$conf    = 0.0;
		$reasons = array();

		// (a) Name resolution: whole string first, then word windows.
		if ( '' !== $name ) {
			$resolved = self::resolve_text( $name, $resolver );
			if ( ! empty( $resolved['genres'] ) ) {
				$genres    = $resolved['genres'];
				$conf      = $resolved['whole'] ? 0.75 : 0.70;
				$reasons[] = $resolved['whole']
					? sprintf( 'artist name "%s" resolves directly through the genre resolver', $name )
					: sprintf( 'artist name "%s" resolves through a name word-window match', $name );
			}
		}

		// (a) Performer attribute text: corroborates a name match only.
		foreach ( $performer as $text ) {
			if ( ! is_string( $text ) || '' === $text ) {
				continue;
			}

			$resolved = self::resolve_text( $text, $resolver );
			if ( empty( $resolved['genres'] ) ) {
				continue;
			}

			// The performer attribute is a per-event act name, not a
			// description ("Sebastian (Space Punk Discoteca)", "this.is.emo
			// VS this.is.nu-metal Night" under "Various DJs"). One event's
			// billing must never decide an artist's genre on its own — that
			// is the per-event classification #30 rules out. It may only
			// corroborate a match already made from the artist's own name.
			if ( empty( $genres ) ) {
				continue;
			}

			$merged = self::merge_genres( $genres, $resolved['genres'] );
			if ( $merged !== $genres && count( array_intersect( $genres, $resolved['genres'] ) ) > 0 ) {
				$genres    = $merged;
				$conf      = min( 0.85, $conf + 0.05 );
				$reasons[] = sprintf( 'performer attribute "%s" corroborates the name resolution', $text );
			}
		}

		// (b) Co-billing prior.
		$with_genres = array_values(
			array_filter(
				$cobilled,
				static fn( $genres_list ): bool => is_array( $genres_list ) && ! empty( $genres_list )
			)
		);

		if ( empty( $genres ) && count( $with_genres ) >= 3 ) {
			$share_target = (int) ceil( 0.6 * count( $with_genres ) );
			$occurrences  = array();
			foreach ( $with_genres as $genres_list ) {
				foreach ( array_unique( $genres_list ) as $slug ) {
					if ( is_string( $slug ) && '' !== $slug ) {
						$occurrences[ $slug ] = ( $occurrences[ $slug ] ?? 0 ) + 1;
					}
				}
			}

			arsort( $occurrences );
			$distinct = count( $occurrences );

			if ( $distinct >= 1 && $distinct <= 2 ) {
				$top_slug  = (string) array_key_first( $occurrences );
				$top_count = (int) $occurrences[ $top_slug ];

				if ( $top_count >= $share_target ) {
					$genres    = array( $top_slug );
					$conf      = min( 0.85, 0.70 + 0.05 * ( $top_count - $share_target ) );
					$reasons[] = sprintf(
						'co-billing prior: %1$d of %2$d genre-carrying co-billed artists share "%3$s"',
						$top_count,
						count( $with_genres ),
						$top_slug
					);
				}
			}
		}

		$genres = array_slice( array_values( array_unique( $genres ) ), 0, self::GENRE_CAP );

		return array(
			'genres'     => $genres,
			'confidence' => empty( $genres ) ? 0.0 : round( $conf, 2 ),
			'reasons'    => $reasons,
		);
	}

	/**
	 * Resolve free text to genres: whole string first, then word windows.
	 *
	 * Window scan (longest first, left to right) lets "The Bluegrass Boys"
	 * resolve through "bluegrass" and "indie rock trio" through "indie rock",
	 * while whole-string matches keep the higher confidence. Windows made up
	 * entirely of generic band nouns ({@see ArtistGenreClassifier::SCAN_STOP_WORDS})
	 * are skipped. Pure static; the resolver is injected.
	 *
	 * @param string   $text     Raw text.
	 * @param callable $resolver Value resolver, fn(string $value): string[].
	 * @return array{genres:string[],whole:bool}
	 */
	public static function resolve_text( string $text, callable $resolver ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array(
				'genres' => array(),
				'whole'  => false,
			);
		}

		$whole = $resolver( $text );
		$whole = is_array( $whole ) ? array_values( array_filter( $whole, 'is_string' ) ) : array();
		if ( ! empty( $whole ) ) {
			return array(
				'genres' => $whole,
				'whole'  => true,
			);
		}

		$tokens = preg_split( '/[^a-z0-9]+/', strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$tokens = is_array( $tokens ) ? array_slice( $tokens, 0, 16 ) : array();

		$count = count( $tokens );
		for ( $length = $count; $length >= 1; $length-- ) {
			for ( $start = 0; $start + $length <= $count; $start++ ) {
				$window = array_slice( $tokens, $start, $length );

				if ( empty( array_diff( $window, self::SCAN_STOP_WORDS ) ) ) {
					continue;
				}

				$resolved = $resolver( implode( ' ', $window ) );
				$resolved = is_array( $resolved ) ? array_values( array_filter( $resolved, 'is_string' ) ) : array();

				if ( ! empty( $resolved ) ) {
					return array(
						'genres' => $resolved,
						'whole'  => false,
					);
				}
			}
		}

		return array(
			'genres' => array(),
			'whole'  => false,
		);
	}

	/**
	 * Merge two genre slug lists, preserving first-seen order, capped at 3.
	 *
	 * @param string[] $base Base list.
	 * @param string[] $extra Additional list.
	 * @return string[]
	 */
	private static function merge_genres( array $base, array $extra ): array {
		$merged = $base;

		foreach ( $extra as $slug ) {
			if ( '' !== $slug && ! in_array( $slug, $merged, true ) ) {
				$merged[] = $slug;
			}
			if ( count( $merged ) >= self::GENRE_CAP ) {
				break;
			}
		}

		return $merged;
	}

	/**
	 * Select candidate artist terms ordered by event count descending.
	 *
	 * One term_taxonomy query: `count` is the term's object count (the same
	 * basis as the issue's distribution numbers). Artists already carrying
	 * `_genres` are excluded unless overwriting (the classifier runs once per
	 * artist; mirrors and prior runs win).
	 *
	 * @param int  $min_events Minimum term count.
	 * @param int  $limit      Max candidates (0 = no limit).
	 * @param int  $offset     Candidates to skip.
	 * @param bool $overwrite  Include artists that already have `_genres`.
	 * @return array<int,array{term_id:int,name:string,count:int}>
	 */
	public function select_candidates( int $min_events, int $limit, int $offset, bool $overwrite ): array {
		global $wpdb;

		$term_taxonomy = $wpdb->term_taxonomy;
		$terms_table   = $wpdb->terms;
		$termmeta      = $wpdb->termmeta;

		$exclude_sql = $overwrite ? '' : " AND NOT EXISTS ( SELECT 1 FROM {$termmeta} tm WHERE tm.term_id = tt.term_id AND tm.meta_key = '" . self::GENRES_META_KEY . "' )";
		$limit_sql   = $limit > 0 ? sprintf( ' LIMIT %d OFFSET %d', $limit, $offset ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSqlParameter -- One-shot bulk read over the term catalog; per-term queries would be orders of magnitude slower. Interpolated values are trusted $wpdb table properties, a compile-time-constant meta key, %d placeholders, and fixed LIMIT/OFFSET integers; no user input is interpolated.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, tt.count FROM {$term_taxonomy} tt INNER JOIN {$terms_table} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s AND tt.count >= %d{$exclude_sql} ORDER BY tt.count DESC, tt.term_id ASC{$limit_sql}",
				self::TAXONOMY,
				$min_events
			),
			ARRAY_A
		);
		// phpcs:enable

		$candidates = array();
		foreach ( (array) $rows as $row ) {
			$candidates[] = array(
				'term_id' => (int) $row['term_id'],
				'name'    => (string) $row['name'],
				'count'   => (int) $row['count'],
			);
		}

		return $candidates;
	}

	/**
	 * Resolve an artist selector to an events-site artist term ID.
	 *
	 * @param string $artist Term ID or slug.
	 * @return int 0 when unresolvable.
	 */
	public function resolve_artist_term( string $artist ): int {
		$artist = trim( $artist );
		if ( '' === $artist ) {
			return 0;
		}

		if ( ctype_digit( $artist ) ) {
			$term = get_term( (int) $artist, self::TAXONOMY );
			return $term instanceof \WP_Term ? (int) $term->term_id : 0;
		}

		$term = get_term_by( 'slug', sanitize_title( $artist ), self::TAXONOMY );
		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * Aggregate per-artist signals for the candidates with set-based SQL.
	 *
	 * Four queries over the candidate subset (no per-artist N+1 beyond the
	 * bounded event-row fetch): published event rows (titles + performer
	 * attributes), the co-billing graph, venue co-occurrence, and external
	 * music links from event meta.
	 *
	 * @param array<int,array{term_id:int,name:string,count:int}> $candidates Candidates.
	 * @return array<int,array> term_id => signals (events, titles,
	 *                          performer_texts, cobilled_genres, venues,
	 *                          external_links).
	 */
	public function collect_signals( array $candidates ): array {
		global $wpdb;

		$ids = array_map( static fn( array $c ): int => (int) $c['term_id'], $candidates );
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$posts_table   = $wpdb->posts;
		$postmeta      = $wpdb->postmeta;
		$relationships = $wpdb->term_relationships;
		$term_taxonomy = $wpdb->term_taxonomy;

		$signals = array();
		foreach ( $candidates as $candidate ) {
			$signals[ (int) $candidate['term_id'] ] = array(
				'events'          => 0,
				'titles'          => array(),
				'performer_texts' => array(),
				'cobilled_genres' => array(),
				'venues'          => array(),
				'external_links'  => array(),
			);
		}

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Four one-shot bulk reads over the candidate subset; per-artist WP_Query would be orders of magnitude slower and the classifier runs once. Interpolated values are trusted $wpdb table properties, fixed meta keys, compile-time-constant patterns, and an IN() placeholder list built entirely from %d tokens; the replacement sniff cannot count the IN() tokens, so its warning is suppressed here; no user input is interpolated.

			// Event rows: title + performer attribute, ordered for the per-artist caps.
			$event_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tt.term_id AS artist_id, p.ID AS event_id, p.post_title,
						SUBSTRING( p.post_content, LOCATE( '\"performer\":\"', p.post_content ) + 13, 200 ) AS performer_raw
					FROM {$posts_table} p
					INNER JOIN {$relationships} tr ON tr.object_id = p.ID
					INNER JOIN {$term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
					WHERE p.post_type = %s AND p.post_status = 'publish' AND tt.term_id IN ( {$placeholders} )
					ORDER BY tt.term_id ASC, p.ID ASC",
					array_merge( array( self::TAXONOMY, self::POST_TYPE ), $chunk )
				),
				ARRAY_A
			);

			$this->absorb_event_rows( (array) $event_rows, $signals );

			// Co-billing graph: other artist terms on the same events.
			$cobilled_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.term_id AS artist_id, b.term_id AS cobilled_id, COUNT( DISTINCT p.ID ) AS shared
					FROM {$posts_table} p
					INNER JOIN {$relationships} ar ON ar.object_id = p.ID
					INNER JOIN {$term_taxonomy} a ON a.term_taxonomy_id = ar.term_taxonomy_id AND a.taxonomy = %s AND a.term_id IN ( {$placeholders} )
					INNER JOIN {$relationships} br ON br.object_id = p.ID
					INNER JOIN {$term_taxonomy} b ON b.term_taxonomy_id = br.term_taxonomy_id AND b.taxonomy = %s AND b.term_id <> a.term_id
					WHERE p.post_type = %s AND p.post_status = 'publish'
					GROUP BY a.term_id, b.term_id
					HAVING shared >= %d",
					array_merge( array( self::TAXONOMY ), $chunk, array( self::TAXONOMY, self::POST_TYPE, self::MIN_SHARED_EVENTS ) )
				),
				ARRAY_A
			);

			$this->absorb_cobilled_rows( (array) $cobilled_rows, $signals );

			// Venue co-occurrence.
			$venue_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.term_id AS artist_id, v.term_id AS venue_id, COUNT( DISTINCT p.ID ) AS shared
					FROM {$posts_table} p
					INNER JOIN {$relationships} ar ON ar.object_id = p.ID
					INNER JOIN {$term_taxonomy} a ON a.term_taxonomy_id = ar.term_taxonomy_id AND a.taxonomy = %s AND a.term_id IN ( {$placeholders} )
					INNER JOIN {$relationships} vr ON vr.object_id = p.ID
					INNER JOIN {$term_taxonomy} v ON v.term_taxonomy_id = vr.term_taxonomy_id AND v.taxonomy = 'venue'
					WHERE p.post_type = %s AND p.post_status = 'publish'
					GROUP BY a.term_id, v.term_id",
					array_merge( array( self::TAXONOMY ), $chunk, array( self::POST_TYPE ) )
				),
				ARRAY_A
			);

			$this->absorb_venue_rows( (array) $venue_rows, $signals );

			// External music links from event meta (text hints only; never fetched).
			$link_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.term_id AS artist_id, GROUP_CONCAT( DISTINCT LEFT( pm.meta_value, 120 ) SEPARATOR ' ' ) AS links
					FROM {$posts_table} p
					INNER JOIN {$relationships} ar ON ar.object_id = p.ID
					INNER JOIN {$term_taxonomy} a ON a.term_taxonomy_id = ar.term_taxonomy_id AND a.taxonomy = %s AND a.term_id IN ( {$placeholders} )
					INNER JOIN {$postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ( '_datamachine_ticket_url', '_datamachine_source_url' )
					WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value REGEXP %s
					GROUP BY a.term_id",
					array_merge( array( self::TAXONOMY ), $chunk, array( self::POST_TYPE, self::EXTERNAL_LINK_PATTERN ) )
				),
				ARRAY_A
			);

			$this->absorb_link_rows( (array) $link_rows, $signals );

			// phpcs:enable
		}

		$this->hydrate_cobilled_genres( $signals );
		$this->hydrate_venue_names( $signals );

		return $signals;
	}

	/**
	 * Fold event rows into the per-artist signal accumulators.
	 *
	 * @param array<int,array> $rows    Raw SQL rows.
	 * @param array<int,array> $signals Signal accumulators (by reference).
	 */
	private function absorb_event_rows( array $rows, array &$signals ): void {
		foreach ( $rows as $row ) {
			$artist_id = (int) $row['artist_id'];
			if ( ! isset( $signals[ $artist_id ] ) ) {
				continue;
			}

			++$signals[ $artist_id ]['events'];

			$title = trim( (string) $row['post_title'] );
			if ( '' !== $title && count( $signals[ $artist_id ]['titles'] ) < self::MAX_TITLES && ! in_array( $title, $signals[ $artist_id ]['titles'], true ) ) {
				$signals[ $artist_id ]['titles'][] = $title;
			}

			$performer_raw = trim( (string) $row['performer_raw'] );
			if ( '' !== $performer_raw && count( $signals[ $artist_id ]['performer_texts'] ) < self::MAX_PERFORMER_TEXTS ) {
				// The SUBSTRING window may include trailing block JSON; cut at the first quote.
				$cut       = strpos( $performer_raw, '"' );
				$performer = false === $cut ? $performer_raw : substr( $performer_raw, 0, $cut );
				$performer = trim( $performer );

				if ( '' !== $performer && ! in_array( $performer, $signals[ $artist_id ]['performer_texts'], true ) ) {
					$signals[ $artist_id ]['performer_texts'][] = $performer;
				}
			}
		}
	}

	/**
	 * Fold co-billing rows into the per-artist signal accumulators.
	 *
	 * @param array<int,array> $rows    Raw SQL rows.
	 * @param array<int,array> $signals Signal accumulators (by reference).
	 */
	private function absorb_cobilled_rows( array $rows, array &$signals ): void {
		foreach ( $rows as $row ) {
			$artist_id = (int) $row['artist_id'];
			if ( ! isset( $signals[ $artist_id ] ) ) {
				continue;
			}

			$cobilled_id = (int) $row['cobilled_id'];
			$shared      = (int) $row['shared'];

			$current = $signals[ $artist_id ]['cobilled_genres'][ $cobilled_id ] ?? 0;
			$signals[ $artist_id ]['cobilled_genres'][ $cobilled_id ] = max( $current, $shared );
		}
	}

	/**
	 * Fold venue rows into the per-artist signal accumulators.
	 *
	 * @param array<int,array> $rows    Raw SQL rows.
	 * @param array<int,array> $signals Signal accumulators (by reference).
	 */
	private function absorb_venue_rows( array $rows, array &$signals ): void {
		foreach ( $rows as $row ) {
			$artist_id = (int) $row['artist_id'];
			if ( ! isset( $signals[ $artist_id ] ) ) {
				continue;
			}

			$venue_id = (int) $row['venue_id'];
			$shared   = (int) $row['shared'];

			$current                                      = $signals[ $artist_id ]['venues'][ $venue_id ] ?? 0;
			$signals[ $artist_id ]['venues'][ $venue_id ] = max( $current, $shared );
		}
	}

	/**
	 * Fold external-link rows into the per-artist signal accumulators.
	 *
	 * @param array<int,array> $rows    Raw SQL rows.
	 * @param array<int,array> $signals Signal accumulators (by reference).
	 */
	private function absorb_link_rows( array $rows, array &$signals ): void {
		foreach ( $rows as $row ) {
			$artist_id = (int) $row['artist_id'];
			if ( ! isset( $signals[ $artist_id ] ) ) {
				continue;
			}

			$links = array_filter( array_map( 'trim', explode( ' ', (string) $row['links'] ) ) );
			foreach ( $links as $link ) {
				if ( ! in_array( $link, $signals[ $artist_id ]['external_links'], true ) ) {
					$signals[ $artist_id ]['external_links'][] = $link;
				}
			}
		}
	}

	/**
	 * Replace co-billed term IDs with their `_genres` slug lists.
	 *
	 * One bulk termmeta read across every co-billed term ID; artists without
	 * genres are dropped from the map (the deterministic prior and the AI
	 * prompt only see genre-carrying co-bills).
	 *
	 * @param array<int,array> $signals Signal accumulators (by reference).
	 */
	private function hydrate_cobilled_genres( array &$signals ): void {
		$all_ids = array();
		foreach ( $signals as $signal ) {
			foreach ( array_keys( $signal['cobilled_genres'] ) as $cobilled_id ) {
				$all_ids[ (int) $cobilled_id ] = true;
			}
		}

		if ( empty( $all_ids ) ) {
			foreach ( array_keys( $signals ) as $artist_id ) {
				$signals[ $artist_id ]['cobilled_genres'] = array();
			}
			return;
		}

		$genres_map = $this->fetch_term_meta_map( array_keys( $all_ids ), self::GENRES_META_KEY );

		foreach ( array_keys( $signals ) as $artist_id ) {
			$hydrated = array();

			foreach ( $signals[ $artist_id ]['cobilled_genres'] as $cobilled_id => $shared ) {
				$raw = (string) ( $genres_map[ (int) $cobilled_id ] ?? '' );
				if ( '' === $raw ) {
					continue;
				}

				$slugs = json_decode( $raw, true );
				$slugs = is_array( $slugs ) ? array_values( array_filter( $slugs, 'is_string' ) ) : array();
				if ( empty( $slugs ) ) {
					continue;
				}

				$hydrated[ (int) $cobilled_id ] = $slugs;
			}

			$signals[ $artist_id ]['cobilled_genres'] = $hydrated;
		}
	}

	/**
	 * Replace venue term IDs with "Name (tier)" display strings.
	 *
	 * One bulk term fetch + one bulk termmeta read; venues are kept per
	 * artist by shared-event count and capped for the prompt.
	 *
	 * @param array<int,array> $signals Signal accumulators (by reference).
	 */
	private function hydrate_venue_names( array &$signals ): void {
		$all_ids = array();
		foreach ( $signals as $signal ) {
			foreach ( array_keys( $signal['venues'] ) as $venue_id ) {
				$all_ids[ (int) $venue_id ] = true;
			}
		}

		if ( empty( $all_ids ) ) {
			foreach ( array_keys( $signals ) as $artist_id ) {
				$signals[ $artist_id ]['venues'] = array();
			}
			return;
		}

		$venue_ids = array_keys( $all_ids );

		$names = array();
		$terms = get_terms(
			array(
				'taxonomy'               => 'venue',
				'include'                => $venue_ids,
				'hide_empty'             => false,
				'number'                 => 0,
				'orderby'                => 'none',
				'update_term_meta_cache' => false,
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$names[ (int) $term->term_id ] = $term->name;
			}
		}

		$tiers = $this->fetch_term_meta_map( $venue_ids, '_venue_tier' );

		foreach ( array_keys( $signals ) as $artist_id ) {
			$entries = $signals[ $artist_id ]['venues'];

			arsort( $entries );
			$entries = array_slice( $entries, 0, self::MAX_VENUES, true );

			$display = array();
			foreach ( $entries as $venue_id => $shared ) {
				$name      = (string) ( $names[ $venue_id ] ?? sprintf( 'venue #%d', $venue_id ) );
				$tier      = (string) ( $tiers[ $venue_id ] ?? '' );
				$display[] = '' !== $tier ? sprintf( '%s (%s)', $name, $tier ) : $name;
			}

			$signals[ $artist_id ]['venues'] = $display;
		}
	}

	/**
	 * Fetch one term-meta key for a list of terms as an id => value map.
	 *
	 * Set-based IN() lookup — no per-term meta reads.
	 *
	 * @param array<int,int> $term_ids Term IDs.
	 * @param string         $meta_key Term meta key.
	 * @return array<int,string>
	 */
	public function fetch_term_meta_map( array $term_ids, string $meta_key ): array {
		global $wpdb;

		if ( empty( $term_ids ) ) {
			return array();
		}

		$map    = array();
		$chunks = array_chunk( array_map( 'intval', array_values( array_unique( $term_ids ) ) ), 1000 );

		foreach ( $chunks as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal identifiers: the $wpdb->termmeta table name and an IN() placeholder list built entirely from %d tokens.
			$sql = "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s AND term_id IN ( {$placeholders} )";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot bulk read; get_term_meta per term would be thousands of queries.
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is passed to prepare() immediately on this line.
				$wpdb->prepare( $sql, array_merge( array( $meta_key ), $chunk ) ),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$map[ (int) $row['term_id'] ] = (string) $row['meta_value'];
			}
		}

		return $map;
	}

	/**
	 * Run the constrained AI pass over the artists the deterministic pass
	 * left empty, in batches.
	 *
	 * Modeled on extrachill-network's term-classification task:
	 * ConversationManager::buildConversationMessage + RequestBuilder::build
	 * with the DM default system model, JSON-only response, and every
	 * returned name re-resolved through the network resolver. Per-batch
	 * failures (WP_Error, invalid JSON) count as errors and never abort the
	 * run; they are recorded in {@see self::warnings()}.
	 *
	 * @param array<int,int>       $term_ids   Artist term IDs needing AI.
	 * @param array<int,array>     $signals    Signals by term ID.
	 * @param array<string,string> $vocabulary Active vocabulary slug => name.
	 * @param int                  $batch_size Artists per request.
	 * @param array<int,array>     $proposals  Proposal map (by reference; filled with AI results).
	 * @return array<string,int> Counters: proposals, requests, errors, prompt, completion, total.
	 */
	public function run_ai_pass( array $term_ids, array $signals, array $vocabulary, int $batch_size, array &$proposals ): array {
		$counts = array(
			'proposals'  => 0,
			'requests'   => 0,
			'errors'     => 0,
			'prompt'     => 0,
			'completion' => 0,
			'total'      => 0,
		);

		if ( ! class_exists( '\DataMachine\Engine\AI\ConversationManager' ) || ! class_exists( '\DataMachine\Engine\AI\RequestBuilder' ) ) {
			$this->warnings[] = 'Data Machine AI engine is not available — the AI pass was skipped; affected artists go to the review list.';
			$counts['errors'] = count( $term_ids );
			return $counts;
		}

		$model = class_exists( '\DataMachine\Core\PluginSettings' )
			? \DataMachine\Core\PluginSettings::resolveModelForAgentMode( 0, 'system' )
			: array(
				'provider' => '',
				'model'    => '',
			);

		$provider = $model['provider'];
		$model_id = $model['model'];

		if ( '' === $provider || '' === $model_id ) {
			$this->warnings[] = 'No default AI provider/model configured in Data Machine — the AI pass was skipped; affected artists go to the review list.';
			$counts['errors'] = count( $term_ids );
			return $counts;
		}

		$names = array_values( $vocabulary );
		$total = count( $term_ids );
		$done  = 0;

		foreach ( array_chunk( $term_ids, max( 1, $batch_size ) ) as $batch ) {
			$prompt = $this->build_ai_prompt( $batch, $signals, $names );

			$message = \DataMachine\Engine\AI\ConversationManager::buildConversationMessage( 'user', $prompt );

			++$counts['requests'];
			$response = \DataMachine\Engine\AI\RequestBuilder::build(
				array( $message ),
				$provider,
				$model_id,
				array(),
				array( 'system' ),
				array(
					'task_type'       => 'extrachill_events_artist_genre_classification',
					'calling_user_id' => 0,
				)
			);

			$done += count( $batch );

			if ( is_wp_error( $response ) ) {
				++$counts['errors'];
				$this->warnings[] = sprintf( 'AI batch failed (%d/%d artists done): %s', $done, $total, $response->get_error_message() );
				continue;
			}

			$this->absorb_ai_usage( $response, $counts );

			$text    = \DataMachine\Engine\AI\RequestBuilder::resultText( $response );
			$text    = trim( (string) $text );
			$text    = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
			$decoded = json_decode( (string) $text, true );

			if ( ! is_array( $decoded ) ) {
				++$counts['errors'];
				$this->warnings[] = sprintf( 'AI batch returned invalid JSON (%d/%d artists done).', $done, $total );
				continue;
			}

			foreach ( $batch as $term_id ) {
				$entry = $decoded[ (string) $term_id ] ?? null;

				if ( ! is_array( $entry ) ) {
					continue;
				}

				$raw_genres     = is_array( $entry['genres'] ?? null ) ? $entry['genres'] : array();
				$raw_confidence = isset( $entry['confidence'] ) ? (float) $entry['confidence'] : 0.0;
				$raw_confidence = max( 0.0, min( 1.0, $raw_confidence ) );

				// Every returned name must re-resolve; unresolvable names are dropped.
				$slugs = array();
				foreach ( $raw_genres as $name ) {
					if ( ! is_string( $name ) || '' === trim( $name ) ) {
						continue;
					}

					$slug = extrachill_network_resolve_genres( trim( $name ), 1 );
					if ( empty( $slug ) ) {
						continue;
					}

					$slug = (string) $slug[0];
					if ( ! in_array( $slug, $slugs, true ) ) {
						$slugs[] = $slug;
					}
					if ( count( $slugs ) >= self::GENRE_CAP ) {
						break;
					}
				}

				if ( empty( $slugs ) ) {
					continue;
				}

				++$counts['proposals'];
				$proposals[ $term_id ]['source']     = 'ai';
				$proposals[ $term_id ]['genres']     = $slugs;
				$proposals[ $term_id ]['confidence'] = round( $raw_confidence, 2 );
				$proposals[ $term_id ]['reasons'][]  = sprintf(
					'constrained AI proposal (names re-resolved against the %d-genre vocabulary)',
					count( $names )
				);
			}
		}

		return $counts;
	}

	/**
	 * Build the constrained AI prompt for one batch of artists.
	 *
	 * @param array<int,int>    $batch     Artist term IDs.
	 * @param array<int,array>  $signals   Signals by term ID.
	 * @param array<int,string> $names     Allowed vocabulary NAMES.
	 * @return string
	 */
	private function build_ai_prompt( array $batch, array $signals, array $names ): string {
		$artists = array();

		foreach ( $batch as $term_id ) {
			$signal = $signals[ $term_id ] ?? null;
			if ( null === $signal ) {
				continue;
			}

			$artists[] = array(
				'term_id'         => $term_id,
				'name'            => (string) ( $signal['name'] ?? '' ),
				'event_titles'    => array_slice( array_values( $signal['titles'] ), 0, self::MAX_TITLES ),
				'venues'          => array_values( $signal['venues'] ),
				'cobilled_genres' => array_values(
					array_unique(
						array_merge(
							...array_values(
								array_map(
									static fn( $slugs ): array => is_array( $slugs ) ? $slugs : array(),
									$signal['cobilled_genres']
								)
							)
						)
					)
				),
				'performer_text'  => array_slice( array_values( $signal['performer_texts'] ), 0, self::MAX_PERFORMER_TEXTS ),
				'external_links'  => array_slice( array_values( $signal['external_links'] ), 0, 5 ),
			);
		}

		$prompt  = "You are classifying music artists into a closed genre vocabulary for a live music events catalog.\n\n";
		$prompt .= 'Allowed genre names (the ONLY values you may return, exactly as written): ' . implode( ', ', $names ) . "\n\n";
		$prompt .= "Rules:\n";
		$prompt .= "- Return JSON only. No markdown, no commentary. Shape: {\"<term_id>\": {\"genres\": [\"Name\", ...], \"confidence\": 0.0}}\n";
		$prompt .= "- At most 3 genres per artist, most confident first.\n";
		$prompt .= "- Only names from the allowed list. Never invent or modify a value.\n";
		$prompt .= "- Base each choice on the artist's event titles, venues, co-billed artists' genres, performer text, and links.\n";
		$prompt .= "- Return an empty genres list when unsure — do not guess.\n";
		$prompt .= "- confidence is your 0.0-1.0 certainty that the genres fit this artist.\n\n";
		$prompt .= 'Artists: ' . wp_json_encode( $artists, JSON_UNESCAPED_UNICODE );

		return $prompt;
	}

	/**
	 * Accumulate token usage from a wp-ai-client result, defensively.
	 *
	 * @param mixed             $result RequestBuilder result.
	 * @param array<string,int> $counts Counter map (by reference).
	 */
	private function absorb_ai_usage( $result, array &$counts ): void {
		if ( ! is_object( $result ) || ! method_exists( $result, 'getTokenUsage' ) ) {
			return;
		}

		try {
			$usage = $result->getTokenUsage();
		} catch ( \Throwable $throwable ) {
			return;
		}

		if ( ! is_object( $usage ) ) {
			return;
		}

		if ( method_exists( $usage, 'getPromptTokens' ) ) {
			$counts['prompt'] += (int) $usage->getPromptTokens();
		}
		if ( method_exists( $usage, 'getCompletionTokens' ) ) {
			$counts['completion'] += (int) $usage->getCompletionTokens();
		}
		if ( method_exists( $usage, 'getTotalTokens' ) ) {
			$counts['total'] += (int) $usage->getTotalTokens();
		}
	}
}
