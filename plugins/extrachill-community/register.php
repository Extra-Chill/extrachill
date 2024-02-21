<?php

function wp_surgeon_registration_form_shortcode() {
        global $wp_surgeon_registration_errors;

    ob_start(); // Start output buffering

    // Check if the user is already logged in
    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        // Get BBPress profile URL
        $profile_url = bbp_get_user_profile_url($current_user->ID);

        echo '<p>You are already registered and logged in! <a href="' . esc_url($profile_url) . '">View Profile</a></p>';
    } else {
        $errors = wp_surgeon_get_registration_errors();
        // Registration form HTML
        ?>
<div class="register-form">
     <form action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>" method="post">
<p>
    <label for="wp_surgeon_username">Username</label>
    <input type="text" name="wp_surgeon_username" id="wp_surgeon_username" required>
    <br>
    <small>Usernames cannot be changed.</small>
</p>

    <p>
        <label for="wp_surgeon_email">Email</label>
        <input type="email" name="wp_surgeon_email" id="wp_surgeon_email" required>
    </p>
        <p>
        <label for="wp_surgeon_email_confirm">Confirm Email</label>
        <input type="email" name="wp_surgeon_email_confirm" id="wp_surgeon_email_confirm" required>
    </p>
    <p>
        <label for="wp_surgeon_password">Password</label>
        <input type="password" name="wp_surgeon_password" id="wp_surgeon_password" required>
    </p>
       <p>
        <label for="wp_surgeon_password_confirm">Confirm Password</label>
        <input type="password" name="wp_surgeon_password_confirm" id="wp_surgeon_password_confirm" required>
    </p>
</div>
<b>Check All That Apply</b>
    <small class="register-form">Artist and Industry Professional require admin verification.</small>
</p>
    <div class="register-checkboxes">
    <p>
        <label for="user_is_fan">
            <input name="user_is_fan" type="checkbox" id="user_is_fan" value="1" checked disabled /> Fan
        </label>
    </p>
    <p>
<label for="user_is_artist">
            <input name="user_is_artist" type="checkbox" id="user_is_artist" value="1" /> Artist
        </label>
    </p>
    <p>
        <label for="user_is_professional">
            <input name="user_is_professional" type="checkbox" id="user_is_professional" value="1" /> Industry Professional
        </label>
    </p>
</div>
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
                            wp_nonce_field('wp_surgeon_register_nonce', 'wp_surgeon_register_nonce_field');
?>
</form>

        <?php
    }

    return ob_get_clean(); // Return the buffered output
}
add_shortcode('wp_surgeon_registration_form', 'wp_surgeon_registration_form_shortcode');


// Function to handle user registration
// Global variable to store registration errors
$GLOBALS['wp_surgeon_registration_errors'] = array();
function wp_surgeon_handle_registration() {
    global $wp_surgeon_registration_errors;

    if (isset($_POST['wp_surgeon_register']) && check_admin_referer('wp_surgeon_register_nonce', 'wp_surgeon_register_nonce_field')) {
        // Capture and sanitize input
        $username = sanitize_user($_POST['wp_surgeon_username']);
        $email = sanitize_email($_POST['wp_surgeon_email']);
        $password = esc_attr($_POST['wp_surgeon_password']);
        $password_confirm = esc_attr($_POST['wp_surgeon_password_confirm']);
        $email_confirm = sanitize_email($_POST['wp_surgeon_email_confirm']);

        // Add Turnstile verification
        $turnstile_response = $_POST['cf-turnstile-response'];
        $verify = wp_surgeon_verify_turnstile($turnstile_response);

        if (!$verify) {
            $wp_surgeon_registration_errors[] = 'Captcha verification failed. Please try again.';
            return; // Stop execution if captcha fails
        }

        // Validate form input
        if ($email != $email_confirm) {
            $wp_surgeon_registration_errors[] = 'Error: Emails do not match.';
        }

        if ($password != $password_confirm) {
            $wp_surgeon_registration_errors[] = 'Error: Passwords do not match.';
        }

        if (username_exists($username) || email_exists($email)) {
            $wp_surgeon_registration_errors[] = 'Error: User already exists with this username/email.';
        }

        // Proceed with registration if no errors
        if (empty($wp_surgeon_registration_errors)) {
            $user_id = wp_create_user($username, $password, $email);

            if (!is_wp_error($user_id)) {
                wp_surgeon_notify_admin_new_user($user_id, $username, $email);
                set_transient('registration_success', 'Registration successful!', 60);
                wp_redirect(esc_url($_SERVER['REQUEST_URI']));
                exit;
            } else {
                $wp_surgeon_registration_errors[] = 'Error: ' . $user_id->get_error_message();
            }
        }
    }
}

