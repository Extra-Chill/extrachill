<?php
/**
 * Asset Management
 *
 * Context-aware CSS/JavaScript loading with dynamic filemtime() versioning.
 * CSS: 11 files with conditional loading.
 * JavaScript: 5 files total (4 loaded here, 1 by feature module).
 */

function extrachill_enqueue_global_styles() {
    wp_enqueue_style(
        'extrachill-community-global',
        EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/global.css',
        array(),
        filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/global.css')
    );
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_global_styles');

function extrachill_enqueue_notification_styles() {
    if (is_page('notifications')) {
        wp_enqueue_style(
            'extrachill-notifications',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/notifications.css',
            array(),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/notifications.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_notification_styles');

function extrachill_enqueue_leaderboard_styles() {
    if (is_page_template('page-templates/leaderboard-template.php')) {
        wp_enqueue_style(
            'extrachill-leaderboard',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/leaderboard.css',
            array(),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/leaderboard.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_leaderboard_styles');

function extrachill_enqueue_settings_page_assets() {
    if (!is_page('settings')) {
        return;
    }

    wp_enqueue_style(
        'extrachill-settings-page',
        EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/settings-page.css',
        array(),
        filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/settings-page.css')
    );

    wp_enqueue_style(
        'extrachill-shared-tabs',
        get_template_directory_uri() . '/assets/css/shared-tabs.css',
        array(),
        filemtime(get_template_directory() . '/assets/css/shared-tabs.css')
    );

    wp_enqueue_script(
        'extrachill-shared-tabs',
        get_template_directory_uri() . '/assets/js/shared-tabs.js',
        array(),
        filemtime(get_template_directory() . '/assets/js/shared-tabs.js'),
        true
    );
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_settings_page_assets');

function enqueue_bbpress_global_styles() {
    if (is_bbpress() || is_front_page() || is_home() || is_page('recent')) {
        wp_enqueue_style(
            'extrachill-bbpress',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/bbpress.css',
            array(),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/bbpress.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'enqueue_bbpress_global_styles');

function modular_bbpress_styles() {
    if (bbp_is_forum_archive() || is_front_page() || is_home() || bbp_is_single_forum()) {
        wp_enqueue_style(
            'community-home',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/home.css',
            array('extrachill-bbpress'),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/home.css')
        );
    }

    if ( bbp_is_topic_archive() || bbp_is_single_forum() || is_page('recent') || is_page('following') || bbp_is_single_user() || bbp_is_search_results() || is_search() ) {
        wp_enqueue_style(
            'topics-loop',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/topics-loop.css',
            array('extrachill-bbpress'),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/topics-loop.css')
        );
    }

    if (bbp_is_single_reply() || bbp_is_single_topic() || bbp_is_single_user() || is_page('recent')) {
        wp_enqueue_style(
            'replies-loop',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/replies-loop.css',
            array('extrachill-bbpress'),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/replies-loop.css')
        );
    }

}
add_action('wp_enqueue_scripts', 'modular_bbpress_styles');

function enqueue_blog_comments_feed_styles() {
    if (is_page_template('page-templates/main-blog-comments-feed.php')) {
        // Load replies-loop.css for base card styling
        wp_enqueue_style(
            'replies-loop',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/replies-loop.css',
            array('extrachill-bbpress'),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/replies-loop.css')
        );

        // Load blog-specific overrides
        wp_enqueue_style(
            'blog-comments-feed',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/blog-comments-feed.css',
            array('replies-loop'),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/blog-comments-feed.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'enqueue_blog_comments_feed_styles');

function enqueue_user_profile_styles() {
    if ( bbp_is_single_user() || bbp_is_single_user_edit() || bbp_is_user_home() ) {
        wp_enqueue_style(
            'user-profile',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/user-profile.css',
            array('extrachill-bbpress'),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/user-profile.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'enqueue_user_profile_styles');

function extrachill_enqueue_scripts() {
    $stylesheet_dir_uri = EXTRACHILL_COMMUNITY_PLUGIN_URL;
    $stylesheet_dir = EXTRACHILL_COMMUNITY_PLUGIN_DIR;

    $upvote_script_version = filemtime( $stylesheet_dir . '/inc/assets/js/upvote.js' );
    $mentions_script_version = filemtime( $stylesheet_dir . '/inc/assets/js/extrachill-mentions.js' );

    if ( is_bbpress() || is_page('recent') ) {
        wp_enqueue_script('extrachill-upvote', $stylesheet_dir_uri . '/inc/assets/js/upvote.js', array(), $upvote_script_version, true);
        wp_localize_script('extrachill-upvote', 'extrachillCommunity', array(
            'restNonce' => wp_create_nonce('wp_rest')
        ));
    }

    if (is_bbpress()) {
        wp_enqueue_script('extrachill-mentions', $stylesheet_dir_uri . '/inc/assets/js/extrachill-mentions.js', array('editor'), $mentions_script_version, true);
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_scripts', 20);

function enqueue_content_expand_script() {
    if ( is_page('recent') || is_page_template('page-templates/main-blog-comments-feed.php') ) {
        $script_path = '/inc/assets/js/content-expand.js';
        $script_full_path = EXTRACHILL_COMMUNITY_PLUGIN_DIR . $script_path;
        $version = filemtime($script_full_path);
        wp_enqueue_script( 'extrachill-content-expand', EXTRACHILL_COMMUNITY_PLUGIN_URL . $script_path, array(), $version, true );
    }
}
add_action( 'wp_enqueue_scripts', 'enqueue_content_expand_script' );

function enqueue_custom_tinymce_plugin_scripts() {
    if (is_bbpress() && (bbp_is_single_topic() || bbp_is_single_reply() || bbp_is_topic_edit() || bbp_is_reply_edit() || bbp_is_single_forum())) {
        $script_version = filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/js/tinymce-image-upload.js');

        wp_enqueue_script('custom-tinymce-plugin', EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/tinymce-image-upload.js', array('editor', 'wp-i18n', 'utils'), $script_version, true);

        wp_localize_script('custom-tinymce-plugin', 'customTinymcePlugin', array(
            'restNonce' => wp_create_nonce('wp_rest')
        ));
    }
}
add_action('wp_enqueue_scripts', 'enqueue_custom_tinymce_plugin_scripts');

function extrachill_dequeue_bbpress_default_styles() {
    wp_dequeue_style('bbp-default');
    wp_deregister_style('bbp-default');
}
add_action('wp_enqueue_scripts', 'extrachill_dequeue_bbpress_default_styles', 15);