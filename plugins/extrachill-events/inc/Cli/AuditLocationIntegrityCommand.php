<?php
/**
 * Read-only location taxonomy integrity audit.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use ExtraChillEvents\Core\LocationIntegrityAuditor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AuditLocationIntegrityCommand {

	/**
	 * Report root-level city overlaps and duplicate canonical city names.
	 *
	 * This command never changes terms or relationships. Findings require
	 * operator review because same-named cities in different states can be valid.
	 *
	 * ## OPTIONS
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
	 *     wp extrachill events locations audit-integrity --url=events.extrachill.com
	 *     wp extrachill events locations audit-integrity --format=json --url=events.extrachill.com
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		if ( ! taxonomy_exists( 'location' ) ) {
			\WP_CLI::error( 'The location taxonomy is not registered on this site. Run with --url=events.extrachill.com.' );
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'location',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			\WP_CLI::error( $terms->get_error_message() );
			return;
		}

		$rows = array_map(
			static function ( \WP_Term $term ): array {
				return array(
					'term_id' => (int) $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
					'parent'  => (int) $term->parent,
					'count'   => (int) $term->count,
				);
			},
			$terms
		);

		$findings = LocationIntegrityAuditor::audit( $rows );
		if ( empty( $findings ) ) {
			\WP_CLI::success( 'No location hierarchy or canonical city overlaps found.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$findings,
			array( 'issue', 'reason', 'candidate_id', 'candidate_name', 'candidate_slug', 'candidate_count', 'canonical_id', 'canonical_name', 'canonical_slug', 'canonical_count' )
		);
		\WP_CLI::warning( sprintf( '%d review candidate(s) found. This audit is read-only; no terms were changed.', count( $findings ) ) );
	}
}
