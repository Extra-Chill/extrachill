<?php
/**
 * Extra Chill Community Theme functions and definitions
 * 
 * Hybrid WordPress theme with PSR-4 autoloading, modular asset management,
 * bbPress integration, and cross-domain authentication support.
 *
 * @package ExtraChillCommunity
 * @version 1.0.0
 * @author Chris Huber
 * @link https://community.extrachill.com
 */

  /**
 * Theme setup and WordPress feature support
 */
function extra_chill_community_setup() {
    // Add default posts and comments RSS feed links to head.
    add_theme_support('automatic-feed-links');

    // Let WordPress manage the document title.
    add_theme_support('title-tag');

    // Enable support for Post Thumbnails on posts and pages.
    add_theme_support('post-thumbnails');

    // Enable support for custom logo.
    add_theme_support('custom-logo', array(
        'height'      => 250,
        'width'       => 250,
        'flex-width'  => true,
        'flex-height' => true,
    ));

    // Enable support for HTML5 markup.
    add_theme_support('html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script',
    ));

    // Add theme support for selective refresh for widgets.
    add_theme_support('customize-selective-refresh-widgets');

    // Register navigation menus.
    register_nav_menus(array(
        'primary'      => esc_html__('Primary Menu', 'extra-chill-community'),
        'footer'       => esc_html__('Footer Menu', 'extra-chill-community'),
        'footer-extra' => esc_html__('Footer Extra Menu', 'extra-chill-community'),
    ));
    
    // Register additional footer menus
    for ( $i = 1; $i <= 5; $i++ ) {
        register_nav_menus(array(
            'footer-' . $i => sprintf(esc_html__('Footer Menu %d', 'extra-chill-community'), $i),
        ));
    }
}
add_action('after_setup_theme', 'extra_chill_community_setup');

/**
 * Register widget areas.
 */
function extra_chill_community_widgets_init() {
    register_sidebar(array(
        'name'          => esc_html__('Sidebar', 'extra-chill-community'),
        'id'            => 'sidebar-1',
        'description'   => esc_html__('Add widgets here.', 'extra-chill-community'),
        'before_widget' => '<section id="%1$s" class="widget %2$s">',
        'after_widget'  => '</section>',
        'before_title'  => '<h2 class="widget-title">',
        'after_title'   => '</h2>',
    ));
    
    // Register footer widget areas
    for ( $i = 1; $i <= 5; $i++ ) {
        register_sidebar(array(
            'name'          => sprintf(esc_html__('Footer Widget Area %d', 'extra-chill-community'), $i),
            'id'            => 'footer-' . $i,
            'description'   => sprintf(esc_html__('Footer widget area %d.', 'extra-chill-community'), $i),
            'before_widget' => '<section id="%1$s" class="widget %2$s">',
            'after_widget'  => '</section>',
            'before_title'  => '<h3 class="widget-title">',
            'after_title'   => '</h3>',
        ));
    }
}
add_action('widgets_init', 'extra_chill_community_widgets_init');
/**
 * Enqueue notifications styles only on notifications page
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

/**
 * Enqueue leaderboard styles only on leaderboard template
 */
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



// ExtraChill Integration Files
require_once get_stylesheet_directory() . '/extrachill-integration/article-topic-sync.php';
require_once get_stylesheet_directory() . '/extrachill-integration/blog-searching-forum.php';
require_once get_stylesheet_directory() . '/extrachill-integration/extrachill-com-articles.php';
require_once get_stylesheet_directory() . '/extrachill-integration/extrachill-comments.php';
require_once get_stylesheet_directory() . '/extrachill-integration/get-user-details.php';
require_once get_stylesheet_directory() . '/extrachill-integration/rest-api-forums-feed.php';
require_once get_stylesheet_directory() . '/extrachill-integration/seamless-comments.php';
require_once get_stylesheet_directory() . '/extrachill-integration/seamless-login.php';
require_once get_stylesheet_directory() . '/extrachill-integration/serve-login-form.php';
require_once get_stylesheet_directory() . '/extrachill-integration/session-tokens.php';
require_once get_stylesheet_directory() . '/extrachill-integration/validate-session.php';

