<?php
/**
 * Regenerate OG Card CLI Command
 *
 * Thin WP-CLI adapter over the `extrachill/regenerate-og-card` ability owned
 * by Extra Chill Network (inc/Abilities/OgCardRegenerationAbility.php). No
 * business logic lives here — argument parsing and output formatting only.
 *
 * @package ExtraChill\CLI\Commands\Network
 */

namespace ExtraChill\CLI\Commands\Network;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RegenerateOgCardCommand {

	/**
	 * Force one or more existing Open Graph cards to re-render.
	 *
	 * Delegates entirely to the `extrachill/regenerate-og-card` ability. Only
	 * touches posts that already have a card — this regenerates, it does not
	 * generate a first card for a post that has never been shared.
	 *
	 * The default path still honours the card's content signature and is a
	 * no-op when nothing has changed. Use `--force` to bypass that check —
	 * this is the reason the command exists: a rendering-only fix (not a
	 * data change) does not move the signature, so only `--force` reaches a
	 * card generated before the fix shipped.
	 *
	 * ## OPTIONS
	 *
	 * [--post-id=<id>]
	 * : Regenerate a single post by ID. Takes priority over every other selector.
	 *
	 * [--post-type=<post_type>]
	 * : Bulk: regenerate every existing card whose post is of this post type.
	 *
	 * [--on-disk]
	 * : Bulk: regenerate by scanning the og-cards cache bucket on disk directly,
	 * rather than by post meta. Use to reconcile the bucket after drift.
	 *
	 * [--blog-id=<id>]
	 * : Target blog. Defaults to the current site. With no other selector,
	 * targets every existing card on this blog across all eligible post types.
	 *
	 * [--force]
	 * : Bypass the content-signature check and re-render even when the
	 * existing card is considered current.
	 *
	 * [--dry-run]
	 * : Report what would regenerate — including the predicted new URL —
	 * without writing or deleting anything.
	 *
	 * [--limit=<limit>]
	 * : Maximum candidates processed by this call, for bulk selectors. Page
	 * through a larger set with --offset and the reported next-offset.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--offset=<offset>]
	 * : Candidate offset, for bulk selectors.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format for the per-card table.
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
	 *     # Force-regenerate one card so a shipped rendering fix reaches it.
	 *     wp extrachill network regenerate-og-card --post-id=486727 --force
	 *
	 *     # See what a forced bulk pass over every event card on this blog
	 *     # would do, without writing anything.
	 *     wp extrachill network regenerate-og-card --blog-id=7 --force --dry-run
	 *
	 *     # Actually run it, then page through the rest with --offset.
	 *     wp extrachill network regenerate-og-card --blog-id=7 --force --limit=50
	 *     wp extrachill network regenerate-og-card --blog-id=7 --force --limit=50 --offset=50
	 *
	 *     # Reconcile the on-disk cache bucket directly.
	 *     wp extrachill network regenerate-og-card --on-disk --force
	 *
	 * ## NOTES
	 *
	 * Run this with the site context that matches the target post type's
	 * owning plugin (e.g. `--url=events.extrachill.com` for event cards) so
	 * the card's data collector has full access to that plugin's functions.
	 * The ability accepts --blog-id for convenience, but full data fidelity
	 * for post types owned by a site-only-active plugin depends on the
	 * invoking site context, not just which blog's posts are queried.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/regenerate-og-card' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/regenerate-og-card ability not found. Is extrachill-network active and >= 2.17.0?' );
		}

		$input = array(
			'force'   => isset( $assoc_args['force'] ),
			'dry_run' => isset( $assoc_args['dry-run'] ),
			'limit'   => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 25,
			'offset'  => isset( $assoc_args['offset'] ) ? (int) $assoc_args['offset'] : 0,
		);

		if ( isset( $assoc_args['post-id'] ) ) {
			$input['post_id'] = (int) $assoc_args['post-id'];
		}
		if ( isset( $assoc_args['post-type'] ) ) {
			$input['post_type'] = (string) $assoc_args['post-type'];
		}
		if ( isset( $assoc_args['on-disk'] ) ) {
			$input['on_disk'] = true;
		}
		if ( isset( $assoc_args['blog-id'] ) ) {
			$input['blog_id'] = (int) $assoc_args['blog-id'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$format = $assoc_args['format'] ?? 'table';

		$this->render( $result, $format );
	}

	/**
	 * Render the ability's response.
	 *
	 * @param array  $result Ability output.
	 * @param string $format Output format.
	 */
	private function render( array $result, string $format ): void {
		if ( 'table' === $format ) {
			$label = $result['dry_run'] ? 'DRY RUN — nothing was written' : 'Regenerate OG Card';
			WP_CLI::log( sprintf( '%s (mode: %s, blog: %d, force: %s)', $label, $result['mode'], $result['blog_id'], $result['force'] ? 'yes' : 'no' ) );
			WP_CLI::log( '' );
		}

		if ( ! empty( $result['cards'] ) ) {
			$rows = array();
			foreach ( $result['cards'] as $card ) {
				$rows[] = array(
					'post_id' => $card['post_id'] ?? 0,
					'type'    => $card['post_type'] ?? '',
					'status'  => $this->cardStatus( $card ),
					'old_url' => $card['old_url'] ?? '',
					'new_url' => $card['new_url'] ?? '',
				);
			}
			Utils\format_items( $format, $rows, array( 'post_id', 'type', 'status', 'old_url', 'new_url' ) );
		}

		if ( ! empty( $result['errors'] ) && 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::warning( sprintf( '%d error(s):', count( $result['errors'] ) ) );
			foreach ( $result['errors'] as $error ) {
				WP_CLI::log( sprintf( '  - post #%d: %s', $error['post_id'] ?? 0, $error['error'] ?? '' ) );
			}
		}

		if ( 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::log(
				sprintf(
					'Requested: %d  Regenerated: %d  Reused: %d  Skipped (ineligible): %d  Errors: %d',
					$result['requested'] ?? 0,
					$result['regenerated'] ?? 0,
					$result['reused'] ?? 0,
					$result['skipped_ineligible'] ?? 0,
					count( $result['errors'] ?? array() )
				)
			);

			if ( ! empty( $result['has_more'] ) ) {
				WP_CLI::log( sprintf( 'More candidates remain — resume with --offset=%d', $result['next_offset'] ) );
			}
		}
	}

	/**
	 * Human-readable status for a single card row.
	 *
	 * @param array $card Card entry from the ability response.
	 * @return string
	 */
	private function cardStatus( array $card ): string {
		if ( ! empty( $card['skipped'] ) ) {
			return 'skipped (' . ( $card['reason'] ?? 'unknown' ) . ')';
		}
		if ( ! empty( $card['regenerated'] ) ) {
			return 'regenerated';
		}
		if ( ! empty( $card['reused'] ) ) {
			return 'reused';
		}
		return 'unknown';
	}
}
