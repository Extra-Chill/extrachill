<?php
/**
 * ImportSource interface — contract for every concert-import adapter.
 *
 * The framework knows nothing about specific external services. Each adapter
 * (SetlistFmImportSource, PhishNetImportSource, ...) implements this interface
 * and is registered via the `extrachill_concert_import_sources` filter so the
 * orchestrator can discover it at runtime.
 *
 * @package ExtraChill\Users\Concert_Import
 * @since 0.13.0
 */

namespace ExtraChill\Users\Concert_Import;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter contract for an external concert-history source.
 */
interface ImportSource {

	/**
	 * Unique source slug (e.g. 'setlist-fm', 'phish-net'). Used as the
	 * primary key in the import runs table and the React UI.
	 */
	public function slug(): string;

	/**
	 * Human-readable label (e.g. 'setlist.fm').
	 */
	public function label(): string;

	/**
	 * Per-source rate limit descriptor.
	 *
	 * @return array{ requests_per_second?: float, requests_per_day?: int }
	 */
	public function rate_limit(): array;

	/**
	 * Whether the platform-side credential (API key) is configured.
	 *
	 * Used to disable the source in the UI before Chris provisions it.
	 */
	public function is_configured(): bool;

	/**
	 * Probe the source with the given username to verify it exists and
	 * return a total event count to seed the confirmation dialog.
	 *
	 * @param string $username External-platform username.
	 * @return array{ total: int, username: string }|\WP_Error
	 */
	public function preview( string $username );

	/**
	 * Fetch a single page of attended events for the given username.
	 *
	 * Implementations must:
	 *  - Treat $page as 1-indexed.
	 *  - Return up to `per_page` ExternalEvent instances in `events`.
	 *  - Set `total_pages` based on the source's metadata so the orchestrator
	 *    can decide whether to enqueue another page.
	 *  - Return WP_Error with the special code 'rate_limit' when the source's
	 *    rate limit is hit; the orchestrator backs off accordingly.
	 *
	 * @param string $username   External-platform username.
	 * @param int    $page       1-indexed page number.
	 * @return array{
	 *   events: ExternalEvent[],
	 *   total_pages: int,
	 *   total: int,
	 *   page: int,
	 * }|\WP_Error
	 */
	public function fetch_page( string $username, int $page );
}
