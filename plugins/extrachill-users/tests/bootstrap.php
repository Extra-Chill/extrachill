<?php
/**
 * PHPUnit bootstrap file for extrachill-users tests.
 *
 * Loads shared WordPress mocks and plugin-specific setup.
 *
 * @package ExtraChill\Users
 */

// Plugin constants.
define( 'EXTRACHILL_USERS_VERSION', '0.5.7' );
define( 'EXTRACHILL_USERS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'EXTRACHILL_USERS_PLUGIN_URL', 'https://example.com/wp-content/plugins/extrachill-users/' );

// Load plugin files being tested.
require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/auth-tokens/tokens.php';
require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/auth-tokens/bearer-auth.php';
require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/onboarding/service.php';
require_once EXTRACHILL_USERS_PLUGIN_DIR . 'inc/core/user-creation.php';
