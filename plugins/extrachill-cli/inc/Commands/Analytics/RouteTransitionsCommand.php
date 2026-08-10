<?php
/**
 * Analytics Route Transitions CLI Command
 *
 * Thin presenter for the extrachill/get-route-transitions ability.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RouteTransitionsCommand {

	/**
	 * Show first-party same-session route transitions.
	 *
	 * Reports adjacent route transitions, session entries and terminals, and
	 * exact-length ordered sequences from identified, bot-filtered pageviews.
	 * Route identity is the owning ability's (blog_id, route_family) pair.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : UTC lookback days. Accepted range: 1-90.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--blog-id=<id>]
	 * : Filter to one blog ID. Zero reports the network.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--session-gap-mins=<mins>]
	 * : Inactivity gap that ends a session. Accepted range: 1-120.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--sequence-length=<length>]
	 * : Exact number of route observations per sequence. Accepted range: 2-5.
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--cohort=<cohort>]
	 * : Session-entry acquisition cohort.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - first_time
	 *   - returning
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Maximum rows in each ranking. Accepted range: 1-100.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--max-pageviews=<count>]
	 * : Maximum identified pageviews loaded. Accepted range: 100-25000.
	 * ---
	 * default: 10000
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format. JSON and CSV preserve the complete ability envelope.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill analytics route-transitions
	 *     wp extrachill analytics route-transitions --cohort=first_time
	 *     wp extrachill analytics route-transitions --sequence-length=5 --limit=50
	 *     wp extrachill analytics route-transitions --blog-id=7 --format=json
	 *
	 * ## NOTES
	 *
	 * This command delegates sessionization, acquisition cohorts, ranking, and
	 * coverage semantics to extrachill/get-route-transitions. It takes no acting
	 * user context; omit the global `--user` flag.
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-route-transitions' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-route-transitions ability not found. Is extrachill-analytics active?' );
		}

		$input  = array(
			'days'             => (int) ( $assoc_args['days'] ?? 28 ),
			'blog_id'          => (int) ( $assoc_args['blog-id'] ?? 0 ),
			'session_gap_mins' => (int) ( $assoc_args['session-gap-mins'] ?? 30 ),
			'sequence_length'  => (int) ( $assoc_args['sequence-length'] ?? 3 ),
			'cohort'           => (string) ( $assoc_args['cohort'] ?? 'all' ),
			'limit'            => (int) ( $assoc_args['limit'] ?? 25 ),
			'max_pageviews'    => (int) ( $assoc_args['max-pageviews'] ?? 10000 ),
		);
		$format = $assoc_args['format'] ?? 'table';
		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( 'json' === $format ) {
			WP_CLI::print_value( $result, array( 'format' => 'json' ) );
			return;
		}

		if ( 'csv' === $format ) {
			Utils\format_items( 'csv', array( $this->csv_row( $result ) ), array_map( 'strval', array_keys( $result ) ) );
			return;
		}

		$this->display_table( $result );
	}

	/**
	 * Render the human report without recalculating ability metrics.
	 *
	 * @param array $result Ability result.
	 * @return void
	 */
	private function display_table( array $result ) {
		$bounds   = (array) ( $result['bounds'] ?? array() );
		$period   = (array) ( $result['period'] ?? array() );
		$counts   = (array) ( $result['counts'] ?? array() );
		$coverage = (array) ( $result['coverage'] ?? array() );

		WP_CLI::log( 'First-Party Route Transitions' );
		WP_CLI::log(
			sprintf(
				'Window (UTC): %s to %s; rankings since %s',
				$period['since'] ?? 'unavailable',
				$period['until'] ?? 'unavailable',
				$period['ranking_since'] ?? 'unavailable'
			)
		);
		WP_CLI::log(
			sprintf(
				'Scope: blog %s; cohort %s; session gap %sm; sequence length %s; row limit %s; pageview cap %s',
				0 === (int) ( $bounds['blog_id'] ?? 0 ) ? 'network' : (string) $bounds['blog_id'],
				$bounds['cohort'] ?? 'all',
				$bounds['session_gap_mins'] ?? 'unavailable',
				$bounds['sequence_length'] ?? 'unavailable',
				$bounds['limit'] ?? 'unavailable',
				$bounds['max_pageviews'] ?? 'unavailable'
			)
		);
		WP_CLI::log( str_repeat( '-', 72 ) );

		$this->display_coverage( $coverage, $counts );
		$this->display_rankings( $result );

		if ( ! empty( $result['note'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( $result['note'] );
		}
	}

	/**
	 * Render identity, route collection, and admitted-session coverage.
	 *
	 * @param array $coverage Coverage envelope.
	 * @param array $counts   Session and transition counts.
	 * @return void
	 */
	private function display_coverage( array $coverage, array $counts ) {
		$total      = (int) ( $coverage['total_pageviews'] ?? 0 );
		$identified = (int) ( $coverage['identified_pageviews'] ?? 0 );
		$rate       = $total > 0 ? $this->pct( $coverage['identity_coverage_rate'] ?? 0 ) : 'n/a';

		WP_CLI::log( 'First-party identity and session coverage:' );
		WP_CLI::log(
			sprintf(
				'Pageviews: %s total; %s identified; %s anonymous; identity coverage %s%s',
				$this->count( $total ),
				$this->count( $identified ),
				$this->count( $coverage['anonymous_pageviews'] ?? 0 ),
				$rate,
				'n/a' === $rate ? ' (no pageviews in window)' : '%'
			)
		);
		WP_CLI::log(
			sprintf(
				'Rankings: %s identified pageviews loaded; complete-window rankings: %s',
				$this->count( $coverage['loaded_identified_pageviews'] ?? 0 ),
				! empty( $coverage['truncated'] ) ? 'no (bounded recent sample)' : 'yes'
			)
		);
		WP_CLI::log(
			sprintf(
				'Sessions: %s included; %s first-time; %s returning; %s one-page direct terminals',
				$this->count( $counts['sessions'] ?? 0 ),
				$this->count( $counts['first_time_sessions'] ?? 0 ),
				$this->count( $counts['returning_sessions'] ?? 0 ),
				$this->count( $counts['direct_terminal_sessions'] ?? 0 )
			)
		);

		$rows = array(
			array(
				'classification' => 'explicit route family',
				'pageviews'      => $this->count( $coverage['explicit_route_family_pageviews'] ?? 0 ),
			),
			array(
				'classification' => 'historical inferred singular',
				'pageviews'      => $this->count( $coverage['inferred_singular_pageviews'] ?? 0 ),
			),
			array(
				'classification' => 'historical unclassified',
				'pageviews'      => $this->count( $coverage['historical_unclassified_pageviews'] ?? 0 ),
			),
		);
		Utils\format_items( 'table', $rows, array( 'classification', 'pageviews' ) );
		WP_CLI::log( '' );
	}

	/**
	 * Render all four ability rankings.
	 *
	 * @param array $result Ability result.
	 * @return void
	 */
	private function display_rankings( array $result ) {
		$transition_rows = array_map( array( $this, 'transition_row' ), (array) ( $result['transitions'] ?? array() ) );
		$this->display_section(
			'Transitions:',
			$transition_rows,
			array( 'from_blog', 'from_route', 'to_blog', 'to_route', 'surface', 'count' ),
			'no same-session transitions available for this scope'
		);

		$entry_rows = array_map( array( $this, 'route_row' ), (array) ( $result['entries'] ?? array() ) );
		$this->display_section( 'Session entries:', $entry_rows, array( 'blog_id', 'route_family', 'count' ), 'no session entries available for this scope' );

		$terminal_rows = array_map( array( $this, 'route_row' ), (array) ( $result['terminals'] ?? array() ) );
		$this->display_section( 'Session terminals:', $terminal_rows, array( 'blog_id', 'route_family', 'count' ), 'no session terminals available for this scope' );

		$sequence_rows = array_map( array( $this, 'sequence_row' ), (array) ( $result['sequences'] ?? array() ) );
		$this->display_section( 'Ordered sequences:', $sequence_rows, array( 'path', 'count' ), 'no complete ordered sequences available for this scope and sequence length' );
	}

	/**
	 * Render one ranking or its honest empty state.
	 *
	 * @param string $label   Section label.
	 * @param array  $rows    Display rows.
	 * @param array  $columns Display columns.
	 * @param string $empty_state Empty-state explanation.
	 * @return void
	 */
	private function display_section( $label, array $rows, array $columns, $empty_state ) {
		WP_CLI::log( $label );
		if ( empty( $rows ) ) {
			WP_CLI::log( "  ({$empty_state})" );
			WP_CLI::log( '' );
			return;
		}

		Utils\format_items( 'table', $rows, $columns );
		WP_CLI::log( '' );
	}

	/**
	 * Build one transition display row.
	 *
	 * @param array $row Ability transition row.
	 * @return array<string,string>
	 */
	private function transition_row( $row ) {
		$from = (array) ( $row['from'] ?? array() );
		$to   = (array) ( $row['to'] ?? array() );

		return array(
			'from_blog'  => (string) ( $from['blog_id'] ?? '' ),
			'from_route' => (string) ( $from['route_family'] ?? '' ),
			'to_blog'    => (string) ( $to['blog_id'] ?? '' ),
			'to_route'   => (string) ( $to['route_family'] ?? '' ),
			'surface'    => ! empty( $row['same_surface'] ) ? 'same' : 'cross',
			'count'      => $this->count( $row['count'] ?? 0 ),
		);
	}

	/**
	 * Build one entry or terminal display row.
	 *
	 * @param array $row Ability route row.
	 * @return array<string,string>
	 */
	private function route_row( $row ) {
		$route = (array) ( $row['route'] ?? array() );

		return array(
			'blog_id'      => (string) ( $route['blog_id'] ?? '' ),
			'route_family' => (string) ( $route['route_family'] ?? '' ),
			'count'        => $this->count( $row['count'] ?? 0 ),
		);
	}

	/**
	 * Build one ordered sequence display row.
	 *
	 * @param array $row Ability sequence row.
	 * @return array<string,string>
	 */
	private function sequence_row( $row ) {
		$nodes = array_map(
			static function ( $node ) {
				return (int) ( $node['blog_id'] ?? 0 ) . ':' . (string) ( $node['route_family'] ?? '' );
			},
			(array) ( $row['path'] ?? array() )
		);

		return array(
			'path'  => implode( ' -> ', $nodes ),
			'count' => $this->count( $row['count'] ?? 0 ),
		);
	}

	/**
	 * Preserve the complete response envelope in one deterministic CSV row.
	 *
	 * @param array $result Ability result.
	 * @return array<string,mixed>
	 */
	private function csv_row( array $result ) {
		foreach ( $result as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$result[ $key ] = wp_json_encode( $value );
			}
		}

		return $result;
	}

	/**
	 * Format a count for human output.
	 *
	 * @param mixed $value Count.
	 * @return string
	 */
	private function count( $value ) {
		return number_format( (int) $value );
	}

	/**
	 * Format a 0..1 rate as a percentage without its suffix.
	 *
	 * @param mixed $value Rate.
	 * @return string
	 */
	private function pct( $value ) {
		return number_format( (float) $value * 100, 1 );
	}
}
