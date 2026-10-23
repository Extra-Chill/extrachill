<?php
/**
 * Grade for show-ratings-fan against the Gardner persona's nontechnical
 * oracles. Browser evidence comes from the observation sink; server truth
 * comes from the event_review comments and their aggregates.
 *
 * @package ExtraChillNetwork
 */

$fixture = get_site_option( 'ec_rig_journey_fixture_show_ratings_fan', array() );
if ( empty( $fixture['events_blog_id'] ) ) {
	throw new RuntimeException( 'Journey fixture missing; seed did not run.' );
}

$obs = array();
foreach ( (array) get_site_option( 'ec_show_ratings_observations', array() ) as $o ) {
	if ( is_array( $o ) && isset( $o['label'] ) ) {
		$obs[ $o['label'] ] = $o['value'] ?? null;
	}
}

$results = array();
$record  = static function ( string $id, string $task, string $outcome, $evidence ) use ( &$results ): void {
	$results[] = compact( 'id', 'task', 'outcome', 'evidence' );
};

switch_to_blog( (int) $fixture['events_blog_id'] );
$reviews = static function ( int $post_id ): array {
	$rows = get_comments( array( 'post_id' => $post_id, 'status' => 'all', 'user_id' => (int) $GLOBALS['ec_rf_fan'] ) );
	return array_map(
		static fn( $c ) => array(
			'id'       => (int) $c->comment_ID,
			'type'     => $c->comment_type,
			'approved' => $c->comment_approved,
			'rating'   => get_comment_meta( $c->comment_ID, '_dme_rating', true ),
			'text'     => $c->comment_content,
		),
		$rows
	);
};
$GLOBALS['ec_rf_fan'] = (int) $fixture['fan_id'];
$recent_reviews = $reviews( (int) $fixture['recent_id'] );
$older_reviews  = $reviews( (int) $fixture['older_id'] );
$meta           = static fn( int $id ) => array_filter( get_post_meta( $id ), static fn( $k ) => str_contains( $k, 'rating' ), ARRAY_FILTER_USE_KEY );
$recent_meta    = $meta( (int) $fixture['recent_id'] );
$older_meta     = $meta( (int) $fixture['older_id'] );
restore_current_blog();

$v  = $obs['visitor-recent'] ?? null;
$record( 'visitor-told-how-to-rate', 'A logged-out visitor on a past show sees how to rate it.', is_array( $v ) && ( $v['loginNote'] || 'visible' === $v['gate'] ) ? 'pass' : 'finding', $v );

$b = $obs['fan-before-mark'] ?? null;
$record( 'gate-explains-itself', 'Before "I Was There", the fan sees one plain sentence explaining why they cannot rate yet, and no dead form.', is_array( $b ) && 'visible' === $b['gate'] && 'visible' !== $b['stars'] ? 'pass' : 'finding', $b );

$a = $obs['fan-after-mark'] ?? null;
$record( 'form-unlocks-in-place', 'After tapping "I Was There", the star form appears without a reload.', is_array( $a ) && 'visible' === $a['stars'] && 'visible' === $a['submit'] ? 'pass' : 'finding', $a );

$p = $obs['fan-after-post'] ?? null;
$approved = array_filter( $recent_reviews, static fn( $r ) => '1' === (string) $r['approved'] && '5' === (string) $r['rating'] );
$record( 'review-posts-immediately', 'Posting 5 stars + review publishes immediately (no moderation) and the page confirms it.', ! empty( $approved ) && is_array( $p ) && ! $p['moderationNote'] ? 'pass' : 'finding', array( 'server' => $recent_reviews, 'browser' => $p ) );

$r = $obs['fan-after-reload'] ?? null;
$record( 'reload-persistence', 'After reload the fan sees their own rating and the show summary, and is not offered the form again.', is_array( $r ) && $r['yourRating'] && $r['summary'] && 'visible' !== $r['stars'] ? 'pass' : 'finding', $r );
$record( 'duplicate-prevention', 'Exactly one review exists for the fan on the show.', 1 === count( $recent_reviews ) ? 'pass' : 'finding', $recent_reviews );

$ms = $obs['myshows-past'] ?? null;
$card_ok = false;
foreach ( (array) ( $ms['cards'] ?? array() ) as $card ) {
	$card_ok = $card_ok || ! empty( $card['hasStars'] );
}
$record( 'my-shows-offers-rating', 'My Shows -> Past shows stars on the attended past show.', $card_ok ? 'pass' : 'finding', $ms );
$changed = array_values( $older_reviews );
$record( 'my-shows-change-mind', 'Rating 4 then changing to 3 from My Shows leaves one 3-star rating on the server.', 1 === count( $changed ) && '3' === (string) $changed[0]['rating'] ? 'pass' : ( $card_ok ? 'finding' : 'skip' ), array( 'server' => $older_reviews, 'after_rate' => $obs['myshows-after-rate'] ?? null, 'after_change' => $obs['myshows-changed-mind'] ?? null, 'after_reload' => $obs['myshows-after-reload'] ?? null ) );

$ap = $obs['artist-page-past'] ?? null;
$record( 'listing-shows-rating', 'The Goose artist page (past shows) shows a star badge on the rated show.', is_array( $ap ) && ! empty( $ap['badges'] ) ? 'pass' : 'finding', array( 'upcoming' => $obs['artist-page'] ?? null, 'past' => $ap ) );

$va = $obs['visitor-recent-after'] ?? null;
$record( 'visitor-sees-review', 'A logged-out visitor sees the summary and the fan\'s review on the show page.', is_array( $va ) && $va['summary'] && str_contains( (string) $va['commentsText'], 'Arcadia' ) ? 'pass' : 'finding', $va );

$c = $obs['fan-after-rate-it-click'] ?? null;
$record( 'rate-it-link-finds-form', 'After "I Was There", a "Rate this show" link appears by the button and jumps to the star picker.', is_array( $a ) && 'visible' === ( $a['rateItLink'] ?? '' ) && is_array( $c ) && abs( (int) $c['respondTop'] ) < (int) $c['vh'] ? 'pass' : 'finding', array( 'after_mark' => $a, 'after_click' => $c ) );
$record( 'logged-out-copy-says-rate', 'Logged-out visitors on a past show are asked to log in to rate it.', is_array( $v ) && 'Log in to rate this show.' === ( $v['loginNote'] ?? '' ) && str_contains( (string) ( $v['ctaHeading'] ?? '' ), 'rate this show' ) ? 'pass' : 'finding', $v );
$record( 'reviews-heading', 'The list heading calls the review a review.', is_array( $va ) && str_starts_with( (string) ( $va['commentsTitle'] ?? '' ), '1 review' ) ? 'pass' : 'finding', $va['commentsTitle'] ?? null );
$record( 'post-rating-form-is-for-comments', 'After rating, the form heading reads "Add a comment".', is_array( $r ) && 'Add a comment' === ( $r['heading'] ?? '' ) ? 'pass' : 'finding', $r['heading'] ?? null );

$summary = array(
	'schema'       => 'extrachill-network/journey-result/show-ratings-fan/v1',
	'fixture'      => $fixture,
	'results'      => $results,
	'passed'       => count( array_filter( $results, static fn( $x ) => 'pass' === $x['outcome'] ) ),
	'findings'     => count( array_filter( $results, static fn( $x ) => 'finding' === $x['outcome'] ) ),
	'skipped'      => count( array_filter( $results, static fn( $x ) => 'skip' === $x['outcome'] ) ),
	'server'       => array( 'recent_meta' => $recent_meta, 'older_meta' => $older_meta ),
	'observations' => $obs,
);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $summary ) ) );
