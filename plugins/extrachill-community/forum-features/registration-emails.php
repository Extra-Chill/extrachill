<?php
//emails for registratiion to admin and new users
function wp_surgeon_notify_admin_new_user($user_id) {
    $user_data = get_userdata($user_id);
    $username = $user_data->user_login;
    $email = $user_data->user_email;

    $admin_email = get_option('admin_email');
    $subject = "New User Registration Notification";
    $message = "A new user has registered on your site.\n\n";
    $message .= "Username: " . $username . "\n";
    $message .= "Email: " . $email . "\n";
    $message .= "User ID: " . $user_id . "\n";
    $message .= "Artist: " . (isset($_POST['user_is_artist']) && $_POST['user_is_artist'] == '1' ? 'Yes' : 'No') . "\n";
    $message .= "Professional: " . (isset($_POST['user_is_professional']) && $_POST['user_is_professional'] == '1' ? 'Yes' : 'No') . "\n";
    $message .= "You can view the user profile here: " . get_edit_user_link($user_id);

    wp_mail($admin_email, $subject, $message);
}

add_action( 'user_register', 'wp_surgeon_notify_admin_new_user', 10, 1 );

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
    $message .= "<p>Welcome to the <strong>Extra Chill Community</strong>! Now that you're here, this place is a lot more chill!</p>";
    $message .= "<p>You can now participate in the forum, upvote & comment on the blog, and submit events to <a href='https://extrachill.com/calendar'>our calendar</a>.</p>";
    $message .= "<p>Get started by <a href='https://community.extrachill.com/t/introductions-thread'>introducing yourself in The Back Bar</a>!</p>";
    $message .= "<p>You can also <a href='https://extrachill.com/product/ad-free-license'>purchase a $10 ad-free license</a> to remove ads from extrachill.com forever.</p>";
    $message .= "<p><strong>Account Details:</strong><br>";
    $message .= "Username: <strong>" . $username . "</strong><br>";
    $message .= "If you forget your password, you can reset it <a href='" . esc_url($reset_pass_link) . "'>here</a>.</p>";
    $message .= "<p><strong>Other Useful Links:</strong><br>";
    $message .= "<a href='" . esc_url(home_url('/')) . "'>Community Forums</a><br>";
    $message .= "<a href='https://extrachill.com'>Extra Chill</a><br>";
    $message .= "<a href='https://extrachill.com/festival-wire/'>Festival Wire</a><br>";
    $message .= "<a href='https://instagram.com/extrachill'>Instagram</a></p>";
    $message .= "<p>See you in the Community!</p>";
    $message .= "<p>Much love,<br>";
    $message .= "Extra Chill</p>";
    $message .= "</body></html>";

    // Headers for HTML content and custom From
    $headers = array('Content-Type: text/html; charset=UTF-8', 'From: Extra Chill <chubes@extrachill.com>');

    // Send the email
    wp_mail($email, $subject, $message, $headers);
}

add_action( 'user_register', 'send_welcome_email_to_new_user' );