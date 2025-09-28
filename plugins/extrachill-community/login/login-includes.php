<?php
/**
 * Login Module Includes
 *
 * Requires the necessary files for the login and registration functionality.
 */

require_once dirname(__FILE__) . '/login.php';
require_once dirname(__FILE__) . '/register.php';
require_once dirname(__FILE__) . '/logout.php';
require_once dirname(__FILE__) . '/registration-emails.php';

// Enqueue assets for the consolidated Login/Register page
function extrachill_enqueue_login_register_assets() {
    // Check if we are on the consolidated login/register page template
    if (is_page_template('page-templates/login-register-template.php')) {
        // Enqueue Cloudflare Turnstile API only if not in development environment
        if ( 'development' !== wp_get_environment_type() ) {
             wp_enqueue_script('cloudflare-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true);
        }

        // Enqueue custom login/register tabs script
        $login_register_tabs_script_path = EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/login/js/login-register-tabs.js';
        if ( file_exists( $login_register_tabs_script_path ) ) {
            wp_enqueue_script(
                'login-register-tabs',
                EXTRACHILL_COMMUNITY_PLUGIN_URL . '/login/js/login-register-tabs.js',
                array('shared-tabs', 'jquery', 'cloudflare-turnstile'), // Correct dependencies: shared-tabs, jquery, and cloudflare-turnstile
                filemtime( $login_register_tabs_script_path ),
                true
            );
        }

        // Enqueue login/register specific styles
        wp_enqueue_style('login-register-styles', EXTRACHILL_COMMUNITY_PLUGIN_URL . '/css/login-register.css', array(), filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/css/login-register.css'));

        // Remove commented-out shared-tabs.css enqueue as styles are included in login-register.css
        // wp_enqueue_style('shared-tabs-styles', EXTRACHILL_COMMUNITY_PLUGIN_URL . '/css/components/shared-tabs.css', array(), filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/css/components/shared-tabs.css'));

        // Enqueue script for the join flow UI logic
        $join_flow_ui_script_path = EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/login/js/join-flow-ui.js';
        if ( file_exists( $join_flow_ui_script_path ) ) {
            wp_enqueue_script(
                'join-flow-ui',
                EXTRACHILL_COMMUNITY_PLUGIN_URL . '/login/js/join-flow-ui.js',
                array('login-register-tabs'), // Depends on the login-register-tabs script
                filemtime( $join_flow_ui_script_path ),
                true
            );
        }
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_login_register_assets');


