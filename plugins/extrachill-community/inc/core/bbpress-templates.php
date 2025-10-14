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

function extrachill_community_get_bbpress_template_path() {
    return EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'bbpress';
}

function extrachill_community_register_bbpress_templates() {
    bbp_register_template_stack('extrachill_community_get_bbpress_template_path', 1);
}
add_action('bbp_register_theme_packages', 'extrachill_community_register_bbpress_templates');

/**
 * Override homepage template to show forum index
 *
 * Only applies to community.extrachill.com to avoid conflicts with other site homepages.
 *
 * @param string $template Default homepage template path
 * @return string Modified template path
 */
function extrachill_community_homepage_template($template) {
    // Only override on community.extrachill.com
    $community_blog_id = get_blog_id_from_url( 'community.extrachill.com', '/' );

    if ( $community_blog_id && get_current_blog_id() === $community_blog_id ) {
        return EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/forum-homepage.php';
    }

    return $template;
}
add_filter('extrachill_template_homepage', 'extrachill_community_homepage_template', 10);

 /**
  * Remove forum statistics template notice
  *
  * Prevents bbp_single_forum_description() from displaying the template notice
  * with forum statistics while preserving the separate forum description content.
  */
 add_filter( 'bbp_get_single_forum_description', '__return_empty_string' );
