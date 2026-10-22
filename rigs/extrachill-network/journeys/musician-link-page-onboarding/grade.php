<?php
/**
 * Grade the musician link-page onboarding journey from the server side.
 *
 * Browser steps record what the musician SAW; this grade records what the
 * network actually PERSISTED after they walked it: did the live join funnel
 * create an account and route it through onboarding, did the fixture
 * musician end up with an artist profile and a provisioned Link Page, did
 * the color and newsletter edits survive, and did the fan's inline
 * subscription land. Honest outcomes: a finding is a product defect, a skip
 * is a runtime that could not fairly judge the case; neither becomes a pass.
 *
 * @package ExtraChillNetwork
 */

$fixture = get_site_option( 'ec_rig_journey_fixture_musician_link_page_onboarding', array() );
if ( empty( $fixture['artist_blog_id'] ) ) {
	throw new RuntimeException( 'Journey fixture missing; seed did not run.' );
}

$artist_blog_id = (int) $fixture['artist_blog_id'];
$link_blog_id   = (int) $fixture['link_blog_id'];
$musician_id    = (int) $fixture['musician_user_id'];

$results = array();

/**
 * Map a boolean check to a graded outcome.
 *
 * @param bool $passed Whether the case passed.
 * @return string
 */
function musician_journey_outcome( $passed ) {
	return $passed ? 'pass' : 'finding';
}

/**
 * Record one graded case.
 *
 * @param array  $results  Result list (by reference).
 * @param string $id       Case id.
 * @param string $task     Plain-language expectation.
 * @param string $outcome  pass|finding|skip.
 * @param array  $evidence Evidence.
 */
function musician_journey_record( array &$results, string $id, string $task, string $outcome, array $evidence ): void {
	$results[] = array(
		'id'       => $id,
		'task'     => $task,
		'outcome'  => $outcome,
		'evidence' => $evidence,
	);
}

// ---------------------------------------------------------------------------
// Live join funnel (extrachill.link/join -> register -> onboarding).
// ---------------------------------------------------------------------------
$live_user = get_user_by( 'email', 'porch-lights-live@example.test' );
musician_journey_record(
	$results,
	'join-link-registration-creates-account',
	'A musician who clicks extrachill.link/join and registers ends up with an account.',
	$live_user ? 'pass' : 'finding',
	array( 'user_id' => $live_user ? (int) $live_user->ID : 0 )
);
if ( $live_user ) {
	musician_journey_record(
		$results,
		'join-registration-remembers-join-context',
		'The account created via /join is marked as a join-flow signup so onboarding routes it to the artist tools.',
		musician_journey_outcome( '1' === get_user_meta( $live_user->ID, 'onboarding_from_join', true ) ),
		array( 'onboarding_from_join' => get_user_meta( $live_user->ID, 'onboarding_from_join', true ) )
	);
	musician_journey_record(
		$results,
		'join-onboarding-marks-artist',
		'Finishing onboarding with "I am a musician" checked leaves the account able to create an artist profile.',
		musician_journey_outcome( '1' === get_user_meta( $live_user->ID, 'user_is_artist', true ) ),
		array(
			'user_is_artist'       => get_user_meta( $live_user->ID, 'user_is_artist', true ),
			'onboarding_completed' => get_user_meta( $live_user->ID, 'onboarding_completed', true ),
			'blogs'                => array_map( 'intval', array_keys( get_blogs_of_user( $live_user->ID ) ) ),
		)
	);
	$live_artists = get_user_meta( $live_user->ID, '_artist_profile_ids', true );
	musician_journey_record(
		$results,
		'join-funnel-reaches-artist-profile',
		'The live join funnel ends with the musician holding an artist profile.',
		! empty( $live_artists ) ? 'pass' : 'finding',
		array( 'artist_profile_ids' => $live_artists )
	);
}

// ---------------------------------------------------------------------------
// Venue and promoter doors: /join must not push them into an artist profile.
// ---------------------------------------------------------------------------
foreach ( array(
	'venue'    => 'porch-venue-live@example.test',
	'promoter' => 'porch-promoter-live@example.test',
) as $intent_id => $email ) {
	$member = get_user_by( 'email', $email );
	$ids    = $member ? get_user_meta( $member->ID, '_artist_profile_ids', true ) : array();
	musician_journey_record(
		$results,
		'join-' . $intent_id . '-not-routed-to-artist',
		'A ' . $intent_id . ' joining via /join is recorded as such and is not made to create an artist profile.',
		musician_journey_outcome( $member && get_user_meta( $member->ID, 'onboarding_join_intent', true ) === $intent_id && empty( $ids ) && '1' === get_user_meta( $member->ID, 'user_is_professional', true ) ),
		array(
			'user_id'            => $member ? (int) $member->ID : 0,
			'join_intent'        => $member ? get_user_meta( $member->ID, 'onboarding_join_intent', true ) : null,
			'user_is_artist'     => $member ? get_user_meta( $member->ID, 'user_is_artist', true ) : null,
			'artist_profile_ids' => $ids,
		)
	);
}

