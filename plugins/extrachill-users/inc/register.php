<?php
/**
 * User Registration System
 *
 * @package ExtraChill\Users
 */

/**
 * Render registration form with roster invitation support.
 *
 * @param array $attributes Block attributes or shortcode attributes
 * @return string Registration form HTML
 */
function extrachill_registration_form_shortcode( $attributes = array() ) {
    global $extrachill_registration_errors;

    // Store redirect URL in global for use by registration handler
    // Don't store if there's an active invitation or join flow (they have their own redirects)
    $has_invitation = isset($_GET['action']) && $_GET['action'] === 'bp_accept_invite'
        && isset($_GET['token']) && isset($_GET['artist_id']);
    $is_join_flow = isset($_POST['from_join']) && $_POST['from_join'] === 'true';

    if ( ! empty( $attributes['redirectUrl'] ) && ! $has_invitation && ! $is_join_flow ) {
        $GLOBALS['extrachill_registration_redirect_url'] = $attributes['redirectUrl'];
    }

    ob_start();

    $invite_token = null;
    $invite_artist_id = null;
    $invited_email = '';
    $artist_name_for_invite_message = '';

    if (isset($_GET['action']) && $_GET['action'] === 'bp_accept_invite' && isset($_GET['token']) && isset($_GET['artist_id'])) {
        $token_from_url = sanitize_text_field($_GET['token']);
        $artist_id_from_url = absint($_GET['artist_id']);

        if (function_exists('bp_get_pending_invitations')) {
            $pending_invitations = bp_get_pending_invitations($artist_id_from_url);
            foreach ($pending_invitations as $invite) {
                if (isset($invite['token']) && $invite['token'] === $token_from_url && isset($invite['status']) && $invite['status'] === 'invited_new_user') {
                    $invite_token = $token_from_url;
                    $invite_artist_id = $artist_id_from_url;
                    $invited_email = isset($invite['email']) ? sanitize_email($invite['email']) : '';
                    $artist_post_for_invite = get_post($invite_artist_id);
                    if ($artist_post_for_invite) {
                        $artist_name_for_invite_message = $artist_post_for_invite->post_title;
                    }
                    break;
                }
            }
        }
    }

    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        $profile_url = 'https://community.extrachill.com/u/' . $current_user->user_nicename;
        echo '<p>You are already registered and logged in! <a href="' . esc_url($profile_url) . '">View Profile</a></p>';
    } else {
        $errors = extrachill_get_registration_errors();
        ?>
        <div class="login-register-form">
    <h2>Join the Extra Chill Community</h2>
    <p>Sign up to connect with music lovers, artists, and professionals in the online music scene! It's free and easy.</p>

    <?php if (!empty($artist_name_for_invite_message) && !empty($invite_token)) : ?>
        <div class="notice notice-invite">
            <p><?php echo sprintf(esc_html__('You have been invited to join the artist \'%s\'! Please complete your registration below to accept.', 'extra-chill-community'), esc_html($artist_name_for_invite_message)); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="login-register-errors">
            <?php foreach ($errors as $error): ?>
                <p class="error"><?php echo esc_html($error); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>" method="post">
        <label for="extrachill_username">Username <small>(required)</small></label>
        <input type="text" name="extrachill_username" id="extrachill_username" placeholder="Choose a username" required value="<?php echo isset($_POST['extrachill_username']) ? esc_attr($_POST['extrachill_username']) : ''; ?>">
        
        <label for="extrachill_email">Email</label>
        <input type="email" name="extrachill_email" id="extrachill_email" placeholder="you@example.com" required value="<?php echo !empty($invited_email) ? esc_attr($invited_email) : (isset($_POST['extrachill_email']) ? esc_attr($_POST['extrachill_email']) : ''); ?>">

        <label for="extrachill_password">Password</label>
        <input type="password" name="extrachill_password" id="extrachill_password" placeholder="Create a password" required>

        <label for="extrachill_password_confirm">Confirm Password</label>
        <input type="password" name="extrachill_password_confirm" id="extrachill_password_confirm" placeholder="Repeat your password" required>

        <div class="registration-user-types">
            <label>
                <input type="checkbox" id="user_is_fan" checked disabled> I love music
            </label>
            <label>
                <input type="checkbox" name="user_is_artist" id="user_is_artist" value="1"> I am a musician
                <small>(required for artist profiles and link pages)</small>
            </label>
            <label>
                <input type="checkbox" name="user_is_professional" id="user_is_professional" value="1"> I work in the music industry
                <small>(required for artist profiles and link pages)</small>
            </label>
        </div>

        <div class="registration-submit-section">
            <input type="submit" name="extrachill_register" class="button-1 button-medium" value="Join Now">
        </div>

        <?php echo ec_render_turnstile_widget(); ?>

        <?php wp_nonce_field('extrachill_register_nonce', 'extrachill_register_nonce_field'); ?>
        <?php if ($invite_token && $invite_artist_id) : ?>
            <input type="hidden" name="invite_token" value="<?php echo esc_attr($invite_token); ?>">
            <input type="hidden" name="invite_artist_id" value="<?php echo esc_attr($invite_artist_id); ?>">
        <?php endif; ?>
    </form>
</div>


        <?php
    }

    return ob_get_clean();
}


