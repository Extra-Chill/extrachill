<?php
/**
 * GeneratePress child theme functions and definitions.
 *
 * Add your custom PHP in this file.
 * Only edit this file if you have direct access to it on your server (to fix errors if they happen).
 */


include_once get_stylesheet_directory() . '/bbpress-customization.php';

$folder_path = get_stylesheet_directory() . '/extrachill-integration/';
$files = scandir($folder_path);

foreach ($files as $file) {
    $file_path = $folder_path . $file;
    
    // Check if the file is a PHP file and not a directory
    if (is_file($file_path) && pathinfo($file_path, PATHINFO_EXTENSION) === 'php') {
        include_once $file_path;
    }
}
$folder_path = get_stylesheet_directory() . '/forum-features/';
$files = scandir($folder_path);

foreach ($files as $file) {
    $file_path = $folder_path . $file;
    
    // Check if the file is a PHP file and not a directory
    if (is_file($file_path) && pathinfo($file_path, PATHINFO_EXTENSION) === 'php') {
        include_once $file_path;
    }
}

// Enqueue sorting script
function enqueue_sorting_script() {
    wp_enqueue_script('sorting', get_stylesheet_directory_uri() . '/js/sorting.js', ['jquery'], null, true);

    // Localize script to pass AJAX URL and nonce
    wp_localize_script('sorting', 'wpSurgeonAjax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wp_surgeon_sort_nonce')
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_sorting_script');

function extrachill_enqueue_scripts() {
    $stylesheet_dir_uri = get_stylesheet_directory_uri();
    $stylesheet_dir = get_stylesheet_directory();

    // Dynamic versioning based on file modification times
    $follow_script_version = filemtime( $stylesheet_dir . '/js/extrachill-follow.js' );
    $custom_avatar_script_version = filemtime( $stylesheet_dir . '/js/custom-avatar.js' );
    $upvote_script_version = filemtime( $stylesheet_dir . '/js/upvote.js' );

    // Enqueue the follow script with dynamic versioning
    wp_enqueue_script('extrachill-follow', $stylesheet_dir_uri . '/js/extrachill-follow.js', array('jquery'), $follow_script_version, true);
    wp_localize_script('extrachill-follow', 'extrachill_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('extrachill_follow_nonce'),
    ));
    wp_localize_script('extrachill-follow', 'extrachillData', array(
        'apiRoute' => esc_url(rest_url('extrachill/v1/manage_fan_profile')),
        'nonce' => wp_create_nonce('wp_rest')
    ));

    // Conditionally enqueue the custom avatar script with dynamic versioning
    if (function_exists('bbp_is_single_user_edit') && bbp_is_single_user_edit()) {
        wp_enqueue_script('extrachill-custom-avatar', $stylesheet_dir_uri . '/js/custom-avatar.js', array('jquery'), $custom_avatar_script_version, true);
        wp_localize_script('extrachill-custom-avatar', 'extrachillCustomAvatar', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('custom_avatar_upload_nonce')
        ));
    }

    // Only enqueue upvote script if not in forum 1494
    if (!function_exists('bbp_get_forum_id') || (function_exists('bbp_get_forum_id') && bbp_get_forum_id() != 1494)) {
        wp_enqueue_script('extrachill-upvote', $stylesheet_dir_uri . '/js/upvote.js', array('jquery'), $upvote_script_version, true);
        wp_localize_script('extrachill-upvote', 'extrachill_ajax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('upvote_nonce'),
            'is_user_logged_in' => is_user_logged_in(),
            'user_id' => get_current_user_id()
        ));
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_scripts');



function enqueue_quote_script() {
    // Check if bbPress is active and the current page is a bbPress page
    if (function_exists('is_bbpress') && is_bbpress()) {
        // Define the path to your script relative to the theme directory
        $script_path = '/js/quote.js';
        
        // Get the absolute path to the script file
        $script_file = get_stylesheet_directory() . $script_path;
        
        // Use filemtime() to get the file's last modified time for versioning
        $version = filemtime($script_file);
        
        // Register and enqueue the script with dynamic versioning
        wp_enqueue_script('custom-bbpress-quote', get_stylesheet_directory_uri() . $script_path, array('jquery'), $version, true);
    }
}
add_action('wp_enqueue_scripts', 'enqueue_quote_script');


function enqueue_profile_tab_script() {
    if (bbp_is_single_user()) { // Assuming bbPress user profile condition
        wp_enqueue_script('profile-tab-script', get_stylesheet_directory_uri() . '/js/user-profile-tabs.js', array('jquery'), null, true);
    }
}
add_action('wp_enqueue_scripts', 'enqueue_profile_tab_script');

function enqueue_collapse_script() {
    // Check if we're on the homepage or the forum front page
    if ( is_front_page() || is_page('/') ) {
        // Define the path to the script relative to the theme directory
        $script_path = '/js/home-collapse.js';

        // Get the full path to the script
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
    wp_localize_script('extrachill-utilities', 'extrachillQuote', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'quote_nonce' => wp_create_nonce('quote_nonce'), // Nonce specifically for quote actions
    ));
}

add_action('wp_enqueue_scripts', 'enqueue_utilities');


function enqueue_fontawesome() {
    wp_enqueue_style('font-awesome', '//cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css');
}
add_action('wp_enqueue_scripts', 'enqueue_fontawesome');


// Redirect non-admin users attempting to access the backend
function wp_surgeon_redirect_admin() {
    if (!current_user_can('administrator') && !(defined('DOING_AJAX') && DOING_AJAX)) {
        wp_redirect(home_url('/user-dashboard/'));
        exit;
    }
}
add_action('admin_init', 'wp_surgeon_redirect_admin');

