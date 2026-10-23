<?php
/**
 * Grade the photo contest night journey from the server side.
 *
 * The browser steps record what each persona SAW; this grade records what
 * the network DID: REST answers, stored entries, and vote counts.
 *
 * Honest outcomes: a finding is a product defect, a skip is a case an
 * upstream failure left unable to judge; neither becomes a pass.
 *
 * @package ExtraChillNetwork
 */

$fixture = get_site_option( 'ec_rig_journey_fixture_photo_contest_night', array() );
if ( empty( $fixture['blog_id'] ) ) {
	throw new RuntimeException( 'Journey fixture missing; seed did not run.' );
}

$results = array();
$record  = static function ( string $id, string $task, string $outcome, array $evidence ) use ( &$results ) {
	$results[] = compact( 'id', 'task', 'outcome', 'evidence' );
};

$rest     = (array) get_site_option( 'ec_photo_contest_journey_rest', array() );
$observed = array();
foreach ( (array) get_site_option( 'ec_photo_contest_journey_observations', array() ) as $o ) {
	if ( is_array( $o ) && isset( $o['label'] ) ) {
		$observed[ $o['label'] ] = $o['value'] ?? null;
	}
}

switch_to_blog( (int) $fixture['blog_id'] );
$entries = extrachill_content_blocks_photo_contest_get_entries( (int) $fixture['page_id'], (string) $fixture['contest_id'] );
restore_current_blog();

$by_name = array();
foreach ( $entries as $e ) {
	$by_name[ $e['display_name'] ] = (int) $e['vote_count'];
}

$seed_errors = array_values( array_filter( (array) $fixture['seeded'], static fn( $s ) => isset( $s['error'] ) ) );
$record( 'seed-gallery', 'Three earlier entrants exist before the personas arrive.', empty( $seed_errors ) ? 'pass' : 'skip', array( 'seeded' => $fixture['seeded'] ) );

$submits = array_values( array_filter( $rest, static fn( $r ) => '/extrachill/v1/content-blocks/photo-contest/submit' === $r['route'] ) );
$submit  = $submits ? end( $submits ) : null;
$after   = $observed['entrant-after-submit'] ?? null;
$record(
	'entrant-submits-from-phone',
	'An entrant on a phone enters email, name, handle and a camera-roll photo, and the entry is stored.',
	( $submit && 200 === (int) $submit['status'] && isset( $by_name['Rig Phone Entrant'] ) ) ? 'pass' : 'finding',
	array( 'rest' => $submit, 'stored' => isset( $by_name['Rig Phone Entrant'] ), 'saw' => $after, 'file' => $observed['entrant-file-set'] ?? null, 'turnstile' => $observed['entrant-turnstile'] ?? null )
);
$record(
	'entrant-gets-share-tools',
	'Right after entering, the entrant sees their personal link and an in-person QR code.',
	is_array( $after ) && ! empty( $after['success'] ) && ! empty( $after['qrLoaded'] ) && ! empty( $after['shareLink'] ) ? 'pass' : ( is_array( $after ) && ! empty( $after['success'] ) ? 'finding' : 'skip' ),
	array( 'saw' => $after )
);
$landing = $observed['entrant-landing'] ?? null;
$record(
	'entrant-finds-form-fast',
	'On a phone the entry form starts above the fold.',
	is_array( $landing ) ? ( ! empty( $landing['formAboveFold'] ) ? 'pass' : 'finding' ) : 'skip',
	array( 'layout' => $landing )
);

$focused = $observed['voter-focused-state'] ?? null;
$record(
	'friend-link-focuses-one-entry',
	"A friend opening an entrant's personal link sees only that costume with a Vote button.",
	is_array( $focused ) ? ( ! empty( $focused['focused'] ) && 1 === count( (array) $focused['cards'] ) ? 'pass' : 'finding' ) : 'skip',
	array( 'pick' => $observed['voter-pick-share'] ?? null, 'saw' => $focused, 'layout' => $observed['voter-focused-landing'] ?? null )
);
$votes = array_values( array_filter( $rest, static fn( $r ) => '/extrachill/v1/content-blocks/photo-contest/vote' === $r['route'] ) );
$record(
	'friend-votes',
	'The friend votes with an email and the count lands on the entry.',
	( ( $by_name['Mothman Mike'] ?? 0 ) >= 1 ) ? 'pass' : 'finding',
	array( 'votes_rest' => $votes, 'counts' => $by_name, 'saw' => $observed['voter-after-vote'] ?? null, 'first_tap' => $observed['voter-after-first-tap'] ?? null )
);
$record(
	'no-double-vote',
	'A second tap on the same costume does not add a vote.',
	( ( $by_name['Mothman Mike'] ?? 0 ) === 1 ) ? 'pass' : 'finding',
	array( 'count' => $by_name['Mothman Mike'] ?? null, 'saw' => $observed['voter-repeat-attempt'] ?? null )
);
$second = $observed['voter-second-entry-one-tap'] ?? null;
$others = array_sum( $by_name ) - ( $by_name['Mothman Mike'] ?? 0 );
$record(
	'see-all-then-one-tap-vote',
	"From 'See all entries', the friend votes for a second costume in one tap with the remembered email.",
	$others >= 1 ? 'pass' : 'finding',
	array( 'counts' => $by_name, 'saw' => $second, 'gallery' => $observed['voter-gallery'] ?? null )
);

$summary = array(
	'schema'  => 'extrachill-network/journey-result/photo-contest-night/v1',
	'results' => $results,
	'rest'    => $rest,
	'counts'  => $by_name,
);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable result evidence.
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $summary ) ) );
