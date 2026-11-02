<?php
require __DIR__ . '/publish-plugin-docs-smoke.php';
require dirname( __DIR__ ) . '/inc/abilities/adopt-mdi-pages.php';
$GLOBALS['meta'] = array( 42 => array( '_source_path' => 'plugins/team/docs/user/start.md' ) );
$before = $GLOBALS['meta'];
$r = extrachill_docs_execute_adopt_mdi_pages( array() );
check( $r['success'] && $r['dry_run'] && $before === $GLOBALS['meta'], 'read-only default' );
$r = extrachill_docs_execute_adopt_mdi_pages( array( 'apply' => true ) );
check( $r['success'], 'apply adoption' );
foreach ( array( '_markdown_source_path', '_markdown_source_identity' ) as $key ) {
	check( $GLOBALS['meta'][42][$key] === $before[42]['_source_path'], 'canonical identity used by existing_source_map' );
}
$after = $GLOBALS['meta'];
$r = extrachill_docs_execute_adopt_mdi_pages( array( 'apply' => true ) );
check( $r['rows'][0]['action'] === 'unchanged' && $after === $GLOBALS['meta'], 'idempotent' );
$GLOBALS['meta'][42]['_markdown_source_identity'] = 'other-owner';
$before = $GLOBALS['meta'];
check( ! extrachill_docs_execute_adopt_mdi_pages( array( 'apply' => true ) )['success'] && $before === $GLOBALS['meta'], 'conflicts never overwritten' );
echo "MDI adoption smoke passed.\n";
