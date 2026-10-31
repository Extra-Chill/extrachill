<?php
/** Disposable native WordPress test configuration for CI concurrency. */

define( 'DB_NAME', getenv( 'DB_NAME' ) );
define( 'DB_USER', getenv( 'DB_USER' ) );
define( 'DB_PASSWORD', getenv( 'DB_PASSWORD' ) );
define( 'DB_HOST', getenv( 'DB_HOST' ) . ':' . getenv( 'DB_PORT' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Extra Chill Events Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

$table_prefix = 'wptests_';

define( 'ABSPATH', rtrim( getenv( 'WP_CORE_DIR' ), '/\\' ) . '/' );
