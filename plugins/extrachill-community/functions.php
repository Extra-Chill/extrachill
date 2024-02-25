<?php
/**
 * GeneratePress child theme functions and definitions.
 *
 * Add your custom PHP in this file.
 * Only edit this file if you have direct access to it on your server (to fix errors if they happen).
 */



// Include login functionalities
include_once get_stylesheet_directory() . '/login.php';

// Include registration functionalities
include_once get_stylesheet_directory() . '/register.php';

// Include community profiles functionalities
include_once get_stylesheet_directory() . '/profiles/register-profiles.php';

// In your functions.php
require_once get_stylesheet_directory() . '/profiles/fan-profile.php';
require_once get_stylesheet_directory() . '/profiles/professional-profile.php';
require_once get_stylesheet_directory() . '/profiles/artist-profile.php';
include_once get_stylesheet_directory() . '/bbpress/bbpress-customization.php';
require_once get_stylesheet_directory() . '/social-networking.php'; 
include_once get_stylesheet_directory() . '/chill-forums-rank.php';
include_once get_stylesheet_directory() . '/restricted-forums.php';
include_once get_stylesheet_directory() . '/following-feed.php';
include_once get_stylesheet_directory() . '/upvote.php'; 
include_once get_stylesheet_directory() . '/team-members-mods.php'; 
include_once get_stylesheet_directory() . '/custom-avatar.php'; 
include_once get_stylesheet_directory() . '/verification.php'; 
include_once get_stylesheet_directory() . '/recent-feed.php'; 
include_once get_stylesheet_directory() . '/notifications.php'; 
include_once get_stylesheet_directory() . '/forum-badges.php'; 
include_once get_stylesheet_directory() . '/tinymce-image-uploads.php'; 
include_once get_stylesheet_directory() . '/quote.php';  
include_once get_stylesheet_directory() . '/tinymce-customization.php';  
include_once get_stylesheet_directory() . '/moderation.php'; 
include_once get_stylesheet_directory() . '/dynamic-menu.php';  
include_once get_stylesheet_directory() . '/quick-post.php';  



$folder_path = get_stylesheet_directory() . '/extrachill-integration/';
$files = scandir($folder_path);

foreach ($files as $file) {
    $file_path = $folder_path . $file;
    
    // Check if the file is a PHP file and not a directory
    if (is_file($file_path) && pathinfo($file_path, PATHINFO_EXTENSION) === 'php') {
        include_once $file_path;
    }
}


function extrachill_enqueue_scripts() {
    // Enqueue the follow script
    wp_enqueue_script('extrachill-follow', get_stylesheet_directory_uri() . '/js/extrachill-follow.js', array('jquery'), null, true);
    wp_localize_script('extrachill-follow', 'extrachill_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('extrachill_follow_nonce'), // Nonce for follow/unfollow actions
    ));

    // Enqueue the quoting functionality script (utilities.js)
    wp_enqueue_script('extrachill-utilities', get_stylesheet_directory_uri() . '/js/utilities.js', array('jquery'), null, true);
    wp_localize_script('extrachill-utilities', 'extrachillQuote', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'quote_nonce' => wp_create_nonce('quote_nonce'), // Nonce specifically for quote actions
    ));

    // Localize the 'extrachill-follow' script again with 'extrachillData'
    wp_localize_script('extrachill-follow', 'extrachillData', array(
        'apiRoute' => esc_url(rest_url('extrachill/v1/manage_fan_profile')),
        'nonce' => wp_create_nonce('wp_rest') // Nonce for profile management
    ));

    // Enqueue scripts for profile creation and editing pages
    if (is_page_template('page-templates/create-profiles-template.php') || is_page_template('page-templates/edit-profiles-template.php')) {
        wp_enqueue_script('extrachill-profile-management', get_stylesheet_directory_uri() . '/js/extrachill-profile-management.js', array('jquery'), '', true);
        wp_localize_script('extrachill-profile-management', 'extrachillData', array(
            'apiRoute' => esc_url(rest_url('extrachill/v1/manage_fan_profile')),
            'nonce' => wp_create_nonce('wp_rest') // Nonce for profile management
        ));
    }

    // Conditionally enqueue the custom avatar script only on bbPress user profile edit pages
    if (function_exists('bbp_is_single_user_edit') && bbp_is_single_user_edit()) {
        wp_enqueue_script('extrachill-custom-avatar', get_stylesheet_directory_uri() . '/js/custom-avatar.js', array('jquery'), null, true);
        wp_localize_script('extrachill-custom-avatar', 'extrachillCustomAvatar', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('custom_avatar_upload_nonce') // Nonce for custom avatar upload
        ));
    }

    // Enqueue the upvote script
    wp_enqueue_script('extrachill-upvote', get_stylesheet_directory_uri() . '/js/upvote.js', array('jquery'), null, true);
    wp_localize_script('extrachill-upvote', 'extrachill_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('upvote_nonce'),
        'is_user_logged_in' => is_user_logged_in(),
        'user_id' => get_current_user_id() // Add this line to make the user ID available
    ));
    

    // Only enqueue sorting.js on the 'social' page
    if (is_page('social') || (function_exists('get_post') && get_post()->post_name == 'social')) {
        wp_enqueue_script('extrachill-sorting', get_stylesheet_directory_uri() . '/js/sorting.js', array('jquery'), null, true);
        // Assuming sorting.js might need some localized data, add wp_localize_script() here if needed
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_scripts');


function enqueue_profile_tab_script() {
    if (bbp_is_single_user()) { // Assuming bbPress user profile condition
        wp_enqueue_script('profile-tab-script', get_stylesheet_directory_uri() . '/js/user-profile-tabs.js', array('jquery'), null, true);
    }
}
add_action('wp_enqueue_scripts', 'enqueue_profile_tab_script');



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
