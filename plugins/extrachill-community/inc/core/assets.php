<?php
/**
 * Asset Management
 * 
 * Centralized loading of CSS and JavaScript files for the Extra Chill Community theme.
 * Handles conditional loading, dynamic versioning, and dependency management.
 * 
 * @package Extra Chill Community
 */

/**
 * Load main theme stylesheet
 */
function extra_chill_community_enqueue_scripts() {
    // Enqueue main theme stylesheet
    wp_enqueue_style( 'extra-chill-community-style', 
        get_stylesheet_uri(), 
        array(), 
        filemtime(get_stylesheet_directory() . '/style.css') 
    );
}
add_action( 'wp_enqueue_scripts', 'extra_chill_community_enqueue_scripts', 10 );

/**
 * Load external CDN resources
 */
function enqueue_fontawesome() {
    wp_enqueue_style('font-awesome', '//cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css');
}
add_action('wp_enqueue_scripts', 'enqueue_fontawesome');

/**
 * Load conditional page template assets
 */
function extrachill_enqueue_notification_styles() {
    if (is_page_template('page-templates/notifications-feed.php')) {
        wp_enqueue_style(
            'extrachill-notifications', 
            get_stylesheet_directory_uri() . '/forum-features/social/css/notifications.css', 
            array('extra-chill-community-style'), 
            filemtime(get_stylesheet_directory() . '/forum-features/social/css/notifications.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_notification_styles');

function extrachill_enqueue_leaderboard_styles() {
    if (is_page_template('page-templates/leaderboard-template.php')) {
        wp_enqueue_style(
            'extrachill-leaderboard', 
            get_stylesheet_directory_uri() . '/css/leaderboard.css', 
            array('extra-chill-community-style'), 
            filemtime(get_stylesheet_directory() . '/css/leaderboard.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_leaderboard_styles');

function extrachill_enqueue_settings_page_assets() {
    if (is_page_template('page-templates/settings-page.php')) {
        wp_enqueue_style(
            'settings-page-style',
            get_stylesheet_directory_uri() . '/css/settings-page.css',
            array('shared-tabs'), // Add shared-tabs as a dependency
            filemtime(get_stylesheet_directory() . '/css/settings-page.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_settings_page_assets');

function extrachill_enqueue_shared_tabs_assets() {
    // Define page templates that use the shared tabs
    $shared_tabs_templates = array(
        'page-templates/settings-page.php',
        'page-templates/login-register-template.php'
    );

    if (is_page_template($shared_tabs_templates)) {
        wp_enqueue_style(
            'shared-tabs',
            get_stylesheet_directory_uri() . '/css/shared-tabs.css',
            array(), 
            filemtime(get_stylesheet_directory() . '/css/shared-tabs.css')
        );

        wp_enqueue_script(
            'shared-tabs',
            get_stylesheet_directory_uri() . '/js/shared-tabs.js',
            array('jquery'), 
            filemtime(get_stylesheet_directory() . '/js/shared-tabs.js'),
            true // Load in footer
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_shared_tabs_assets');

/**
 * Load bbPress context-aware assets
 */
function modular_bbpress_styles() {
    // Forums Loop - Load only on forum listing/single forum
    if ((function_exists('bbp_is_forum_archive') && bbp_is_forum_archive()) || is_front_page() || (function_exists('bbp_is_single_forum') && bbp_is_single_forum())) {
        wp_enqueue_style(
            'forums-loop',
            get_stylesheet_directory_uri() . '/css/forums-loop.css',
            array('extra-chill-community-style'),
            filemtime(get_stylesheet_directory() . '/css/forums-loop.css')
        );
    }

    // Topics Loop - Load only on topic archives/single topic/search results/relevant pages
    if ( (function_exists('bbp_is_topic_archive') && bbp_is_topic_archive()) || (function_exists('bbp_is_single_forum') && bbp_is_single_forum()) || is_page('recent') || is_page('following') || (function_exists('bbp_is_single_user') && bbp_is_single_user()) || (function_exists('bbp_is_search_results') && bbp_is_search_results()) || is_search() ) {
        wp_enqueue_style(
            'topics-loop',
            get_stylesheet_directory_uri() . '/css/topics-loop.css',
            array('extra-chill-community-style'),
            filemtime(get_stylesheet_directory() . '/css/topics-loop.css')
        );
    }

    // Replies Loop - Load only when replies are displayed
    if ((function_exists('bbp_is_single_reply') && bbp_is_single_reply()) || (function_exists('bbp_is_single_topic') && bbp_is_single_topic()) || (function_exists('bbp_is_single_user') && bbp_is_single_user()) || is_page_template('page-templates/recent-feed-template.php')) {
        wp_enqueue_style(
            'replies-loop',
            get_stylesheet_directory_uri() . '/css/replies-loop.css',
            array('extra-chill-community-style'),
            filemtime(get_stylesheet_directory() . '/css/replies-loop.css')
        );
    }

    if (is_page('register') || is_page('login') || is_page_template('page-templates/login-register-template.php')) {
        wp_enqueue_style(
            'register',
            get_stylesheet_directory_uri() . '/css/login-register.css',
            array('extra-chill-community-style'),
            filemtime(get_stylesheet_directory() . '/css/login-register.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'modular_bbpress_styles');

function enqueue_user_profile_styles() {
    // User Profile styles - Load only on user profile pages
    if ( (function_exists('bbp_is_single_user') && (bbp_is_single_user() || (function_exists('bbp_is_single_user_edit') && bbp_is_single_user_edit()) || (function_exists('bbp_is_user_home') && bbp_is_user_home())))
       ) {
        wp_enqueue_style(
            'user-profile',
            get_stylesheet_directory_uri() . '/css/user-profile.css',
            array('extra-chill-community-style'),
            filemtime(get_stylesheet_directory() . '/css/user-profile.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'enqueue_user_profile_styles');

/**
 * Load interactive JavaScript with AJAX localization
 */
function extrachill_enqueue_scripts() {
    $stylesheet_dir_uri = get_stylesheet_directory_uri();
    $stylesheet_dir = get_stylesheet_directory();

    // Dynamic versioning based on file modification times
    $follow_script_version = filemtime( $stylesheet_dir . '/forum-features/social/js/extrachill-follow.js' );
    $custom_avatar_script_version = filemtime( $stylesheet_dir . '/js/custom-avatar.js' );
    $upvote_script_version = filemtime( $stylesheet_dir . '/forum-features/social/js/upvote.js' );
    $mentions_script_version = filemtime( $stylesheet_dir . '/forum-features/social/js/extrachill-mentions.js' );

    // Conditionally enqueue the custom avatar script with dynamic versioning
    if (function_exists('bbp_is_single_user_edit') && bbp_is_single_user_edit()) {
        wp_enqueue_script('extrachill-custom-avatar', $stylesheet_dir_uri . '/js/custom-avatar.js', array('jquery'), $custom_avatar_script_version, true);
        wp_localize_script('extrachill-custom-avatar', 'extrachillCustomAvatar', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('extrachill_custom_avatar_nonce')
        ));
        
        $script_path = '/js/manage-user-profile-links.js';
        $script_url = $stylesheet_dir_uri . $script_path;
        $script_version = filemtime($stylesheet_dir . $script_path);
        wp_enqueue_script('manage-user-profile-links', $script_url, array('jquery'), $script_version, true);
        // Prepare link types for user profile social links
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

    // Enqueue upvote script
    wp_enqueue_script('extrachill-upvote', $stylesheet_dir_uri . '/forum-features/social/js/upvote.js', array('jquery'), $upvote_script_version, true);
    wp_localize_script('extrachill-upvote', 'extrachill_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('upvote_nonce'),
        'user_id' => get_current_user_id()
    ));

    // Conditionally enqueue the mentions script with dynamic versioning for bbPress
   if (function_exists('is_bbpress') && is_bbpress()) {
       wp_enqueue_script('extrachill-mentions', $stylesheet_dir_uri . '/forum-features/social/js/extrachill-mentions.js', array('jquery'), $mentions_script_version, true);
   }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_scripts', 20);

function enqueue_quote_script() {
    // Check if bbPress is active and the current page is a bbPress page
    if (function_exists('is_bbpress') && is_bbpress()) {
        // Define the script path
        $script_path = '/js/quote.js';
        
        // Get the full file path for the file check
        $script_file = get_stylesheet_directory() . $script_path;

        // Use filemtime() to get the file's last modified time for versioning
        $version = filemtime($script_file);

        // Register and enqueue the script with dynamic versioning
        wp_enqueue_script('custom-bbpress-quote', get_stylesheet_directory_uri() . $script_path, array('jquery'), $version, true);
    }
}
add_action('wp_enqueue_scripts', 'enqueue_quote_script');

function enqueue_collapse_script() {
    // Check if we're on the homepage or the forum front page
    if ( is_front_page() || is_home() || is_page_template('page-templates/recent-feed-template.php') ) {
        // Define the script path relative to the stylesheet directory
        $script_path = '/js/home-collapse.js';
        
        // Get the full file path for the filemtime() function
        $script_full_path = get_stylesheet_directory() . $script_path;

        // Use file's last modified time as version to ensure fresh cache on updates
        $version = filemtime($script_full_path);

        // Enqueue the script with dynamic versioning
        wp_enqueue_script( 'home-collapse', get_stylesheet_directory_uri() . $script_path, array('jquery'), $version, true );
    }
}
add_action( 'wp_enqueue_scripts', 'enqueue_collapse_script' );

function enqueue_utilities() {
    // Get the file path
    $script_path = get_stylesheet_directory() . '/js/utilities.js';

    // Fetch the last modified time of the file for versioning
    $version = filemtime($script_path);

    // Enqueue the script with dynamic versioning
    wp_enqueue_script('extrachill-utilities', get_stylesheet_directory_uri() . '/js/utilities.js', array('jquery'), $version, true);

    // Localize the script for AJAX functionality
    wp_localize_script('extrachill-utilities', 'extrachill_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('extrachill_ajax_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'enqueue_utilities');

function extrachill_enqueue_nav_scripts() {
    $script_path = '/js/nav-menu.js';
    $version = filemtime(get_stylesheet_directory() . $script_path);

    wp_enqueue_script('extrachill-nav-menu', get_stylesheet_directory_uri() . $script_path, array(), $version, true);
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_nav_scripts');

/**
 * Asset cleanup and dequeuing
 */
function extrachill_dequeue_bbpress_default_styles() {
    wp_dequeue_style('bbp-default');
    wp_deregister_style('bbp-default');
}
add_action('wp_enqueue_scripts', 'extrachill_dequeue_bbpress_default_styles', 15);