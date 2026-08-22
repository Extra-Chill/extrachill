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

	class ExtraChillDocsTestAbility {
		private $result;
		public $inputs = array();

		public function __construct( $result ) {
			$this->result = $result;
		}

		public function execute( array $input ) {
			$this->inputs[] = $input;
			return $this->result;
		}
	}

	$GLOBALS['extrachill_docs_test_actions'] = array();
	$GLOBALS['extrachill_docs_test_filters'] = array();
	$GLOBALS['extrachill_docs_test_rules']   = array();
	$GLOBALS['extrachill_docs_test_abilities'] = array();

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

	function wp_get_ability( $name ) {
		return $GLOBALS['extrachill_docs_test_abilities'][ $name ] ?? null;
	}

	require dirname( __DIR__ ) . '/inc/abilities/upsert-doc-page.php';
	require dirname( __DIR__ ) . '/inc/access/team-private-docs.php';
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

	$entry = array(
		'repo'         => 'Extra-Chill/example',
		'parent_slug'  => 'example',
		'parent_title' => 'Example',
		'docs_subpath' => 'docs/user',
		'post_status'  => 'publish',
	);
	$GLOBALS['extrachill_docs_test_abilities']['datamachine-code/list-github-tree'] = new ExtraChillDocsTestAbility( new WP_Error( 'github_unavailable', 'GitHub unavailable.' ) );
	$listing_failure = extrachill_docs_sync_one_repo( $entry, true );
	$assert( str_contains( $listing_failure['errors'][0] ?? '', 'github_unavailable: GitHub unavailable.' ), 'listing WP_Error preserves code and message' );
	$assert( 'nested_error: Nested failure.' === extrachill_docs_sync_error_message( array( 'error' => new WP_Error( 'nested_error', 'Nested failure.' ) ), 'unknown' ), 'nested WP_Error preserves code and message' );

	$listing = array(
		'success' => true,
		'files'   => array(
			array(
				'path' => 'docs/user/start.md',
				'sha'  => 'abc',
				'type' => 'blob',
			),
		),
	);
	$GLOBALS['extrachill_docs_test_abilities']['datamachine-code/list-github-tree'] = new ExtraChillDocsTestAbility( $listing );
	$GLOBALS['extrachill_docs_test_abilities']['datamachine-code/get-github-file']  = new ExtraChillDocsTestAbility( new WP_Error( 'fetch_denied', 'Fetch denied.' ) );
	$GLOBALS['extrachill_docs_test_abilities']['extrachill-docs/upsert-doc-page']   = new ExtraChillDocsTestAbility( array( 'success' => true ) );
	$fetch_failure = extrachill_docs_sync_one_repo( $entry, true );
	$assert( 'fetch_failed' === ( $fetch_failure['files'][0]['action'] ?? '' ) && 'fetch_denied: Fetch denied.' === ( $fetch_failure['files'][0]['error'] ?? '' ), 'fetch WP_Error becomes a structured file failure' );
	$GLOBALS['extrachill_docs_test_abilities']['datamachine-code/get-github-file'] = new ExtraChillDocsTestAbility(
		array(
			'success' => false,
			'errors'  => array(
				array(
					'code'    => 'github_http_error',
					'message' => 'GitHub returned 503.',
				),
			),
		)
	);
	$fetch_failure = extrachill_docs_sync_one_repo( $entry, true );
	$assert( 'github_http_error: GitHub returned 503.' === ( $fetch_failure['files'][0]['error'] ?? '' ), 'provider errors array preserves code and message' );

	$GLOBALS['extrachill_docs_test_abilities']['datamachine-code/get-github-file'] = new ExtraChillDocsTestAbility(
		array(
			'success' => true,
			'files'   => array(
				array(
					'path'    => 'docs/user/start.md',
					'content' => '# Start',
				),
			),
		)
	);
	$GLOBALS['extrachill_docs_test_abilities']['extrachill-docs/upsert-doc-page'] = new ExtraChillDocsTestAbility( new WP_Error( 'upsert_failed', 'Upsert failed.' ) );
	$upsert_failure = extrachill_docs_sync_one_repo( $entry, true );
	$assert( 'upsert_failed' === ( $upsert_failure['files'][0]['action'] ?? '' ) && 'upsert_failed: Upsert failed.' === ( $upsert_failure['files'][0]['error'] ?? '' ), 'upsert WP_Error becomes a structured file failure' );
	$GLOBALS['extrachill_docs_test_abilities']['extrachill-docs/upsert-doc-page'] = new ExtraChillDocsTestAbility(
		array(
			'success'      => false,
			'error'        => 'insert_failed',
			'error_detail' => 'Database rejected the page.',
		)
	);
	$upsert_failure = extrachill_docs_sync_one_repo( $entry, true );
	$assert( 'insert_failed; Database rejected the page.' === ( $upsert_failure['files'][0]['error'] ?? '' ), 'upsert failure preserves code and detail' );

	$upsert_ability = new ExtraChillDocsTestAbility(
		array(
			'success' => true,
			'action'  => 'created',
			'page_id' => 42,
		)
	);
	$GLOBALS['extrachill_docs_test_abilities']['extrachill-docs/upsert-doc-page'] = $upsert_ability;
	$sync_success = extrachill_docs_sync_one_repo( $entry, true );
	$assert( 'created' === ( $sync_success['files'][0]['action'] ?? '' ) && 42 === ( $sync_success['files'][0]['page_id'] ?? 0 ) && '' === ( $sync_success['files'][0]['error'] ?? '' ), 'successful upsert mapping remains unchanged' );
	$assert( 'publish' === ( $upsert_ability->inputs[0]['post_status'] ?? '' ), 'public status propagates to the upsert ability' );

	$private_entry                = $entry;
	$private_entry['post_status'] = 'private';
	$upsert_ability->inputs       = array();
	extrachill_docs_sync_one_repo( $private_entry, true );
	$assert( 'private' === ( $upsert_ability->inputs[0]['post_status'] ?? '' ), 'private status propagates to the upsert ability' );

	$platform_map = extrachill_docs_load_platform_map();
	$studio_entry = array_values( array_filter( $platform_map, static fn( array $item ): bool => 'Extra-Chill/extrachill-studio' === $item['repo'] ) );
	$assert( 'private' === ( $studio_entry[0]['post_status'] ?? '' ), 'Studio platform map entry is private' );

	extrachill_docs_add_rewrite_rules();
	$legacy_rule_index = array_search( array( '^([^/]+)/([^/]+)/?$', 'index.php?ec_doc=$matches[2]&ec_doc_platform=$matches[1]', 'top' ), $GLOBALS['extrachill_docs_test_rules'], true );
	$page_rule_index   = array_search( array( '^events\-calendar/getting\-started\-with\-my\-shows/?$', 'index.php?page_id=58', 'top' ), $GLOBALS['extrachill_docs_test_rules'], true );
	$assert( false !== $page_rule_index, 'synced pages receive exact rewrite rules' );
	$assert( false !== $legacy_rule_index && $page_rule_index < $legacy_rule_index, 'synced page rules are registered before and outrank the legacy fallback' );

	$plugin_source = file_get_contents( dirname( __DIR__ ) . '/extrachill-docs.php' );
	$assert( str_contains( $plugin_source, 'Requires Plugins: data-machine, data-machine-code' ), 'runtime dependencies are declared' );
	$assert( str_contains( $plugin_source, 'inc/access/team-private-docs.php' ), 'team private-page access is loaded' );

	if ( $failures ) {
		foreach ( $failures as $failure ) {
			fwrite( STDERR, "FAIL: {$failure}\n" );
		}
		exit( 1 );
	}

	echo "Docs sync runtime smoke tests passed.\n";
}
