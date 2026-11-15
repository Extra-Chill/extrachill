<?php
/**
 * `wp extrachill events locations reconcile-roots`
 *
 * Dry-run-first repair for qualified root location terms. Thin adapter over
 * RootLocationReconciler (extrachill-events).
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use ExtraChillEvents\Core\RootLocationReconciler;

defined( 'ABSPATH' ) || exit;

/** Reports and optionally repairs exact qualified root location duplicates. */
final class ReconcileRootLocationsCommand {

	/**
	 * Report or reconcile safe "City, State/Province" root terms.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Move relationships, create and verify redirects, and delete safe duplicates. Requires an administrator user context. Default is dry-run.
	 *
	 * [--format=<format>]
	 * : Output format: table, json, or csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events locations reconcile-roots --url=events.extrachill.com
	 *     wp extrachill events locations reconcile-roots --url=events.extrachill.com --user=<administrator-login-or-id> --apply
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		OwnerSiteAbility::require_service( 'events', 'Events', RootLocationReconciler::class );

		if ( ! taxonomy_exists( 'location' ) ) {
			\WP_CLI::error( 'The location taxonomy is not registered. Use --url=events.extrachill.com.' );
		}

		$reconciler = new RootLocationReconciler();
		$apply      = ! empty( $assoc_args['apply'] );
		if ( $apply ) {
			$ready = $reconciler->assert_apply_ready();
			if ( is_wp_error( $ready ) ) {
				\WP_CLI::error( $ready->get_error_message() );
			}
		}

		$rows = $reconciler->reconcile( $apply );
		if ( is_wp_error( $rows ) ) {
			\WP_CLI::error( $rows->get_error_message() );
			return;
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'candidate_id', 'candidate', 'canonical_id', 'canonical', 'relationships', 'status', 'reason' )
		);

		if ( ! $apply ) {
			\WP_CLI::log( 'Dry run: no redirects, relationships, or terms changed. Re-run with --apply only after reviewing every row.' );
		}
	}
}
