<?php
/**
 * Asset Management
 *
 * Conditional loading of CSS and JavaScript files for the Extra Chill Community plugin.
 * Handles context-aware enqueuing, dynamic versioning, and dependency management.
 *
 * JavaScript files loaded here (5): upvote.js, extrachill-mentions.js, home-collapse.js,
 * utilities.js, tinymce-image-upload.js
 *
 * JavaScript files loaded elsewhere (2):
 * - custom-avatar.js (loaded by inc/user-profiles/edit/upload-custom-avatar.php)
 * - manage-user-profile-links.js (loaded by inc/user-profiles/edit/user-links.php)
 *
 * CSS files (9): notifications.css, leaderboard.css, settings-page.css, bbpress.css,
 * home.css, topics-loop.css, replies-loop.css, user-profile.css, tinymce-editor.css
 *
 * @package ExtraChillCommunity
 */

function enqueue_fontawesome() {
    wp_enqueue_style('font-awesome', '//cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css');
}
add_action('wp_enqueue_scripts', 'enqueue_fontawesome');

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
        array('jquery'),
        filemtime(get_template_directory() . '/assets/js/shared-tabs.js'),
        true
    );
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_settings_page_assets');

function enqueue_bbpress_global_styles() {
    if (is_bbpress() || is_front_page() || is_home()) {
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

    if (bbp_is_single_reply() || bbp_is_single_topic() || bbp_is_single_user() || is_page_template('page-templates/recent-feed-template.php')) {
        wp_enqueue_style(
            'replies-loop',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/replies-loop.css',
            array('extrachill-bbpress'),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/replies-loop.css')
        );
    }

    // Load share button styles from theme on single topic pages
    if (bbp_is_single_topic()) {
        $share_css_path = get_template_directory() . '/assets/css/share.css';
        if (file_exists($share_css_path)) {
            wp_enqueue_style(
                'extrachill-share',
                get_template_directory_uri() . '/assets/css/share.css',
                array('extrachill-bbpress'),
                filemtime($share_css_path)
            );
        }
    }
}
add_action('wp_enqueue_scripts', 'modular_bbpress_styles');

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

    wp_enqueue_script('extrachill-upvote', $stylesheet_dir_uri . '/inc/assets/js/upvote.js', array('jquery'), $upvote_script_version, true);
    wp_localize_script('extrachill-upvote', 'extrachill_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('upvote_nonce'),
        'user_id' => get_current_user_id()
    ));

   if (is_bbpress()) {
       wp_enqueue_script('extrachill-mentions', $stylesheet_dir_uri . '/inc/assets/js/extrachill-mentions.js', array('jquery'), $mentions_script_version, true);
   }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_scripts', 20);

function enqueue_collapse_script() {
    if ( is_front_page() || is_home() || is_page_template('page-templates/recent-feed-template.php') ) {
        $script_path = '/inc/assets/js/home-collapse.js';
        $script_full_path = EXTRACHILL_COMMUNITY_PLUGIN_DIR . $script_path;
        $version = filemtime($script_full_path);
        wp_enqueue_script( 'home-collapse', EXTRACHILL_COMMUNITY_PLUGIN_URL . $script_path, array('jquery'), $version, true );
    }
}
add_action( 'wp_enqueue_scripts', 'enqueue_collapse_script' );

function enqueue_utilities() {
    $script_path = EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/js/utilities.js';
    $version = filemtime($script_path);
    wp_enqueue_script('extrachill-utilities', EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/utilities.js', array('jquery'), $version, true);
    wp_localize_script('extrachill-utilities', 'extrachill_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('extrachill_ajax_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'enqueue_utilities');

function enqueue_custom_tinymce_plugin_scripts() {
    if (is_bbpress() && (bbp_is_single_topic() || bbp_is_single_reply() || bbp_is_topic_edit() || bbp_is_reply_edit() || bbp_is_single_forum())) {
        $script_version = filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/js/tinymce-image-upload.js');

        wp_enqueue_script('custom-tinymce-plugin', EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/tinymce-image-upload.js', array('jquery'), $script_version, true);

        wp_localize_script('custom-tinymce-plugin', 'customTinymcePlugin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('handle_tinymce_image_upload_nonce'),
        ));
    }
}
add_action('wp_enqueue_scripts', 'enqueue_custom_tinymce_plugin_scripts');

function extrachill_dequeue_bbpress_default_styles() {
    wp_dequeue_style('bbp-default');
    wp_deregister_style('bbp-default');
}
add_action('wp_enqueue_scripts', 'extrachill_dequeue_bbpress_default_styles', 15);