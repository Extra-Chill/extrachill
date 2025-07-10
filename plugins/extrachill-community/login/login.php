<?php
// Ensure Band Platform user linking functions are available for join flow logic
$bp_user_linking_functions = dirname(__FILE__) . '/../band-platform/user-linking.php';
if (file_exists($bp_user_linking_functions)) {
    require_once $bp_user_linking_functions;
}

function wp_surgeon_login_form() {
    ob_start(); // Start output buffering

    if (is_user_logged_in()) {
        echo '<div class="login-already-logged-in">You are already logged in.</div>';
    } else {
        wp_surgeon_display_login_form();
        wp_surgeon_display_error_messages(); // Display error messages
    }

    return ob_get_clean(); // Clean (erase) the output buffer and turn off output buffering
}

// Move redirect logic to template_redirect
add_action('template_redirect', 'wp_surgeon_login_page_redirect');
function wp_surgeon_login_page_redirect() {
    if (is_user_logged_in()) {
        // Only run on the login page
        if (is_page('login')) {
            // --- START Join Flow Redirect for Logged-in Users ---
            $from_join = isset($_GET['from_join']) && $_GET['from_join'] === 'true';

            if ($from_join) {
                $user_id = get_current_user_id();

                // Get the user's band profile IDs directly from user meta.
                $user_band_ids = get_user_meta( $user_id, '_band_profile_ids', true );

                if ( ! empty( $user_band_ids ) && is_array( $user_band_ids ) ) {
                    // User has one or more band profiles.
                    // Find the most recently updated band profile to redirect to its link page manager.
                    $most_recent_band_query = new WP_Query( array(
                        'post_type'      => 'band_profile',
                        'post__in'       => $user_band_ids,
                        'posts_per_page' => 1,
                        'orderby'        => 'modified',
                        'order'          => 'DESC',
                        'fields'         => 'ids', // Only need the ID
                    ) );

                    if ( $most_recent_band_query->have_posts() ) {
                         $most_recent_band_id = $most_recent_band_query->posts[0];
                        $link_page_manage_page = get_page_by_path('manage-link-page');
                        if ($link_page_manage_page) {
                            $target_url = add_query_arg(
                                array(
                                    'band_id' => $most_recent_band_id,
                                    'from_join_success' => 'existing_user_link_page'
                                ),
                                get_permalink($link_page_manage_page)
                            );
                            wp_redirect($target_url);
                            exit;
                        } else {
                            // Fallback to default login redirect
                        }
                    } else {
                         // Fallback to default login redirect
                    }
                    wp_reset_postdata(); // Restore original Post Data
                } else {
                    // User does not have any band profiles, redirect to create one.
                    $manage_band_page = get_page_by_path('manage-band-profiles');
                    if ($manage_band_page) {
                        $target_url = add_query_arg(
                            array(
                                'from_join' => 'true'
                            ),
                            get_permalink($manage_band_page)
                        );
                        wp_redirect($target_url);
                        exit;
                    } else {
                        // Fallback to default login redirect
                    }
                }
            }
            // --- END Join Flow Redirect for Logged-in Users ---

            // Default redirect for logged-in users on the login page if not from join flow
            $redirect_url = isset($_REQUEST['redirect_to']) ? esc_url_raw($_REQUEST['redirect_to']) : home_url();
            
            // Special handling for administrators to prevent redirect loops
            if (current_user_can('administrator') && isset($_REQUEST['redirect_to'])) {
                $redirect_to = $_REQUEST['redirect_to'];
                // If admin is being redirected to wp-admin, allow direct access
                if (strpos($redirect_to, '/wp-admin') !== false) {
                    wp_redirect($redirect_to);
                    exit;
                }
            }
            
            wp_redirect($redirect_url);
            exit;
        }
    }
}

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

