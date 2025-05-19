<?php

// Include Band Platform dependencies if not already loaded globally
// Ideally, these should be loaded by a central plugin/theme loader.
$bp_roster_data_functions = dirname(__FILE__) . '/band-platform/roster/roster-data-functions.php';
$bp_user_linking_functions = dirname(__FILE__) . '/band-platform/user-linking.php';
if (file_exists($bp_roster_data_functions)) {
    require_once $bp_roster_data_functions;
}
if (file_exists($bp_user_linking_functions)) {
    require_once $bp_user_linking_functions;
}

function wp_surgeon_registration_form_shortcode() {
    global $wp_surgeon_registration_errors;

    ob_start(); // Start output buffering

    $invite_token = null;
    $invite_band_id = null;
    $invited_email = '';
    $band_name_for_invite_message = '';

    if (isset($_GET['action']) && $_GET['action'] === 'bp_accept_invite' && isset($_GET['token']) && isset($_GET['band_id'])) {
        $token_from_url = sanitize_text_field($_GET['token']);
        $band_id_from_url = absint($_GET['band_id']);

        if (function_exists('bp_get_pending_invitations')) {
            $pending_invitations = bp_get_pending_invitations($band_id_from_url);
            foreach ($pending_invitations as $invite) {
                if (isset($invite['token']) && $invite['token'] === $token_from_url && isset($invite['status']) && $invite['status'] === 'invited_new_user') {
                    $invite_token = $token_from_url;
                    $invite_band_id = $band_id_from_url;
                    $invited_email = isset($invite['email']) ? sanitize_email($invite['email']) : '';
                    $band_post_for_invite = get_post($invite_band_id);
                    if ($band_post_for_invite) {
                        $band_name_for_invite_message = $band_post_for_invite->post_title;
                    }
                    break;
                }
            }
        }
    }

    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        $profile_url = bbp_get_user_profile_url($current_user->ID);
        echo '<p>You are already registered and logged in! <a href="' . esc_url($profile_url) . '">View Profile</a></p>';
    } else {
        $errors = wp_surgeon_get_registration_errors();
        ?>
        <div class="login-register-form">
    <h2>Join the Extra Chill Community</h2>
    <p>Sign up to connect with music lovers, artists, and professionals in the online music scene! It's free and easy.</p>

    <?php if (!empty($band_name_for_invite_message) && !empty($invite_token)) : ?>
        <div class="bp-notice bp-notice-info" style="border-left: 4px solid #17a2b8; padding: 12px; margin-bottom: 20px; background-color: #e6f7ff;">
            <p style="margin:0;"><?php echo sprintf(esc_html__('You have been invited to join the band \'%s\'! Please complete your registration below to accept.', 'generatepress_child'), esc_html($band_name_for_invite_message)); ?></p>
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
        <label for="wp_surgeon_username">Username <small>(required)</small></label>
        <input type="text" name="wp_surgeon_username" id="wp_surgeon_username" placeholder="Choose a username" required value="<?php echo isset($_POST['wp_surgeon_username']) ? esc_attr($_POST['wp_surgeon_username']) : ''; ?>">
        
        <label for="wp_surgeon_email">Email</label>
        <input type="email" name="wp_surgeon_email" id="wp_surgeon_email" placeholder="you@example.com" required value="<?php echo !empty($invited_email) ? esc_attr($invited_email) : (isset($_POST['wp_surgeon_email']) ? esc_attr($_POST['wp_surgeon_email']) : ''); ?>">

        <label for="wp_surgeon_password">Password</label>
        <input type="password" name="wp_surgeon_password" id="wp_surgeon_password" placeholder="Create a password" required>

        <label for="wp_surgeon_password_confirm">Confirm Password</label>
        <input type="password" name="wp_surgeon_password_confirm" id="wp_surgeon_password_confirm" placeholder="Repeat your password" required>

        <div style="margin-top: 15px;">
            <label style="display:block; margin-bottom: 5px;">
                <input type="checkbox" id="user_is_fan" checked disabled> I love music
            </label>
            <label style="display:block; margin-bottom: 5px;">
                <input type="checkbox" name="user_is_artist" id="user_is_artist" value="1"> I am a musician
            </label>
            <label style="display:block; margin-bottom: 5px;">
                <input type="checkbox" name="user_is_professional" id="user_is_professional" value="1"> I work in the music industry
            </label>
        </div>

        <div style="margin-top: 15px;">
            <input type="submit" name="wp_surgeon_register" value="Join Now">
        </div>

        <div class="cf-turnstile" data-sitekey="0x4AAAAAAAPvQsUv5Z6QBB5n" data-callback="community_register" style="margin-top: 20px;"></div>

        <?php wp_nonce_field('wp_surgeon_register_nonce', 'wp_surgeon_register_nonce_field'); ?>
        <?php if ($invite_token && $invite_band_id) : ?>
            <input type="hidden" name="invite_token" value="<?php echo esc_attr($invite_token); ?>">
            <input type="hidden" name="invite_band_id" value="<?php echo esc_attr($invite_band_id); ?>">
        <?php endif; ?>
    </form>
</div>


        <?php
    }

    return ob_get_clean(); // Return the buffered output
}
add_shortcode('wp_surgeon_registration_form', 'wp_surgeon_registration_form_shortcode');


