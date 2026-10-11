<?php
/**
 * Installed-plugin docs publisher smoke tests (#79).
 *
 * Run with: php tests/publish-plugin-docs-smoke.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'EXTRACHILL_DOCS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	class WP_Post {
		public $ID;
		public $post_name;

		public function __construct( int $id, string $slug = '' ) {
			$this->ID        = $id;
			$this->post_name = $slug;
		}
	}

	// Pages on the fake docs site: [ id, slug, parent ].
	$GLOBALS['ec_test_pages'] = array(
		array( 57, 'finding-live-music', 54 ),
		array( 90, 'owned-elsewhere', 54 ),
		array( 91, 'top-level', 0 ),
	);
	$GLOBALS['ec_test_meta']  = array(
		90 => array( '_source_repo' => 'Extra-Chill/other' ),
	);

	class WP_Query {
		public $posts = array();

		public function __construct( array $args ) {
			foreach ( $GLOBALS['ec_test_pages'] as $page ) {
				if ( ( $args['name'] ?? null ) === $page[1] && ( $args['post_parent'] ?? null ) === $page[2] ) {
					$this->posts[] = new WP_Post( $page[0], $page[1] );
				}
			}
		}
	}

	function add_action() {}
	function get_post_meta( $id, $key ) {
		return $GLOBALS['ec_test_meta'][ $id ][ $key ] ?? '';
	}
	function sanitize_title( $title ) {
		return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $title ) );
	}
	function __( $text ) {
		return $text;
	}

	require dirname( __DIR__ ) . '/inc/abilities/upsert-doc-page.php';
	require dirname( __DIR__ ) . '/inc/abilities/publish-plugin-docs.php';

	$failures = array();
	$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
		if ( ! $condition ) {
			$failures[] = $message;
		}
	};

	// Fixture plugins directory.
	$root = sys_get_temp_dir() . '/ec-docs-publish-' . bin2hex( random_bytes( 4 ) );
	mkdir( $root . '/extrachill-events/docs/user', 0777, true );
	mkdir( $root . '/extrachill-docs/ec_docs/mcp', 0777, true );
	file_put_contents( $root . '/extrachill-events/docs/user/finding-live-music.md', "# Finding Live Music\n\nBrowse shows.\n" );
	file_put_contents( $root . '/extrachill-events/docs/user/b-second.md', "# Second\n" );
	file_put_contents( $root . '/extrachill-events/docs/user/notes.txt', 'ignored' );
	file_put_contents( $root . '/extrachill-docs/ec_docs/mcp/connect.md', "# Connecting Your AI Client\n" );

	$map = array(
		array(
			'repo'         => 'Extra-Chill/extrachill-events',
			'parent_slug'  => 'events-calendar',
			'parent_title' => 'Events Calendar',
			'docs_subpath' => 'docs/user',
			'post_status'  => 'publish',
		),
		array(
			'repo'         => 'Extra-Chill/extrachill-docs',
			'parent_slug'  => 'mcp',
			'parent_title' => 'Connect Your AI',
			'docs_subpath' => 'ec_docs/mcp',
			'post_status'  => 'publish',
		),
		array(
			'repo'         => 'Extra-Chill/extrachill-newsletter',
			'parent_slug'  => 'newsletter',
			'parent_title' => 'Newsletter',
			'docs_subpath' => 'docs/user',
			'post_status'  => 'publish',
		),
	);

	$plan    = extrachill_docs_plan_plugin_docs( $map, $root );
	$publish = array_values( array_filter( $plan, static fn( $r ) => 'publish' === $r['action'] ) );
	$paths   = array_map( static fn( $r ) => $r['repo'] . ':' . $r['path'], $publish );

	$assert( 3 === count( $publish ), 'only .md files in each mapped docs path are planned' );
	$assert( in_array( 'Extra-Chill/extrachill-events:docs/user/finding-live-music.md', $paths, true ), 'plugin docs/user files map to repo-relative source paths' );
	$assert( in_array( 'Extra-Chill/extrachill-docs:ec_docs/mcp/connect.md', $paths, true ), 'a custom docs_subpath is honoured' );
	$assert( 'events-calendar' === $publish[0]['parent_slug'] && 'Events Calendar' === $publish[0]['parent_title'], 'parent comes from the platform map' );

	$skipped = array_values( array_filter( $plan, static fn( $r ) => 'skipped_missing_plugin' === $r['action'] ) );
	$assert( 1 === count( $skipped ) && 'Extra-Chill/extrachill-newsletter' === $skipped[0]['repo'], 'a mapped plugin that is not installed is skipped' );

	$only = extrachill_docs_plan_plugin_docs( $map, $root, 'Extra-Chill/extrachill-docs' );
	$assert( 1 === count( $only ) && 'ec_docs/mcp/connect.md' === $only[0]['path'], 'repo filter restricts the plan' );

	$body = "# Finding Live Music\n\nBrowse shows.\n";
	$assert( sha1( 'blob ' . strlen( $body ) . "\0" . $body ) === extrachill_docs_markdown_sha( $body ), 'sha uses the git blob format' );

	$before = extrachill_docs_plugin_docs_fingerprints( $plan );
	$again  = extrachill_docs_plugin_docs_fingerprints( extrachill_docs_plan_plugin_docs( $map, $root ) );
	$assert( $before === $again, 'unchanged docs produce an unchanged fingerprint, so nothing republishes' );
	file_put_contents( $root . '/extrachill-events/docs/user/b-second.md', "# Second, edited\n" );
	$after = extrachill_docs_plugin_docs_fingerprints( extrachill_docs_plan_plugin_docs( $map, $root ) );
	$assert( $before['Extra-Chill/extrachill-events'] !== $after['Extra-Chill/extrachill-events'], 'an edited doc changes its repo fingerprint' );
	$assert( $before['Extra-Chill/extrachill-docs'] === $after['Extra-Chill/extrachill-docs'], 'other repos keep their fingerprint' );

	// Adoption of pages left unkeyed by the sync cleanup.
	$adopted = extrachill_docs_find_child_page_by_slug( 'finding-live-music', 54 );
	$assert( $adopted instanceof WP_Post && 57 === $adopted->ID, 'an unkeyed same-slug child is adopted instead of duplicated' );
	$assert( null === extrachill_docs_find_child_page_by_slug( 'owned-elsewhere', 54 ), 'a page keyed to another source is never adopted' );
	$assert( null === extrachill_docs_find_child_page_by_slug( 'top-level', 0 ), 'no adoption without a resolved parent' );
	$assert( null === extrachill_docs_find_child_page_by_slug( 'missing', 54 ), 'no match means create' );

	$cleanup = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $cleanup as $entry ) {
		$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );

	if ( $failures ) {
		fwrite( STDERR, "Publish plugin docs smoke tests failed:\n- " . implode( "\n- ", $failures ) . "\n" );
		exit( 1 );
	}

	echo "Publish plugin docs smoke tests passed.\n";
}