// Function to create 'forum_user' role

function wp_surgeon_create_forum_user_role() {
    add_role('forum_user', 'Forum User', array(
        'read' => true, // Allows a user to read
        'level_0' => true, // Equivalent to subscriber
        'edit_posts' => true, // Allows editing of their own posts
                'edit_others_posts' => false,

    ));
} 

add_action('init', 'wp_surgeon_create_forum_user_role');

function wp_surgeon_custom_logout_url($logout_url, $redirect) {
    // Nonce for security
    $action = 'custom-logout-action';
    // Current URL for staying on the same page
    $current_url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $logout_url = add_query_arg('custom_logout', '1', $current_url);
    $logout_url = wp_nonce_url($logout_url, $action, 'logout_nonce');
    return $logout_url;
}
add_filter('logout_url', 'wp_surgeon_custom_logout_url', 10, 2);

function wp_surgeon_handle_custom_logout() {
    if (isset($_GET['custom_logout']) && $_GET['custom_logout'] == '1') {
        // Verify the nonce for security
        $nonce = $_GET['logout_nonce'] ?? '';
        if (wp_verify_nonce($nonce, 'custom-logout-action')) {
            wp_logout();
            wp_safe_redirect(remove_query_arg(['custom_logout', 'logout_nonce']));
            exit;
        }
    }
}
add_action('init', 'wp_surgeon_handle_custom_logout');

function wp_surgeon_get_readable_role($role) {
    $roles = array(
        'forum_user' => 'Community Member',
        // Add other roles here if needed
    );

    return isset($roles[$role]) ? $roles[$role] : ucfirst($role);
}


function wp_surgeon_has_profile_post($user_id, $profile_type) {
    // Define the custom query arguments to retrieve the fan profile post
    $args = array(
        'post_type' => $profile_type,
        'author' => $user_id,
        'posts_per_page' => 1, // Limit to 1 post per user
    );

    // Query the posts
    $profile_query = new WP_Query($args);

    if ($profile_query->have_posts()) {
        // Return the post ID of the first found fan profile
        return $profile_query->posts[0]->ID;
    }

    // If no fan profile is found, return false
    return false;
}

// Custom template include for profiles
add_filter('template_include', 'extrachill_custom_template_include');

function extrachill_custom_template_include($template) {
    $profile_types = ['fan', 'professional', 'artist'];
    foreach ($profile_types as $type) {
        if (is_singular($type . '_profile')) {
            $custom_template = locate_template('page-templates/' . $type . '-profile-template.php');
            if (!empty($custom_template)) {
                return $custom_template;
            }
        }
    }
    return $template;
}

/* 
 * 
 * ALL THE BASIC SHIT AT THE BOTTOM 
 * 
 * */ 

function homepage_custom_header_message() {
    if (is_front_page()) { // Check if this is the homepage
        echo '<h1>' . get_the_title() . '</h1>'; // Display the default homepage title
        if (is_user_logged_in()) {
            echo '<p>Welcome ' . esc_html(wp_get_current_user()->display_name) . ', <a href="/user-dashboard">View Dashboard</a></p>';
        } else {
            echo '<p>You are not signed in. <a href="' . wp_login_url() . '">Login</a>/<a href="' . wp_registration_url() . '">Register</a></p>';
        }
    }
}
add_action('generate_inside_site_header', 'homepage_custom_header_message');

add_action( 'wp', function() {
    remove_action( 'generate_before_content', 'generate_featured_page_header_inside_single', 10 );
	remove_action( 'generate_after_header', 'generate_featured_page_header',10 );
} );

add_filter( 'generate_copyright', 'extrachill_footer_text' );
function extrachill_footer_text() {
    ?>
    &copy; <?php echo date( 'Y' ); ?> <a href="https://www.extrachill.com" target="_blank" rel="noopener noreferrer"><?php bloginfo( 'name' ); ?></a>
    <?php
}

/*     external links in new tab     */

function add_target_blank_to_external_links($content) {
    $home_url = home_url(); // Gets your blog's URL
    $content = preg_replace_callback(
        '@<a\s[^>]*href=([\'"])(.+?)\1[^>]*>@i',
        function($matches) use ($home_url) {
            // Extract the domain from the link
            $url_parts = parse_url($matches[2]);
            $domain = isset($url_parts['host']) ? $url_parts['host'] : '';

            // Check if the domain is not extrachill.com or its subdomains and not internal links
            if ($domain !== 'extrachill.com' && !preg_match('/\.extrachill\.com$/i', $domain) && strpos($matches[2], $home_url) === false) {
                // Add target="_blank" and rel="noopener noreferrer" to external links
                return str_replace('<a', '<a target="_blank" rel="noopener noreferrer"', $matches[0]);
            } else {
                // Return the original match if it's an internal link or to extrachill.com or its subdomains
                return $matches[0];
            }
        },
        $content
    );

    return $content;
}

add_filter('the_content', 'add_target_blank_to_external_links');

function custom_search_filter($query) {
    if (!is_admin() && $query->is_main_query()) {
        if ($query->is_search) {
            // Define which post types to include in search results
            $query->set('post_type', array('post', 'page', 'forum', 'topic', 'reply'));
        }
    }
    return $query;
}
add_filter('pre_get_posts', 'custom_search_filter');

// Remove admin bar for all users except administrators
function wp_surgeon_remove_admin_bar() {
    if (!current_user_can('administrator')) {
        show_admin_bar(false);
    }
}
add_action('after_setup_theme', 'wp_surgeon_remove_admin_bar');