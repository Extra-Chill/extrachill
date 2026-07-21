<?php
/**
 * Docs sync runtime smoke tests.
 *
 * Run with: php tests/sync-runtime-smoke.php
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
	require dirname( __DIR__ ) . '/inc/sync/sync-orchestrator.php';
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

	$markdown = '[Privacy](../privacy.md#sharing) [External](https://example.com/file.md) ![Image](diagram.md)';
	$resolved = extrachill_docs_resolve_internal_markdown_links( $markdown, 'events-calendar' );
	$assert( str_contains( $resolved, '[Privacy](https://docs.example/events-calendar/privacy/#sharing)' ), 'relative document links resolve to sibling pages' );
	$assert( str_contains( $resolved, '[External](https://example.com/file.md)' ), 'external Markdown URLs remain unchanged' );
	$assert( str_contains( $resolved, '![Image](diagram.md)' ), 'image targets remain unchanged' );

	$assert(
		in_array( array( 'init', 'extrachill_docs_schedule_sync_cron' ), $GLOBALS['extrachill_docs_test_actions'], true ),
		'existing installations repair the sync schedule on init'
	);

	$sync_source = file_get_contents( dirname( __DIR__ ) . '/inc/sync/sync-orchestrator.php' );
	$assert( str_contains( $sync_source, '[--repo=<repository>]' ), 'repo-scoped CLI synopsis is WP-CLI safe' );
	$assert( str_contains( $sync_source, "wp_get_ability( 'datamachine-code/list-github-tree' )" ), 'sync uses the current GitHub tree ability' );
	$assert( str_contains( $sync_source, "wp_get_ability( 'datamachine-code/get-github-file' )" ), 'sync uses the current GitHub file ability' );
	$assert( str_contains( $sync_source, 'PermissionHelper::run_as_authenticated' ), 'background sync establishes a bounded system context' );
	$assert( extrachill_docs_sync_created_pages( array( array( 'files' => array( array( 'action' => 'created' ) ) ) ) ), 'created pages require a rewrite flush' );
	$assert( ! extrachill_docs_sync_created_pages( array( array( 'files' => array( array( 'action' => 'unchanged' ) ) ) ) ), 'unchanged pages do not flush rewrites' );

	extrachill_docs_add_rewrite_rules();
	$legacy_rule_index = array_search( array( '^([^/]+)/([^/]+)/?$', 'index.php?ec_doc=$matches[2]&ec_doc_platform=$matches[1]', 'top' ), $GLOBALS['extrachill_docs_test_rules'], true );
	$page_rule_index   = array_search( array( '^events\-calendar/getting\-started\-with\-my\-shows/?$', 'index.php?page_id=58', 'top' ), $GLOBALS['extrachill_docs_test_rules'], true );
	$assert( false !== $page_rule_index, 'synced pages receive exact rewrite rules' );
	$assert( false !== $legacy_rule_index && $page_rule_index < $legacy_rule_index, 'synced page rules are registered before and outrank the legacy fallback' );

	$plugin_source = file_get_contents( dirname( __DIR__ ) . '/extrachill-docs.php' );
	$assert( str_contains( $plugin_source, 'Requires Plugins: data-machine, data-machine-code' ), 'runtime dependencies are declared' );

	if ( $failures ) {
		foreach ( $failures as $failure ) {
			fwrite( STDERR, "FAIL: {$failure}\n" );
		}
		exit( 1 );
	}

	echo "Docs sync runtime smoke tests passed.\n";
}
