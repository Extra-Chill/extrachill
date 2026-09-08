<?php
/**
 * Docs runtime smoke tests.
 *
 * Run with: php tests/runtime-smoke.php
 */

namespace DataMachine\Core\Content {
	class ContentFormat {
		public static $result = '';

		public static function convert( string $content, string $from, string $to, array $context = array() ) {
			unset( $content, $from, $to, $context );
			return self::$result;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'EXTRACHILL_DOCS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	class WP_Error {
		private $code;
		private $message;

		public function __construct( string $code, string $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	$GLOBALS['extrachill_docs_test_actions'] = array();
	$GLOBALS['extrachill_docs_test_filters'] = array();
	$GLOBALS['extrachill_docs_test_rules']   = array();

	function add_action( $hook, $callback ) {
		$GLOBALS['extrachill_docs_test_actions'][] = array( $hook, $callback );
	}

	function add_filter( $hook, $callback ) {
		$GLOBALS['extrachill_docs_test_filters'][] = array( $hook, $callback );
	}

	function add_rewrite_rule( $regex, $query, $position ) {
		$GLOBALS['extrachill_docs_test_rules'][] = array( $regex, $query, $position );
	}

	function get_posts() {
		return array( 58 );
	}

	function get_page_uri( $page_id ) {
		return 58 === $page_id ? 'events-calendar/getting-started-with-my-shows' : false;
	}

	function __( $text ) {
		return $text;
	}

	function sanitize_title( $value ) {
		$value = strtolower( (string) $value );
		return trim( preg_replace( '/[^a-z0-9]+/', '-', $value ), '-' );
	}

	function home_url( $path = '' ) {
		return 'https://docs.example' . $path;
	}

	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}

	require dirname( __DIR__ ) . '/inc/abilities/upsert-doc-page.php';
	require dirname( __DIR__ ) . '/inc/access/team-private-docs.php';
	require dirname( __DIR__ ) . '/inc/core/platform-map.php';
	require dirname( __DIR__ ) . '/inc/core/rewrite-rules.php';

	$failures = array();
	$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
		if ( ! $condition ) {
			$failures[] = $message;
		}
	};

	\DataMachine\Core\Content\ContentFormat::$result = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
	$converted = extrachill_docs_convert_markdown_to_blocks( '# Hello' );
	$assert( is_string( $converted ) && str_contains( $converted, '<!-- wp:paragraph -->' ), 'canonical converter result is returned' );

	$conversion_error = new WP_Error( 'conversion_failed', 'No conversion.' );
	\DataMachine\Core\Content\ContentFormat::$result = $conversion_error;
	$assert( $conversion_error === extrachill_docs_convert_markdown_to_blocks( '# Hello' ), 'converter errors are preserved' );

	$titled_markdown = "# Artist Preferences\n\nIntroduction.\n\n## Notifications\nDetails.";
	$stripped_title  = extrachill_docs_strip_title_heading_from_markdown( $titled_markdown );
	$assert( ! str_contains( $stripped_title, '# Artist Preferences' ), 'source H1 is removed from synced content' );
	$assert( str_starts_with( $stripped_title, 'Introduction.' ), 'content begins after the removed source H1' );
	$assert( str_contains( $stripped_title, '## Notifications' ), 'subsequent headings are preserved' );
	$assert( 'Artist Preferences' === extrachill_docs_extract_title_from_markdown( $titled_markdown, 'artist-preferences.md' ), 'source H1 remains the page title' );
	$assert( 'Artist Preferences' === extrachill_docs_extract_title_from_markdown( 'Introduction.', 'artist-preferences.md' ), 'title falls back to the filename without an H1' );
	$assert( 'Introduction.' === extrachill_docs_strip_title_heading_from_markdown( 'Introduction.' ), 'markdown without an H1 is unchanged' );
	$assert( 3 === EXTRACHILL_DOCS_CONTENT_TRANSFORM_VERSION, 'content transform version forces existing pages through the corrected conversion' );
	$team_caps = extrachill_docs_grant_team_private_page_access( array( 'access_studio' => true ) );
	$assert( ! empty( $team_caps['read_private_pages'] ), 'Studio access grants private docs access' );
	$public_caps = extrachill_docs_grant_team_private_page_access( array( 'read' => true ) );
	$assert( empty( $public_caps['read_private_pages'] ), 'ordinary readers do not gain private docs access' );

	$markdown = '[Privacy](../privacy.md#sharing) [External](https://example.com/file.md) ![Image](diagram.md)';
	$resolved = extrachill_docs_resolve_internal_markdown_links( $markdown, 'events-calendar' );
	$assert( str_contains( $resolved, '[Privacy](https://docs.example/events-calendar/privacy/#sharing)' ), 'relative document links resolve to sibling pages' );
	$assert( str_contains( $resolved, '[External](https://example.com/file.md)' ), 'external Markdown URLs remain unchanged' );
	$assert( str_contains( $resolved, '![Image](diagram.md)' ), 'image targets remain unchanged' );

	$platform_map = extrachill_docs_load_platform_map();
	$studio_entry = array_values( array_filter( $platform_map, static fn( array $item ): bool => 'Extra-Chill/extrachill-studio' === $item['repo'] ) );
	$assert( 'private' === ( $studio_entry[0]['post_status'] ?? '' ), 'Studio platform map entry is private' );

	extrachill_docs_add_rewrite_rules();
	$legacy_rule_index = array_search( array( '^([^/]+)/([^/]+)/?$', 'index.php?ec_doc=$matches[2]&ec_doc_platform=$matches[1]', 'top' ), $GLOBALS['extrachill_docs_test_rules'], true );
	$page_rule_index   = array_search( array( '^events\-calendar/getting\-started\-with\-my\-shows/?$', 'index.php?page_id=58', 'top' ), $GLOBALS['extrachill_docs_test_rules'], true );
	$assert( false !== $page_rule_index, 'child pages receive exact rewrite rules' );
	$assert( false !== $legacy_rule_index && $page_rule_index < $legacy_rule_index, 'child page rules are registered before and outrank the legacy fallback' );

	$assert( ! file_exists( dirname( __DIR__ ) . '/inc/sync/sync-orchestrator.php' ), 'sync orchestrator is removed' );
	$assert( ! file_exists( dirname( __DIR__ ) . '/inc/sync/synced-page-guard.php' ), 'synced page guard is removed' );

	$plugin_source = file_get_contents( dirname( __DIR__ ) . '/extrachill-docs.php' );
	$assert( str_contains( $plugin_source, 'Requires Plugins: data-machine' ), 'runtime dependency is declared' );
	$assert( ! str_contains( $plugin_source, 'data-machine-code' ), 'data-machine-code dependency is removed' );
	$assert( str_contains( $plugin_source, 'inc/access/team-private-docs.php' ), 'team private-page access is loaded' );
	$assert( str_contains( $plugin_source, 'inc/core/sync-cleanup.php' ), 'one-shot sync cleanup is loaded' );

	if ( $failures ) {
		foreach ( $failures as $failure ) {
			fwrite( STDERR, "FAIL: {$failure}\n" );
		}
		exit( 1 );
	}

	echo "Docs runtime smoke tests passed.\n";
}
