<?php
/**
 * Artist Coverage CLI Commands
 *
 * Thin adapter over the extrachill-events artist coverage abilities, which
 * keep the Ticketmaster "Artists We Cover" import in sync with the artists
 * the publication writes about.
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Manage which acts the Ticketmaster artist import follows. */
class ArtistCoverageCommand {

	/**
	 * Run an Events-owned ability, erroring on failure.
	 *
	 * @param string $ability_name Ability name.
	 * @param array  $input        Input.
	 * @return array
	 */
	private function run( $ability_name, array $input = array() ) {
		$ability = OwnerSiteAbility::get( 'events', 'Events', $ability_name );
		$result  = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		return is_array( $result ) ? $result : array();
	}

	/**
	 * List covered artists and their Ticketmaster matches.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Include artists with no match or no upcoming shows.
	 *
	 * [--format=<format>]
	 * : Output format.
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
	 *     wp extrachill events artist-coverage list --url=events.extrachill.com
	 *
	 * @subcommand list
	 * @when after_wp_load
	 */
	public function list_( $args, $assoc_args ) {
		$result  = $this->run( 'extrachill/get-artist-coverage' );
		$config  = (array) ( $result['config'] ?? array() );
		$artists = (array) ( $result['artists'] ?? array() );

		if ( ! Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			$artists = array_values( array_filter( $artists, static fn( $a ) => '' !== $a['attraction_id'] && $a['upcoming'] > 0 ) );
		}

		foreach ( (array) ( $config['manual'] ?? array() ) as $id => $name ) {
			array_unshift(
				$artists,
				array(
					'term_id'         => 0,
					'name'            => $name . ' (added)',
					'attraction_id'   => $id,
					'attraction_name' => $name,
					'upcoming'        => '',
					'excluded'        => false,
					'checked'         => 0,
				)
			);
		}

		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		Utils\format_items( $format, $artists, array( 'name', 'attraction_name', 'attraction_id', 'upcoming', 'excluded' ) );
		WP_CLI::log( sprintf( 'Flow step: %s | min posts: %d | IDs written: %d', $config['flow_step_id'] ?? '', (int) ( $config['min_posts'] ?? 0 ), count( (array) ( $result['attraction_ids'] ?? array() ) ) ) );
	}

	/**
	 * Add an act to the import by name.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Act name; must exactly match a Ticketmaster attraction.
	 *
	 * [--attraction-id=<id>]
	 * : Explicit Ticketmaster attraction ID (skips the name lookup).
	 *
	 * [--sync]
	 * : Write the flow immediately.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events artist-coverage add "Goose" --sync --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function add( $args, $assoc_args ) {
		$input = array( 'name' => (string) $args[0] );
		if ( ! empty( $assoc_args['attraction-id'] ) ) {
			$input['attraction_id'] = (string) $assoc_args['attraction-id'];
		}
		$result = $this->run( 'extrachill/add-artist-coverage', $input );
		WP_CLI::success( sprintf( 'Added %s (%s).', $result['name'] ?? '', $result['attraction_id'] ?? '' ) );
		$this->maybe_sync( $assoc_args );
	}

	/**
	 * Exclude an act from the import (e.g. a different act sharing the name).
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Covered artist name, added act name, or attraction ID.
	 *
	 * [--sync]
	 * : Write the flow immediately.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events artist-coverage exclude "Damn Skippy" --sync --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function exclude( $args, $assoc_args ) {
		$result = $this->run( 'extrachill/exclude-artist-coverage', array( 'name' => (string) $args[0] ) );
		WP_CLI::success( sprintf( 'Excluded %s.', $result['attraction_id'] ?? '' ) );
		$this->maybe_sync( $assoc_args );
	}

	/**
	 * Match covered artists to Ticketmaster and write the flow.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Max Ticketmaster lookups this run.
	 * ---
	 * default: 40
	 * ---
	 *
	 * [--dry-run]
	 * : Report without saving matches or writing the flow.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events artist-coverage sync --limit=400 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function sync( $args, $assoc_args ) {
		$result = $this->run(
			'extrachill/sync-artist-coverage',
			array(
				'dry_run' => (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false ),
				'limit'   => (int) ( $assoc_args['limit'] ?? 40 ),
			)
		);

		foreach ( (array) ( $result['errors'] ?? array() ) as $error ) {
			WP_CLI::warning( (string) $error );
		}
		unset( $result['errors'] );
		Utils\format_items( 'table', array( $result ), array_keys( $result ) );
	}

	/**
	 * Configure the target flow step and thresholds.
	 *
	 * ## OPTIONS
	 *
	 * [--flow-step=<flow_step_id>]
	 * : Ticketmaster import flow step to maintain.
	 *
	 * [--min-posts=<n>]
	 * : Published main-site posts an artist needs to be covered.
	 *
	 * [--max-ids=<n>]
	 * : Cap on attraction IDs written.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events artist-coverage configure --flow-step=228_..._896 --url=events.extrachill.com
	 *
	 * @when after_wp_load
	 */
	public function configure( $args, $assoc_args ) {
		$input = array();
		if ( isset( $assoc_args['flow-step'] ) ) {
			$input['flow_step_id'] = (string) $assoc_args['flow-step'];
		}
		if ( isset( $assoc_args['min-posts'] ) ) {
			$input['min_posts'] = (int) $assoc_args['min-posts'];
		}
		if ( isset( $assoc_args['max-ids'] ) ) {
			$input['max_ids'] = (int) $assoc_args['max-ids'];
		}

		$result = $this->run( 'extrachill/configure-artist-coverage', $input );
		WP_CLI::success( sprintf( 'Flow step %s, min posts %d, max IDs %d.', $result['flow_step_id'] ?? '', (int) ( $result['min_posts'] ?? 0 ), (int) ( $result['max_ids'] ?? 0 ) ) );
	}

	/**
	 * Run a no-lookup sync when --sync is passed.
	 *
	 * @param array $assoc_args Flags.
	 */
	private function maybe_sync( array $assoc_args ) {
		if ( Utils\get_flag_value( $assoc_args, 'sync', false ) ) {
			$this->sync( array(), array( 'limit' => 0 ) );
		}
	}
}
