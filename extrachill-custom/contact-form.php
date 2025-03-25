<?php

function custom_contact_form_shortcode() {
    $form_html = '<form action="' . esc_url( admin_url('admin-post.php') ) . '" method="post">
        <div class="custom-contact-form">
            <div class="form-group">
                <label for="fullname">What\'s your name? <abbr class="required" title="Required">*</abbr></label>
                <input type="text" id="fullname" name="fullname" required class="input-text">
            </div>
            <div class="form-group">
                <label for="email">What\'s your email? <abbr class="required" title="Required">*</abbr></label>
                <input type="email" id="email" name="email" required class="input-text">
            </div>
            <div class="form-group">
                <label for="subject">What\'s up? <abbr class="required" title="Required">*</abbr></label>
                <select id="subject" name="subject" required class="input-text">
                    <option value="Just sharing music.">Just sharing music</option>
                    <option value="There\'s a cool show coming up.">There\'s a cool show coming up</option>
                    <option value="Online order question.">Online order question</option>
                    <option value="I want to work for Extra Chill.">I want to work for Extra Chill</option>
                    <option value="I\'m interested in advertising.">I\'m interested in advertising</option>
                    <option value="It\'s something else.">It\'s something else</option>
                </select>
            </div>
            <div class="form-group">
                <label for="message">Message <abbr class="required" title="Required">*</abbr></label>
                <textarea id="message" name="message" required class="input-text" rows="10"></textarea>
                </div>
                <div class="cf-turnstile" data-sitekey="0x4AAAAAAAPvQsUv5Z6QBB5n" data-callback="ec_contact"></div>
            <div class="form-group consent">
            <p>By using this contact form, you consent to receiving emails from Extra Chill.</p>
        </div>
                    <input type="hidden" name="action" value="ec_contact_form_action">'
            . wp_nonce_field( 'ec_contact_form_nonce', 'ec_contact_form_nonce_field', true, false ) .
            '<button type="submit" class="submit-button">Submit</button>
        </div>
    </form>';

    return $form_html;
}
add_shortcode('ec_custom_contact_form', 'custom_contact_form_shortcode');


function handle_ec_contact_form_submission() {
    // Security check
    if (!isset($_POST['ec_contact_form_nonce_field']) || !wp_verify_nonce($_POST['ec_contact_form_nonce_field'], 'ec_contact_form_nonce')) {
        wp_die('Security check failed');
    }

        // Check if Turnstile response is set
        if (!isset($_POST['cf-turnstile-response'])) {
            wp_die('Captcha response is missing. Please make sure JavaScript is enabled and that you are not blocking scripts.');
        }
    
    // Verify the Turnstile captcha
    $turnstile_response = $_POST['cf-turnstile-response'];
    if (!wp_surgeon_verify_turnstile($turnstile_response)) {
        wp_die('Captcha verification failed. Please try again.');
    }

    // Sanitize form data
    $name = sanitize_text_field($_POST['fullname']);
    $email = sanitize_email($_POST['email']);
    $subject = sanitize_text_field($_POST['subject']);
    $message = sanitize_textarea_field($_POST['message']);

    // Sync data to Sendy
    sync_to_sendy($email, $name);

    // Insert submission into the database
    global $wpdb;
    $table_name = $wpdb->prefix . 'contact_submissions';
    $wpdb->insert(
        $table_name,
        array(
            'time' => current_time('mysql'),
            'email' => $email,
        )
    );

    // Send emails
    send_email_to_admin($name, $email, $subject, $message);
    send_confirmation_email_to_user($name, $email, $subject, $message);

    // Redirect to avoid form resubmission
    wp_redirect(home_url('/thank-you/'));
    exit;
}