function wp_surgeon_display_login_form() {
    $is_login_page = ($_SERVER['REQUEST_URI'] == '/login/' || strpos($_SERVER['REQUEST_URI'], '/login') !== false);
    $action_url = $is_login_page ? site_url('wp-login.php', 'login_post') : admin_url('admin-ajax.php');

    ?>
    <div class="login-register-form">
        <h2>Login to Extra Chill</h2>
        <p>Welcome back! Log in to your account.</p>

        <?php if (isset($_GET['from_join']) && $_GET['from_join'] === 'true') : ?>
            <div class="bp-notice bp-notice-info" style="background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 10px; margin-bottom: 15px;">
                Welcome back! Please log in to connect your account and continue setting up your link page.
            </div>
        <?php endif; ?>

        <form id="loginform" action="<?php echo esc_url($action_url); ?>" method="post">
            <div id="login-error-message" class="login-register-errors" style="display: none;"></div>

            <label for="user_login">Username</label>
            <input type="text" name="log" id="user_login" class="input" placeholder="Your username" required>

            <label for="user_pass">Password</label>
            <input type="password" name="pwd" id="user_pass" class="input" placeholder="Your password" required>

            <input type="hidden" name="action" value="handle_login">
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr(wp_surgeon_get_redirect_url()); ?>">

            <input type="submit" id="wp-submit" class="button button-primary" value="Log In">
        </form>

        <p style="margin-top: 15px;">Not a member? <a href="<?php echo esc_url(home_url('/login/#tab-register')); ?>" class="js-switch-to-register">Sign up here</a></p>
    </div>
    <?php
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

function login_error_message() {
    if (isset($_GET['login']) && $_GET['login'] == 'failed') {
        echo '<div class="error-message">Error: Invalid username or password. Please try again.</div>';
    }
}

add_filter('login_redirect', 'wp_surgeon_redirect_login_errors_to_custom_page', 99, 3);
function wp_surgeon_redirect_login_errors_to_custom_page($redirect_to, $request, $user) {
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

add_action('template_redirect', 'wp_surgeon_redirect_wp_login_access');
function wp_surgeon_redirect_wp_login_access() {
    if ( strpos( strtolower($_SERVER['REQUEST_URI']), '/wp-login.php' ) !== false ) {
        // Only redirect if user is NOT logged in AND NOT an administrator
        // Allow administrators full access to wp-login.php
        if ( ! is_user_logged_in() ) {
            // Redirect non-logged-in users to custom login page
            wp_redirect( home_url( '/login/' ) );
            exit;
        }
        // If user is logged in, allow access (administrators and other users)
        // wp-login.php will handle its own internal logic for what to show
    }
}

add_filter('authenticate', 'wp_surgeon_force_custom_login_redirect_on_error', 99, 3);
function wp_surgeon_force_custom_login_redirect_on_error($user, $username, $password) {
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

// --- START Join Flow Post-Login Redirect Logic --- (This hook is for AFTER login form submission)
// We will keep this for users who log in via the form while in the join flow.
add_filter('login_redirect', 'bp_join_flow_login_redirect', 10, 3);
function bp_join_flow_login_redirect($redirect_to, $requested_redirect_to, $user) {
    if (isset($user->ID) && $user->ID > 0) {
        $from_join = false;
        // Check if 'from_join' is in the original request or in the 'redirect_to' parameter of the login form
        if (isset($_REQUEST['from_join']) && $_REQUEST['from_join'] === 'true') {
            $from_join = true;
        } elseif (isset($_REQUEST['redirect_to'])) {
            // Parse the redirect_to URL to check for from_join query parameter
            $redirect_to_parts = parse_url($_REQUEST['redirect_to']);
            if (isset($redirect_to_parts['query'])) {
                parse_str($redirect_to_parts['query'], $query_params);
                if (isset($query_params['from_join']) && $query_params['from_join'] === 'true') {
                    $from_join = true;
                }
            }
        }


        if ($from_join) {
            $user_id = $user->ID;

            // Get the user's band profile IDs directly from user meta.
            $user_band_ids = get_user_meta( $user_id, '_band_profile_ids', true );

            if ( ! empty( $user_band_ids ) && is_array( $user_band_ids ) ) {
                // User has one or more band profiles.
                // Find the most recently updated band profile to redirect to its link page manager.
                $most_recent_band_query = new WP_Query( array(
                    'post_type'      => 'band_profile',
                    'post__in'       => $user_band_ids,
                    'posts_per_page' => 1,
                    'orderby'        => 'modified',
                    'order'          => 'DESC',
                    'fields'         => 'ids', // Only need the ID
                ) );

                if ( $most_recent_band_query->have_posts() ) {
                     $most_recent_band_id = $most_recent_band_query->posts[0];
                    $link_page_manage_page = get_page_by_path('manage-link-page');
                    if ($link_page_manage_page) {
                        $target_url = add_query_arg(
                            array(
                                'band_id' => $most_recent_band_id,
                                'from_join_success' => 'existing_user_link_page'
                            ),
                            get_permalink($link_page_manage_page)
                        );
                        return $target_url;
                    } else {
                        return $redirect_to; // Fallback
                    }
                } else {
                     return $redirect_to; // Fallback
                }
                 wp_reset_postdata(); // Restore original Post Data
            } else {
                // User does not have any band profiles, redirect to create one.
                $manage_band_page = get_page_by_path('manage-band-profiles');
                if ($manage_band_page) {
                    $target_url = add_query_arg(
                        array(
                            'from_join' => 'true'
                        ),
                        get_permalink($manage_band_page)
                    );
                    return $target_url;
                } else {
                    return $redirect_to; // Fallback
                }
            }
        }
    }
    return $redirect_to;
}
// --- END Join Flow Post-Login Redirect Logic ---
