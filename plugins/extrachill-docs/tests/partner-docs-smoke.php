<?php
/** Standalone regression: publish partner docs from the real map without a runner. */
define( 'ABSPATH', __DIR__ );
define( 'EXTRACHILL_DOCS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
function add_action( ...$args ) {}
function add_filter( ...$args ) {}

require dirname( __DIR__ ) . '/inc/core/platform-map.php';
require dirname( __DIR__ ) . '/inc/core/doc-page-helpers.php';
require dirname( __DIR__ ) . '/inc/abilities/publish-plugin-docs.php';
require dirname( __DIR__ ) . '/inc/core/mdi-layout.php';

function check_partner_docs( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
}

$map = extrachill_docs_load_platform_map();
$partners = array_values( array_filter( $map, static fn( $entry ) => 'plugins/extrachill-api' === $entry['repo'] ) );
check_partner_docs( 1 === count( $partners ), 'Partner Websites entry loads without runner configuration' );
check_partner_docs( 'Partner Websites' === $partners[0]['parent_title'], 'Published platform title' );
check_partner_docs( 'docs/partner' === $partners[0]['docs_subpath'], 'Non-user subpath survives loader' );

$plan = extrachill_docs_plan_plugin_docs( $map, dirname( __DIR__, 2 ), 'plugins/extrachill-api' );
$names = array_column( $plan, 'path' );
$expected = array_map( static fn( $name ) => 'plugins/extrachill-api/docs/partner/' . $name . '.md', array( 'api', 'custom-builds', 'index', 'wordpress-plugin' ) );
check_partner_docs( $expected === $names, 'Exactly four direct Markdown pages planned' );
foreach ( $plan as $row ) {
	check_partner_docs( 'publish' === $row['action'], 'Page is publishable' );
	check_partner_docs( 'partner-websites' === $row['parent_slug'], 'Partner parent slug' );
	check_partner_docs( 'publish' === $row['post_status'], 'Partner guides are public' );
	check_partner_docs( null !== extrachill_docs_mdi_source( $row['path'] ), 'MDI accepts partner source identity' );
}
check_partner_docs( null === extrachill_docs_mdi_source( 'plugins/extrachill-api/docs/partner/nested/page.md' ), 'Nested files are not published' );
check_partner_docs( null === extrachill_docs_mdi_source( 'plugins/extrachill-api/docs/user/api.md' ), 'Unmapped user path is not published' );
echo "Partner docs publishing smoke passed.\n";
