<?php

// open mic signup form, sync to sendy

function extrachill_open_mic_form() {
    ob_start(); ?>
    <form id="openMicSignup" method="post">
        <h2>Open Mic Signup Form</h2>
        <p><b><label for="performance_type">Performance Type:</label></b>
        <select id="performance_type" name="performance_type" required>
            <option value="music">Music</option>
            <option value="comedy">Comedy</option>
            <option value="other">Other</option>
        </select></p>
        <b><label for="name">Name:</label></b>
        <input type="text" id="name" name="name" required>
        <b><label for="email">Email:</label></b>
        <input type="email" id="email" name="email" required>
        <b><label for="instagram_handle">Instagram Handle:</label></b>
        <input type="text" id="instagram_handle" name="instagram_handle" required>
        <?php wp_nonce_field('open_mic_nonce_action', 'open_mic_nonce'); ?>
        <div class="cf-turnstile" data-sitekey="0x4AAAAAAAPvQsUv5Z6QBB5n" data-callback="open_mic_callback"></div>
        <input type="submit" value="Submit">
        <div id="signupMessage"></div>
    </form>
    <script type="text/javascript">
// Define the callback function that Turnstile will call when the CAPTCHA is completed
function open_mic_callback(response) {
    document.getElementById('openMicSignup').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        formData.append('action', 'open_mic_signup');

        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData
        }).then(response => response.text())
          .then(data => {
            document.getElementById('signupMessage').innerText = data;
          });
    });
}

document.getElementById('openMicSignup').addEventListener('submit', function(e) {
    e.preventDefault(); // Prevent form submission until CAPTCHA is completed
});
</script>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

    <?php
    return ob_get_clean();
}
add_shortcode('open_mic_signup', 'extrachill_open_mic_form');

function extrachill_create_signup_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'open_mic_signups';

    // Check if the table already exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            performance_type tinytext NOT NULL,
            name tinytext NOT NULL,
            email tinytext NOT NULL,
            instagram_handle tinytext NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
add_action('after_switch_theme', 'extrachill_create_signup_table');


function extrachill_handle_open_mic_signup() {
    if (!check_ajax_referer('open_mic_nonce_action', 'open_mic_nonce', false)) {
        wp_send_json_error('Nonce verification failed', 403);
        wp_die();
    }

    $turnstile_response = sanitize_text_field($_POST['cf-turnstile-response']);
    $secret_key = '0x4AAAAAAAPvQp7DbBfqJD7LW-gbrAkiAb0';

    $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'body' => [
            'secret' => $secret_key,
            'response' => $turnstile_response,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ]
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('Captcha verification request failed', 403);
        wp_die();
    }

    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    if (!isset($result['success']) || !$result['success']) {
        echo isset($result['error-codes']) ? implode(", ", $result['error-codes']) : 'Captcha verification failed, please try again.';
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'open_mic_signups';

    $data = [
        'performance_type' => sanitize_text_field($_POST['performance_type']),
        'name' => sanitize_text_field($_POST['name']),
        'email' => sanitize_email($_POST['email']),
        'instagram_handle' => sanitize_text_field($_POST['instagram_handle'])
    ];

    $insert_result = $wpdb->insert($table_name, $data);

    if ($insert_result) {
        // Send confirmation email to the submitter
        send_confirmation_to_submitter($data['email'], $data['name']);

        // Notify the admin
        notify_admin_of_signup($data);

        // Sync with Sendy
        sync_open_mic_to_sendy($data);

        echo 'Thank you for signing up! We will reach out if there is a performance slot available.';
    } else {
        echo 'There was an error processing your submission.';
    }

    wp_die();
}

// Hook the AJAX actions
add_action('wp_ajax_open_mic_signup', 'extrachill_handle_open_mic_signup');
add_action('wp_ajax_nopriv_open_mic_signup', 'extrachill_handle_open_mic_signup');

function open_mic_submissions_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'open_mic_signups';
    $submissions = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC");

    echo '<div class="wrap"><h1>Open Mic Submissions</h1>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Performance Type</th><th>Instagram Handle</th></tr></thead>';
    echo '<tbody>';
    foreach ($submissions as $submission) {
        echo "<tr><td>{$submission->id}</td><td>{$submission->name}</td><td>{$submission->email}</td><td>{$submission->performance_type}</td><td>{$submission->instagram_handle}</td></tr>";
    }
    echo '</tbody></table></div>';
}

function sync_open_mic_to_sendy($data) {
    $sendy_url = 'https://mail.extrachill.com/sendy/subscribe'; // Replace with your Sendy installation URL
    $list_id = 'xG1xzVf2maosKt08923bhs8w'; // The list ID where you want to add the subscriber
    $api_key = 'z7RZLH84oEKNzMvFZhdt'; // Your Sendy API key

    $response = wp_remote_post($sendy_url, array(
        'body' => array(
            'email' => $data['email'],
            'name' => $data['name'],
            'list' => $list_id,
            'boolean' => 'true', // To ensure you get a JSON response
            'api_key' => $api_key
        )
    ));

    if (is_wp_error($response)) {
        error_log('Sendy sync failed: ' . $response->get_error_message());
    } else {
        error_log('Sendy sync successful for: ' . $data['email']);
    }
}

function send_confirmation_to_submitter($email, $name) {
    $subject = "Confirmation of Your Open Mic Signup";
    $message = "Hi " . $name . ",\n\nThank you for submitting to perform at the Extra Chill Happy Hour at Lofi Brewing! We will be in touch soon regarding performance details.\n\nBest,\nThe Extra Chill Team";

    wp_mail($email, $subject, $message);
}

function notify_admin_of_signup($data) {
    $admin_email = get_option('admin_email'); // Get the admin email from WordPress settings
    $subject = "New Open Mic Signup";
    $message = "A new signup has been received:\n\n"
             . "Name: " . $data['name'] . "\n"
             . "Email: " . $data['email'] . "\n"
             . "Performance Type: " . $data['performance_type'] . "\n"
             . "Instagram Handle: " . $data['instagram_handle'];

    wp_mail($admin_email, $subject, $message);
}
?>
