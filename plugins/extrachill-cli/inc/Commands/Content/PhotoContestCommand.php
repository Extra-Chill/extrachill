<?php
/**
 * Photo Contest CLI Commands
 *
 * Thin WP-CLI adapter over the extrachill/photo-contest-top,
 * extrachill/photo-contest-hide, and extrachill/photo-contest-list abilities
 * (registered by extrachill-content-blocks). No business logic lives here —
 * argument parsing and output formatting only. The abilities own ranking,
 * moderation, and persistence.
 *
 * The top and hide abilities require a user who can edit_others_posts; run
 * with the global --user flag (e.g. --user=<staff-login>) so the ability
 * permission checks pass.
 *
 * @package ExtraChill\CLI\Commands\Content
 */

namespace ExtraChill\CLI\Commands\Content;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PhotoContestCommand {

	/**
	 * Show the ranked top-N entries of a contest by vote count.
	 *
	 * Thin wrapper over the extrachill/photo-contest-top ability. Staff
	 * tooling for contest night: pull the ranked finalists (e.g. the top 10
	 * who go on stage). Requires a user with edit_others_posts — pass the
	 * global --user flag.
	 *
	 * ## OPTIONS
	 *
	 * --post=<id>
	 * : Contest post ID.
	 *
	 * --contest=<contest_id>
	 * : Contest block instance ID (the block's contestId attribute).
	 *
	 * [--limit=<n>]
	 * : Number of entries to list.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--include-hidden]
	 * : Include admin-hidden entries in the ranking.
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
	 *     # Pull the top 10 finalists for the Halloween contest.
	 *     wp extrachill content photo-contest top --post=12345 --contest=contest-1729 --user=admin
	 *
	 *     # Export the top 25 (including hidden entries) as CSV.
	 *     wp extrachill content photo-contest top --post=12345 --contest=contest-1729 --limit=25 --include-hidden --format=csv --user=admin
	 *
	 * @subcommand top
	 * @when after_wp_load
	 */
	public function top( $args, $assoc_args ) {
		$input = array(
			'post_id'    => isset( $assoc_args['post'] ) ? (int) $assoc_args['post'] : 0,
			'contest_id' => (string) ( $assoc_args['contest'] ?? '' ),
			'count'      => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 10,
		);

		if ( isset( $assoc_args['include-hidden'] ) ) {
			$input['include_hidden'] = true;
		}

		$result = $this->execute_ability( 'extrachill/photo-contest-top', $input );

		$entries = isset( $result['entries'] ) && is_array( $result['entries'] ) ? $result['entries'] : array();

		if ( empty( $entries ) ) {
			WP_CLI::warning( 'No entries found.' );
			return;
		}

		$rows = array();
		$rank = 0;
		foreach ( $entries as $entry ) {
			++$rank;
			$rows[] = array( 'rank' => $rank ) + $this->entry_row( $entry );
		}

		WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$rows,
			array( 'rank', 'entry_id', 'votes', 'name', 'instagram', 'hidden', 'share_url' )
		);
	}

	/**
	 * Hide an entry from the public grid (or restore it with --unhide).
	 *
	 * Thin wrapper over the extrachill/photo-contest-hide ability. Hidden
	 * entries can no longer receive votes; their historical votes stay in the
	 * database for auditability. Requires a user with edit_others_posts —
	 * pass the global --user flag.
	 *
	 * ## OPTIONS
	 *
	 * <entry_id>
	 * : Entry attachment ID (see `photo-contest top --include-hidden`).
	 *
	 * [--unhide]
	 * : Restore the entry instead of hiding it.
	 *
	 * ## EXAMPLES
	 *
	 *     # Pull an inappropriate entry in one action.
	 *     wp extrachill content photo-contest hide 98765 --user=admin
	 *
	 *     # Restore it.
	 *     wp extrachill content photo-contest hide 98765 --unhide --user=admin
	 *
	 * @subcommand hide
	 * @when after_wp_load
	 */
	public function hide( $args, $assoc_args ) {
		$entry_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $entry_id <= 0 ) {
			WP_CLI::error( 'A valid entry ID is required.' );
		}

		$result = $this->execute_ability(
			'extrachill/photo-contest-hide',
			array(
				'entry_id' => $entry_id,
				'hidden'   => ! isset( $assoc_args['unhide'] ),
			)
		);

		WP_CLI::success( (string) ( $result['message'] ?? 'Done.' ) );
	}

	/**
	 * List the visible entries of a contest with live vote counts.
	 *
	 * Thin wrapper over the extrachill/photo-contest-list ability. Entries
	 * are returned in insertion order. --include-hidden is honoured only for
	 * users who can moderate the contest (edit_others_posts) — pass the
	 * global --user flag.
	 *
	 * ## OPTIONS
	 *
	 * --post=<id>
	 * : Contest post ID.
	 *
	 * --contest=<contest_id>
	 * : Contest block instance ID (the block's contestId attribute).
	 *
	 * [--include-hidden]
	 * : Include admin-hidden entries (moderators only; ignored otherwise).
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
	 *     wp extrachill content photo-contest list --post=12345 --contest=contest-1729
	 *     wp extrachill content photo-contest list --post=12345 --contest=contest-1729 --include-hidden --format=json --user=admin
	 *
	 * @subcommand list
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$input = array(
			'post_id'    => isset( $assoc_args['post'] ) ? (int) $assoc_args['post'] : 0,
			'contest_id' => (string) ( $assoc_args['contest'] ?? '' ),
		);

		if ( isset( $assoc_args['include-hidden'] ) ) {
			$input['include_hidden'] = true;
		}

		$result = $this->execute_ability( 'extrachill/photo-contest-list', $input );

		$entries = isset( $result['entries'] ) && is_array( $result['entries'] ) ? $result['entries'] : array();

		if ( empty( $entries ) ) {
			WP_CLI::warning( 'No entries found.' );
			return;
		}

		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[] = $this->entry_row( $entry );
		}

		WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$rows,
			array( 'entry_id', 'votes', 'name', 'instagram', 'hidden', 'share_url' )
		);
	}

	/**
	 * Resolve an ability, execute it, and surface WP_Errors as WP_CLI::error.
	 *
	 * @param string $ability_name Ability name.
	 * @param array  $input        Ability input.
	 * @return array Ability result.
	 */
	private function execute_ability( $ability_name, array $input ) {
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			WP_CLI::error( sprintf( '%s ability not available — is extrachill-content-blocks active?', $ability_name ) );
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Map an ability entry object to a display row.
	 *
	 * @param array $entry Entry from the ability result.
	 * @return array<string, mixed>
	 */
	private function entry_row( $entry ) {
		$instagram = (string) ( $entry['instagram'] ?? '' );

		return array(
			'entry_id'  => $entry['id'] ?? '',
			'votes'     => $entry['vote_count'] ?? 0,
			'name'      => $entry['display_name'] ?? '',
			'instagram' => '' !== $instagram ? '@' . $instagram : '',
			'hidden'    => ! empty( $entry['hidden'] ) ? 'yes' : '',
			'share_url' => $entry['share_url'] ?? '',
		);
	}
}
