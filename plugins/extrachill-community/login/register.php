<?php
/**
 * User Registration System
 *
 * Handles user registration with email verification and validation.
 *
 * @package ExtraChillCommunity
 */

function extrachill_registration_form_shortcode() {
    global $extrachill_registration_errors;

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
        $profile_url = bbp_get_user_profile_url($current_user->ID);
        echo '<p>You are already registered and logged in! <a href="' . esc_url($profile_url) . '">View Profile</a></p>';
    } else {
        $errors = extrachill_get_registration_errors();
        ?>
        <div class="login-register-form">
    <h2>Join the Extra Chill Community</h2>
    <p>Sign up to connect with music lovers, artists, and professionals in the online music scene! It's free and easy.</p>

    <?php if (isset($_GET['from_join']) && $_GET['from_join'] === 'true') : ?>
        <div class="bp-notice bp-notice-info">
            Welcome! Create a new account to get started with your extrachill.link page.
        </div>
    <?php endif; ?>

    <?php if (!empty($artist_name_for_invite_message) && !empty($invite_token)) : ?>
        <div class="bp-notice bp-notice-invite">
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

        <?php if (isset($_GET['from_join']) && $_GET['from_join'] === 'true') : ?>
            <div class="bp-notice bp-notice-info join-flow-requirement">
                <p><strong>Note:</strong> To create your extrachill.link page, you must select either "I am a musician" or "I work in the music industry".</p>
            </div>
        <?php endif; ?>

        <div class="registration-submit-section">
            <input type="submit" name="extrachill_register" value="Join Now">
        </div>

        <div class="cf-turnstile" data-sitekey="0x4AAAAAAAPvQsUv5Z6QBB5n" data-callback="community_register"></div>

        <?php wp_nonce_field('extrachill_register_nonce', 'extrachill_register_nonce_field'); ?>
        <?php if ( isset($_GET['from_join']) && $_GET['from_join'] === 'true' ) : ?>
            <input type="hidden" name="from_join" value="true">
        <?php endif; ?>
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
function extrachill_handle_registration() {
    global $extrachill_registration_errors;
    $processed_invite_artist_id = null; // Variable to store artist_id if invite is processed

    if (isset($_POST['extrachill_register']) && check_admin_referer('extrachill_register_nonce', 'extrachill_register_nonce_field')) {
        // Collect and sanitize form data
        $username = sanitize_user($_POST['extrachill_username']);
        $email = sanitize_email($_POST['extrachill_email']);
        $password = esc_attr($_POST['extrachill_password']);
        $password_confirm = esc_attr($_POST['extrachill_password_confirm']);

        // Captcha verification
        $turnstile_response = $_POST['cf-turnstile-response'];
        
        if (!extrachill_verify_turnstile($turnstile_response)) {
            $extrachill_registration_errors[] = 'Captcha verification failed. Please try again.';
            return; // Early return to prevent further processing
        }

        // Password confirmation
        if ($password !== $password_confirm) {
            $extrachill_registration_errors[] = 'Error: Passwords do not match.';
            return; // Early return to prevent further processing
        }

        // Join flow validation - require artist or professional status
        $from_join_flag = isset($_POST['from_join']) && $_POST['from_join'] === 'true';
        if ($from_join_flag) {
            $user_is_artist = isset($_POST['user_is_artist']) && $_POST['user_is_artist'] === '1';
            $user_is_professional = isset($_POST['user_is_professional']) && $_POST['user_is_professional'] === '1';
            
            if (!$user_is_artist && !$user_is_professional) {
                $extrachill_registration_errors[] = 'To create your extrachill.link page, please select either "I am a musician" or "I work in the music industry".';
                return; // Early return to prevent further processing
            }
        }

        // Check for existing username or email
        if (username_exists($username) || email_exists($email)) {
            $extrachill_registration_errors[] = 'Error: User already exists with this username/email.';
            return; // Early return to prevent further processing
        }

        // Create the user
        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            // Convert WP_Error to string and add to errors array
            $error_messages = implode(", ", $user_id->get_error_messages());
            $extrachill_registration_errors[] = 'Registration error: ' . $error_messages;
            return; // Early return to prevent further processing
        }

        // If there are errors, redirect back to the registration form with errors
        if (!empty($extrachill_registration_errors)) {
             // Store errors temporarily if needed across the redirect, though current setup uses a global.
             // If using a global, make sure the global persists across the redirect or use transients.
             // For now, assuming global is sufficient or errors are handled on reload.
            $register_url = home_url('/login/');
             // Add #tab-register hash to the redirect URL
            $redirect_url_with_hash = $register_url . '#tab-register';
            wp_redirect(esc_url_raw($redirect_url_with_hash));
            exit;
        }

        // If the user is created successfully, continue to sync with Sendy and log in the user
        $sync_success = sync_to_sendy($email, $username);
        if (!$sync_success) {
            $extrachill_registration_errors[] = 'Failed to synchronize with Sendy. Please contact support or try again later.';
            // Consider whether you want to roll back user creation here or just leave the user registered without Sendy sync
        }

        // Check for and process band invitation
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
                    $valid_invite_id_for_removal = $invite['id']; // Assuming 'id' is the key for the invitation unique ID
                    break;
                }
            }

            if ($valid_invite_data) {
                // USER_IS_ARTIST META IS NO LONGER FORCED HERE. USER CHOICE FROM CHECKBOX WILL BE USED.

                if (bp_add_artist_membership($user_id, $invite_artist_id_posted)) {
                    if (bp_remove_pending_invitation($invite_artist_id_posted, $valid_invite_id_for_removal)) {
                        $processed_invite_artist_id = $invite_artist_id_posted; // Store for redirect
                    } else {
                        // Still treat as success for user, but log the cleanup issue
                        $processed_invite_artist_id = $invite_artist_id_posted;
                    }
                } else {
                    $extrachill_registration_errors[] = 'Your account was created, but there was an issue joining the invited band. Please contact support.';
                }
            } else {
                // Don't add an error to $extrachill_registration_errors here, as registration itself might be fine, just invite part failed.
                // User gets registered as normal without joining band.
            }
        } elseif ($invite_token_posted || $invite_artist_id_posted) {
            // Log if token/artist_id was posted but functions were missing (should not happen with require_once at top)
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
        // Pass the from_join flag to auto_login_new_user
        $from_join_flag = isset($_GET['from_join']) && $_GET['from_join'] === 'true';
        auto_login_new_user($user_id, $processed_invite_artist_id, $from_join_flag);


     }
 }

