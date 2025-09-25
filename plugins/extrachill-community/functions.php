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

function extra_chill_community_setup() {
    add_theme_support('automatic-feed-links');
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');

    add_theme_support('custom-logo', array(
        'height'      => 250,
        'width'       => 250,
        'flex-width'  => true,
        'flex-height' => true,
    ));

    add_theme_support('html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script',
    ));

    add_theme_support('customize-selective-refresh-widgets');

    register_nav_menus(array(
        'primary'      => esc_html__('Primary Menu', 'extra-chill-community'),
        'footer'       => esc_html__('Footer Menu', 'extra-chill-community'),
        'footer-extra' => esc_html__('Footer Extra Menu', 'extra-chill-community'),
    ));

    for ( $i = 1; $i <= 5; $i++ ) {
        register_nav_menus(array(
            'footer-' . $i => sprintf(esc_html__('Footer Menu %d', 'extra-chill-community'), $i),
        ));
    }
}
add_action('after_setup_theme', 'extra_chill_community_setup');

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




require_once get_stylesheet_directory() . '/extrachill-integration/blog-searching-forum.php';
require_once get_stylesheet_directory() . '/extrachill-integration/extrachill-com-articles.php';
require_once get_stylesheet_directory() . '/extrachill-integration/extrachill-comments.php';
require_once get_stylesheet_directory() . '/extrachill-integration/get-user-details.php';
require_once get_stylesheet_directory() . '/extrachill-integration/seamless-comments.php';
require_once get_stylesheet_directory() . '/extrachill-integration/session-tokens.php';
require_once get_stylesheet_directory() . '/extrachill-integration/validate-session.php';

require_once get_stylesheet_directory() . '/forum-features/forum-features.php';

require_once get_stylesheet_directory() . '/login/login-includes.php';
require_once get_stylesheet_directory() . '/login/email-change-emails.php';













// Load core functionality modules
require_once get_template_directory() . '/inc/core/admin-access-control.php';
require_once get_template_directory() . '/inc/core/assets.php';

/**
 * Clean up unused forum_user role from database
 */
function extrachill_cleanup_forum_user_role_notice() {
    if (!current_user_can('administrator')) {
        return;
    }

    if (get_option('extrachill_forum_user_cleanup_completed') || get_option('extrachill_forum_user_cleanup_dismissed')) {
        return;
    }

    if (!get_role('forum_user')) {
        update_option('extrachill_forum_user_cleanup_completed', true);
        return;
    }

    if (isset($_GET['extrachill_cleanup_forum_role']) && wp_verify_nonce($_GET['_wpnonce'], 'cleanup_forum_role')) {
        remove_role('forum_user');
        update_option('extrachill_forum_user_cleanup_completed', true);
        echo '<div class="notice notice-success is-dismissible"><p><strong>Extra Chill:</strong> Unused forum_user role has been removed from the database.</p></div>';
        return;
    }

    if (isset($_GET['extrachill_dismiss_cleanup']) && wp_verify_nonce($_GET['_wpnonce'], 'dismiss_cleanup')) {
        update_option('extrachill_forum_user_cleanup_dismissed', true);
        return;
    }

    $cleanup_url = wp_nonce_url(add_query_arg('extrachill_cleanup_forum_role', '1'), 'cleanup_forum_role');
    $dismiss_url = wp_nonce_url(add_query_arg('extrachill_dismiss_cleanup', '1'), 'dismiss_cleanup');

    echo '<div class="notice notice-info is-dismissible">';
    echo '<p><strong>Extra Chill Theme Cleanup:</strong> An unused "forum_user" role was found in your database from previous theme code.</p>';
    echo '<p><a href="' . esc_url($cleanup_url) . '" class="button button-primary">Clean Up Now</a> ';
    echo '<a href="' . esc_url($dismiss_url) . '" class="button button-secondary">Dismiss</a></p>';
    echo '</div>';
}
add_action('admin_notices', 'extrachill_cleanup_forum_user_role_notice');

/**
 * Core utility functions
 */

function custom_search_filter($query) {
    if (!is_admin() && $query->is_main_query() && function_exists('bbp_is_search') && bbp_is_search()) {
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

        if ( in_array( 'menu-item-has-children', $classes ) ) {
            $is_top_level = in_array('menu-item-depth-0', $classes);
            if ( $is_top_level ) {
                $item_output .= ' <i class="submenu-indicator fas fa-angle-down"></i>';
            }
        }
        $item_output .= '</a>';
        $item_output .= $args->after;
        $output .= apply_filters( 'walker_nav_menu_start_el', $item_output, $item, $depth, $args );
    }
}



function set_default_ec_custom_title( $user_id ) {
    update_user_meta( $user_id, 'ec_custom_title', 'Extra Chillian' );
}
add_action( 'user_register', 'set_default_ec_custom_title' );




 