$GLOBALS['wp_surgeon_registration_errors'] = array();
function wp_surgeon_handle_registration() {
    global $wp_surgeon_registration_errors;
    $processed_invite_band_id = null; // Variable to store band_id if invite is processed

    if (isset($_POST['wp_surgeon_register']) && check_admin_referer('wp_surgeon_register_nonce', 'wp_surgeon_register_nonce_field')) {
        // Collect and sanitize form data
        $username = sanitize_user($_POST['wp_surgeon_username']);
        $email = sanitize_email($_POST['wp_surgeon_email']);
        $password = esc_attr($_POST['wp_surgeon_password']);
        $password_confirm = esc_attr($_POST['wp_surgeon_password_confirm']);

        // Captcha verification
        $turnstile_response = $_POST['cf-turnstile-response'];
        if (!wp_surgeon_verify_turnstile($turnstile_response)) {
            $wp_surgeon_registration_errors[] = 'Captcha verification failed. Please try again.';
            return; // Early return to prevent further processing
        }

        // Password confirmation
        if ($password !== $password_confirm) {
            $wp_surgeon_registration_errors[] = 'Error: Passwords do not match.';
            return; // Early return to prevent further processing
        }

        // Check for existing username or email
        if (username_exists($username) || email_exists($email)) {
            $wp_surgeon_registration_errors[] = 'Error: User already exists with this username/email.';
            return; // Early return to prevent further processing
        }

        // Create the user
        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            // Convert WP_Error to string and add to errors array
            $error_messages = implode(", ", $user_id->get_error_messages());
            $wp_surgeon_registration_errors[] = 'Registration error: ' . $error_messages;
            error_log("Registration failed for $email: " . $error_messages); // Optionally log this error
            return; // Early return to prevent further processing
        }

        // If the user is created successfully, continue to sync with Sendy and log in the user
        $sync_success = sync_to_sendy($email, $username);
        if (!$sync_success) {
            $wp_surgeon_registration_errors[] = 'Failed to synchronize with Sendy. Please contact support or try again later.';
            // Consider whether you want to roll back user creation here or just leave the user registered without Sendy sync
        }

        // Check for and process band invitation
        $invite_token_posted = isset($_POST['invite_token']) ? sanitize_text_field($_POST['invite_token']) : null;
        $invite_band_id_posted = isset($_POST['invite_band_id']) ? absint($_POST['invite_band_id']) : null;

        if ($invite_token_posted && $invite_band_id_posted && function_exists('bp_get_pending_invitations') && function_exists('bp_add_band_membership') && function_exists('bp_remove_pending_invitation')) {
            $pending_invitations = bp_get_pending_invitations($invite_band_id_posted);
            $valid_invite_data = null;
            $valid_invite_id_for_removal = null;

            foreach ($pending_invitations as $invite) {
                if (isset($invite['token']) && $invite['token'] === $invite_token_posted && 
                    isset($invite['email']) && strtolower($invite['email']) === strtolower($email) &&
                    isset($invite['status']) && $invite['status'] === 'invited_new_user') {
                    $valid_invite_data = $invite;
                    $valid_invite_id_for_removal = $invite['id']; // Assuming 'id' is the key for the invitation unique ID
                    break;
                }
            }

            if ($valid_invite_data) {
                // USER_IS_ARTIST META IS NO LONGER FORCED HERE. USER CHOICE FROM CHECKBOX WILL BE USED.
                // error_log("Band Invite Registration: Forcing user_is_artist for user ID $user_id for band $invite_band_id_posted."); // This line is removed

                if (bp_add_band_membership($user_id, $invite_band_id_posted)) {
                    if (bp_remove_pending_invitation($invite_band_id_posted, $valid_invite_id_for_removal)) {
                        error_log("Band Invite Registration: User $user_id successfully added to band $invite_band_id_posted and invite removed.");
                        $processed_invite_band_id = $invite_band_id_posted; // Store for redirect
                    } else {
                        error_log("Band Invite Registration: User $user_id added to band $invite_band_id_posted, but FAILED to remove pending invite ID $valid_invite_id_for_removal.");
                        // Still treat as success for user, but log the cleanup issue
                        $processed_invite_band_id = $invite_band_id_posted;
                    }
                } else {
                    error_log("Band Invite Registration: FAILED to add user $user_id to band $invite_band_id_posted.");
                    $wp_surgeon_registration_errors[] = 'Your account was created, but there was an issue joining the invited band. Please contact support.';
                }
            } else {
                 error_log("Band Invite Registration: Invalid or mismatched token/email for invite. Token: $invite_token_posted, Band ID: $invite_band_id_posted, New User Email: $email");
                // Don't add an error to $wp_surgeon_registration_errors here, as registration itself might be fine, just invite part failed.
                // User gets registered as normal without joining band.
            }
        } elseif ($invite_token_posted || $invite_band_id_posted) {
            // Log if token/band_id was posted but functions were missing (should not happen with require_once at top)
            error_log("Band Invite Registration: Invite token/band_id posted, but required band platform functions are missing.");
        }

          // Save user statuses after successful registration (DIRECTLY IN THIS FUNCTION)
    // Respect the checkbox for user_is_artist regardless of invite status
    if (isset($_POST['user_is_artist'])) {
        update_user_meta($user_id, 'user_is_artist', '1');
   } else {
        update_user_meta($user_id, 'user_is_artist', '0');
    }

    // Respect the checkbox for user_is_professional
    if (isset($_POST['user_is_professional'])) {
       update_user_meta($user_id, 'user_is_professional', '1');
   } else {
       update_user_meta( $user_id, 'user_is_professional', '0' );
   }
        // Other post-registration processes like auto-login
        auto_login_new_user($user_id, $processed_invite_band_id);


     }
 }

