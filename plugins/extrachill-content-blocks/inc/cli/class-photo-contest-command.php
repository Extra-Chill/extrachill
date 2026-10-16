<?php
declare(strict_types=1);
/**
 * WP-CLI command: photo contest moderation and finalists.
 *
 * Staff tooling for contest night: pull the ranked top-N entries by votes
 * (so the top 10 can go on stage) and hide/restore an entry in one action.
 * Delegates to the photo-contest business logic in
 * src/blocks/photo-contest/index.php.
 *
 * @package ExtraChillContentBlocks
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Photo contest staff commands (top-N finalists, hide/restore entries).
 */
class Extrachill_Content_Blocks_Photo_Contest_Command {

	/**
	 * Show the ranked top-N entries of a contest by vote count.
	 *
	 * ## OPTIONS
	 *
	 * --post=<id>
	 * : Contest post ID.
	 *
	 * --contest=<contest-id>
	 * : Contest block instance ID (the block's contestId attribute).
	 *
	 * [--count=<n>]
	 * : Number of entries to list.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--include-hidden]
	 * : Include admin-hidden entries in the ranking.
	 *
	 * ## EXAMPLES
	 *
	 *     # Pull the top 10 finalists for the Halloween contest.
	 *     wp extrachill-content-blocks photo-contest top --post=12345 --contest=contest-1729...
	 *
	 * @subcommand top
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public function top( $args, $assoc_args ) {
		$post_id    = absint( $assoc_args['post'] ?? 0 );
		$contest_id = (string) ( $assoc_args['contest'] ?? '' );
		$count      = max( 1, absint( $assoc_args['count'] ?? 10 ) );

		if ( ! function_exists( 'extrachill_content_blocks_photo_contest_top_entries' ) ) {
			\WP_CLI::error( 'Photo contest business logic is not loaded; activate extrachill-content-blocks first.' );
		}

		if ( 0 === $post_id || '' === $contest_id ) {
			\WP_CLI::error( 'Both --post and --contest are required.' );
		}

		if ( null === extrachill_content_blocks_photo_contest_get_settings( $post_id, $contest_id ) ) {
			\WP_CLI::error( sprintf( 'No photo-contest block with contestId "%s" found on post %d.', $contest_id, $post_id ) );
		}

		$entries = extrachill_content_blocks_photo_contest_top_entries(
			$post_id,
			$contest_id,
			$count,
			isset( $assoc_args['include-hidden'] )
		);

		if ( empty( $entries ) ) {
			\WP_CLI::warning( 'No entries found.' );
			return;
		}

		$rows = array();
		$rank = 0;
		foreach ( $entries as $entry ) {
			++$rank;
			$rows[] = array(
				'rank'      => $rank,
				'entry_id'  => $entry['id'],
				'votes'     => $entry['vote_count'],
				'name'      => $entry['display_name'],
				'instagram' => '' !== $entry['instagram'] ? '@' . $entry['instagram'] : '',
				'hidden'    => $entry['hidden'] ? 'yes' : '',
				'share_url' => $entry['share_url'],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'rank', 'entry_id', 'votes', 'name', 'instagram', 'hidden', 'share_url' ) );
	}

	/**
	 * Hide an entry from the public grid (or restore it with --unhide).
	 *
	 * Hidden entries can no longer receive votes; their historical votes stay
	 * in the database for auditability.
	 *
	 * ## OPTIONS
	 *
	 * --entry=<id>
	 * : Entry attachment ID (see `photo-contest top --include-hidden`).
	 *
	 * [--unhide]
	 * : Restore the entry instead of hiding it.
	 *
	 * ## EXAMPLES
	 *
	 *     # Pull an inappropriate entry in one action.
	 *     wp extrachill-content-blocks photo-contest hide --entry=98765
	 *
	 *     # Restore it.
	 *     wp extrachill-content-blocks photo-contest hide --entry=98765 --unhide
	 *
	 * @subcommand hide
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public function hide( $args, $assoc_args ) {
		$entry_id = absint( $assoc_args['entry'] ?? 0 );
		$hidden   = ! isset( $assoc_args['unhide'] );

		if ( ! function_exists( 'extrachill_content_blocks_photo_contest_set_entry_hidden' ) ) {
			\WP_CLI::error( 'Photo contest business logic is not loaded; activate extrachill-content-blocks first.' );
		}

		if ( 0 === $entry_id ) {
			\WP_CLI::error( '--entry is required.' );
		}

		$result = extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, $hidden );

		if ( empty( $result['success'] ) ) {
			\WP_CLI::error( (string) $result['message'] );
		}

		\WP_CLI::success( (string) $result['message'] );
	}
}
