<?php
/** Standalone MDI adapter smoke: no WordPress or MDI required. */
define( 'ABSPATH', __DIR__ );
define( 'OBJECT', 'OBJECT' );
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/ec-mdi-' . getmypid() );
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function sanitize_title( $s ) { return strtolower( $s ); }
function home_url( $s ) { return 'https://docs.example' . $s; }
function is_wp_error( $v ) { return false; }
class WP_Post { public $ID = 7; public $post_status = 'publish'; }
function get_page_by_path( ...$args ) { return new WP_Post(); }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][$id][$key] = $value; }
function get_posts( ...$args ) { return array( (object) array( 'ID' => 42 ) ); }
function extrachill_docs_load_platform_map() {
	return array(
		array( 'repo' => 'plugins/public', 'docs_subpath' => 'docs/user', 'parent_slug' => 'help', 'parent_title' => 'Help', 'post_status' => 'publish' ),
		array( 'repo' => 'plugins/team', 'docs_subpath' => 'docs/user', 'parent_slug' => 'team', 'parent_title' => 'Team', 'post_status' => 'private' ),
	);
}
function markdown_db_register_content_layout_profile( $id, $profile ) { $GLOBALS['profile'] = $profile; }
function check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
class WP_Query { public $posts = array(); }
class WP_Markdown_CLI {
	static function import( $options ) {
		check( $options['path'] === WP_PLUGIN_DIR && $options['profile'] === 'markdown-h1-title' && $options['layout_profile'] === EXTRACHILL_DOCS_MDI_LAYOUT, 'import options' );
		check( count( ($GLOBALS['profile']['enumerate'])( WP_PLUGIN_DIR ) ) === 1, 'repo filter' );
		return array( 'success' => true, 'skipped_count' => 0 );
	}
}
require dirname( __DIR__ ) . '/inc/core/doc-page-helpers.php';
require dirname( __DIR__ ) . '/inc/abilities/publish-plugin-docs.php';
require dirname( __DIR__ ) . '/inc/core/mdi-layout.php';
foreach ( array( 'public', 'team' ) as $component ) {
	mkdir( WP_PLUGIN_DIR . '/' . $component . '/docs/user', 0777, true );
	file_put_contents( WP_PLUGIN_DIR . '/' . $component . '/docs/user/start.md', "# Start\n\n[Other](other.md#part)\n" );
}
try {
	extrachill_docs_register_mdi_layout();
	$p = $GLOBALS['profile'];
	$paths = $p['enumerate']( WP_PLUGIN_DIR );
	check( count( $paths ) === 2, 'nested enumeration' );
	foreach ( $paths as $path ) {
		$m = $p['map_source']( $path );
		check( $m['post_parent'] === 7 && $m['post_type'] === 'page' && $m['post_name'] === 'start', 'parent and slug mapping' );
		check( $m['source_identity'] === 'plugins/' . $path, 'canonical identity' );
		$GLOBALS['meta'][42]['_markdown_source_identity'] = $m['source_identity'];
		check( $p['path_for_post']( (object) array( 'ID' => 42 ) ) === $path, 'inverse path' );
		$context = array( 'source_path' => $m['source_identity'] );
		check( extrachill_docs_mdi_post_data( array(), $context, new stdClass() ) === array(), 'unrelated imports unchanged' );
		$GLOBALS['extrachill_docs_mdi_importing'] = true;
		$data = extrachill_docs_mdi_post_data( array(), $context, new stdClass() );
		check( $data['post_status'] === ( str_starts_with( $path, 'team/' ) ? 'private' : 'publish' ), 'visibility' );
		$text = extrachill_docs_mdi_post_content( file_get_contents( WP_PLUGIN_DIR . '/' . $path ), $context, array(), new stdClass() );
		check( str_contains( $text, '/other/#part)' ) && str_starts_with( $text, '# Start' ), 'rewrite before conversion; H1 owned by MDI' );
		unset( $GLOBALS['extrachill_docs_mdi_importing'] );
	}
	check( extrachill_docs_execute_publish_plugin_docs( array( 'repo' => 'plugins/team', 'dry_run' => true ) )['success'], 'wrapper' );
	check( ! isset( $GLOBALS['extrachill_docs_mdi_importing'] ), 'import scope cleaned' );
	check( extrachill_docs_mdi_source( 'plugins/team/docs/user/../evil.md' ) === null, 'reject traversal' );
} finally {
	foreach ( array( 'public', 'team' ) as $c ) {
		unlink( WP_PLUGIN_DIR . '/' . $c . '/docs/user/start.md' );
		rmdir( WP_PLUGIN_DIR . '/' . $c . '/docs/user' );
		rmdir( WP_PLUGIN_DIR . '/' . $c . '/docs' );
		rmdir( WP_PLUGIN_DIR . '/' . $c );
	}
	rmdir( WP_PLUGIN_DIR );
}
echo "MDI publishing smoke passed.\n";
