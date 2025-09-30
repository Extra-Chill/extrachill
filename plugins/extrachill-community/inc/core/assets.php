<?php
/**
 * Asset Management
 *
 * Centralized loading of CSS and JavaScript files for the Extra Chill Community plugin.
 * Handles conditional loading, dynamic versioning, and dependency management.
 *
 * @package ExtraChillCommunity
 */

function extra_chill_community_enqueue_scripts() {
    wp_enqueue_style( 'extra-chill-community-style',
        get_stylesheet_uri(),
        array(),
        filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/style.css')
    );
}
add_action( 'wp_enqueue_scripts', 'extra_chill_community_enqueue_scripts', 10 );

function enqueue_fontawesome() {
    wp_enqueue_style('font-awesome', '//cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css');
}
add_action('wp_enqueue_scripts', 'enqueue_fontawesome');

function extrachill_enqueue_notification_styles() {
    if (is_page_template('page-templates/notifications-feed.php')) {
        wp_enqueue_style(
            'extrachill-notifications',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/notifications.css',
            array('extra-chill-community-style'),
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
            array('extra-chill-community-style'),
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

/**
 * Load bbPress context-aware assets
 */
function modular_bbpress_styles() {
    if (bbp_is_forum_archive() || is_front_page() || bbp_is_single_forum()) {
        wp_enqueue_style(
            'forums-loop',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/forums-loop.css',
            array('extra-chill-community-style'),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/forums-loop.css')
        );
    }

    if ( bbp_is_topic_archive() || bbp_is_single_forum() || is_page('recent') || is_page('following') || bbp_is_single_user() || bbp_is_search_results() || is_search() ) {
        wp_enqueue_style(
            'topics-loop',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/topics-loop.css',
            array('extra-chill-community-style'),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/topics-loop.css')
        );
    }

    if (bbp_is_single_reply() || bbp_is_single_topic() || bbp_is_single_user() || is_page_template('page-templates/recent-feed-template.php')) {
        wp_enqueue_style(
            'replies-loop',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/replies-loop.css',
            array('extra-chill-community-style'),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/replies-loop.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'modular_bbpress_styles');

function enqueue_user_profile_styles() {
    if ( bbp_is_single_user() || bbp_is_single_user_edit() || bbp_is_user_home() ) {
        wp_enqueue_style(
            'user-profile',
            EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/user-profile.css',
            array('extra-chill-community-style'),
            filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/user-profile.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'enqueue_user_profile_styles');

/**
 * Load interactive JavaScript with AJAX localization
 */
function extrachill_enqueue_scripts() {
    $stylesheet_dir_uri = EXTRACHILL_COMMUNITY_PLUGIN_URL;
    $stylesheet_dir = EXTRACHILL_COMMUNITY_PLUGIN_DIR;

    $follow_script_version = filemtime( $stylesheet_dir . '/inc/assets/js/extrachill-follow.js' );
    $custom_avatar_script_version = filemtime( $stylesheet_dir . '/inc/assets/js/custom-avatar.js' );
    $upvote_script_version = filemtime( $stylesheet_dir . '/inc/assets/js/upvote.js' );
    $mentions_script_version = filemtime( $stylesheet_dir . '/inc/assets/js/extrachill-mentions.js' );

    if (bbp_is_single_user_edit()) {
        wp_enqueue_script('extrachill-custom-avatar', $stylesheet_dir_uri . '/inc/assets/js/custom-avatar.js', array('jquery'), $custom_avatar_script_version, true);
        wp_localize_script('extrachill-custom-avatar', 'extrachillCustomAvatar', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('extrachill_custom_avatar_nonce')
        ));
        
        $script_path = '/inc/assets/js/manage-user-profile-links.js';
        $script_url = $stylesheet_dir_uri . $script_path;
        $script_version = filemtime($stylesheet_dir . $script_path);
        wp_enqueue_script('manage-user-profile-links', $script_url, array('jquery'), $script_version, true);
        $link_types = function_exists('bp_get_supported_social_link_types') ? bp_get_supported_social_link_types() : array(
            'facebook' => array('label' => 'Facebook', 'icon' => 'fab fa-facebook'),
            'instagram' => array('label' => 'Instagram', 'icon' => 'fab fa-instagram'),
            'twitter' => array('label' => 'Twitter', 'icon' => 'fab fa-twitter'),
            'youtube' => array('label' => 'YouTube', 'icon' => 'fab fa-youtube'),
            'tiktok' => array('label' => 'TikTok', 'icon' => 'fab fa-tiktok'),
            'spotify' => array('label' => 'Spotify', 'icon' => 'fab fa-spotify'),
            'soundcloud' => array('label' => 'SoundCloud', 'icon' => 'fab fa-soundcloud'),
            'bandcamp' => array('label' => 'Bandcamp', 'icon' => 'fab fa-bandcamp'),
            'website' => array('label' => 'Website', 'icon' => 'fas fa-globe'),
            'other' => array('label' => 'Other', 'icon' => 'fas fa-link')
        );
        wp_localize_script('manage-user-profile-links', 'ExtrachillProfileLinks', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('manage_user_profile_links_nonce'),
            'linkTypes' => $link_types
        ));
    }

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

function enqueue_quote_script() {
    if (is_bbpress()) {
        $script_path = '/inc/assets/js/quote.js';
        $script_file = EXTRACHILL_COMMUNITY_PLUGIN_DIR . $script_path;
        $version = filemtime($script_file);
        wp_enqueue_script('custom-bbpress-quote', EXTRACHILL_COMMUNITY_PLUGIN_URL . $script_path, array('jquery'), $version, true);
    }
}
add_action('wp_enqueue_scripts', 'enqueue_quote_script');

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

function extrachill_enqueue_nav_scripts() {
    $script_path = '/inc/assets/js/nav-menu.js';
    $version = filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . $script_path);

    wp_enqueue_script('extrachill-nav-menu', EXTRACHILL_COMMUNITY_PLUGIN_URL . $script_path, array(), $version, true);
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_nav_scripts');

/**
 * Enqueue TinyMCE image upload plugin scripts
 */
function enqueue_custom_tinymce_plugin_scripts() {
    // Check if we're on a bbPress page and specifically on a topic or reply form.
    if (is_bbpress() && (bbp_is_single_topic() || bbp_is_single_reply() || bbp_is_topic_edit() || bbp_is_reply_edit() || bbp_is_single_forum())) {
        // Dynamically version the script based on the file modification time for cache busting.
        $script_version = filemtime(EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/js/tinymce-image-upload.js');

        wp_enqueue_script('custom-tinymce-plugin', EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/tinymce-image-upload.js', array('jquery'), $script_version, true);

        wp_localize_script('custom-tinymce-plugin', 'customTinymcePlugin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('handle_tinymce_image_upload_nonce'),
        ));
    }
}
add_action('wp_enqueue_scripts', 'enqueue_custom_tinymce_plugin_scripts');

/**
 * Enqueue Topic Quick Reply CSS and JS only on single topic pages.
 */
function extrachill_enqueue_topic_quick_reply_assets() {
    if ( bbp_is_single_topic() ) {
        $css_path = EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/topic-quick-reply.css';
        $js_path = EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/js/topic-quick-reply.js';

        if ( file_exists( $css_path ) ) {
             wp_enqueue_style(
                'extrachill-topic-quick-reply',
                EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/topic-quick-reply.css',
                array(),
                filemtime( $css_path )
            );
        }

        if ( file_exists( $js_path ) ) {
             wp_enqueue_script(
                'extrachill-topic-quick-reply',
                EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/js/topic-quick-reply.js',
                array('jquery', 'editor', 'quicktags'), // Keep editor dependencies
                filemtime( $js_path ),
                true // Load in footer
            );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_topic_quick_reply_assets' );

/**
 * Enqueue sorting script (currently disabled)
 */
function enqueue_sorting_script() {
    wp_enqueue_script('sorting', EXTRACHILL_COMMUNITY_PLUGIN_URL . 'inc/assets/js/sorting.js', ['jquery'], null, true);
    wp_localize_script('sorting', 'extraChillAjax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('extrachill_sort_nonce')
    ]);
}
// add_action('wp_enqueue_scripts', 'enqueue_sorting_script'); // Currently disabled

/**
 * Dequeue bbPress default styles for custom styling
 */
function extrachill_dequeue_bbpress_default_styles() {
    wp_dequeue_style('bbp-default');
    wp_deregister_style('bbp-default');
}
add_action('wp_enqueue_scripts', 'extrachill_dequeue_bbpress_default_styles', 15);