add_action('init', 'extrachill_handle_registration');


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
    }
}


function auto_login_new_user($user_id, $redirect_artist_id = null, $from_join_flow = false) {
    $user = get_user_by('id', $user_id);

    if ($user) {
        wp_set_current_user($user_id, $user->user_login);
        wp_set_auth_cookie($user_id, true);
        do_action('wp_login', $user->user_login, $user); // Fire the wp_login action

        // Determine the redirect URL

        // If from join flow, use default redirect
        if ($from_join_flow) {
            $redirect_url = apply_filters('registration_redirect', home_url());
        }
        // If not from join flow and a specific artist ID was provided (e.g., from invite), redirect there.
        else if ($redirect_artist_id) {
             // Redirect to the artist's profile page if it exists.
             $artist_post = get_post($redirect_artist_id);
             if ($artist_post && $artist_post->post_type === 'artist_profile') {
                 $redirect_url = get_permalink($artist_post);
             } else {
                 // Fallback if band post not found
                 $redirect_url = home_url();
             }

        }
        // Default redirect after registration if not from join flow or artist invite
        else {
            $redirect_url = apply_filters('registration_redirect', home_url());
        }


        wp_redirect(esc_url_raw($redirect_url));
        exit;
    }
}


function extrachill_get_registration_errors() {
    // Initialize an empty array to store errors
    $errors = [];

    // Check if there are any stored errors in a global variable or a session
    if (isset($GLOBALS['extrachill_registration_errors'])) {
        $errors = $GLOBALS['extrachill_registration_errors'];
    }

    return $errors;
}

// Function to verify Turnstile response
function extrachill_verify_turnstile($response) {
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
        return false;
    }

    $body = wp_remote_retrieve_body($verify_response);
    $result = json_decode($body);

    if (!$result || empty($result->success)) {
        return false;
    }

    return true;
}
