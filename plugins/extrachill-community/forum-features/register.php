<?php

function wp_surgeon_registration_form_shortcode() {
    global $wp_surgeon_registration_errors;

    ob_start(); // Start output buffering

    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        $profile_url = bbp_get_user_profile_url($current_user->ID);
        echo '<p>You are already registered and logged in! <a href="' . esc_url($profile_url) . '">View Profile</a></p>';
    } else {
        $errors = wp_surgeon_get_registration_errors();
        ?>
        <div class="register-form">
            <form action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>" method="post">
                <p>
                    <label for="wp_surgeon_username">Username</label>
                    <input type="text" name="wp_surgeon_username" id="wp_surgeon_username" required>
                    <br>
                    <small>This is what you will be known as in the forum. Usernames cannot be changed.</small>
                </p>
                <p>
                    <label for="wp_surgeon_email">Email</label>
                    <input type="email" name="wp_surgeon_email" id="wp_surgeon_email" required>
                </p>
                <p>
                    <label for="wp_surgeon_password">Password</label>
                    <input type="password" name="wp_surgeon_password" id="wp_surgeon_password" required>
                </p>
                <p>
                    <label for="wp_surgeon_password_confirm">Confirm Password</label>
                    <input type="password" name="wp_surgeon_password_confirm" id="wp_surgeon_password_confirm" required>
                </p>
                <p>
                    <input type="submit" name="wp_surgeon_register" value="Register">
                    <div class="cf-turnstile" data-sitekey="0x4AAAAAAAPvQsUv5Z6QBB5n" data-callback="community_register"></div>
                </p>
                <?php if (!empty($errors)): ?>
                    <div class="registration-errors">
                        <?php foreach ($errors as $error): ?>
                            <p class="error"><?php echo $error; ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; 
                wp_nonce_field('wp_surgeon_register_nonce', 'wp_surgeon_register_nonce_field'); ?>
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

        // Other post-registration processes like auto-login
        auto_login_new_user($user_id);
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

    

function auto_login_new_user($user_id) {
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
    wp_redirect(home_url('/user-dashboard'));
    exit;
}


function save_user_iss_on_registration($user_id) {
    // Retrieve user data
    $user_data = get_userdata($user_id);
    $username = $user_data->user_login;
    $email = $user_data->user_email;

    // Assign the 'Fan' role by default to every user
    update_user_meta($user_id, 'user_is_fan', '1');

    if (isset($_POST['user_is_artist'])) {
        // Set the initial artist role to 'pending'
        update_user_meta($user_id, 'user_is_artist_pending', '1');
    }

    if (isset($_POST['user_is_professional'])) {
        // Set the initial professional role to 'pending'
        update_user_meta($user_id, 'user_is_professional_pending', '1');
    }
}
add_action('user_register', 'save_user_iss_on_registration');


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


function send_welcome_email_to_new_user($user_id) {
    $user_data = get_userdata($user_id);
    $username = $user_data->user_login;
    $email = $user_data->user_email;
    // Set the password reset link
    $reset_pass_link = wp_lostpassword_url();

    // Email subject
    $subject = "Welcome to the Extra Chill Community!";

    // Email body with HTML formatting
    $message = "<html><body>";
    $message .= "<p>Hello <strong>" . $username . "</strong>,</p>";
    $message .= "<p>Welcome to the <strong>Extra Chill Community</strong>! We're excited to have you on board.</p>";
    $message .= "<p>You can now participate in the Community, including upvoting and commenting on blog articles on the main site.</p>";
    $message .= "<p>If you are an artist or music industry professional, don't forget to request verification in your <a href='" . esc_url(home_url('/user-dashboard')) . "'>User Dashboard</a>.</p>";
    $message .= "<p><strong>Account Details:</strong><br>";
    $message .= "Username: <strong>" . $username . "</strong><br>";
    $message .= "If you forget your password, you can reset it <a href='" . esc_url($reset_pass_link) . "'>here</a>.</p>";
    $message .= "<p><strong>Other Useful Links:</strong><br>";
    $message .= "<a href='" . esc_url(home_url('/')) . "'>Community Forums</a><br>";
    $message .= "<a href='https://extrachill.com'>Main Blog</a><br>";
    $message .= "<a href='https://instagram.com/extrachill'>Instagram</a></p>";
    $message .= "<p>Learn more about how the Extra Chill Community works: <a href='" . esc_url(home_url('/community-info')) . "'>Community Info</a>.</p>";
    $message .= "<p>Enjoy your stay,<br>";
    $message .= "The Extra Chill Team</p>";
    $message .= "</body></html>";

    // Headers for HTML content and custom From
    $headers = array('Content-Type: text/html; charset=UTF-8', 'From: Extra Chill <chubes@extrachill.com>');

    // Send the email
    wp_mail($email, $subject, $message, $headers);
}

add_action( 'user_register', 'send_welcome_email_to_new_user' );


add_action( 'user_register', 'wp_surgeon_notify_admin_new_user', 10, 1 );

function wp_surgeon_notify_admin_new_user($user_id) {
    $user_data = get_userdata($user_id);
    $username = $user_data->user_login;
    $email = $user_data->user_email;

    $admin_email = get_option('admin_email');
    $subject = "New User Registration Notification";

    $is_fan = get_user_meta($user_id, 'user_is_fan', true) ? 'Yes' : 'No';
    $is_artist_pending = get_user_meta($user_id, 'user_is_artist_pending', true) ? 'Yes' : 'No';
    $is_professional_pending = get_user_meta($user_id, 'user_is_professional_pending', true) ? 'Yes' : 'No';

    $message = "A new user has registered on your site.\n\n";
    $message .= "Username: $username\n";
    $message .= "Email: $email\n";
    $message .= "User ID: $user_id\n";
    $message .= "Fan: $is_fan\n";
    $message .= "You can view the user profile here: " . get_edit_user_link($user_id);

    wp_mail($admin_email, $subject, $message);
}

function wp_surgeon_verify_turnstile($response) {
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