$GLOBALS['extrachill_registration_errors'] = array();

/**
 * Handle registration form submission.
 *
 * Creates user on community.extrachill.com via extrachill_create_community_user filter,
 * processes roster invitations via extrachill-artist-platform functions,
 * subscribes to newsletter via extrachill_multisite_subscribe(),
 * and auto-login with auth cookie.
 */
function extrachill_handle_registration() {
    global $extrachill_registration_errors;
    $processed_invite_artist_id = null;

    if (isset($_POST['extrachill_register']) && check_admin_referer('extrachill_register_nonce', 'extrachill_register_nonce_field')) {
        $username = sanitize_user($_POST['extrachill_username']);
        $email = sanitize_email($_POST['extrachill_email']);
        $password = esc_attr($_POST['extrachill_password']);
        $password_confirm = esc_attr($_POST['extrachill_password_confirm']);

        $turnstile_response = isset( $_POST['cf-turnstile-response'] ) ? wp_unslash( $_POST['cf-turnstile-response'] ) : '';

        if ( empty( $turnstile_response ) ) {
            $extrachill_registration_errors[] = 'Captcha verification required. Please complete the challenge and try again.';
            return;
        }

        if (!ec_verify_turnstile_response($turnstile_response)) {
            $extrachill_registration_errors[] = 'Captcha verification failed. Please try again.';
            return;
        }

        if ($password !== $password_confirm) {
            $extrachill_registration_errors[] = 'Error: Passwords do not match.';
            return;
        }

        if (username_exists($username) || email_exists($email)) {
            $extrachill_registration_errors[] = 'Error: User already exists with this username/email.';
            return;
        }

        $registration_data = array(
            'username' => $username,
            'password' => $password,
            'email' => $email,
            'user_is_artist' => isset($_POST['user_is_artist']),
            'user_is_professional' => isset($_POST['user_is_professional'])
        );
        $user_id = apply_filters('extrachill_create_community_user', false, $registration_data);
        if (is_wp_error($user_id)) {
            $error_messages = implode(", ", $user_id->get_error_messages());
            $extrachill_registration_errors[] = 'Registration error: ' . $error_messages;
            return;
        }

        update_user_meta($user_id, 'registration_page', sanitize_text_field($_SERVER['REQUEST_URI']));
        update_user_meta($user_id, 'registration_timestamp', current_time('mysql'));

        if (!empty($extrachill_registration_errors)) {
            $register_url = home_url('/login/');
            $redirect_url_with_hash = $register_url . '#tab-register';
            wp_redirect(esc_url_raw($redirect_url_with_hash));
            exit;
        }

        if (function_exists('extrachill_multisite_subscribe')) {
            $sync_result = extrachill_multisite_subscribe($email, 'registration');
            if (!$sync_result['success']) {
                error_log('Registration newsletter subscription failed: ' . $sync_result['message']);
            }
        }

        $invite_token_posted = isset($_POST['invite_token']) ? sanitize_text_field($_POST['invite_token']) : null;
        $invite_artist_id_posted = isset($_POST['invite_artist_id']) ? absint($_POST['invite_artist_id']) : null;

        if ($invite_token_posted && $invite_artist_id_posted && function_exists('bp_get_pending_invitations') && function_exists('bp_add_artist_membership') && function_exists('bp_remove_pending_invitation')) {
            $pending_invitations = bp_get_pending_invitations($invite_artist_id_posted);
            $valid_invite_data = null;
            $valid_invite_id_for_removal = null;

            foreach ($pending_invitations as $invite) {
                if (isset($invite['token']) && $invite['token'] === $invite_token_posted &&
                    isset($invite['email']) && strtolower($invite['email']) === strtolower($email) &&
                    isset($invite['status']) && $invite['status'] === 'invited_new_user') {
                    $valid_invite_data = $invite;
                    $valid_invite_id_for_removal = $invite['id'];
                    break;
                }
            }

            if ($valid_invite_data) {
                if (bp_add_artist_membership($user_id, $invite_artist_id_posted)) {
                    if (bp_remove_pending_invitation($invite_artist_id_posted, $valid_invite_id_for_removal)) {
                        $processed_invite_artist_id = $invite_artist_id_posted;
                    } else {
                        $processed_invite_artist_id = $invite_artist_id_posted;
                    }
                } else {
                    $extrachill_registration_errors[] = 'Your account was created, but there was an issue joining the invited band. Please contact support.';
                }
            }
        }

        auto_login_new_user($user_id, $processed_invite_artist_id);
     }
 }

