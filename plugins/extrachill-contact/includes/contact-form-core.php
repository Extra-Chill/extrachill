<?php
/**
 * ExtraChill Contact Form Core Functionality
 */

defined( 'ABSPATH' ) || exit;

function custom_contact_form_shortcode() {
    $form_html = '
    <form id="ec-contact-form" class="custom-contact-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">
        ' . wp_nonce_field('ec_contact_form_action', 'ec_contact_form_nonce', true, false) . '
        <input type="hidden" name="action" value="ec_contact_form_action">

        <div class="form-group">
            <label for="contact_name">Name *</label>
            <input type="text" name="contact_name" id="contact_name" class="input-text" required>
        </div>

        <div class="form-group">
            <label for="contact_email">Email *</label>
            <input type="email" name="contact_email" id="contact_email" class="input-text" required>
        </div>

        <div class="form-group">
            <label for="contact_subject">Subject *</label>
            <select name="contact_subject" id="contact_subject" class="input-text" required>
                <option value="">Select a subject</option>
                <option value="General Inquiry">General Inquiry</option>
                <option value="Press/Media">Press/Media</option>
                <option value="Festival Submission">Festival Submission</option>
                <option value="Partnership">Partnership</option>
                <option value="Technical Issue">Technical Issue</option>
                <option value="Other">Other</option>
            </select>
        </div>

        <div class="form-group">
            <label for="contact_message">Message *</label>
            <textarea name="contact_message" id="contact_message" class="input-text" rows="5" required></textarea>
        </div>

        <div class="form-group consent">
            <input type="checkbox" name="newsletter_consent" id="newsletter_consent" value="yes">
            <label for="newsletter_consent">Subscribe to our newsletter</label>
        </div>

        <div class="cf-turnstile" data-sitekey="0x4AAAAAAAPvQsUv5Z6QBB5n"></div>

        <div class="form-group">
            <input type="submit" value="Send Message" class="submit-button">
        </div>
    </form>';

    return $form_html;
}
add_shortcode('ec_custom_contact_form', 'custom_contact_form_shortcode');

function handle_ec_contact_form_submission() {
    if (!wp_verify_nonce($_POST['ec_contact_form_nonce'], 'ec_contact_form_action')) {
        wp_die('Nonce verification failed');
    }

    $turnstile_response = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';
    if (!wp_surgeon_verify_turnstile($turnstile_response)) {
        wp_die('Captcha verification failed');
    }

    $name = sanitize_text_field(wp_unslash($_POST['contact_name']));
    $email = sanitize_email(wp_unslash($_POST['contact_email']));
    $subject = sanitize_text_field(wp_unslash($_POST['contact_subject']));
    $message = sanitize_textarea_field(wp_unslash($_POST['contact_message']));
    $newsletter_consent = isset($_POST['newsletter_consent']) ? sanitize_text_field(wp_unslash($_POST['newsletter_consent'])) : '';

    send_email_to_admin($name, $email, $subject, $message);
    send_confirmation_email_to_user($name, $email, $subject, $message);

    if ($newsletter_consent === 'yes') {
        sync_to_sendy($email);
    }

    $redirect_url = add_query_arg('contact_success', '1', wp_get_referer());
    wp_redirect($redirect_url);
    exit;
}
add_action('admin_post_ec_contact_form_action', 'handle_ec_contact_form_submission');
add_action('admin_post_nopriv_ec_contact_form_action', 'handle_ec_contact_form_submission');

function sync_to_sendy($email) {
    if (function_exists('subscribe_email_to_sendy')) {
        try {
            subscribe_email_to_sendy($email, 'contact');
        } catch (Exception $e) {
            error_log('Contact form Sendy sync failed: ' . $e->getMessage());
        }
    }
}

/**
 * Verify Cloudflare Turnstile response with hardcoded credentials
 */
function wp_surgeon_verify_turnstile($response) {
    if (empty($response)) {
        return false;
    }

    $secret_key = '0x4AAAAAAAPvQp7DbBfqJD7LW-gbrAkiAb0';

    $verification_url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $verification_data = array(
        'secret' => $secret_key,
        'response' => $response
    );

    $response = wp_remote_post($verification_url, array(
        'body' => $verification_data,
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    return isset($data['success']) && $data['success'] === true;
}

function send_email_to_admin($name, $email, $subject, $message) {
    $admin_email = get_option('admin_email');
    $admin_headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: ' . $email
    );

    $subject = stripslashes(htmlspecialchars_decode($subject, ENT_QUOTES));

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

    $subject = stripslashes(htmlspecialchars_decode($subject, ENT_QUOTES));

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

function wp_surgeon_enqueue_turnstile_script() {
    if (is_page('contact-us')) {
        wp_enqueue_script('cloudflare-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true);
    }
}
add_action('wp_enqueue_scripts', 'wp_surgeon_enqueue_turnstile_script');

function display_contact_success_message() {
    if (isset($_GET['contact_success']) && $_GET['contact_success'] == '1') {
        echo '<div class="contact-success-message" style="background: #d4edda; color: #155724; padding: 15px; border: 1px solid #c3e6cb; border-radius: 4px; margin: 20px 0;">
                <strong>Thank you!</strong> Your message has been sent successfully. We\'ll get back to you soon.
              </div>';
    }
}
add_action('wp_head', 'display_contact_success_message');