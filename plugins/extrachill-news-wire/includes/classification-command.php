<?php
/**
 * Festival Wire classification maintenance command.
 *
 * @package ExtraChillNewsWire
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Festival Wire classification maintenance. */
class EC_News_Wire_Classification_Command {
	/**
	 * Audit classifications. Mutations require --apply.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Reassign or detach reported Festival Wire terms. Default is dry-run.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function audit( $args, $assoc_args ) {
		$apply    = isset( $assoc_args['apply'] );
		$findings = ec_news_wire_audit_classifications( $apply );

		if ( empty( $findings ) ) {
			WP_CLI::success( 'No malformed Festival Wire classifications found.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $findings, array( 'taxonomy', 'term_id', 'name', 'action', 'target', 'posts', 'reason', 'result' ) );
		$partial = array_filter(
			$findings,
			function ( $finding ) {
				return 0 === strpos( $finding['result'], 'partial' );
			}
		);
		if ( ! empty( $partial ) ) {
			WP_CLI::error( sprintf( '%s with partial failures in %d of %d finding(s).', $apply ? 'Completed' : 'Audit incomplete', count( $partial ), count( $findings ) ) );
		}
		WP_CLI::success( sprintf( '%s %d classification finding(s).', $apply ? 'Processed' : 'Dry-run found', count( $findings ) ) );
	}
}

WP_CLI::add_command( 'extrachill-news-wire classifications', 'EC_News_Wire_Classification_Command' );
