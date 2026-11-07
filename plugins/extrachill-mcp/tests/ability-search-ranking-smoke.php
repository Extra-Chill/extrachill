<?php
/**
 * Standalone regression coverage for network ability search ranking.
 *
 * Run: php plugins/extrachill-mcp/tests/ability-search-ranking-smoke.php
 *
 * @package ExtraChillMcp
 */

declare( strict_types = 1 );

use ExtraChillMcp\Abilities\NetworkAbilitySearch;

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../src/Abilities/NetworkAbilitySearch.php';

/** Fail independently of PHP's assert configuration. */
function check_search( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test without WordPress.
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

/* Keep fixture variables out of the WordPress global namespace. */
( function (): void {
	$records = array(
		array(
			'name'        => 'extrachill/update-artist',
			'label'       => 'Update Artist',
			'description' => 'Edit an artist profile.',
		),
		array(
			'name'        => 'extrachill/save-link-page-links',
			'label'       => 'Save Link Page Links',
			'description' => 'Save links for an artist.',
		),
		array(
			'name'        => 'extrachill/save-link-page-settings',
			'label'       => 'Save Link Page Settings',
			'description' => 'Save settings for an artist.',
		),
		array(
			'name'        => 'extrachill/save-link-page-styles',
			'label'       => 'Save Link Page Styles',
			'description' => 'Save styles for an artist.',
		),
		array(
			'name'        => 'extrachill/create-artist-link-page',
			'label'       => 'Create Artist Link Page',
			'description' => 'Create an artist link page.',
		),
		array(
			'name'        => 'extrachill/get-artist-platform-stats',
			'description' => 'Statistics for create artist profile link page activity.',
		),
		array(
			'name'        => 'extrachill/list-orders',
			'description' => 'Read shop orders.',
		),
		array(
			'name'        => 'extrachill/list-forums',
			'description' => 'Read community forums.',
		),
	);
	$by_name = array_column( $records, null, 'name' );
	$query   = 'create artist profile link page';
	$entries = NetworkAbilitySearch::match_records( $records, $query );
	$ranked  = NetworkAbilitySearch::rank( $entries, $query, 1, $by_name );
	$names   = array_column( $ranked, 'name' );
	$top     = array_slice( $names, 0, 6 );
	foreach ( array_slice( $records, 0, 5 ) as $record ) {
		check_search( in_array( $record['name'], $top, true ), 'Missing top result: ' . $record['name'] );
	}
	check_search( 'extrachill/create-artist-link-page' === $names[0], 'More name hits should rank first.' );
	check_search( ! in_array( 'extrachill/list-orders', $names, true ), 'Unrelated records must not match.' );
	check_search( array_search( 'extrachill/save-link-page-links', $names, true ) < array_search( 'extrachill/get-artist-platform-stats', $names, true ), 'Name hits should outweigh description hits.' );

	foreach ( array( '+link', $query . ' +link' ) as $required_query ) {
		$matches = NetworkAbilitySearch::match_records( $records, $required_query );
		check_search( 5 === count( $matches ), 'Required link should retain exactly five records.' );
		check_search( ! in_array( 'extrachill/update-artist', array_column( $matches, 'name' ), true ), '+link must exclude update-artist.' );
	}
	check_search( array() === NetworkAbilitySearch::match_records( $records, 'artist +missing' ), 'Every required term must match.' );
	check_search( array() === NetworkAbilitySearch::match_records( $records, 'missing +link' ), 'A required hit must not replace a plain-term hit.' );
	check_search( count( $records ) === count( NetworkAbilitySearch::match_records( $records, '' ) ), 'Empty query should retain every record.' );
	foreach ( array( 'select:extrachill/update-artist', 'missing +link select:extrachill/update-artist' ) as $selected_query ) {
		check_search( array( 'extrachill/update-artist' ) === array_column( NetworkAbilitySearch::match_records( $records, $selected_query ), 'name' ), 'select: must pick exactly the requested ability.' );
	}

	/* Local and remote candidates use the same matcher and weighted ranking. */
	foreach ( array( 'local', 'remote' ) as $site ) {
		$site_entries = $entries;
		foreach ( $site_entries as &$entry ) {
			$entry['site'] = $site;
		}
		unset( $entry );
		check_search( array_column( NetworkAbilitySearch::rank( NetworkAbilitySearch::dedupe( $site_entries ), $query, 1, $by_name ), 'name' ) === $names, 'Ranking must not depend on the source site.' );
	}

	/* Label and category are searchable, including case-insensitive terms. */
	$fields = array(
		array(
			'name'  => 'fixture/one',
			'label' => 'Artist',
		),
		array(
			'name'     => 'fixture/two',
			'category' => 'artist',
		),
	);
	check_search( 2 === count( NetworkAbilitySearch::match_records( $fields, 'ARTIST missing' ) ), 'Label and category should support any-match queries.' );
	check_search( 2 === count( NetworkAbilitySearch::match_records( $fields, '+ARTIST' ) ), 'Required terms should match label and category case-insensitively.' );

	/* #1223: hyphenated and natural queries reach network-wide tools like web-fetch. */
	$all_sites = array( 'auth', 'main', 'community', 'shop', 'artist', 'events', 'newsletter', 'docs', 'wire', 'studio', 'link_pages' );
	$web       = array(
		array(
			'name'        => 'datamachine/web-fetch',
			'label'       => 'Web Fetch',
			'description' => 'Fetch a public web page (http/https) and return its title and readable text. Use it to read an article, show listing, episode page or profile you have a link for.',
			'sites'       => $all_sites,
		),
		array(
			'name'        => 'data-machine-events/backfill-canonical-ticket-urls',
			'label'       => 'Backfill Canonical Ticket URLs',
			'description' => 'Backfill canonical ticket URLs for events.',
			'sites'       => array( 'events' ),
		),
		array(
			'name'        => 'data-machine-events/resync-ticket-urls',
			'label'       => 'Resync Ticket URLs',
			'description' => 'Resync ticket URLs from sources.',
			'sites'       => array( 'events' ),
		),
		array(
			'name'        => 'datamachine/fetch-reddit',
			'label'       => 'Fetch Reddit',
			'description' => 'Fetch posts from a subreddit.',
			'sites'       => $all_sites,
		),
		array(
			'name'        => 'datamachine/facebook-read',
			'label'       => 'Facebook Read',
			'description' => 'Read posts from the connected Facebook Page.',
			'sites'       => array( 'main' ),
		),
		array(
			'name'        => 'data-machine-events/get-calendar-page',
			'label'       => 'Get Calendar Page',
			'description' => 'Render one page of the events calendar.',
			'sites'       => array( 'events' ),
		),
		array(
			'name'        => 'datamachine/webhook-trigger-enable',
			'label'       => 'Enable Webhook Trigger',
			'description' => 'Enable webhook trigger for a flow.',
			'sites'       => $all_sites,
		),
	);
	$web_by_name = array_column( $web, null, 'name' );
	$web_rank    = static function ( string $web_query ) use ( $web, $web_by_name, $all_sites ): array {
		$matched = NetworkAbilitySearch::match_records( $web, $web_query );
		foreach ( $matched as &$match ) {
			$match['sites'] = $web_by_name[ $match['name'] ]['sites'];
		}
		unset( $match );
		return array_column( NetworkAbilitySearch::rank( $matched, $web_query, count( $all_sites ), $web_by_name ), 'name' );
	};
	foreach ( array( 'fetch-url' => 1, 'fetch url' => 1, 'web' => 1, 'read a web page' => 3, 'open link' => 3 ) as $web_query => $within ) {
		$position = array_search( 'datamachine/web-fetch', $web_rank( $web_query ), true );
		check_search( false !== $position && $position < $within, "web-fetch must rank in the top {$within} for '{$web_query}'." );
	}

	/* Term expansion: split on separators, drop stopwords, keep the joined token as a non-counting group. */
	$groups = NetworkAbilitySearch::expand_terms( array( 'fetch-url', 'a', 'the' ) );
	check_search( array( 'fetch-url', 'fetch', 'url' ) === array_map( static fn( $g ) => array_key_first( $g['alternates'] ), $groups ), 'Hyphenated terms split and stopwords drop.' );
	check_search( false === $groups[0]['counts'] && true === $groups[1]['counts'], 'Only split words count toward coverage.' );
	check_search( isset( $groups[2]['alternates']['web'] ) && $groups[2]['alternates']['web'] < 1.0, 'Synonyms carry a reduced weight.' );
	check_search( array() !== NetworkAbilitySearch::match_records( $web, 'fetch-url' ), 'Hyphenated query must match records.' );

	echo "Ability search ranking tests passed.\n";
} )();