// Forum Features - Master loader for all forum functionality
require_once get_stylesheet_directory() . '/forum-features/forum-features.php';

// Include the new login module includes file
require_once get_stylesheet_directory() . '/login/login-includes.php';

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
            'nonce' => wp_create_nonce('custom_avatar_upload_nonce')
        ));

        // Enqueue dynamic user profile links script
        $script_path = '/js/manage-user-profile-links.js';
        $script_url = $stylesheet_dir_uri . $script_path;
        $script_version = filemtime($stylesheet_dir . $script_path);
        wp_enqueue_script('manage-user-profile-links', $script_url, array('jquery'), $script_version, true);
        // Prepare link types (reuse band profile types if available)
        $link_types = function_exists('bp_get_supported_social_link_types') ? bp_get_supported_social_link_types() : array(
            'website' => array('label' => 'Website'),
            'instagram' => array('label' => 'Instagram'),
            'twitter' => array('label' => 'Twitter'),
            'facebook' => array('label' => 'Facebook'),
            'spotify' => array('label' => 'Spotify'),
            'soundcloud' => array('label' => 'SoundCloud'),
            'bandcamp' => array('label' => 'Bandcamp'),
            'custom' => array('label' => 'Custom', 'has_custom_label' => true),
        );
        $existing_links = get_user_meta(bbp_get_displayed_user_id(), '_user_profile_dynamic_links', true);
        if (!is_array($existing_links)) $existing_links = array();
        wp_localize_script('manage-user-profile-links', 'userProfileLinksData', array(
            'existingLinks' => $existing_links,
            'linkTypes' => $link_types,
            'text' => array(
                'removeLink' => __('Remove Link', 'bbpress'),
                'customLinkLabel' => __('Custom Link Label', 'bbpress'),
            ),
        ));
    }

    // Only enqueue upvote script if not in forum 1494
    if (!function_exists('bbp_get_forum_id') || (function_exists('bbp_get_forum_id') && bbp_get_forum_id() != 1494)) {
        wp_enqueue_script('extrachill-upvote', $stylesheet_dir_uri . '/forum-features/social/js/upvote.js', array('jquery'), $upvote_script_version, true);
        wp_localize_script('extrachill-upvote', 'extrachill_ajax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('upvote_nonce'),
            'is_user_logged_in' => is_user_logged_in(),
            'user_id' => get_current_user_id()
        ));
    }

    // Conditionally enqueue the mentions script with dynamic versioning for bbPress
   if (function_exists('is_bbpress') && is_bbpress()) {
       wp_enqueue_script('extrachill-mentions', $stylesheet_dir_uri . '/forum-features/social/js/extrachill-mentions.js', array('jquery'), $mentions_script_version, true);
   }

}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_scripts');


add_action('wp_enqueue_scripts', 'modular_bbpress_styles');

