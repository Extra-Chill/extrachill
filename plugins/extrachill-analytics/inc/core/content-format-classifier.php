<?php
/**
 * Content Format Classifier
 *
 * Deterministic, single-label mapping of a WordPress post to a CONTENT FORMAT —
 * the editorial archetype the revenue lens rolls up by (song-meaning, listicle,
 * charleston-local, explainer, interview, trivia, guitar-history, legacy-html,
 * uncategorized). This is the format axis the manual Mediavine-CSV-to-category
 * stitch joined on; the classifier makes that stitch repeatable.
 *
 * Why a NEW classifier and not "reuse the one in content-flags": the shipped
 * ContentFlags/ContentPerformance abilities do NOT classify by format. They take
 * a category slug as input and operate within that one category — there is no
 * post->format function to reuse anywhere in the codebase. So this is the gap,
 * and it is scoped to exactly the gap (RULES.md: build the small thing, not a
 * parallel system). The category slugs are the source of truth; URL/structure
 * heuristics only break ties the category can't.
 *
 * Determinism contract: the same post always yields the same single format. The
 * order of the checks below IS the precedence — the first match wins, most
 * specific first. The mapping is intentionally legible (slug membership) rather
 * than ML so the rollups can be defended as "this came from the taxonomy," not
 * a black box.
 *
 * @package ExtraChill\Analytics
 * @since 0.16.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Category-slug membership for each content format.
 *
 * The first format (top of the array) whose slug set intersects a post's
 * categories wins. Slugs are matched case-insensitively. Edit this map — not the
 * classifier code — to retune which categories roll up into which format.
 *
 * @return array<string, array<int, string>> Format => list of category slugs.
 */
function extrachill_analytics_format_category_map() {
	$map = array(
		// Song meanings / lyric explainers — the HCU-dead but high-$/page legacy.
		'song-meaning'     => array( 'song-meanings', 'song-meaning', 'lyrics', 'lyric-meanings' ),
		// Music history / artist history / guitar history deep-dives.
		'guitar-history'   => array( 'guitar-history', 'guitars', 'gear', 'famous-guitars' ),
		'music-history'    => array( 'music-history', 'history', 'band-art' ),
		// Charleston / local scene — the defensible moat with an events funnel.
		'charleston-local' => array( 'charleston', 'charleston-music', 'local', 'local-music', 'sc-music', 'south-carolina', 'columbia', 'savannah' ),
		// Interviews / artist Q&As.
		'interview'        => array( 'interviews', 'interview', 'q-a', 'qa' ),
		// Trivia / quizzes / facts.
		'trivia'           => array( 'trivia', 'quiz', 'quizzes', 'facts', 'did-you-know' ),
		// Listicles / rankings / roundups.
		'listicle'         => array( 'lists', 'listicles', 'rankings', 'best-of', 'roundups', 'top-10', 'playlists' ),
		// Explainers / how-tos / guides.
		'explainer'        => array( 'explainers', 'explainer', 'guides', 'how-to', 'how-tos', 'music-theory', 'education' ),
		// News / festival-wire reactive coverage.
		'news'             => array( 'news', 'festival-wire', 'festivals', 'live-music', 'shows', 'concerts', 'reviews' ),
	);

	/**
	 * Filter the content-format -> category-slug map used by the revenue lens.
	 *
	 * @param array $map Format => list of category slugs (precedence top-down).
	 */
	return apply_filters( 'extrachill_analytics_format_category_map', $map );
}

/**
 * Classify a post (or post ID) into a single content format.
 *
 * Precedence:
 *   1. legacy-html — the URL ends in ".html" (the 963 ghost pages still
 *      ranking). Checked first because legacy .html is a distribution fact that
 *      cross-cuts categories and the issue calls it out as its own bucket.
 *   2. category membership — first format in the map whose slugs intersect the
 *      post's categories (most-specific format first).
 *   3. trivia/listicle title fallback — a light title-shape heuristic only when
 *      the taxonomy gave no answer, so quiz/list posts that were never
 *      categorized still land somewhere honest.
 *   4. uncategorized — nothing matched.
 *
 * @param int|WP_Post $post Post ID or object.
 * @return string Format label (one of the map keys, 'legacy-html', or 'uncategorized').
 */
function extrachill_analytics_classify_format( $post ) {
	$post = get_post( $post );
	if ( ! $post instanceof WP_Post ) {
		return 'uncategorized';
	}

	// 1. Legacy .html URLs — a structural fact, not a taxonomy one.
	$permalink = get_permalink( $post );
	if ( is_string( $permalink ) && preg_match( '/\.html(?:[\/?#]|$)/i', $permalink ) ) {
		return 'legacy-html';
	}

	// 2. Category membership (precedence = map order).
	$terms = get_the_terms( $post->ID, 'category' );
	$slugs = array();
	if ( is_array( $terms ) ) {
		foreach ( $terms as $term ) {
			$slugs[] = strtolower( $term->slug );
		}
	}

	if ( ! empty( $slugs ) ) {
		foreach ( extrachill_analytics_format_category_map() as $format => $format_slugs ) {
			foreach ( $format_slugs as $candidate ) {
				if ( in_array( strtolower( $candidate ), $slugs, true ) ) {
					return $format;
				}
			}
		}
	}

	// 3. Title-shape fallback only when the taxonomy was silent.
	$title = strtolower( (string) $post->post_title );
	if ( preg_match( '/\b(quiz|trivia|how well do you know)\b/', $title ) ) {
		return 'trivia';
	}
	if ( preg_match( '/^\s*\d+\s+/', $title ) || preg_match( '/\b(best|top)\s+\d+\b/', $title ) ) {
		return 'listicle';
	}

	return 'uncategorized';
}

/**
 * Resolve a Mediavine CSV slug/url to a post ID on the current blog.
 *
 * The Mediavine "Pages" export keys rows by page path (sometimes a full URL,
 * sometimes a host-relative path, sometimes a bare slug). url_to_postid() wants
 * a full URL, so normalize first. Legacy .html paths won't resolve via
 * url_to_postid() (no matching rewrite), so they correctly return 0 and the
 * caller treats them as the legacy-html bucket without a post join.
 *
 * @param string $slug_or_url Raw CSV identifier.
 * @param string $hostname    Hostname pages map to (default: extrachill.com).
 * @return int Post ID, or 0 if unresolved.
 */
function extrachill_analytics_revenue_resolve_post_id( $slug_or_url, $hostname = 'extrachill.com' ) {
	$value = trim( (string) $slug_or_url );
	if ( '' === $value ) {
		return 0;
	}

	// Strip query/hash.
	$value = strtok( $value, '?#' );

	// Already a full URL?
	if ( preg_match( '#^https?://#i', $value ) ) {
		$url = $value;
	} else {
		// Treat as host-relative path / bare slug.
		$path = '/' . ltrim( $value, '/' );
		$url  = 'https://' . $hostname . $path;
	}

	$post_id = (int) url_to_postid( $url );

	// Bare-slug fallback: url_to_postid can miss when the path lacks a trailing
	// slash on a /%postname%/ permalink. Retry with a trailing slash.
	if ( 0 === $post_id && ! preg_match( '#/$#', $url ) && ! preg_match( '/\.html$/i', $url ) ) {
		$post_id = (int) url_to_postid( $url . '/' );
	}

	return $post_id;
}
