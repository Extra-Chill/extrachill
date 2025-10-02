<?php
/**
 * bbPress Template Stack Registration
 *
 * Registers the plugin's /bbpress/ directory as a template location
 * so bbPress knows to use the plugin's custom templates.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get the template path for bbPress templates in this plugin
 *
 * @return string Full path to plugin's bbpress template directory
 */
function extrachill_community_get_bbpress_template_path() {
    return EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'bbpress';
}

/**
 * Register the plugin's bbPress template location with bbPress
 *
 * Priority 12 ensures plugin templates are used by default
 */
function extrachill_community_register_bbpress_templates() {
    bbp_register_template_stack('extrachill_community_get_bbpress_template_path', 12);
}
add_action('bbp_register_theme_packages', 'extrachill_community_register_bbpress_templates');

/**
 * Override homepage template on community site to show forum index
 *
 * @param string $template Default homepage template path
 * @return string Modified template path for community site
 */
function extrachill_community_homepage_template($template) {
    // Only override on community.extrachill.com (blog ID 2 in multisite)
    if (get_current_blog_id() === 2) {
        return EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/forum-homepage.php';
    }
    return $template;
}
add_filter('extrachill_template_homepage', 'extrachill_community_homepage_template', 10);