function modular_bbpress_styles() {
    // Forums Loop - Load only on forum listing/single forum
    if (bbp_is_forum_archive() || is_front_page() || bbp_is_single_forum()) {
        wp_enqueue_style(
            'forums-loop',
            get_stylesheet_directory_uri() . '/css/forums-loop.css',
            array('extra-chill-community-style'),
            filemtime(get_stylesheet_directory() . '/css/forums-loop.css')
        );
    }

    // Topics Loop - Load only on topic archives/single topic/search results (WP & bbP)/relevant pages
    if ( bbp_is_topic_archive() || bbp_is_single_forum() || is_page('recent') || is_page('following') || bbp_is_single_user() || bbp_is_search_results() || is_search() ) {
        wp_enqueue_style(
            'topics-loop',
            get_stylesheet_directory_uri() . '/css/topics-loop.css',
            array('extra-chill-community-style'),
            filemtime(get_stylesheet_directory() . '/css/topics-loop.css')
        );
    }


    // Replies Loop - Load only when replies are displayed
    if (bbp_is_single_reply() || bbp_is_single_topic() || bbp_is_single_user()) {
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


add_action('wp_enqueue_scripts', 'enqueue_user_profile_styles');

function enqueue_user_profile_styles() {
    // User Profile styles - Load only on user profile pages OR the band directory forum
    if ( (function_exists('bbp_is_single_user') && (bbp_is_single_user() || bbp_is_single_user_edit() || bbp_is_user_home())) || 
         (function_exists('bbp_is_single_forum') && bbp_is_single_forum(5432)) // Band Directory Forum ID
       ) {
        wp_enqueue_style(
            'user-profile', // This handle might be slightly misleading now, but keep for consistency unless a rename is preferred.
            get_stylesheet_directory_uri() . '/css/user-profile.css',
            array('extra-chill-community-style'),
            filemtime(get_stylesheet_directory() . '/css/user-profile.css')
        );
        // Enqueue band card styles for user profile band grid AND band directory
        wp_enqueue_style(
            'band-profile-cards',
            get_stylesheet_directory_uri() . '/css/band-profile-cards.css',
            array('extra-chill-community-style'), // Or 'user-profile' if it should load after it
            filemtime(get_stylesheet_directory() . '/css/band-profile-cards.css')
        );
    }
}

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


function enqueue_collapse_script() {
    // Check if we're on the homepage or the forum front page
    if ( is_front_page() || is_home() || is_singular('band_profile') ) {
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


// Redirect non-admin users attempting to access the backend and hide admin bar for non-admins
function extrachill_redirect_admin() {
    // If user is not an administrator
    if (!current_user_can('administrator')) {
        // Hide admin bar for non-admins
        show_admin_bar(false);
        
        // If trying to access wp-admin, redirect to homepage
        // Allow admin-ajax.php for AJAX requests
        if (is_admin() && !wp_doing_ajax()) {
            wp_safe_redirect(home_url('/'));
            exit();
        }
    }
    // Administrators get full access to wp-admin and keep admin bar
}
add_action('admin_init', 'extrachill_redirect_admin');

// Prevent WordPress core from redirecting logged-in administrators
function extrachill_prevent_admin_auth_redirect($redirect_to, $requested_redirect_to, $user) {
    // If user is administrator and trying to access wp-admin, ensure they get there
    if (isset($user->ID) && current_user_can('administrator', $user->ID)) {
        if (!empty($requested_redirect_to) && strpos($requested_redirect_to, '/wp-admin') !== false) {
            return $requested_redirect_to; // Send admin directly to wp-admin
        }
        if (!empty($redirect_to) && strpos($redirect_to, '/wp-admin') !== false) {
            return $redirect_to; // Send admin directly to wp-admin
        }
    }
    return $redirect_to;
}
add_filter('login_redirect', 'extrachill_prevent_admin_auth_redirect', 5, 3); // High priority

// Function to create 'forum_user' role

function extrachill_create_forum_user_role() {
    add_role('forum_user', 'Forum User', array(
        'read' => true, // Allows a user to read
        'level_0' => true, // Equivalent to subscriber
        'edit_posts' => true, // Allows editing of their own posts
                'edit_others_posts' => false,

    ));
}

add_action('init', 'extrachill_create_forum_user_role');

function extrachill_get_readable_role($role) {
    $roles = array(
        'forum_user' => 'Community Member',
        // Add other roles here if needed
    );

    return isset($roles[$role]) ? $roles[$role] : ucfirst($role);
}




/*
 *
 * ALL THE BASIC SHIT AT THE BOTTOM
 *
 * */



/**
 * Footer copyright text
 */
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
    if (!is_admin() && $query->is_main_query() && function_exists('bbp_is_search') && bbp_is_search()) {
        // Include forum and bbPress post types only on bbPress search contexts
        $query->set('post_type', array('post', 'page', 'forum', 'topic', 'reply'));
    }
    return $query;
}
add_filter('pre_get_posts', 'custom_search_filter');


class Custom_Walker_Nav_Menu extends Walker_Nav_Menu {
    function start_lvl( &$output, $depth = 0, $args = null ) {
        if ( isset( $args->item_spacing ) && 'discard' === $args->item_spacing ) {
            $t = '';
            $n = '';
        } else {
            $t = "\t";
            $n = "\n";
        }
        $indent = str_repeat( $t, $depth );
        $classes = array( 'sub-menu' );
        $class_names = join( ' ', apply_filters( 'nav_menu_submenu_css_class', $classes, $args, $depth ) );
        $class_names = $class_names ? ' class="' . esc_attr( $class_names ) . '"' : '';

        $output .= "{$n}{$indent}<ul$class_names>{$n}";
    }

    function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
        if ( isset( $args->item_spacing ) && 'discard' === $args->item_spacing ) {
            $t = '';
            $n = '';
        } else {
            $t = "\t";
            $n = "\n";
        }
        $indent = ( $depth ) ? str_repeat( $t, $depth ) : '';

        $classes = empty( $item->classes ) ? array() : (array) $item->classes;
        $classes[] = 'menu-item-' . $item->ID;

        $args = (object) $args;
        $class_names = join( ' ', apply_filters( 'nav_menu_css_class', array_filter( $classes ), $item, $args, $depth ) );
        $class_names = $class_names ? ' class="' . esc_attr( $class_names ) . '"' : '';
        $id = apply_filters( 'nav_menu_item_id', 'menu-item-'. $item->ID, $item, $args, $depth );
        $id = $id ? ' id="' . esc_attr( $id ) . '"' : '';
        $output .= $indent . '<li' . $id . $class_names .'>';

        $atts = array();
        $atts['title']  = ! empty( $item->attr_title ) ? $item->attr_title : '';
        $atts['target'] = ! empty( $item->target )     ? $item->target     : '';
        $atts['rel']    = ! empty( $item->xfn )        ? $item->xfn        : '';
        $atts['href']   = ! empty( $item->url )        ? $item->url        : '';
        $atts = apply_filters( 'nav_menu_link_attributes', $atts, $item, $args, $depth );
        $attributes = '';
        foreach ( $atts as $attr => $value ) {
            if ( ! empty( $value ) ) {
                $value = ( 'href' === $attr ) ? esc_url( $value ) : esc_attr( $value );
                $attributes .= ' ' . $attr . '="' . $value . '"';
            }
        }
        $item_output = $args->before;
        $item_output .= '<a'. $attributes .'>';
        $item_output .= $args->link_before . apply_filters( 'the_title', $item->title, $item->ID ) . $args->link_after;

        // Add a submenu indicator if the item has children
        if ( in_array( 'menu-item-has-children', $classes ) ) {
            // Check if it's a top-level item by looking for menu-item-depth-0 class
            $is_top_level = in_array('menu-item-depth-0', $classes);
            if ( $is_top_level ) {
                // Replace SVG with Font Awesome icon
                $item_output .= ' <i class="submenu-indicator fas fa-angle-down"></i>';
            }
        }
        $item_output .= '</a>';
        $item_output .= $args->after;
        $output .= apply_filters( 'walker_nav_menu_start_el', $item_output, $item, $depth, $args );
    }
}

function extrachill_enqueue_nav_scripts() {
    $script_path = '/js/nav-menu.js';
    $version = filemtime(get_stylesheet_directory() . $script_path);

    wp_enqueue_script('extrachill-nav-menu', get_stylesheet_directory_uri() . $script_path, array(), $version, true);

}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_nav_scripts');

// Forum features are now loaded via the master forum-features.php file above

function set_default_ec_custom_title( $user_id ) {
    update_user_meta( $user_id, 'ec_custom_title', 'Extra Chillian' );
}
add_action( 'user_register', 'set_default_ec_custom_title' );




// Load Band Platform files if the directory exists
$band_platform_dir = get_stylesheet_directory() . '/band-platform';
if ( is_dir( $band_platform_dir ) ) {
    // Centralized include for all band platform PHP files
    require_once( $band_platform_dir . '/band-platform-includes.php' ); 

// Removed temporary test file 
}

// --- Admin Script for Band Members Meta Box ---
function bp_enqueue_admin_scripts( $hook ) {
    global $post;
    // Only load on the edit screen for the 'band_profile' CPT
    if ( 'post.php' == $hook || 'post-new.php' == $hook ) { 
        if ( isset($post->post_type) && 'band_profile' === $post->post_type ) {

            wp_enqueue_script( 
                'band-members-admin', 
                get_stylesheet_directory_uri() . '/js/band-members-admin.js', 
                array( 'jquery' ), 
                filemtime( get_stylesheet_directory() . '/js/band-members-admin.js' ), // Dynamic versioning
                true // Load in footer
            );

            // Localize script to pass data
            wp_localize_script( 'band-members-admin', 'bpMemberArgs', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'searchNonce' => wp_create_nonce( 'bp_member_search_nonce' ), // Generate the nonce here
                'postId' => isset($post->ID) ? $post->ID : 0,
                'noMembersText' => __( 'No members linked yet.', 'extra-chill-community' ) // Pass translatable string
            ));
        }
    }
}
add_action( 'admin_enqueue_scripts', 'bp_enqueue_admin_scripts' );

// --- End Admin Script --- 

/**
 * Enqueue theme stylesheet and scripts.
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
 * Dequeue bbPress default stylesheet to prevent conflicts with custom styling
 */
function extrachill_dequeue_bbpress_default_styles() {
    wp_dequeue_style('bbp-default');
    wp_deregister_style('bbp-default');
}
add_action('wp_enqueue_scripts', 'extrachill_dequeue_bbpress_default_styles', 15);

/**
 * bbPress Customizations
 * Custom hooks, filters, and modifications for bbPress forum integration.
 */

// Disable bbPress default search - using custom search implementation
add_filter('bbp_allow_search', '__return_false');

// Remove topic tags in bbPress
function remove_bbpress_topic_tags() {
    return false;
}
add_filter('bbp_allow_topic_tags', 'remove_bbpress_topic_tags');

// Add a filter to display custom message below edit form in bbPress
add_action('bbp_theme_after_topic_form', 'custom_message_below_edit_form');

function custom_message_below_edit_form() {
    // Get the current post ID
    $post_id = get_the_ID();
    
    // Check if this is the designated music submission forum (configurable via option)
    $music_submission_forum_id = get_option('extrachill_music_submission_forum_id', 138);
    if (is_bbpress() && $post_id == $music_submission_forum_id) {
        // Display the conditional message below the edit form
        echo '<p>Are you an artist submitting your own music? See our <a href="/new-music-submission-guidelines">Music Submission Guidelines</a>.</p>';
    }
}

function remove_counts() {
    $args['show_topic_count'] = false;
    $args['show_reply_count'] = false;
    $args['count_sep'] = '';
return $args;
}
add_filter('bbp_before_list_forums_parse_args', 'remove_counts' );

// Remove reply and edit links from admin links
function ec_remove_reply_and_edit_from_admin_links( $links, $reply_id ) {
    if ( isset( $links['reply'] ) ) {
        unset( $links['reply'] ); // Remove Reply
    }
    if ( isset( $links['edit'] ) ) {
        unset( $links['edit'] ); // Remove Edit
    }
    return $links;
}
add_filter( 'bbp_reply_admin_links', 'ec_remove_reply_and_edit_from_admin_links', 10, 2 );
add_filter( 'bbp_topic_admin_links', 'ec_remove_reply_and_edit_from_admin_links', 10, 2 );

/**
 * Get a human-readable timestamp for a topic's last active time.
 *
 * @param int $topic_id The topic ID.
 * @return string Human-readable time difference (e.g., "5 minutes ago").
 */
function ec_get_topic_last_active_diff( $topic_id ) {
    // Get the last active time as stored by bbPress.
    $last_active_time = bbp_get_topic_last_active_time( $topic_id );
    if ( ! empty( $last_active_time ) ) {
        // Convert it to a timestamp.
        $timestamp = strtotime( $last_active_time );
        // Calculate the time difference from now.
        return human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ago';
    }
    return '';
}

// Function to enqueue assets for the Manage Band Profile page
function extrachill_enqueue_manage_band_profile_assets() {
    if (is_page_template('page-templates/manage-band-profile.php')) {
        // Enqueue specific CSS for manage band profile page
        wp_enqueue_style(
            'manage-band-profile-style',
            get_stylesheet_directory_uri() . '/css/manage-band-profile.css',
            array('shared-tabs'), // Add shared-tabs as a dependency
            filemtime(get_stylesheet_directory() . '/css/manage-band-profile.css')
        );

        // Enqueue specific JS for manage band profile page
        $manage_js_path = get_stylesheet_directory() . '/js/manage-band-profiles.js';
        if ( file_exists( $manage_js_path ) ) {
            wp_enqueue_script(
                'manage-band-profile-script', // Consistent handle
                get_stylesheet_directory_uri() . '/js/manage-band-profiles.js',
                array('jquery', 'shared-tabs'), // Add shared-tabs as a dependency
                filemtime( $manage_js_path ),
                true
            );

            // Localize script to pass data, similar to what was in band-platform-includes.php
            $band_id = isset( $_GET['band_id'] ) ? absint( $_GET['band_id'] ) : 0;
            // If creating a new band, a temporary ID or a signal might be passed, or rely on create_band_profile AJAX to return it.
            // For now, assume 0 if not set for existing band context.
            
            $current_user_id = get_current_user_id();
            $band_profile_id_from_user = 0;

            if ( $band_id === 0 && $current_user_id > 0 ) {
                // Attempt to get the band_id from user meta if not in URL (e.g., user's own default band page)
                $user_band_profiles = get_user_meta( $current_user_id, 'band_profile_ids', true );
                if ( ! empty( $user_band_profiles ) && is_array( $user_band_profiles ) ) {
                    // For simplicity, take the first one. Or, implement logic to select a primary/default.
                    $band_profile_id_from_user = reset( $user_band_profiles ); 
                }
            }
            // Prioritize URL param, then user meta, then 0.
            $final_band_id = $band_id ?: $band_profile_id_from_user;

            $data_to_pass = array(
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'bandProfileId' => $final_band_id, 
                'ajaxAddNonce'  => wp_create_nonce( 'bp_ajax_add_roster_member_nonce' ), // Ensure this nonce is verified in the AJAX handler
                'ajaxRemovePlaintextNonce' => wp_create_nonce( 'bp_ajax_remove_plaintext_member_nonce' ),
                'ajaxInviteMemberByEmailNonce' => wp_create_nonce( 'bp_ajax_invite_member_by_email_nonce' ),
                // Nonce for image uploads if handled by this script, or ensure custom-avatar.js handles it with its own nonce
                'i18n' => array( // For any translatable strings used in manage-band-profiles.js
                    'confirmRemoveMember' => __('Are you sure you want to remove "%s" from the roster listing?', 'extra-chill-community'),
                    'enterEmail' => __('Please enter an email address.', 'extra-chill-community'),
                    'sendingInvitation' => __('Sending...', 'extra-chill-community'),
                    'sendInvitation' => __('Send Invitation', 'extra-chill-community'),
                    'errorSendingInvitation' => __('Error: Could not send invitation.', 'extra-chill-community'),
                    'errorAjax' => __('An error occurred. Please try again.', 'extra-chill-community'),
                    'errorRemoveListing' => __('Error: Could not remove listing.', 'extra-chill-community')
                )
            );
            wp_localize_script( 'manage-band-profile-script', 'bpManageMembersData', $data_to_pass );
        }
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_manage_band_profile_assets');

/**
 * Remove private forum IDs option on theme deactivation.
 */
function extrachill_remove_private_forum_ids_option() {
    delete_option( 'extrachill_private_forum_ids' );
}
add_action( 'switch_theme', 'extrachill_remove_private_forum_ids_option' );

/**
 * Enqueue assets for the User Settings page.
 */
function extrachill_enqueue_settings_page_assets() {
    if (is_page_template('page-templates/settings-page.php')) {
        wp_enqueue_style(
            'settings-page-style',
            get_stylesheet_directory_uri() . '/css/settings-page.css',
            array('shared-tabs'), // Add shared-tabs as a dependency
            filemtime(get_stylesheet_directory() . '/css/settings-page.css')
        );
        // No specific JS for settings page anymore as tabs are shared.
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_settings_page_assets');

// Function to enqueue shared tab assets
function extrachill_enqueue_shared_tabs_assets() {
    // Define page templates that use the shared tabs
    $shared_tabs_templates = array(
        'page-templates/settings-page.php',
        'page-templates/manage-band-profile.php',
        'page-templates/manage-link-page.php',
        'page-templates/login-register-template.php'
    );

    if (is_page_template($shared_tabs_templates)) {
        wp_enqueue_style(
            'shared-tabs',
            get_stylesheet_directory_uri() . '/css/shared-tabs.css',
            array(), // Add dependencies like 'extra-chill-community-style' if needed
            filemtime(get_stylesheet_directory() . '/css/shared-tabs.css')
        );

        wp_enqueue_script(
            'shared-tabs',
            get_stylesheet_directory_uri() . '/js/shared-tabs.js',
            array('jquery'), // jQuery is not strictly needed by the current shared-tabs.js, can be removed if confirmed
            filemtime(get_stylesheet_directory() . '/js/shared-tabs.js'),
            true // Load in footer
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_shared_tabs_assets');


// Function to enqueue Band Switcher component styles
function extrachill_enqueue_band_switcher_styles() {
    $band_switcher_templates = array(
        'page-templates/manage-band-profile.php',
        'page-templates/manage-link-page.php'
    );

    if (is_page_template($band_switcher_templates)) {
        wp_enqueue_style(
            'band-switcher-styles',
            get_stylesheet_directory_uri() . '/css/components/band-switcher.css',
            array(), // No specific dependencies, or perhaps 'shared-tabs' if always used together
            filemtime(get_stylesheet_directory() . '/css/components/band-switcher.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_band_switcher_styles');

/**
 * Add custom rewrite rules for extrachill.link domain.
 */
function extrch_add_link_page_rewrites() {
    // Only apply these rules if the current host is extrachill.link
    $current_host = strtolower( $_SERVER['HTTP_HOST'] ?? '' );
    if ( stripos( $current_host, 'extrachill.link' ) !== false ) {
        // Rewrite rule for the root extrachill.link URL to load the default 'extrachill' link page
        add_rewrite_rule(
            '^$',
            'index.php?post_type=band_link_page&name=extrachill',
            'top'
        );

        // Rewrite rule for extrachill.link/bandname/ to load the corresponding band_link_page
        add_rewrite_rule(
            '^([^/]+)/?$',
            'index.php?post_type=band_link_page&name=$matches[1]',
            'top'
        );
    }
}
add_action('init', 'extrch_add_link_page_rewrites');

/**
 * Redirects users from the default WordPress login page to the custom login page.
 */
function extrachill_redirect_wp_login() {
    // Get the full REQUEST_URI to catch all wp-login.php access patterns
    $request_uri = $_SERVER['REQUEST_URI'];
    
    // Check if the user is trying to access wp-login.php
    if (strpos($request_uri, 'wp-login.php') !== false && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Allow specific actions like logout, lostpassword, resetpass
        $allowed_actions = ['logout', 'lostpassword', 'resetpass', 'rp', 'activate'];
        $action = isset($_GET['action']) ? $_GET['action'] : '';

        // If user is already logged in and is an administrator, allow access to wp-login.php
        // This prevents redirect loops when admins try to access wp-admin
        if (is_user_logged_in() && current_user_can('administrator')) {
            return; // Allow administrators full access to wp-login.php
        }

        // For non-logged-in users or non-admins, redirect to custom login page
        if (!in_array($action, $allowed_actions) && !is_user_logged_in()) {
            // Preserve any redirect_to parameter
            $redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '';
            $login_url = home_url('/login');
            if (!empty($redirect_to)) {
                $login_url = add_query_arg('redirect_to', urlencode($redirect_to), $login_url);
            }
            wp_redirect($login_url);
            exit();
        }
    }
}
add_action('template_redirect', 'extrachill_redirect_wp_login');

/**
 * Filters the login URL to use the custom login page.
 */
function extrachill_custom_login_url($login_url, $redirect) {
    // If user is already logged in and is an administrator, don't modify the login URL
    // This prevents redirect loops when admins try to access wp-admin
    if (is_user_logged_in() && current_user_can('administrator')) {
        return $login_url; // Return the original wp-login.php URL for admins
    }
    
    // Use the custom login page URL for non-admins or non-logged-in users
    $custom_login_url = home_url('/login');

    // Append redirect_to parameter if it exists
    if (!empty($redirect)) {
        $custom_login_url = add_query_arg('redirect_to', urlencode($redirect), $custom_login_url);
    }

    return $custom_login_url;
}
add_filter('login_url', 'extrachill_custom_login_url', 10, 2);


/**
 * Clear most active users cache when new topics or replies are created
 * This ensures the cached data stays fresh when new activity occurs
 */
function clear_most_active_users_cache( $post_id, $post, $update ) {
    // Only clear cache for new posts, not updates
    if ( $update ) {
        return;
    }
    
    // Only clear cache for topics and replies
    if ( in_array( $post->post_type, array( 'topic', 'reply' ) ) ) {
        delete_transient( 'most_active_users_30_days' );
    }
}
add_action( 'wp_insert_post', 'clear_most_active_users_cache', 10, 3 );

/**
 * Manual function to clear most active users cache
 * Can be called from admin or via AJAX if needed
 */
function clear_most_active_users_cache_manual() {
    $deleted = delete_transient( 'most_active_users_30_days' );
    return $deleted;
}

/**
 * AJAX handler for manually clearing most active users cache
 * Only accessible to administrators
 */
function ajax_clear_most_active_users_cache() {
    // Check nonce for security
    if ( ! wp_verify_nonce( $_POST['nonce'], 'clear_most_active_users_cache' ) ) {
        wp_die( 'Security check failed' );
    }
    
    // Check user permissions
    if ( ! current_user_can( 'administrator' ) ) {
        wp_die( 'Insufficient permissions' );
    }
    
    $result = clear_most_active_users_cache_manual();
    
    wp_send_json_success( array(
        'cleared' => $result,
        'message' => $result ? 'Cache cleared successfully' : 'Cache was already empty or expired'
    ) );
}
add_action( 'wp_ajax_clear_most_active_users_cache', 'ajax_clear_most_active_users_cache' );

/**
 * Clear online users related caches when needed
 * This ensures the online users count stays accurate
 */
function clear_online_users_cache() {
    delete_transient( 'online_users_count' );
    delete_transient( 'most_ever_online_check' );
}

/**
 * Clear user activity cache when user logs out
 * This ensures accurate online user counts
 */
function clear_user_activity_cache_on_logout( $user_id ) {
    $user_activity_cache_key = 'user_activity_' . $user_id;
    delete_transient( $user_activity_cache_key );
}
add_action( 'wp_logout', 'clear_user_activity_cache_on_logout' );

/**
 * Clear user activity cache when user logs in
 * This ensures fresh activity tracking
 */
function clear_user_activity_cache_on_login( $user_login, $user ) {
    if ( $user && isset( $user->ID ) ) {
        $user_activity_cache_key = 'user_activity_' . $user->ID;
        delete_transient( $user_activity_cache_key );
    }
}
add_action( 'wp_login', 'clear_user_activity_cache_on_login', 10, 2 );
