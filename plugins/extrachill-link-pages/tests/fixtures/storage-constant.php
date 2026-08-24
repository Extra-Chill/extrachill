<?php

define( 'EC_LINK_PAGE_STORAGE_BLOG_ID', (int) getenv( 'STORAGE_BLOG_ID' ) );
require_once dirname( __DIR__ ) . '/bootstrap.php';

$GLOBALS['ec_test']['filters']['ec_link_page_storage_blog_id'] = array();
$GLOBALS['ec_test']['site_options'][ EC_LINK_PAGE_STORAGE_BLOG_OPTION ] = 0;
$storage_blog_id = ec_get_link_page_storage_blog_id();
$activation      = ec_prepare_link_pages_activation( true );

echo json_encode(
	array(
		'storage_blog_id' => $storage_blog_id,
		'activation_ready' => true === $activation,
		'activation_error' => is_wp_error( $activation ) ? $activation->get_error_code() : '',
	)
);