// ---------------------------------------------------------------------------
// Fixture musician: artist profile + Link Page + edits.
// ---------------------------------------------------------------------------
$artist_ids = get_user_meta( $musician_id, '_artist_profile_ids', true );
$artist_ids = is_array( $artist_ids ) ? array_map( 'intval', $artist_ids ) : array();
musician_journey_record(
	$results,
	'create-artist-creates-profile',
	'Submitting the Create Artist Profile form creates an artist profile the musician manages.',
	$artist_ids ? 'pass' : 'finding',
	array( 'artist_profile_ids' => $artist_ids )
);

$artist_id   = $artist_ids ? (int) end( $artist_ids ) : 0;
$artist_post = null;
if ( $artist_id ) {
	switch_to_blog( $artist_blog_id );
	$artist_post = get_post( $artist_id );
	restore_current_blog();
}

$link_page_id = 0;
$link_meta    = array();
if ( $artist_id && function_exists( 'ec_get_link_page_id_for_owner' ) ) {
	$owned        = ec_get_link_page_id_for_owner( 'post:' . $artist_blog_id . ':artist_profile:' . $artist_id );
	$link_page_id = is_wp_error( $owned ) ? 0 : (int) $owned;
}
switch_to_blog( $link_blog_id );
$link_post_type      = function_exists( 'ec_link_page_post_type' ) ? ec_link_page_post_type( $link_blog_id ) : 'artist_link_page';
$link_pages          = get_posts(
	array(
		'post_type'      => $link_post_type,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'DESC',
	)
);
$link_page_inventory = array();
$owned_pages         = array();
foreach ( $link_pages as $link_page_post ) {
	$link_page_inventory[] = array(
		'id'     => (int) $link_page_post->ID,
		'slug'   => $link_page_post->post_name,
		'title'  => $link_page_post->post_title,
		'status' => $link_page_post->post_status,
		'owner'  => get_post_meta( $link_page_post->ID, '_link_page_owner', true ),
	);
	$owner                 = ec_get_link_page_owner( $link_page_post->ID );
	if ( is_array( $owner ) && 'post' === $owner['kind'] && (int) $owner['blog_id'] === $artist_blog_id && (int) $owner['object_id'] === $artist_id ) {
		$owned_pages[ $link_page_post->ID ] = $link_page_post->post_status;
	}
	if ( ! $link_page_id && $artist_post && ( $link_page_post->post_name === $artist_post->post_name || $link_page_post->post_title === $artist_post->post_title ) ) {
		$link_page_id = (int) $link_page_post->ID;
	}
}
if ( $link_page_id ) {
	$link_meta = array(
		'css_vars'               => get_post_meta( $link_page_id, '_link_page_custom_css_vars', true ),
		'subscribe_display_mode' => get_post_meta( $link_page_id, '_link_page_subscribe_display_mode', true ),
		'subscribe_description'  => get_post_meta( $link_page_id, '_link_page_subscribe_description', true ),
		'links'                  => get_post_meta( $link_page_id, '_link_page_links', true ),
	);
}
restore_current_blog();

$before_create = get_site_option( 'musician_journey_before_create_link_page', array() );
musician_journey_record(
	$results,
	'link-page-created-only-after-cta',
	'Creating an artist leaves no Link Page; clicking Create Link Page creates exactly one published page.',
	! $artist_id ? 'skip' : musician_journey_outcome(
		(int) ( $before_create['artist_id'] ?? 0 ) === $artist_id
		&& array() === ( $before_create['page_ids'] ?? null )
		&& 1 === count( $owned_pages )
		&& 'publish' === reset( $owned_pages )
	),
	array(
		'artist_id'  => $artist_id,
		'before_cta' => $before_create,
		'after_cta'  => $owned_pages,
	)
);
musician_journey_record(
	$results,
	'artist-gets-link-page',
	'The artist\'s Link Page is stored on extrachill.link.',
	$link_page_id ? 'pass' : ( $artist_id ? 'finding' : 'skip' ),
	array(
		'artist_id'      => $artist_id,
		'artist_slug'    => $artist_post ? $artist_post->post_name : null,
		'link_page_id'   => $link_page_id,
		'link_post_type' => $link_post_type,
		'inventory'      => $link_page_inventory,
	)
);

