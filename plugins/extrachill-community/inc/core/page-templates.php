<?php
/**
 * Page Template Registration
 *
 * Registers and loads community page templates with WordPress.
 * Makes templates available in page template dropdown and handles template loading.
 *
 * @package ExtraChillCommunity
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register community page templates with WordPress
 *
 * Makes page templates appear in the page template dropdown in admin.
 *
 * @param array $templates Existing page templates.
 * @param WP_Theme $theme Current theme object.
 * @param WP_Post|null $post Current post object or null.
 * @param string $post_type Post type.
 * @return array Modified templates array.
 */
function extrachill_community_register_page_templates($templates, $theme, $post, $post_type) {
    // Only register templates for 'page' post type
    if ($post_type !== 'page') {
        return $templates;
    }

    $community_templates = array(
        'page-templates/leaderboard-template.php'      => __('Leaderboard', 'extrachill-community'),
        'page-templates/recent-feed-template.php'      => __('Recent Feed', 'extrachill-community'),
        'page-templates/main-blog-comments-feed.php'   => __('Main Blog Comments Feed', 'extrachill-community'),
    );

    // Only register templates that actually exist
    foreach ($community_templates as $template_file => $template_name) {
        $full_path = EXTRACHILL_COMMUNITY_PLUGIN_DIR . $template_file;
        if (file_exists($full_path)) {
            $templates[$template_file] = $template_name;
        }
    }

    return $templates;
}
add_filter('theme_page_templates', 'extrachill_community_register_page_templates', 10, 4);

/**
 * Load community page templates
 *
 * Integrates with theme's template router to serve plugin templates.
 * Hooks into extrachill_template_page filter provided by theme's routing system.
 *
 * @param string $template Current template path from theme router.
 * @return string Modified template path.
 */
function extrachill_community_load_page_templates($template) {
    global $post;

    if (!$post || !is_page()) {
        return $template;
    }

    $page_template = get_page_template_slug($post);

    $template_map = array(
        'page-templates/leaderboard-template.php'      => 'page-templates/leaderboard-template.php',
        'page-templates/recent-feed-template.php'      => 'page-templates/recent-feed-template.php',
        'page-templates/main-blog-comments-feed.php'   => 'page-templates/main-blog-comments-feed.php',
    );

    if (isset($template_map[$page_template])) {
        $plugin_template = EXTRACHILL_COMMUNITY_PLUGIN_DIR . $template_map[$page_template];
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }

    return $template;
}
add_filter('extrachill_template_page', 'extrachill_community_load_page_templates', 10);
