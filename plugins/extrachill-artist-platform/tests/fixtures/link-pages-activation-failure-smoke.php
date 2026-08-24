<?php

$root = dirname( __DIR__, 2 );
$mode = $argv[1] ?? 'missing';

define( 'ABSPATH', __DIR__ . '/' );

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

function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}

function plugin_dir_url() {
	return 'https://example.test/';
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function get_option( $name, $default = false ) {
	return 'active_plugins' === $name ? array( 'extrachill-link-pages/extrachill-link-pages.php' ) : $default;
}

function get_site_option( $name, $default = false ) {
	return $default;
}

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['smoke']['actions'][] = array( $hook, $callback, $priority );
}

function add_filter() {
}

function register_activation_hook( $file, $callback ) {
	$GLOBALS['smoke']['activation_callback'] = $callback;
}

function register_deactivation_hook() {
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function esc_html( $value ) {
	return $value;
}

function wp_die( $message ) {
	throw new RuntimeException( $message );
}

require_once $root . '/extrachill-artist-platform.php';

if ( 'missing' !== $mode ) {
	if ( 'stale-version' === $mode ) {
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
	}
	require_once __DIR__ . '/fake-external-link-pages-runtime.php';
}

$failed  = false;
$message = '';
try {
	call_user_func( $GLOBALS['smoke']['activation_callback'] );
} catch ( RuntimeException $exception ) {
	$failed  = true;
	$message = $exception->getMessage();
}

echo json_encode(
	array(
		'failed'  => $failed,
		'message' => $message,
	)
);
