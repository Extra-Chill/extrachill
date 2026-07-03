<?php
/**
 * WP-CLI command for the stranded-submission detector (#110).
 *
 * Registers `wp extrachill-studio detect-strandings`, which scans the Studio
 * subsite (blog 12) for `post` entries that look like stranded editorial
 * content and prints them, plus the #107 guard-rejection counter.
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
 * Detect editorial submissions stranded on the Studio subsite.
 *
 * @since 0.20.1
 */
class EC_Studio_Strand_Detector_CLI {

	/**
	 * Scan the Studio subsite for candidate stranded editorial submissions.
	 *
	 * Editorial blog posts are meant to be born on main (blog 1); a `post` on
	 * the Studio subsite (blog 12) with substantial content/images by a team
	 * member — and no social-draft meta — is a candidate stranding. Running
	 * this against blog 12 would have surfaced the Steve Hughes submission
	 * (post 88) instead of it being found by accident.
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
	 * : Maximum number of Studio-subsite posts to inspect.
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
	 *     # Report candidate strandings on the Studio subsite.
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
		$this->assert_on_studio_subsite();

		$min_len = isset( $assoc_args['min-content-len'] ) ? (int) $assoc_args['min-content-len'] : EC_STUDIO_STRAND_MIN_CONTENT_LEN;
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 500;
		$format  = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$candidates = ec_studio_detect_strandings(
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
				\WP_CLI\Utils\format_items( $format, array(), array( 'id', 'title', 'author', 'author_id', 'date', 'status', 'reason' ) );
			} elseif ( 'count' === $format ) {
				\WP_CLI::line( '0' );
			} else {
				\WP_CLI::success( 'No candidate strandings found on the Studio subsite.' );
			}
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$candidates,
			array( 'id', 'title', 'author', 'author_id', 'date', 'status', 'reason' )
		);

		if ( ! in_array( $format, array( 'json', 'csv', 'yaml', 'count' ), true ) ) {
			\WP_CLI::warning(
				sprintf(
					'%d candidate stranding(s) found on the Studio subsite. Each is a `post` that looks like editorial content but is not on main — review and, if confirmed, recover via the extrachill-multisite migration primitive.',
					count( $candidates )
				)
			);
		}
	}

	/**
	 * Fail fast unless we're running against the Studio subsite.
	 *
	 * The detector only makes sense on blog 12; running it on main would scan
	 * legitimate editorial posts and flag everything. When the multisite helper
	 * is available we assert the current blog IS the Studio subsite.
	 *
	 * @return void
	 */
	private function assert_on_studio_subsite() {
		if ( ! function_exists( 'ec_get_blog_id' ) ) {
			// Can't resolve — warn but proceed, so the command still works on a
			// single-site/dev install.
			\WP_CLI::warning( 'extrachill-multisite not available; cannot confirm this is the Studio subsite. Proceeding against the current site.' );
			return;
		}

		$studio_blog_id  = (int) ec_get_blog_id( 'studio' );
		$current_blog_id = (int) get_current_blog_id();

		if ( $studio_blog_id > 0 && $current_blog_id !== $studio_blog_id ) {
			\WP_CLI::error(
				sprintf(
					'This scan must run against the Studio subsite (blog %d), but the current site is blog %d. Re-run with --url=studio.extrachill.com',
					$studio_blog_id,
					$current_blog_id
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
