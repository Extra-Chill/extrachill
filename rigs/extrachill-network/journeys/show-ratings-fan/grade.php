<?php
/**
 * Grade for show-ratings-fan against the Gardner persona's nontechnical
 * oracles. Browser evidence comes from the observation sink; server truth
 * comes from the fan's private ratings on their attendance records.
 *
 * Ratings are personal: only the fan sees them, on My Shows. Nothing about
 * ratings is public.
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

$events_blog_id = (int) $fixture['events_blog_id'];
$fan_id         = (int) $fixture['fan_id'];
$recent_id      = (int) $fixture['recent_id'];
$older_id       = (int) $fixture['older_id'];

$ratings = function_exists( 'ec_users_get_show_ratings' )
	? ec_users_get_show_ratings( $fan_id, array( $recent_id, $older_id ), $events_blog_id )
	: null;

switch_to_blog( $events_blog_id );
$review_comments = count(
	get_comments(
		array(
			'post__in' => array( $recent_id, $older_id ),
			'status'   => 'all',
			'count'    => false,
		)
	)
);
$recent_path = '/events/' . get_post_field( 'post_name', $recent_id );
restore_current_blog();

$no_public_ratings = static fn( $o ) => is_array( $o ) && 0 === (int) ( $o['ratingUi'] ?? -1 ) && 0 === (int) ( $o['stars'] ?? -1 ) && empty( $o['rateText'] );

$v = $obs['visitor-recent'] ?? null;
$record( 'visitor-sees-no-ratings', 'A logged-out visitor on a past show sees no ratings, stars, or "rate this show" prompts.', $no_public_ratings( $v ) ? 'pass' : 'finding', $v );

$a = $obs['fan-after-mark'] ?? null;
$record( 'show-page-has-no-rating-form', 'After tapping "I Was There", the show page offers no public rating or review form.', $no_public_ratings( $a ) && 'true' === ( $a['pressed'] ?? null ) ? 'pass' : 'finding', $a );

$ms        = $obs['myshows-past'] ?? null;
$cards     = (array) ( $ms['cards'] ?? array() );
$calm_rows = ! empty( $cards ) && array_reduce( $cards, static fn( $ok, $c ) => $ok && 0 === (int) ( $c['stars'] ?? -1 ) && 'Rate' === trim( (string) ( $c['toggle'] ?? '' ) ), true );
$record( 'my-shows-no-empty-stars', 'Unrated shows on My Shows -> Past show a quiet "Rate" button, not rows of empty stars.', $calm_rows ? 'pass' : 'finding', $ms );

$list_text = strtolower( implode( ' ', (array) ( $ms['listText'] ?? array() ) ) );
$record( 'my-shows-no-nag', 'My Shows says once that ratings are private and never nags about unrated shows.', is_array( $ms ) && str_contains( (string) ( $ms['note'] ?? '' ), 'Only you see your ratings' ) && ! str_contains( $list_text, 'need' ) ? 'pass' : 'finding', $ms );

$record( 'my-shows-rates-privately', 'Rating 5 on the recent show, then 4 -> 3 on the older one, leaves 5 and 3 on the fan\'s own records.', is_array( $ratings ) && 5 === ( $ratings[ $recent_id ] ?? null ) && 3 === ( $ratings[ $older_id ] ?? null ) ? 'pass' : 'finding', array( 'server' => $ratings, 'after_rate' => $obs['myshows-after-rate'] ?? null ) );

$after_rate = $obs['myshows-after-rate'] ?? null;
$record( 'my-shows-star-stays-put', 'Tapping stars on a My Shows row rates in place without leaving the page.', is_array( $after_rate ) && str_starts_with( (string) ( $after_rate['path'] ?? '' ), '/my-shows' ) ? 'pass' : 'finding', $after_rate );

$reload = $obs['myshows-after-reload'] ?? null;
$on     = array_map( static fn( $c ) => (int) ( $c['on'] ?? 0 ), (array) ( $reload['cards'] ?? array() ) );
$record( 'reload-persistence', 'After a reload, My Shows shows the fan their own filled stars (5 and 3).', array( 5, 3 ) === $on ? 'pass' : 'finding', $reload );

$opened  = static fn( $o ) => is_array( $o ) && untrailingslashit( (string) ( $o['path'] ?? '' ) ) === untrailingslashit( $recent_path );
$anatomy = $obs['myshows-card-anatomy'] ?? null;
$record( 'my-shows-row-opens-show', 'Tapping the empty part of a show row on My Shows -> Past opens that show.', $opened( $obs['myshows-open-from-row'] ?? null ) ? 'pass' : 'finding', array( 'expected' => $recent_path, 'landed' => $obs['myshows-open-from-row'] ?? null, 'anatomy' => $anatomy ) );
$record( 'my-shows-name-opens-show', 'Tapping the show name on My Shows -> Past opens that show, not the artist page.', $opened( $obs['myshows-open-from-name'] ?? null ) ? 'pass' : 'finding', array( 'expected' => $recent_path, 'landed' => $obs['myshows-open-from-name'] ?? null ) );

$ap = $obs['artist-page-past'] ?? null;
$record( 'listings-show-no-ratings', 'The Goose artist page (past shows) shows no rating badges or stars after the fan rated.', $no_public_ratings( $ap ) ? 'pass' : 'finding', $ap );

$va = $obs['visitor-recent-after'] ?? null;
$record( 'rated-show-stays-private', 'A logged-out visitor on the show the fan rated 5 stars sees no rating.', $no_public_ratings( $va ) ? 'pass' : 'finding', $va );
$record( 'no-review-comments', 'Rating writes no comments on the event.', 0 === $review_comments ? 'pass' : 'finding', $review_comments );

$summary = array(
	'schema'       => 'extrachill-network/journey-result/show-ratings-fan/v2',
	'fixture'      => $fixture,
	'results'      => $results,
	'passed'       => count( array_filter( $results, static fn( $x ) => 'pass' === $x['outcome'] ) ),
	'findings'     => count( array_filter( $results, static fn( $x ) => 'finding' === $x['outcome'] ) ),
	'skipped'      => count( array_filter( $results, static fn( $x ) => 'skip' === $x['outcome'] ) ),
	'server'       => array( 'ratings' => $ratings, 'comments' => $review_comments ),
	'observations' => $obs,
);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $summary ) ) );
