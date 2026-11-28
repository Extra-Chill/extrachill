<?php
/**
 * Database-free WordPress core bootstrap for standalone smokes.
 *
 * Smokes that exercise real core classes (WP_Ability, the REST server, schema
 * validation) load core source files directly instead of booting an installed
 * site. The Homeboy WordPress extension hands standalone smokes a pristine core
 * tree in HOMEBOY_WORDPRESS_CORE_ROOT; that tree has no wp-config.php or
 * database, so wp-load.php cannot run. This mirrors the SHORTINIT file set in
 * wp-settings.php up to, but not including, the database connection.
 *
 * Root resolution: first CLI argument, then WP_ROOT, then
 * HOMEBOY_WORDPRESS_CORE_ROOT. A smoke already running inside WordPress
 * (ABSPATH defined) is left untouched.
 */

if ( defined( 'ABSPATH' ) ) {
	return;
}

( static function () {
	global $argv;

	$candidates = array(
		$argv[1] ?? '',
		(string) getenv( 'WP_ROOT' ),
		(string) getenv( 'HOMEBOY_WORDPRESS_CORE_ROOT' ),
	);

	$root = '';
	foreach ( $candidates as $candidate ) {
		if ( '' !== $candidate && is_file( rtrim( $candidate, '/' ) . '/wp-includes/version.php' ) ) {
			$root = rtrim( $candidate, '/' );
			break;
		}
	}

	if ( '' === $root ) {
		$script = basename( $_SERVER['SCRIPT_FILENAME'] ?? 'smoke.php' );
		fwrite( STDERR, "FAIL: {$script} needs WordPress core source. Pass a core root as the first argument or set WP_ROOT / HOMEBOY_WORDPRESS_CORE_ROOT (the Homeboy WordPress extension sets HOMEBOY_WORDPRESS_CORE_ROOT for standalone smokes).\n" );
		exit( 1 );
	}

	define( 'ABSPATH', $root . '/' );
	define( 'WPINC', 'wp-includes' );
	define( 'SHORTINIT', true );
	if ( ! defined( 'WP_DISABLE_FATAL_ERROR_HANDLER' ) ) {
		define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
	}
} )();

global $wp_version, $wp_db_version, $tinymce_version, $required_php_version, $required_php_extensions, $required_mysql_version, $wp_local_package, $blog_id;

$_wordpress_core_files = array(
	'version.php',
	'compat-utf8.php',
	'compat.php',
	'load.php',
	'class-wp-paused-extensions-storage.php',
	'class-wp-exception.php',
	'class-wp-fatal-error-handler.php',
	'class-wp-recovery-mode-cookie-service.php',
	'class-wp-recovery-mode-key-service.php',
	'class-wp-recovery-mode-link-service.php',
	'class-wp-recovery-mode-email-service.php',
	'class-wp-recovery-mode.php',
	'error-protection.php',
	'default-constants.php',
	'plugin.php',
);
foreach ( $_wordpress_core_files as $_wordpress_core_file ) {
	if ( is_file( ABSPATH . WPINC . '/' . $_wordpress_core_file ) ) {
		require_once ABSPATH . WPINC . '/' . $_wordpress_core_file;
	}
}

wp_initial_constants();
date_default_timezone_set( 'UTC' );

$_wordpress_core_files = array(
	'class-wp-list-util.php',
	'class-wp-token-map.php',
	'utf8.php',
	'formatting.php',
	'meta.php',
	'functions.php',
	'class-wp-meta-query.php',
	'class-wp-matchesmapregex.php',
	'class-wp.php',
	'class-wp-error.php',
	'pomo/mo.php',
	'l10n/class-wp-translation-controller.php',
	'l10n/class-wp-translations.php',
	'l10n/class-wp-translation-file.php',
	'l10n/class-wp-translation-file-mo.php',
	'l10n/class-wp-translation-file-php.php',
	'option.php',
	'cache.php',
);
foreach ( $_wordpress_core_files as $_wordpress_core_file ) {
	if ( is_file( ABSPATH . WPINC . '/' . $_wordpress_core_file ) ) {
		require_once ABSPATH . WPINC . '/' . $_wordpress_core_file;
	}
}
unset( $_wordpress_core_files, $_wordpress_core_file );

if ( ! defined( 'MULTISITE' ) ) {
	define( 'MULTISITE', false );
}

// No database: core's in-memory object cache stands in for persistence, and
// alloptions is primed with the site defaults core helpers read (charset for
// esc_html(), timezone for mysql2date()) so they resolve without a query.
wp_cache_init();
wp_cache_add(
	'alloptions',
	array(
		'blog_charset'    => 'UTF-8',
		'timezone_string' => 'UTC',
		'gmt_offset'      => 0,
		'date_format'     => 'F j, Y',
		'time_format'     => 'g:i a',
	),
	'options'
);
