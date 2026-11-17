<?php
/**
 * Minimal standalone bootstrap for the PHP summary window tests.
 * Run: phpunit --no-configuration --bootstrap tests/php-error-window-bootstrap.php tests/PhpErrorWindowTest.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', '/tmp/wp-content' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MB_IN_BYTES', 1048576 );
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function add_action( ...$args ) {}
function apply_filters( $name, $value ) { return $value; }
function get_site_option( $name, $default = false ) { return $default; }
function extrachill_analytics_php_error_table() { return 'php_errors'; }
require_once dirname( __DIR__ ) . '/inc/core/php-error-log.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-php-error-summary.php';
