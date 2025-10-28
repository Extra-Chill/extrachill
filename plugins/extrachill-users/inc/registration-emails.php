<?php
/**
 * Registration Email System
 *
 * Sends HTML welcome email and admin notification on user_register action hook.
 *
 * @package ExtraChill\Users
 */

/**
 * Send admin notification on new user registration.
 *
 * @param int $user_id User ID
 */
function extrachill_notify_admin_new_user($user_id) {
    $user_data = get_userdata($user_id);
    $username = $user_data->user_login;
    $email = $user_data->user_email;

    $admin_email = get_option('admin_email');
    $subject = "New User Registration Notification";
    $message = "A new user has registered on the Extra Chill platform.\n\n";
    $message .= "Username: " . $username . "\n";
    $message .= "Email: " . $email . "\n";
    $message .= "User ID: " . $user_id . "\n";
    $message .= "Artist: " . (isset($_POST['user_is_artist']) && $_POST['user_is_artist'] == '1' ? 'Yes' : 'No') . "\n";
    $message .= "Professional: " . (isset($_POST['user_is_professional']) && $_POST['user_is_professional'] == '1' ? 'Yes' : 'No') . "\n";
    $message .= "You can view the user profile here: " . get_edit_user_link($user_id);

    wp_mail($admin_email, $subject, $message);
}

add_action( 'user_register', 'extrachill_notify_admin_new_user', 10, 1 );

/**
 * Send welcome email to new user.
 *
 * @param int $user_id User ID
 */
function send_welcome_email_to_new_user($user_id) {
    $user_data = get_userdata($user_id);
    $username = $user_data->user_login;
    $email = $user_data->user_email;
    $reset_pass_link = 'https://community.extrachill.com/reset-password/';

    $subject = "Welcome to the Extra Chill Community!";
    $message = "<html><body>";
    $message .= "<p>Hello <strong>" . $username . "</strong>,</p>";
    $message .= "<p>Welcome to <strong>Extra Chill</strong>! Now that you're here, this place is a lot more chill!</p>";
    $message .= "<p>With your account, you can now participate in community discussions, comment on posts, and follow your favorite artists.</p>";
    $message .= "<p>Get started by <a href='https://community.extrachill.com/t/introductions-thread'>introducing yourself in The Back Bar</a>!</p>";
    $message .= "<p><strong>Account Details:</strong><br>";
    $message .= "Username: <strong>" . $username . "</strong><br>";
    $message .= "If you forget your password, you can reset it <a href='" . esc_url($reset_pass_link) . "'>here</a>.</p>";
    $message .= "<p><strong>Explore the Platform:</strong><br>";
    $message .= "<a href='https://community.extrachill.com'>Community Forums</a><br>";
    $message .= "<a href='https://extrachill.com'>Extra Chill Magazine</a><br>";
    $message .= "<a href='https://extrachill.com/festival-wire/'>Festival Wire</a><br>";
    $message .= "<a href='https://artist.extrachill.com'>Artist Platform</a><br>";
    $message .= "<a href='https://shop.extrachill.com'>Shop</a><br>";
    $message .= "<a href='https://chat.extrachill.com'>AI Chat</a><br>";
    $message .= "<a href='https://events.extrachill.com'>Events Calendar</a><br>";
    $message .= "<a href='https://instagram.com/extrachill'>Instagram</a></p>";
    $message .= "<p>See you around!</p>";
    $message .= "<p>Much love,<br>";
    $message .= "Extra Chill</p>";
    $message .= "</body></html>";

    $from_email = get_option('admin_email');
    $headers = array('Content-Type: text/html; charset=UTF-8', 'From: Extra Chill <' . $from_email . '>');

    wp_mail($email, $subject, $message, $headers);
}

add_action( 'user_register', 'send_welcome_email_to_new_user' );