/**
 * bbPress Customizations
 * Custom hooks, filters, and modifications for bbPress forum integration.
 */

add_filter('bbp_allow_search', '__return_false');

function remove_bbpress_topic_tags() {
    return false;
}
add_filter('bbp_allow_topic_tags', 'remove_bbpress_topic_tags');

add_action('bbp_theme_after_topic_form', 'custom_message_below_edit_form');

function custom_message_below_edit_form() {
    $post_id = get_the_ID();
    $music_submission_forum_id = get_option('extrachill_music_submission_forum_id', 138);

    if (is_bbpress() && $post_id == $music_submission_forum_id) {
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

function ec_remove_reply_and_edit_from_admin_links( $links, $reply_id ) {
    if ( isset( $links['reply'] ) ) {
        unset( $links['reply'] );
    }
    if ( isset( $links['edit'] ) ) {
        unset( $links['edit'] );
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
    if (!function_exists('bbp_get_topic_last_active_time')) {
        return '';
    }

    $last_active_time = bbp_get_topic_last_active_time( $topic_id );
    if ( ! empty( $last_active_time ) ) {
        $timestamp = strtotime( $last_active_time );
        return human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ago';
    }
    return '';
}


/**
 * Remove private forum IDs option on theme deactivation.
 */
function extrachill_remove_private_forum_ids_option() {
    delete_option( 'extrachill_private_forum_ids' );
}
add_action( 'switch_theme', 'extrachill_remove_private_forum_ids_option' );




/**
 * Redirect wp-login.php access to custom login page
 */
function extrachill_redirect_wp_login() {
    $request_uri = $_SERVER['REQUEST_URI'];

    if (strpos($request_uri, 'wp-login.php') !== false && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $allowed_actions = ['logout', 'lostpassword', 'resetpass', 'rp', 'activate'];
        $action = isset($_GET['action']) ? $_GET['action'] : '';

        if (is_user_logged_in() && current_user_can('administrator')) {
            return;
        }

        if (!in_array($action, $allowed_actions) && !is_user_logged_in()) {
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
 * Filter login URL to use custom login page
 */
function extrachill_custom_login_url($login_url, $redirect) {
    if (is_user_logged_in() && current_user_can('administrator')) {
        return $login_url;
    }

    $custom_login_url = home_url('/login');

    if (!empty($redirect)) {
        $custom_login_url = add_query_arg('redirect_to', urlencode($redirect), $custom_login_url);
    }

    return $custom_login_url;
}
add_filter('login_url', 'extrachill_custom_login_url', 10, 2);


/**
 * Clear most active users cache on new forum activity
 */
function clear_most_active_users_cache( $post_id, $post, $update ) {
    if ( $update ) {
        return;
    }

    if ( in_array( $post->post_type, array( 'topic', 'reply' ) ) ) {
        delete_transient( 'most_active_users_30_days' );
    }
}
add_action( 'wp_insert_post', 'clear_most_active_users_cache', 10, 3 );

function clear_most_active_users_cache_manual() {
    $deleted = delete_transient( 'most_active_users_30_days' );
    return $deleted;
}

/**
 * AJAX handler for clearing most active users cache
 */
function ajax_clear_most_active_users_cache() {
    if ( ! wp_verify_nonce( $_POST['nonce'], 'clear_most_active_users_cache' ) ) {
        wp_die( 'Security check failed' );
    }

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

function clear_online_users_cache() {
    delete_transient( 'online_users_count' );
    delete_transient( 'most_ever_online_check' );
}

function clear_user_activity_cache_on_logout( $user_id ) {
    $user_activity_cache_key = 'user_activity_' . $user_id;
    delete_transient( $user_activity_cache_key );
}
add_action( 'wp_logout', 'clear_user_activity_cache_on_logout' );

function clear_user_activity_cache_on_login( $user_login, $user ) {
    if ( $user && isset( $user->ID ) ) {
        $user_activity_cache_key = 'user_activity_' . $user->ID;
        delete_transient( $user_activity_cache_key );
    }
}
add_action( 'wp_login', 'clear_user_activity_cache_on_login', 10, 2 );

/**
 * Email Change Management Functions
 * Helper functions for secure email address change verification system
 */

if ( ! function_exists( 'extrachill_get_pending_email_changes' ) ) {
    /**
     * Get all pending email changes with expired entries cleanup
     *
     * @return array Pending email changes data
     */
    function extrachill_get_pending_email_changes() {
        $pending_changes = get_option( 'extrachill_pending_email_changes', array() );
        $current_time = current_time( 'timestamp' );
        $cleaned_changes = array();

        foreach ( $pending_changes as $user_id => $change_data ) {
            if ( isset( $change_data['timestamp'] ) && ( $current_time - $change_data['timestamp'] ) < ( 48 * HOUR_IN_SECONDS ) ) {
                $cleaned_changes[ $user_id ] = $change_data;
            }
        }

        if ( count( $cleaned_changes ) !== count( $pending_changes ) ) {
            update_option( 'extrachill_pending_email_changes', $cleaned_changes );
        }

        return $cleaned_changes;
    }
}

if ( ! function_exists( 'extrachill_get_user_pending_email_change' ) ) {
    /**
     * @param int $user_id
     * @return array|false Pending change data or false if none exists
     */
    function extrachill_get_user_pending_email_change( $user_id ) {
        $pending_changes = extrachill_get_pending_email_changes();
        return isset( $pending_changes[ $user_id ] ) ? $pending_changes[ $user_id ] : false;
    }
}

if ( ! function_exists( 'extrachill_store_pending_email_change' ) ) {
    /**
     * @param int $user_id
     * @param string $new_email
     * @param string $verification_hash
     * @return bool Success status
     */
    function extrachill_store_pending_email_change( $user_id, $new_email, $verification_hash ) {
        $current_user = get_userdata( $user_id );
        if ( ! $current_user ) {
            return false;
        }

        $pending_changes = extrachill_get_pending_email_changes();

        $pending_changes[ $user_id ] = array(
            'new_email' => $new_email,
            'old_email' => $current_user->user_email,
            'hash' => $verification_hash,
            'timestamp' => current_time( 'timestamp' ),
            'user_id' => $user_id
        );

        return update_option( 'extrachill_pending_email_changes', $pending_changes );
    }
}

if ( ! function_exists( 'extrachill_remove_pending_email_change' ) ) {
    /**
     * @param int $user_id
     * @return bool Success status
     */
    function extrachill_remove_pending_email_change( $user_id ) {
        $pending_changes = extrachill_get_pending_email_changes();

        if ( isset( $pending_changes[ $user_id ] ) ) {
            unset( $pending_changes[ $user_id ] );
            return update_option( 'extrachill_pending_email_changes', $pending_changes );
        }

        return true;
    }
}

if ( ! function_exists( 'extrachill_validate_email_change_hash' ) ) {
    /**
     * @param string $hash Verification hash from URL
     * @return array|false User data and email change info, or false if invalid
     */
    function extrachill_validate_email_change_hash( $hash ) {
        $pending_changes = extrachill_get_pending_email_changes();

        foreach ( $pending_changes as $user_id => $change_data ) {
            if ( isset( $change_data['hash'] ) && hash_equals( $change_data['hash'], $hash ) ) {
                return array(
                    'user_id' => $user_id,
                    'new_email' => $change_data['new_email'],
                    'old_email' => $change_data['old_email'],
                    'timestamp' => $change_data['timestamp']
                );
            }
        }

        return false;
    }
}

if ( ! function_exists( 'extrachill_is_email_available' ) ) {
    /**
     * @param string $email Email address to check
     * @param int $exclude_user_id User ID to exclude from check
     * @return bool True if available, false if taken
     */
    function extrachill_is_email_available( $email, $exclude_user_id = 0 ) {
        $existing_user = get_user_by( 'email', $email );
        if ( $existing_user && $existing_user->ID !== $exclude_user_id ) {
            return false;
        }

        $pending_changes = extrachill_get_pending_email_changes();
        foreach ( $pending_changes as $user_id => $change_data ) {
            if ( $user_id !== $exclude_user_id && $change_data['new_email'] === $email ) {
                return false;
            }
        }

        return true;
    }
}

if ( ! function_exists( 'extrachill_generate_email_change_hash' ) ) {
    /**
     * @param int $user_id
     * @param string $new_email
     * @return string Secure hash
     */
    function extrachill_generate_email_change_hash( $user_id, $new_email ) {
        $timestamp = current_time( 'timestamp' );
        $random = wp_generate_password( 32, false );

        return hash( 'sha256', $user_id . $new_email . $timestamp . $random . wp_salt() );
    }
}

if ( ! function_exists( 'extrachill_can_user_change_email' ) ) {
    /**
     * Check if user can initiate email change with rate limiting
     *
     * @param int $user_id
     * @return bool True if allowed, false if rate limited
     */
    function extrachill_can_user_change_email( $user_id ) {
        $pending_change = extrachill_get_user_pending_email_change( $user_id );
        if ( $pending_change ) {
            $time_since_last = current_time( 'timestamp' ) - $pending_change['timestamp'];
            return $time_since_last > HOUR_IN_SECONDS;
        }

        $last_change = get_user_meta( $user_id, '_last_email_change', true );
        if ( $last_change ) {
            $time_since_last_change = current_time( 'timestamp' ) - $last_change;
            return $time_since_last_change > ( 24 * HOUR_IN_SECONDS );
        }

        return true;
    }
}
