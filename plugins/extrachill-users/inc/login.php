<?php
/**
 * Login System
 *
 * @package ExtraChill\Users
 */

/**
 * Render login form with error handling.
 *
 * @param array $attributes Block attributes
 * @return string Login form HTML
 */
function extrachill_login_form( $attributes = array() ) {
    ob_start();

    if (is_user_logged_in()) {
        echo '<div class="login-already-logged-in">You are already logged in.</div>';
    } else {
        extrachill_display_login_form( $attributes );
        extrachill_display_error_messages();
    }

    return ob_get_clean();
}

function extrachill_display_login_form( $attributes = array() ) {
    // Determine redirect URL
    $current_url = (is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

    if ( ! empty( $attributes['redirectUrl'] ) ) {
        // Use block attribute if set
        $redirect_url = add_query_arg( 'login', 'success', esc_url( $attributes['redirectUrl'] ) );
    } else {
        // Default: redirect to current page with success param
        $redirect_url = add_query_arg( 'login', 'success', $current_url );
    }

    ?>
    <div class="login-register-form">
        <h2>Login to Extra Chill</h2>
        <p>Welcome back! Log in to your account.</p>

        <form id="loginform" action="<?php echo esc_url( site_url('wp-login.php', 'login_post') ); ?>" method="post">
            <div id="login-error-message" class="login-register-errors hidden"></div>

            <label for="user_login">Username</label>
            <input type="text" name="log" id="user_login" class="input" placeholder="Your username" required>

            <label for="user_pass">Password</label>
            <input type="password" name="pwd" id="user_pass" class="input" placeholder="Your password" required>

            <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_url ); ?>">

            <input type="submit" id="wp-submit" class="button" value="Log In">
        </form>

        <p class="login-signup-link">Not a member? <a href="#tab-register" class="js-switch-to-register">Sign up here</a></p>
    </div>
    <?php
}

function extrachill_display_error_messages() {
    if (isset($_GET['login']) && $_GET['login'] == 'failed') {
        $reset_password_link = 'https://community.extrachill.com/reset-password/';
        echo '<div class="error-message">Error: Invalid username or password. Please try again. <a href="' . esc_url($reset_password_link) . '">Forgot your password?</a></div>';
    }
}

function extrachill_get_redirect_url() {
    $current_url = (is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

    if (isset($_GET['redirect_to'])) {
        return esc_url($_GET['redirect_to']);
    }

    // If on login page, redirect to homepage
    $current_path = parse_url($current_url, PHP_URL_PATH);
    if ($current_path && rtrim($current_path, '/') === '/login') {
        return home_url();
    }

    // For inline login forms on other pages, stay on current page
    return add_query_arg('login', 'success', $current_url);
}

/**
 * Redirect failed login attempts back to custom login page.
 *
 * @param string $username Username attempted
 */
function custom_login_failed($username) {
    $referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    $redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '';

    if (!empty($referrer) && (strpos($referrer, '/login/') !== false) && !strstr($referrer, 'wp-login') && !strstr($referrer, 'wp-admin')) {
        $login_url = home_url('/login/');
        $redirect_args = array('login' => 'failed');
        if (!empty($redirect_to)) {
            $redirect_args['redirect_to'] = urlencode($redirect_to);
        }
        $redirect_url_with_hash = add_query_arg($redirect_args, $login_url) . '#tab-login';
        wp_redirect(esc_url_raw($redirect_url_with_hash));
        exit;
    }
}
add_action('wp_login_failed', 'custom_login_failed');

/**
 * Display login error message.
 */
function login_error_message() {
    if (isset($_GET['login']) && $_GET['login'] == 'failed') {
        echo '<div class="error-message">Error: Invalid username or password. Please try again.</div>';
    }
}

/**
 * Redirect login errors to custom login page.
 *
 * @param string         $redirect_to Redirect destination
 * @param string         $request Requested redirect destination
 * @param WP_User|WP_Error $user User object or error
 * @return string Modified redirect URL
 */
add_filter('login_redirect', 'extrachill_redirect_login_errors_to_custom_page', 99, 3);
function extrachill_redirect_login_errors_to_custom_page($redirect_to, $request, $user) {
    if (is_wp_error($user)) {
        $login_url = home_url('/login/');
        $redirect_url_with_hash = add_query_arg('login', 'failed', $login_url) . '#tab-login';
         $referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
         if (!empty($referrer) && (strpos($referrer, '/login/') !== false)) {
             return $redirect_url_with_hash;
         } else {
             return $redirect_to;
         }
    }
    return $redirect_to;
}

/**
 * Redirect wp-login.php access to custom login page.
 */
add_action('template_redirect', 'extrachill_redirect_wp_login_access');
function extrachill_redirect_wp_login_access() {
    if ( strpos( strtolower($_SERVER['REQUEST_URI']), '/wp-login.php' ) !== false ) {
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url( '/login/' ) );
            exit;
        }
    }
}

/**
 * Force redirect on authentication error.
 *
 * @param WP_User|WP_Error $user User object or error
 * @param string $username Username
 * @param string $password Password
 * @return WP_User|WP_Error
 */
add_filter('authenticate', 'extrachill_force_custom_login_redirect_on_error', 99, 3);
function extrachill_force_custom_login_redirect_on_error($user, $username, $password) {
    if (is_wp_error($user) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['log']) && isset($_POST['pwd'])) {
            $login_url = home_url('/login/');
            $redirect_url_with_hash = add_query_arg('login', 'failed', $login_url) . '#tab-login';
            wp_redirect($redirect_url_with_hash);
            exit;
        }
    }
    return $user;
}