$css_vars = is_array( $link_meta['css_vars'] ?? null ) ? $link_meta['css_vars'] : array();
musician_journey_record(
	$results,
	'color-changes-persist',
	'The background and button colors the musician picked are saved.',
	! $link_page_id ? 'skip' : ( ( strtolower( (string) ( $css_vars['--link-page-background-color'] ?? '' ) ) === '#1d3557' && strtolower( (string) ( $css_vars['--link-page-button-bg-color'] ?? '' ) ) === '#e63946' ) ? 'pass' : 'finding' ),
	array( 'css_vars' => $css_vars )
);
musician_journey_record(
	$results,
	'newsletter-settings-persist',
	'The inline subscribe form and the custom subscribe description are saved.',
	! $link_page_id ? 'skip' : ( ( 'inline_form' === ( $link_meta['subscribe_display_mode'] ?? '' ) && false !== strpos( (string) ( $link_meta['subscribe_description'] ?? '' ), 'Porch Lights' ) ) ? 'pass' : 'finding' ),
	array(
		'subscribe_display_mode' => $link_meta['subscribe_display_mode'] ?? null,
		'subscribe_description'  => $link_meta['subscribe_description'] ?? null,
	)
);
$links_json = wp_json_encode( $link_meta['links'] ?? array() );
musician_journey_record(
	$results,
	'added-link-persists',
	'The Bandcamp link the musician added is saved.',
	! $link_page_id ? 'skip' : ( false !== strpos( (string) $links_json, 'porchlights.bandcamp.com' ) ? 'pass' : 'finding' ),
	array( 'links' => $link_meta['links'] ?? null )
);

// Fan subscription via the inline form.
$subscribers = array();
if ( $artist_id ) {
	global $wpdb;
	switch_to_blog( $artist_blog_id );
	$table = $wpdb->prefix . 'artist_subscribers';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$subscribers = $wpdb->get_results( $wpdb->prepare( "SELECT subscriber_email, source FROM {$table} WHERE artist_profile_id = %d", $artist_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	} else {
		$subscribers = array( 'table_missing' => $table );
	}
	restore_current_blog();
}
$fan_found = false;
foreach ( (array) $subscribers as $row ) {
	if ( is_array( $row ) && 'fan-of-porch-lights@example.test' === ( $row['subscriber_email'] ?? '' ) ) {
		$fan_found = true;
	}
}
musician_journey_record(
	$results,
	'fan-inline-subscription-lands',
	'A fan who types their email into the inline subscribe form shows up in the artist subscriber list.',
	! $artist_id ? 'skip' : ( $fan_found ? 'pass' : 'finding' ),
	array( 'subscribers' => $subscribers )
);

/*
 * Regression contract. Every open finding is pinned to the issue that owns
 * it. The journeys workflow fails when:
 *  - a case produces a finding that is NOT pinned (a new regression), or
 *  - a pinned case now passes (the fix landed: remove the pin so the case
 *    guards against the bug coming back).
 * Skips never fail; they mean an upstream case could not set the stage.
 */
$known_findings = array();
$regressions    = array();
$unpin          = array();
foreach ( $results as &$result ) {
	$result['pinned_issue'] = $known_findings[ $result['id'] ] ?? null;
	if ( 'finding' === $result['outcome'] && null === $result['pinned_issue'] ) {
		$regressions[] = $result['id'];
	}
	if ( 'pass' === $result['outcome'] && null !== $result['pinned_issue'] ) {
		$unpin[] = $result['id'] . ' (' . $result['pinned_issue'] . ')';
	}
}
unset( $result );

$summary = array(
	'schema'      => 'extrachill-network/journey-result/musician-link-page-onboarding/v1',
	'fixture'     => $fixture,
	'results'     => $results,
	'regressions' => $regressions,
	'unpin'       => $unpin,
	'passed'      => count( array_filter( $results, static fn( $r ) => 'pass' === $r['outcome'] ) ),
	'findings'    => count( array_filter( $results, static fn( $r ) => 'finding' === $r['outcome'] ) ),
	'skipped'     => count( array_filter( $results, static fn( $r ) => 'skip' === $r['outcome'] ) ),
	'redirects'   => get_site_option( 'musician_journey_redirects', array() ),
	'handoff'     => get_site_option( 'musician_journey_handoff_diag', array() ),
	'observed'    => get_site_option( 'musician_journey_observations', array() ),
	'fatals'      => get_site_option( 'musician_journey_fatals', array() ),
	'trace'       => get_site_option( 'musician_journey_render_trace', array() ),
	'auth_rest'   => get_site_option( 'musician_journey_auth_rest', array() ),
	'rest_diag'   => get_site_option( 'musician_journey_rest_diag', array() ),
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable journey result.
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $summary ) ) );

// Pass/fail is decided by the caller (.github/workflows/journeys.yml) from
// 'regressions' and 'unpin': throwing here would discard this result.
