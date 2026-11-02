<?php
/** No MDI symbols installed: all entry points must fail closed, not fatal. */
define( 'ABSPATH', __DIR__ );
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
require dirname( __DIR__ ) . '/inc/core/mdi-layout.php';
require dirname( __DIR__ ) . '/inc/abilities/publish-plugin-docs.php';
extrachill_docs_register_mdi_layout();
extrachill_docs_publish_changed_plugin_docs();
extrachill_docs_publish_changed_plugin_docs();
$result = extrachill_docs_execute_publish_plugin_docs( array() );
if ( $result['success'] || 'mdi_unavailable' !== $result['error'] ) {
	exit( 1 );
}
// No export integration exists. A future one must stage outside installed code.
foreach ( glob( dirname( __DIR__ ) . '/inc/{core,abilities}/*.php', GLOB_BRACE ) as $file ) {
	if ( preg_match( '/::export\s*\(|[\'"]markdown-db\/export[\'"]/', file_get_contents( $file ) ) ) {
		fwrite( STDERR, "Unexpected MDI export call: $file\n" );
		exit( 1 );
	}
}
echo "Inactive MDI and export safety smoke passed.\n";
