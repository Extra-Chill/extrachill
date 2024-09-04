<?php

function handle_personal_info_submission() {
    if (isset($_POST['_wpnonce_update_personal_info']) && wp_verify_nonce($_POST['_wpnonce_update_personal_info'], 'update-personal-info')) {
        $user_id = get_current_user_id();
        $errors = array();

        // Sanitize and validate user inputs
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $nickname = sanitize_text_field($_POST['nickname']);
        $display_name = sanitize_text_field($_POST['display_name']);

        // Prepare the user update array
        $user_update = array(
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'nickname' => $nickname,
            'display_name' => $display_name
        );

        // If no errors, update the user
        if (empty($errors)) {
            wp_update_user($user_update);
            wp_redirect(add_query_arg('updated', 'true', get_permalink())); // Redirect with a success message
            exit;
        } else {
            // If there are errors, store them in a transient for one-time display
            set_transient('personal_settings_errors', $errors, 45);
        }
    }
}
add_action('init', 'handle_personal_info_submission');

function handle_account_security_submission() {
    if (isset($_POST['_wpnonce_update_account_security']) && wp_verify_nonce($_POST['_wpnonce_update_account_security'], 'update-account-security')) {
        $user_id = get_current_user_id();
        $errors = array();

        // Sanitize and validate user inputs
        $email = sanitize_email($_POST['email']);
        $pass1 = $_POST['pass1'];
        $pass2 = $_POST['pass2'];

        // Validate email
        if (!is_email($email)) {
            $errors['email'] = 'Invalid email address.';
        } elseif (email_exists($email) && $email != wp_get_current_user()->user_email) {
            $errors['email_exists'] = 'This email address is already in use.';
        } else {
            wp_update_user(array('ID' => $user_id, 'user_email' => $email));
        }

        // Password validation
        if (!empty($pass1) && !empty($pass2)) {
            if ($pass1 == $pass2) {
                wp_set_password($pass1, $user_id);
                // Force logout
                wp_logout();
                wp_redirect(wp_login_url());
                exit;
            } else {
                $errors['password'] = 'Passwords do not match.';
            }
        }

        // If there are errors, store them in a transient for one-time display
        if (!empty($errors)) {
            set_transient('security_settings_errors', $errors, 45);
        }
    }
}
add_action('init', 'handle_account_security_submission');
