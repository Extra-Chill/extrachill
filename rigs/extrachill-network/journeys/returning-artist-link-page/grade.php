<?php
/**
 * Grade the returning-artist Link Page journey.
 *
 * Every path back to the editor is a case, judged by the server-side marker
 * its browser step records only after all its assertions pass. Persisted
 * state checks that returning never duplicated the artist or the page and
 * that the edit saved. Regression contract as in the musician journey:
 * known findings are pinned to issues; the journeys workflow fails on an
 * unpinned finding or a pinned one that passes.
 *
 * @package ExtraChillNetwork
 */

$fixture = get_site_option( 'ec_rig_journey_fixture_returning_artist', array() );
if ( empty( $fixture['user_id'] ) ) {
	throw new RuntimeException( 'Returning-artist fixture missing; seed did not run.' );
}
$user_id = (int) $fixture['user_id'];
$reached = (array) get_site_option( 'ec_rig_returning_artist_reached', array() );
$results = array();

/**
 * Record a graded case.
 *
 * @param array  $results  Results (by reference).
 * @param string $id       Case ID.
 * @param string $task     Plain-language expectation.
 * @param bool   $passed   Outcome.
 * @param array  $evidence Evidence.
 */
function returning_artist_record( array &$results, string $id, string $task, bool $passed, array $evidence = array() ): void {
	$results[] = array(
		'id'       => $id,
		'task'     => $task,
		'outcome'  => $passed ? 'pass' : 'finding',
		'evidence' => $evidence,
	);
}

$paths = array(
	'setup_first_session'                   => 'Setup: a new artist creates their page and saves a first link.',
	'returns_from_blog_account_menu'        => 'From the blog, "My Link Page" in the account menu opens the editor with their existing links.',
	'returns_from_community_account_menu'   => 'From the community, "My Link Page" in the account menu opens the editor with their existing links.',
	'returns_from_blog_mobile_account_menu' => 'On a phone, "My Link Page" in the account menu opens the editor with their existing links.',
	'returns_from_artist_dashboard'         => 'From the artist dashboard, "Manage Link Page" opens the editor with their existing links.',
	'returns_from_own_public_page'          => 'On their own public Link Page, the owner edit pencil appears and opens the editor.',
	'edits_and_sees_change_live'            => 'An edit saves and appears on the public Link Page.',
	'analytics_knows_the_page'              => 'Analytics recognises the Link Page instead of saying to create one.',
);
foreach ( $paths as $path_id => $path_task ) {
	returning_artist_record( $results, str_replace( '_', '-', $path_id ), $path_task, isset( $reached[ $path_id ] ) );
}

// Persisted state: returning never duplicated anything, and the edit stuck.
$artist_ids = ec_get_artists_for_user( $user_id );
$artist_ids = is_array( $artist_ids ) ? array_values( array_map( 'intval', $artist_ids ) ) : array();
$one_artist = 1 === count( $artist_ids );
returning_artist_record( $results, 'exactly-one-artist', 'Coming back never creates a second artist profile.', $one_artist, array( 'artist_profile_ids' => $artist_ids ) );

$link_pages = array();
$links_json = '';
if ( 1 === count( $artist_ids ) && function_exists( 'ec_with_link_page_storage_blog' ) ) {
	$artist_blog_id = (int) $fixture['artist_blog_id'];
	$link_pages     = ec_with_link_page_storage_blog(
		static function () use ( $artist_ids, $artist_blog_id ) {
			$found = array();
			foreach ( get_posts(
				array(
					'post_type'      => ec_link_page_post_type(),
					'post_status'    => 'any',
					'posts_per_page' => 20,
					'fields'         => 'ids',
				)
			) as $link_page_id ) {
				$owner = function_exists( 'ec_get_link_page_owner' ) ? ec_get_link_page_owner( $link_page_id ) : null;
				if ( is_array( $owner ) && 'post' === $owner['kind'] && (int) $owner['blog_id'] === $artist_blog_id && (int) $owner['object_id'] === $artist_ids[0] ) {
					$found[ $link_page_id ] = wp_json_encode( get_post_meta( $link_page_id, '_link_page_links', true ) );
				}
			}
			return $found;
		}
	);
	$link_pages     = is_array( $link_pages ) ? $link_pages : array();
	$links_json     = (string) reset( $link_pages );
}
$one_page  = 1 === count( $link_pages );
$edit_kept = false !== strpos( $links_json, 'Tour Dates 2027' );
returning_artist_record( $results, 'exactly-one-link-page', 'Coming back never creates a second Link Page for the artist.', $one_page, array( 'link_page_ids' => array_keys( $link_pages ) ) );
returning_artist_record( $results, 'edit-persisted', 'The edited link title is saved.', $edit_kept, array( 'links' => $links_json ) );

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
	'schema'      => 'extrachill-network/journey-result/returning-artist-link-page/v1',
	'results'     => $results,
	'regressions' => $regressions,
	'unpin'       => $unpin,
	'reached'     => $reached,
);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Machine-readable journey result.
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( (string) wp_json_encode( $summary ) ) );
