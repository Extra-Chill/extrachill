<?php
/** Registration preflight fixture using the real standalone registries. */

$runtime = getenv( 'LINK_PAGES_WORKTREE' );
if ( ! $runtime || ! is_file( $runtime . '/tests/bootstrap.php' ) ) {
	exit( 2 );
}
require_once $runtime . '/tests/bootstrap.php';

define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
$GLOBALS['ec_test']['options']['active_plugins'] = array( 'extrachill-link-pages/extrachill-link-pages.php' );
ec_register_link_page_operation_provider( 'events-promoters', '__return_true' );
require_once dirname( __DIR__, 2 ) . '/inc/Providers/VenueLinkPagesProvider.php';
require_once dirname( __DIR__, 2 ) . '/inc/Providers/PromoterLinkPagesProvider.php';
$result = ExtraChillEvents\Providers\PromoterLinkPagesProvider::initialize();

echo wp_json_encode(
	array(
		'error'       => is_wp_error( $result ) ? $result->get_error_code() : '',
		'owners'      => array_column( ec_link_page_owner_compatibility_registry()->snapshot(), 'name' ),
		'operations'  => array_column( ec_link_page_operation_provider_registry()->snapshot(), 'name' ),
		'projections' => array_column( ec_link_page_public_projection_registry()->snapshot(), 'name' ),
	)
);
