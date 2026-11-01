<?php
/** Standalone migration regression tests: php tests/migrate-source-keys-smoke.php */
define( 'ABSPATH', __DIR__ );
define( 'OBJECT', 'OBJECT' );
define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );
define( 'EXTRACHILL_DOCS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
function add_action( ...$args ) {}
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, ...$args ) { $GLOBALS['options'][ $key ] = $value; return true; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; }
function get_posts( $args ) { return $GLOBALS['pages']; }
function get_page_by_path( $path, ...$args ) { return $GLOBALS['parents'][ $path ] ?? null; }
function sanitize_title( $text ) { return strtolower( $text ); }
function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 'ec_doc', 'post_status' => 'publish' ); }
function clean_post_cache( $id ) {}
function flush_rewrite_rules() { $GLOBALS['flushed'] = true; }
class LegacyDB {
	public $posts = 'posts';
	public $term_relationships = 'relationships';
	public $term_taxonomy = 'taxonomies';
	public $terms = 'terms';
	public function update( $table, $data, $where, ...$formats ) {
		check( 'trash' === $data['post_status'], 'retired posts are only trashed, never deleted' );
		$GLOBALS['trashed'][] = $where['ID'];
		return 1;
	}
	public function get_results( $sql ) {
		return array_values( array_filter( $GLOBALS['legacy'], static fn( $post ) => ! in_array( $post->ID, $GLOBALS['trashed'], true ) ) );
	}
}
$GLOBALS['wpdb'] = new LegacyDB();
require dirname( __DIR__ ) . '/inc/core/platform-map.php';
require dirname( __DIR__ ) . '/inc/abilities/upsert-doc-page.php';
require dirname( __DIR__ ) . '/inc/abilities/publish-plugin-docs.php';
require dirname( __DIR__ ) . '/inc/abilities/migrate-source-keys.php';
require dirname( __DIR__ ) . '/inc/core/rewrite-rules.php';
function check( $ok, $message ) { if ( ! $ok ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
function page( $id, $parent, $slug ) { return (object) array( 'ID' => $id, 'post_parent' => $parent, 'post_name' => $slug ); }
$GLOBALS['parents'] = array( 'mcp' => page( 10, 0, 'mcp' ), 'community' => page( 20, 0, 'community' ), 'events-calendar' => page( 30, 0, 'events-calendar' ) );
$event_plan = array_values( array_filter( extrachill_docs_plan_plugin_docs( extrachill_docs_load_platform_map(), WP_PLUGIN_DIR ), static fn( $row ) => 'plugins/extrachill-events' === $row['repo'] && 'publish' === $row['action'] ) );
check( count( $event_plan ) > 0, 'events sources exist' );
$event_slug = extrachill_docs_derive_slug_from_path( $event_plan[0]['path'] );
$GLOBALS['parents']['artist-platform'] = page( 40, 0, 'artist-platform' );
$GLOBALS['pages'] = array( page( 109, 10, 'connect' ), page( 120, 20, 'getting-started' ), page( 55, 30, $event_slug ), page( 66, 40, 'artist-preferences' ) );
$GLOBALS['meta'] = array( 109 => array( '_source_repo' => 'Extra-Chill/extrachill-docs', '_source_path' => 'ec_docs/mcp/connect.md' ), 120 => array( '_source_repo' => 'Extra-Chill/extrachill-community', '_source_path' => 'docs/user/getting-started.md' ) );
$GLOBALS['options'] = array( EXTRACHILL_DOCS_PUBLISHED_FINGERPRINTS_OPTION => array( 'Extra-Chill/extrachill-docs' => 'abc' ) );
$GLOBALS['legacy'] = array( (object) array( 'ID' => 100, 'platform' => 'mcp', 'post_name' => 'connect' ), (object) array( 'ID' => 41, 'platform' => 'unknown', 'post_name' => 'missing' ) );
$GLOBALS['trashed'] = array();
$before = serialize( array( $GLOBALS['meta'], $GLOBALS['options'] ) );
$plan = extrachill_docs_execute_migrate_source_keys( array() );
check( $plan['dry_run'] && $before === serialize( array( $GLOBALS['meta'], $GLOBALS['options'] ) ) && ! $GLOBALS['trashed'], 'default dry run makes no writes' );
check( in_array( 'unmatched_legacy', array_column( $plan['rows'], 'action' ), true ), 'unmatched legacy is reported' );
extrachill_docs_execute_migrate_source_keys( array( 'apply' => true ) );
check( 'plugins/extrachill-docs/docs/user/connect.md' === get_post_meta( 109, '_source_path', true ), 'page 109 is rekeyed to moved source' );
check( 'plugins/extrachill-community/docs/user/getting-started.md' === get_post_meta( 120, '_source_path', true ), 'community source is rekeyed' );
check( $event_plan[0]['path'] === get_post_meta( 55, '_source_path', true ), 'meta-less events page is adopted' );
check( 'abc' === get_option( EXTRACHILL_DOCS_PUBLISHED_FINGERPRINTS_OPTION )['plugins/extrachill-docs'], 'fingerprint is component keyed' );
check( 109 === extrachill_docs_redirect_target( '/mcp/connect/' ), 'redirect map resolves legacy path without registered taxonomy' );
check( array( 100 ) === $GLOBALS['trashed'], 'only matched legacy is trashed' );
$again = extrachill_docs_execute_migrate_source_keys( array( 'apply' => true ) );
check( array( 'unmatched_legacy' ) === array_column( $again['rows'], 'action' ), 'second apply is idempotent' );
check( 'plugins/extrachill-artist-platform/docs/user/artist-preferences.md' === get_post_meta( 66, '_source_path', true ), 'meta-less artist preferences page is adopted' );
check( 4 === count( $GLOBALS['pages'] ), 'no pages are created' );
// A second owner of a canonical key must block adoption/rekey, not overwrite it.
$GLOBALS['pages'][] = page( 999, 20, 'duplicate' );
$GLOBALS['meta'][999] = $GLOBALS['meta'][120];
$conflict = extrachill_docs_execute_migrate_source_keys( array() );
check( in_array( 'conflict', array_column( $conflict['rows'], 'action' ), true ), 'duplicate source ownership is reported' );
echo "Source migration smoke tests passed.\n";