// Sync user data to Sendy
function sync_to_sendy($email, $name) {
    $sendyUrl = 'https://mail.extrachill.com/sendy';
    $listId = 'BDcQlqYuXXifYrXrd3ViUQ';
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
function send_email_to_admin($name, $email, $subject, $message) {
    $admin_email = get_option('admin_email');
    $admin_headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: ' . $email
    );

    // Fix: Remove backslashes from subject
    $subject = stripslashes(htmlspecialchars_decode($subject, ENT_QUOTES));

    // Ensure the message is safe and properly formatted
    $escaped_message = nl2br(stripslashes(htmlspecialchars($message, ENT_HTML5, 'UTF-8')));

    $admin_body = <<<HTML
<html>
<head>
  <title>New Contact Form Submission</title>
</head>
<body>
  <p><strong>Name:</strong> $name</p>
  <p><strong>Email:</strong> $email</p>
  <p><strong>Subject:</strong> $subject</p>
  <p><strong>Message:</strong></p>
  <div>$escaped_message</div>
</body>
</html>
HTML;

    wp_mail($admin_email, "New submission: $subject", $admin_body, $admin_headers);
}

function send_confirmation_email_to_user($name, $email, $subject, $message) {
    $admin_email = get_option('admin_email');
    $user_subject = "Extra Chill Got Your Message";
    $user_headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: Extra Chill <' . $admin_email . '>'
    );

    // Fix: Remove backslashes from subject
    $subject = stripslashes(htmlspecialchars_decode($subject, ENT_QUOTES));

    // Ensure the message is safe and properly formatted
    $escaped_message = nl2br(stripslashes(htmlspecialchars($message, ENT_HTML5, 'UTF-8')));

    $user_body = <<<HTML
<html>
<head>
  <title>Extra Chill Got Your Message</title>
</head>
<body>
  <p>Hey $name,</p>
  <p>Thank you for reaching out to Extra Chill!</p>
  <p>We prioritize responses for members of the <a href="https://community.extrachill.com">Extra Chill Community</a>, our free-to-join forum where you can connect with other music lovers, share ideas, and get exclusive insights.</p>
  <p>If you're already a member and haven't heard back within two weeks, feel free to follow up.</p>
  <p>Not a member yet? <a href="https://community.extrachill.com">Join here</a> and post your message—this is the best way to get a response from us.</p>
  <p>Here's a summary of your message:</p>
  <blockquote>$escaped_message</blockquote>
  <p>We truly appreciate & support those who support us and look forward to seeing you in the community!</p>
  <p>Best regards,<br>Extra Chill</p>
</body>
</html>
HTML;

    wp_mail($email, $user_subject, $user_body, $user_headers);
}




function create_submissions_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'contact_submissions'; 

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        email varchar(255) DEFAULT '' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'create_submissions_table' );

function contact_form_admin_menu() {
    add_menu_page(
        'Contact Form Submissions', // Page title
        'Contact Submissions', // Menu title
        'manage_options', // Capability
        'contact-form-submissions', // Menu slug
        'contact_form_submissions_page', // Function to display the page
        'dashicons-email-alt' // Icon
    );

    add_submenu_page(
        'contact-form-submissions', // Parent slug
        'Open Mic Submissions', // Page title
        'Open Mic Submissions', // Menu title
        'manage_options', // Capability
        'open-mic-submissions', // Menu slug
        'open_mic_submissions_page' // Function to display the page
    );
}
add_action('admin_menu', 'contact_form_admin_menu');


function contact_form_submissions_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'contact_submissions';
    $submissions = $wpdb->get_results( "SELECT * FROM $table_name" );

    echo '<div class="wrap"><h1>Contact Form Submissions</h1>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Email</th><th>Submission Time</th></tr></thead>';
    echo '<tbody>';
    foreach ( $submissions as $submission ) {
        echo "<tr><td>{$submission->email}</td><td>{$submission->time}</td></tr>";
    }
    echo '</tbody></table></div>';
}

// Hook for authenticated users
add_action( 'admin_post_ec_contact_form_action', 'handle_ec_contact_form_submission' );

// Hook for non-authenticated users
add_action( 'admin_post_nopriv_ec_contact_form_action', 'handle_ec_contact_form_submission' );


function ec_ensure_db_table_exists() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'contact_submissions';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
        create_submissions_table(); // Call your existing table creation function
    }
}
add_action('admin_init', 'ec_ensure_db_table_exists');

function wp_surgeon_enqueue_turnstile_script() {
    if (is_page('contact-us')) { // Change 'register' to the slug/condition identifying your registration page
        wp_enqueue_script('turnstile-js', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true);
    }
}
add_action('wp_enqueue_scripts', 'wp_surgeon_enqueue_turnstile_script');
