<?php

$mode = $argv[1] ?? '';
$root = dirname( __DIR__, 2 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR', $root . '/' );

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

$GLOBALS['smoke'] = array( 'actions' => array() );
if ( 'post-type' === $mode ) {
	$GLOBALS['smoke']['post_type_constant'] = 'link_page';
} elseif ( 'owner-meta' === $mode ) {
	$GLOBALS['smoke']['owner_meta_constant'] = '_other_owner';
} elseif ( 'stale-version' === $mode ) {
	$GLOBALS['smoke']['api_version'] = '0';
} elseif ( 'no-marker' === $mode ) {
	$GLOBALS['smoke']['omit_api_version'] = true;
} elseif ( 'wrong-arity' === $mode ) {
	$GLOBALS['smoke']['wrong_save_arity'] = true;
} elseif ( 'wrong-required-arity' === $mode ) {
	$GLOBALS['smoke']['wrong_save_required_arity'] = true;
} elseif ( 'no-readiness' === $mode ) {
	$GLOBALS['smoke']['omit_readiness'] = true;
} elseif ( 'readiness-arity' === $mode ) {
	$GLOBALS['smoke']['wrong_readiness_arity'] = true;
} elseif ( 'readiness' === $mode ) {
	$GLOBALS['smoke']['runtime_ready'] = false;
}

function get_option( $name, $default = false ) {
	return 'active_plugins' === $name ? array( 'extrachill-link-pages/extrachill-link-pages.php' ) : $default;
}

function get_site_option( $name, $default = false ) {
	return $default;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function add_action( $hook, $callback ) {
	$GLOBALS['smoke']['actions'][] = array( $hook, $callback );
}

function add_filter() {
}

require_once $root . '/inc/link-pages/runtime-handoff.php';
require_once __DIR__ . '/fake-external-link-pages-runtime.php';
$result = extrachill_artist_platform_boot_link_pages_runtime();

echo json_encode(
	array(
		'error_code'    => is_wp_error( $result ) ? $result->get_error_code() : '',
		'notice_hooked' => in_array( array( 'admin_notices', 'extrachill_artist_platform_link_pages_runtime_notice' ), $GLOBALS['smoke']['actions'], true ),
	)
);
