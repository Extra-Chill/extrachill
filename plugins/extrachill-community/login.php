<?php
function wp_surgeon_login_form() {
    ob_start();

    // Define the login page URL for comparison
    $login_page_url = home_url('/login/');

    // Determine the current page URL
    $current_url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $is_login_page = $current_url == $login_page_url;

    // Check if login failed
    $login_failed = isset($_GET['login']) && $_GET['login'] == 'failed';

    // Display error messages based on the context
    if (isset($_POST['log']) && empty($_POST['log'])) {
        echo '<div class="error-message">Error: The username field is empty.</div>';
    } elseif (isset($_POST['pwd']) && empty($_POST['pwd'])) {
        echo '<div class="error-message">Error: The password field is empty.</div>';
    } elseif ($login_failed) {
        echo '<div class="error-message">Error: Invalid username or password. Please try again.</div>';
    }

    if (is_user_logged_in()) {
        // User is logged in, redirect to profile or home page
        if (!$is_login_page && !empty($_REQUEST['redirect_to'])) {
            wp_redirect($_REQUEST['redirect_to']);
            exit;
        } else {
            // Redirect to home or a specific page after successful login from the login page
            wp_redirect(home_url());
            exit;
        }
    } else {
        // If there's a redirect_to parameter or the referrer is not the login page, use it; otherwise, use home URL
        $redirect_url = isset($_GET['redirect_to']) ? esc_url($_GET['redirect_to']) : ($is_login_page ? home_url() : $current_url);

        // Add a hidden input field to store the redirect URL
        echo '<input type="hidden" name="redirect_to" value="' . esc_url($redirect_url) . '">';

        wp_login_form(array(
            'echo' => true,
            'redirect' => $redirect_url, // Redirect to the specified URL after login
            'form_id' => 'custom-login-form',
        ));

        echo '<p>Not a member? <a href="' . esc_url($login_page_url . '/register/') . '">Sign up here</a></p>';
    }

    return ob_get_clean();
}
add_shortcode('wp_surgeon_login', 'wp_surgeon_login_form');

function custom_login_failed($username) {
    $referrer = $_SERVER['HTTP_REFERER'];
    $redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '';

    if (!empty($referrer) && !strstr($referrer, 'wp-login') && !strstr($referrer, 'wp-admin')) {
        $login_url = home_url('/login/');
        // Include the original redirect_to parameter in the redirect URL
        $redirect_args = array('login' => 'failed');
        if (!empty($redirect_to)) {
            $redirect_args['redirect_to'] = urlencode($redirect_to);
        }
        wp_redirect(add_query_arg($redirect_args, $login_url));
        exit;
    }
}

add_action('wp_login_failed', 'custom_login_failed');

// Optionally, you can add a function to detect if the 'login=failed' query is set and display an error message.
function login_error_message() {
    if (isset($_GET['login']) && $_GET['login'] == 'failed') {
        echo '<div class="error-message">Error: Invalid username or password. Please try again.</div>';
    }
}