add_action('init', 'extrachill_handle_registration');

/**
 * Auto-login user after registration with optional artist profile redirect.
 *
 * @param int      $user_id             User ID
 * @param int|null $redirect_artist_id  Optional artist profile ID for roster invitation redirect
 */
function auto_login_new_user($user_id, $redirect_artist_id = null) {
    $user = get_user_by('id', $user_id);

    if ($user) {
        wp_set_current_user($user_id, $user->user_login);
        wp_set_auth_cookie($user_id, true);
        do_action('wp_login', $user->user_login, $user);

        if ($redirect_artist_id) {
            $artist_post = get_post($redirect_artist_id);
            if ($artist_post && $artist_post->post_type === 'artist_profile') {
                $redirect_url = get_permalink($artist_post);
            } else {
                $redirect_url = home_url();
            }
        } elseif ( ! empty( $GLOBALS['extrachill_registration_redirect_url'] ) ) {
            // Use block attribute redirect URL if set
            $redirect_url = add_query_arg( 'registration', 'success', esc_url( $GLOBALS['extrachill_registration_redirect_url'] ) );
        } else {
            // Default: redirect to current page with success param
            $current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $redirect_url = add_query_arg( 'registration', 'success', $current_url );
        }

        $redirect_url = apply_filters('registration_redirect', $redirect_url);

        wp_redirect(esc_url_raw($redirect_url));
        exit;
    }
}

function extrachill_get_registration_errors() {
    $errors = [];

    if (isset($GLOBALS['extrachill_registration_errors'])) {
        $errors = $GLOBALS['extrachill_registration_errors'];
    }

    return $errors;
}

/**
 * Display authentication success notices network-wide.
 *
 * Hooked to extrachill_before_body_content (theme hook) for universal notice display.
 * Triggered by ?registration=success or ?login=success URL parameters.
 */
function extrachill_display_auth_success_notices() {
    if (isset($_GET['registration']) && $_GET['registration'] === 'success') {
        echo '<div class="notice notice-success">
            <strong>Welcome to Extra Chill!</strong> Your account has been created successfully. You are now logged in.
        </div>';
    }

    if (isset($_GET['login']) && $_GET['login'] === 'success') {
        echo '<div class="notice notice-success">
            <strong>Welcome back!</strong> You have successfully logged in.
        </div>';
    }
}
add_action('extrachill_before_body_content', 'extrachill_display_auth_success_notices');

