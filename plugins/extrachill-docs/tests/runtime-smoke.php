<?php
/**
 * Docs runtime smoke tests.
 *
 * Run with: php tests/runtime-smoke.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'OBJECT', 'OBJECT' );
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

	class WP_Post {
		public $ID;
		public $post_title;
		public $post_name;

		public function __construct( int $id, string $title = '', string $slug = '' ) {
			$this->ID         = $id;
			$this->post_title = $title;
			$this->post_name  = $slug;
		}
	}

	class WP_Query {
		public $posts = array();

		public function __construct( array $args ) {
			if ( isset( $args['meta_query'] ) ) {
				return;
			}
			if ( 'using-calendar-filters' === ( $args['name'] ?? '' ) ) {
				$this->posts = array( new WP_Post( 64 ) );
			}
		}
	}

	function get_page_by_path( $path, $output, $post_type ) {
		return 'events-calendar' === $path && 'page' === $post_type ? new WP_Post( 58 ) : null;
	}

	function wp_get_post_terms( $post_id, $taxonomy ) {
		return array( (object) array( 'slug' => $GLOBALS['extrachill_docs_test_term'] ?? 'events-calendar' ) );
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

	require dirname( __DIR__ ) . '/inc/core/doc-page-helpers.php';
	require dirname( __DIR__ ) . '/inc/access/team-private-docs.php';
	require dirname( __DIR__ ) . '/inc/core/platform-map.php';
	require dirname( __DIR__ ) . '/inc/core/rewrite-rules.php';

	$failures = array();
	$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
		if ( ! $condition ) {
			$failures[] = $message;
		}
	};

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
	$studio_entry = array_values( array_filter( $platform_map, static fn( array $item ): bool => 'plugins/extrachill-studio' === $item['repo'] ) );
	$assert( 'private' === ( $studio_entry[0]['post_status'] ?? '' ), 'Studio platform map entry is private' );
	$mcp_entry = array_values( array_filter( $platform_map, static fn( array $item ): bool => 'plugins/extrachill-docs' === $item['repo'] ) );
	$assert( 'mcp' === ( $mcp_entry[0]['parent_slug'] ?? '' ), 'network-level MCP guide has a docs destination' );
	$assert( 'docs/user' === $mcp_entry[0]['docs_subpath'], 'MCP uses canonical user docs' );

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
