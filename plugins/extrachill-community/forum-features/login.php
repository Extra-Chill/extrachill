<?php
function wp_surgeon_login_form() {
    ob_start(); // Start output buffering

    if (is_user_logged_in()) {
        wp_surgeon_handle_logged_in_user();
    } else {
        wp_surgeon_display_login_form();
        wp_surgeon_display_error_messages(); // Display error messages
    }

    return ob_get_clean(); // Clean (erase) the output buffer and turn off output buffering
}
add_shortcode('wp_surgeon_login', 'wp_surgeon_login_form');

function wp_surgeon_handle_logged_in_user() {
    if (is_admin()) {
        return;
    }

    $login_page_url = home_url('/login/');
    $current_url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $is_login_page = $current_url == $login_page_url;

    if (!$is_login_page && !empty($_REQUEST['redirect_to'])) {
        wp_redirect($_REQUEST['redirect_to']);
    } else {
        wp_redirect(home_url());
    }
    exit;
}

add_action('wp_ajax_handle_login', 'wp_surgeon_handle_login');
add_action('wp_ajax_nopriv_handle_login', 'wp_surgeon_handle_login');

function wp_surgeon_handle_login() {
    if (defined('DOING_AJAX') && DOING_AJAX) {
        $user = wp_authenticate($_POST['log'], $_POST['pwd']);
        if (is_wp_error($user)) {
            $reset_password_link = wp_lostpassword_url();
            $error_message = $user->get_error_message() . ' <a href="' . esc_url($reset_password_link) . '">Forgot your password?</a>';
            wp_send_json_error(['message' => $error_message]);
        } else {
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID);
            wp_send_json_success(['message' => 'Login successful']);
        }
    } else {
        $user = wp_signon();
        if (is_wp_error($user)) {
            wp_redirect(add_query_arg('login', 'failed', home_url('/login/')));
            exit;
        }
        wp_redirect($_POST['redirect_to']);
        exit;
    }
}


function wp_surgeon_display_login_form() {
    $is_login_page = ($_SERVER['REQUEST_URI'] == '/login/' || strpos($_SERVER['REQUEST_URI'], '/login') !== false);
    $action_url = $is_login_page ? site_url('wp-login.php', 'login_post') : admin_url('admin-ajax.php');

    echo '<form id="loginform" action="' . esc_url($action_url) . '" method="post">';
    echo '<input type="hidden" name="action" value="handle_login">';
    echo '<div id="login-error-message" style="color: red; margin-bottom: 10px;"></div>'; // Error message container
    echo '<p><label for="user_login">Username<br /><input type="text" name="log" id="user_login" class="input" /></label></p>';
    echo '<p><label for="user_pass">Password<br /><input type="password" name="pwd" id="user_pass" class="input" /></label></p>';
    echo '<p class="submit">';
    echo '<input type="submit" id="wp-submit" class="button button-primary" value="Log In" />';
    echo '<input type="hidden" name="redirect_to" value="' . esc_attr(wp_surgeon_get_redirect_url()) . '" />';
    echo '</p>';
    echo '</form>';
    echo '<p>Not a member? <a href="' . esc_url(home_url('/register/')) . '">Sign up here</a></p>';
}

function wp_surgeon_display_error_messages() {
    if (isset($_GET['login']) && $_GET['login'] == 'failed') {
        $reset_password_link = wp_lostpassword_url();
        echo '<div class="error-message">Error: Invalid username or password. Please try again. <a href="' . esc_url($reset_password_link) . '">Forgot your password?</a></div>';
    }
}

function wp_surgeon_get_redirect_url() {
    $login_page_url = home_url('/login/');
    $current_url = (is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    return isset($_GET['redirect_to']) ? esc_url($_GET['redirect_to']) : ($current_url == $login_page_url ? home_url() : $current_url);
}

function custom_login_failed($username) {
    $referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    $redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '';

    if (!empty($referrer) && !strstr($referrer, 'wp-login') && !strstr($referrer, 'wp-admin')) {
        $login_url = home_url('/login/');
        $redirect_args = array('login' => 'failed');
        if (!empty($redirect_to)) {
            $redirect_args['redirect_to'] = urlencode($redirect_to);
        }
        wp_redirect(add_query_arg($redirect_args, $login_url));
        exit;
    }
}

add_action('wp_login_failed', 'custom_login_failed');

function login_error_message() {
    if (isset($_GET['login']) && $_GET['login'] == 'failed') {
        echo '<div class="error-message">Error: Invalid username or password. Please try again.</div>';
    }
}