add_action('init', 'wp_surgeon_handle_registration');



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
    // Send an email notification to the admin
    wp_surgeon_notify_admin_new_user($user_id, $username, $email);
}
add_action('user_register', 'save_user_iss_on_registration');


// Remove admin bar for all users except administrators
function wp_surgeon_remove_admin_bar() {
    if (!current_user_can('administrator')) {
        show_admin_bar(false);
    }
}
add_action('after_setup_theme', 'wp_surgeon_remove_admin_bar');
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

function auto_login_new_user( $user_id ) {
    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id );

    // Send welcome email
    send_welcome_email_to_new_user( $user_id );

    wp_redirect( home_url( '/user-dashboard' ) ); // Adjust the URL to your user dashboard
    exit;
}

function send_welcome_email_to_new_user( $user_id ) {
    $user_data = get_userdata($user_id);
    $username = $user_data->user_login;
    $email = $user_data->user_email;
    // Set the password reset link
    $reset_pass_link = wp_lostpassword_url();

    // Email subject & body
    $subject = "Welcome to the Extra Chill Community!";
    $message = "Hello " . $username . ",\n\n";
    $message .= "Welcome to the Extra Chill Community! We're excited to have you on board.\n\n";
    $message .= "Here are some details to get you started:\n";
    $message .= "Username: " . $username . "\n";
    $message .= "If you forget your password: " . $reset_pass_link . "\n\n";
    $message .= "Here are some links you might find useful:\n";
    $message .= "Your Dashboard: " . home_url( '/user-dashboard' ) . "\n";
    $message .= "Community Forums: " . home_url( '/' ) . "\n"; 
    $message .= "Main Blog: https://extrachill.com\n";
    $message .= "Instagram: https://instagram.com/extrachill\n";
    // Newly added content
    $message .= "You can now participate in the Community, including upvoting and commenting on blog articles on the main site. Get started by sharing some of your favorite music in The Rabbit Hole: " . esc_url( home_url( '/community/#TheRabbitHole' ) ) . "\n";
    $message .= "Learn more about how the Extra Chill Community works: " . esc_url( home_url( '/community-info' ) ) . "\n";
    // End of newly added content

    $message .= "\nEnjoy your stay,\n";
    $message .= "The Extra Chill Team";

    // Send the email
    wp_mail($email, $subject, $message);
}

add_action( 'user_register', 'send_welcome_email_to_new_user' );


function wp_surgeon_notify_admin_new_user($user_id, $username, $email) {
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
    $message .= "Artist (Pending): $is_artist_pending\n";
    $message .= "Industry Professional (Pending): $is_professional_pending\n";
    $message .= "You can view the user profile here: " . get_edit_user_link($user_id);

    wp_mail($admin_email, $subject, $message);
}
function wp_surgeon_verify_turnstile($response) {
    $secret_key = '0x4AAAAAAAPvQp7DbBfqJD7LW-gbrAkiAb0'; // Replace with your Turnstile secret key
    $verify_url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    $response = wp_remote_post($verify_url, [
        'body' => [
            'secret' => $secret_key,
            'response' => $response,
        ],
    ]);

if (is_wp_error($response)) {
    // Log error for debugging
    error_log('Turnstile verification request failed: ' . $response->get_error_message());
    return false;
}


    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body);

    return !empty($result->success);
}

function wp_surgeon_enqueue_turnstile_script() {
    if (is_page('register')) { // Change 'register' to the slug/condition identifying your registration page
        wp_enqueue_script('turnstile-js', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true);
    }
}
add_action('wp_enqueue_scripts', 'wp_surgeon_enqueue_turnstile_script');
