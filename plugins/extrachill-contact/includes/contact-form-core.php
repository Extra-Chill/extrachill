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
            <label for="contact_name">Name</label>
            <input type="text" name="contact_name" id="contact_name" class="input-text" required>
        </div>

        <div class="form-group">
            <label for="contact_email">Email</label>
            <input type="email" name="contact_email" id="contact_email" class="input-text" required>
        </div>

        <div class="form-group">
            <label for="contact_subject">Subject</label>
            <select name="contact_subject" id="contact_subject" class="input-text" required>
                <option value="">Select a subject</option>
                <option value="General Inquiry">General Inquiry</option>
                <option value="Partnership/Collaboration">Partnership/Collaboration</option>
                <option value="Shop/Store Support">Shop/Store Support</option>
                <option value="Technical Issue">Technical Issue</option>
                <option value="Other">Other</option>
            </select>
        </div>

        <div class="form-group">
            <label for="contact_message">Message</label>
            <textarea name="contact_message" id="contact_message" class="input-text" rows="5" required></textarea>
        </div>

        <p class="form-notice">By submitting this form, you\'ll receive our newsletter with music news, festival coverage, and platform updates.</p>

        ' . ec_render_turnstile_widget() . '

        <div class="form-group">
            <input type="submit" value="Send Message" class="submit-button">
        </div>
    </form>';

    return $form_html;
}
add_shortcode('ec_custom_contact_form', 'custom_contact_form_shortcode');

function handle_ec_contact_form_submission() {
    if (!wp_verify_nonce($_POST['ec_contact_form_nonce'], 'ec_contact_form_action')) {
        extrachill_set_notice(
            __( 'Security verification failed. Please try again.', 'extrachill-contact' ),
            'error'
        );
        wp_redirect(home_url('/contact-us/'));
        exit;
    }

    $turnstile_response = isset( $_POST['cf-turnstile-response'] ) ? wp_unslash( $_POST['cf-turnstile-response'] ) : '';

    if ( empty( $turnstile_response ) ) {
        extrachill_set_notice(
            __( 'Captcha verification failed. Please complete the captcha and try again.', 'extrachill-contact' ),
            'error'
        );
        wp_redirect(home_url('/contact-us/'));
        exit;
    }

    if (!ec_verify_turnstile_response($turnstile_response)) {
        extrachill_set_notice(
            __( 'Captcha verification failed. Please complete the captcha and try again.', 'extrachill-contact' ),
            'error'
        );
        wp_redirect(home_url('/contact-us/'));
        exit;
    }

    $name = sanitize_text_field(wp_unslash($_POST['contact_name']));
    $email = sanitize_email(wp_unslash($_POST['contact_email']));
    $subject = sanitize_text_field(wp_unslash($_POST['contact_subject']));
    $message = sanitize_textarea_field(wp_unslash($_POST['contact_message']));

    send_email_to_admin($name, $email, $subject, $message);
    send_confirmation_email_to_user($name, $email, $subject, $message);

    sync_to_sendy($email);

    extrachill_set_notice(
        __( 'Your message has been sent successfully. We\'ll get back to you soon.', 'extrachill-contact' ),
        'success'
    );
    wp_redirect(home_url('/contact-us/'));
    exit;
}
add_action('admin_post_ec_contact_form_action', 'handle_ec_contact_form_submission');
add_action('admin_post_nopriv_ec_contact_form_action', 'handle_ec_contact_form_submission');

function sync_to_sendy($email) {
    if (function_exists('extrachill_multisite_subscribe')) {
        try {
            extrachill_multisite_subscribe($email, 'contact');
        } catch (Exception $e) {
            error_log('Contact form Sendy sync failed: ' . $e->getMessage());
        }
    }
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

    wp_mail($admin_email, "New submission: " . $subject, $admin_body, $admin_headers);
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
  <p>Thank you for reaching out to Extra Chill! We've received your message and will get back to you within 3-5 business days.</p>
  <p>Here's a summary of what you sent:</p>
  <blockquote>$escaped_message</blockquote>
  <p>While you're waiting, feel free to explore the Extra Chill platform:</p>
  <ul>
    <li><a href="https://community.extrachill.com">Community Forums</a> - Connect with other music lovers</li>
    <li><a href="https://artist.extrachill.com">Artist Platform</a> - Discover and follow artists</li>
    <li><a href="https://shop.extrachill.com">Shop</a> - Browse merch and support the platform</li>
    <li><a href="https://chat.extrachill.com">AI Chat</a> - Get instant answers about music and festivals</li>
    <li><a href="https://events.extrachill.com">Events Calendar</a> - Find upcoming shows and festivals</li>
  </ul>
  <p>Best regards,<br>Extra Chill</p>
</body>
</html>
HTML;

    wp_mail($email, $user_subject, $user_body, $user_headers);
}

function wp_surgeon_enqueue_turnstile_script() {
    if (is_page('contact-us')) {
        ec_enqueue_turnstile_script();
    }
}
add_action('wp_enqueue_scripts', 'wp_surgeon_enqueue_turnstile_script');