add_action('init', 'wp_surgeon_handle_registration');


// Function to sync user data to Sendy
function sync_to_sendy($email, $name) {
    $sendyUrl = 'https://mail.extrachill.com/sendy';
    $listId = 'ZbPsmYRh8EeeEnsg892jZhMw';
    $apiKey = 'z7RZLH84oEKNzMvFZhdt';
    $postData = http_build_query(array(
        'email' => $email,
        'name' => $name,
        'list' => $listId,
        'api_key' => $apiKey,
        'boolean' => 'true'
    ));

    $ch = curl_init("$sendyUrl/subscribe");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/x-www-form-urlencoded"));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    $response = curl_exec($ch);
    curl_close($ch);

    if (strpos($response, '1') === false) {
        error_log('Failed to sync email to Sendy: ' . $response);
    }
}


function auto_login_new_user($user_id, $redirect_band_id = null) {
    // Assuming generate_community_session_token and store_user_session are defined and available
    $token = generate_community_session_token();
    store_user_session($token, $user_id);
    
    // Setting the ECC user session cookie
    setcookie('ecc_user_session_token', $token, [
        'expires' => time() + (6 * 30 * 24 * 60 * 60), // 6 months
        'path' => '/',
        'domain' => '.extrachill.com',
        'secure' => is_ssl(),
        'httponly' => false, // Adjust based on your security requirements
        'samesite' => 'Lax' // Ensure compatibility with your site's cross-site request policy
    ]);

    // Now proceed with setting the WP auth cookie and logging the user in
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true); // Ensure the login is persistent

    // Redirect to the user dashboard or another desired page
    $redirect_url = bbp_get_user_profile_url($user_id); // Default redirect
    if ($redirect_band_id && get_post_type($redirect_band_id) === 'band_profile') {
        $band_profile_url = get_permalink($redirect_band_id);
        if ($band_profile_url) {
            $redirect_url = add_query_arg('invite_accepted', '1', $band_profile_url);
        }
    }
    wp_redirect($redirect_url);
    exit;
}


function wp_surgeon_get_registration_errors() {
    // Initialize an empty array to store errors
    $errors = [];

    // Check if there are any stored errors in a global variable or a session
    if (isset($GLOBALS['wp_surgeon_registration_errors'])) {
        $errors = $GLOBALS['wp_surgeon_registration_errors'];
    }

    // Return the errors
    return $errors;
}

function wp_surgeon_verify_turnstile($response) {

    if ( defined('WP_ENV') && WP_ENV === 'development' ) {
        return true;
    }
    

    $secret_key = '0x4AAAAAAAPvQp7DbBfqJD7LW-gbrAkiAb0'; // Ensure this is the correct key
    $verify_url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    $verify_response = wp_remote_post($verify_url, [
        'body' => [
            'secret' => $secret_key,
            'response' => $response,
        ],
    ]);

    if (is_wp_error($verify_response)) {
        error_log('Turnstile verification request failed: ' . $verify_response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($verify_response);
    $result = json_decode($body);

    if (!$result || empty($result->success)) {
        error_log('Turnstile verification failed or returned unexpected result: ' . $body);
        return false;
    }

    return true;
}


function wp_surgeon_enqueue_turnstile_script() {
    if (is_page('register')) { // Change 'register' to the slug/condition identifying your registration page
        wp_enqueue_script('turnstile-js', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true);
    }
}
add_action('wp_enqueue_scripts', 'wp_surgeon_enqueue_turnstile_script');
