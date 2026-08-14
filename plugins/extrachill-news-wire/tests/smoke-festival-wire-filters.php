<?php
/**
 * Focused regression coverage for Festival Wire archive filters.
 *
 * Run with: php tests/smoke-festival-wire-filters.php
 */

$template = file_get_contents( dirname( __DIR__ ) . '/templates/archive-festival_wire.php' );
$script   = file_get_contents( dirname( __DIR__ ) . '/assets/festival-wire.js' );

function expect_filter( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

expect_filter( false !== strpos( $template, '<form class="festival-filter-controls"' ), 'Filters must use a form for no-JS submission.' );
expect_filter( false !== strpos( $template, 'method="get"' ), 'The filter form must preserve query-parameter behavior.' );

foreach ( array( 'festival', 'location' ) as $filter ) {
	expect_filter( false !== strpos( $template, 'for="' . $filter . '-filter"' ), ucfirst( $filter ) . ' select must have a visible label.' );
	expect_filter( false !== strpos( $template, 'id="' . $filter . '-filter" name="' . $filter . '"' ), ucfirst( $filter ) . ' select must submit its established query parameter.' );
	expect_filter( false !== strpos( $template, 'for="' . $filter . '-search"' ), ucfirst( $filter ) . ' search must have a visible label.' );
	expect_filter( false !== strpos( $template, 'id="' . $filter . '-search" class="filter-search" type="search"' ), ucfirst( $filter ) . ' must expose a search input.' );
	expect_filter( false !== strpos( $template, 'aria-controls="' . $filter . '-filter"' ), ucfirst( $filter ) . ' search must identify the select it filters.' );
	expect_filter( false !== strpos( $template, 'id="' . $filter . '-search-status"' ), ucfirst( $filter ) . ' search must announce its result count.' );
	expect_filter( false !== strpos( $script, "makeSelectSearchable({$filter}Search, {$filter}Select" ), ucfirst( $filter ) . ' search must initialize.' );
}

expect_filter( false !== strpos( $template, "selected( \$current_festival, \$festival['slug'], false )" ), 'Festival preselection must render server-side.' );
expect_filter( false !== strpos( $template, 'selected( $current_location, $location->slug, false )' ), 'Location preselection must render server-side.' );
expect_filter( false !== strpos( $script, 'select.replaceChildren();' ), 'Search must narrow the native option list.' );
expect_filter( false === strpos( $script, 'window.location.href' ), 'JavaScript must not replace native form navigation.' );

print "Festival Wire filter accessibility smoke test passed.\n";
