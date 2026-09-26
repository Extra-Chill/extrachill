<?php
/**
 * WP-CLI command for the stranded-submission detector (#110, #210).
 *
 * Registers `wp extrachill-studio detect-strandings`, which scans EVERY
 * non-main live site on the network for `post` entries that look like
 * stranded editorial content and prints them, plus the #107 guard-rejection
 * counter.
 *
 * @package    ExtraChillStudio
 * @subpackage Compose
 * @since      0.20.1
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * Detect editorial submissions stranded on any non-main network site.
 *
 * @since 0.20.1
 */
class EC_Studio_Strand_Detector_CLI {

	/**
	 * Scan every non-main live site for candidate stranded editorial submissions.
	 *
	 * Editorial blog posts are meant to be born on main (blog 1); a `post` on
	 * ANY other site with substantial content/images by a team member — and no
	 * social-draft meta — is a candidate stranding. Originally this command
	 * only inspected the Studio subsite (#110); since #210 it walks the whole
	 * network, because wp-admin-created posts on community/events/wire/etc.
	 * strand just as surely as a mis-routed compose write on Studio.
	 *
	 * ## OPTIONS
	 *
	 * [--min-content-len=<chars>]
	 * : Minimum body-text length (characters) to treat a post as substantial.
	 * ---
	 * default: 200
	 * ---
	 *
	 * [--limit=<count>]
	 * : Maximum number of posts to inspect PER SITE.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--format=<format>]
	 * : Render format for the candidate list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Report candidate strandings across the network.
	 *     $ wp extrachill-studio detect-strandings --url=studio.extrachill.com
	 *
	 *     # Machine-readable output for a scheduled check.
	 *     $ wp extrachill-studio detect-strandings --url=studio.extrachill.com --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$min_len = isset( $assoc_args['min-content-len'] ) ? (int) $assoc_args['min-content-len'] : EC_STUDIO_STRAND_MIN_CONTENT_LEN;
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 500;
		$format  = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$candidates = ec_studio_detect_network_strandings(
			array(
				'min_content_len' => $min_len,
				'limit'           => $limit,
			)
		);

		// Surface the guard-rejection counter first — a recurring count is a
		// live routing miss, distinct from posts already stranded on disk.
		$this->report_guard_rejections( $format );

		if ( empty( $candidates ) ) {
			if ( in_array( $format, array( 'json', 'csv', 'yaml' ), true ) ) {
				\WP_CLI\Utils\format_items(
					$format,
					array(),
					array( 'blog_id', 'site_name', 'id', 'title', 'author', 'author_id', 'date', 'status', 'reason' )
				);
			} elseif ( 'count' === $format ) {
				\WP_CLI::line( '0' );
			} else {
				\WP_CLI::success( 'No candidate strandings found on any network site.' );
			}
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$candidates,
			array( 'blog_id', 'site_name', 'id', 'title', 'author', 'author_id', 'date', 'status', 'reason' )
		);

		if ( ! in_array( $format, array( 'json', 'csv', 'yaml', 'count' ), true ) ) {
			\WP_CLI::warning(
				sprintf(
					'%d candidate stranding(s) found across the network. Each is a `post` that looks like editorial content but is not on main — review and, if confirmed, recover via the extrachill-multisite migration primitive.',
					count( $candidates )
				)
			);
		}
	}

	/**
	 * Print the #107 guard-rejection observability record.
	 *
	 * @param string $format Active output format (suppressed for machine formats).
	 * @return void
	 */
	private function report_guard_rejections( string $format ) {
		// Keep machine-readable output clean — the candidate list is the payload.
		if ( in_array( $format, array( 'json', 'csv', 'yaml', 'count' ), true ) ) {
			return;
		}

		$record = ec_studio_get_guard_rejection_record();

		if ( empty( $record ) || empty( $record['count'] ) ) {
			\WP_CLI::log( 'Guard rejections (born-on-main #107): none recorded — no compose-marked local write has been blocked.' );
			return;
		}

		\WP_CLI::log(
			sprintf(
				'Guard rejections (born-on-main #107): %d blocked. First %s, last %s (user %d, %s %s). A recurring count means the client-side rewrite is systematically missing — investigate.',
				(int) $record['count'],
				isset( $record['first_seen'] ) ? (string) $record['first_seen'] : '?',
				isset( $record['last_seen'] ) ? (string) $record['last_seen'] : '?',
				isset( $record['last_user_id'] ) ? (int) $record['last_user_id'] : 0,
				isset( $record['last_method'] ) ? (string) $record['last_method'] : '?',
				isset( $record['last_route'] ) ? (string) $record['last_route'] : '?'
			)
		);
	}
}

WP_CLI::add_command( 'extrachill-studio detect-strandings', 'EC_Studio_Strand_Detector_CLI' );
