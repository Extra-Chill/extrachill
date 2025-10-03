<?php
/**
 * Settings Form Handler
 *
 * Processes settings form submissions using WordPress native functionality.
 * Email changes use send_confirmation_on_profile_email() for security.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Process settings form submission
 */
function extrachill_community_handle_settings_form() {
    if (!is_user_logged_in()) {
        return;
    }

    $user_id = get_current_user_id();

    // Check if settings form was submitted
    if (!isset($_POST['submit_user_settings']) || !isset($_POST['_wpnonce_update_user_settings'])) {
        return;
    }

    // Verify nonce
    if (!wp_verify_nonce(sanitize_key($_POST['_wpnonce_update_user_settings']), 'update-user-settings_' . $user_id)) {
        wp_die(esc_html__('Security check failed for user settings.', 'extra-chill-community'));
    }

    $errors = array();
    $success_messages = array();
    $update_args = array('ID' => $user_id);

    // --- Account Details ---
    if (isset($_POST['first_name'])) {
        $update_args['first_name'] = sanitize_text_field(wp_unslash($_POST['first_name']));
    }
    if (isset($_POST['last_name'])) {
        $update_args['last_name'] = sanitize_text_field(wp_unslash($_POST['last_name']));
    }
    if (isset($_POST['display_name'])) {
        $update_args['display_name'] = sanitize_text_field(wp_unslash($_POST['display_name']));
    }

    $personal_info_changed = false;
    $current_wp_user = wp_get_current_user();

    if (array_key_exists('first_name', $update_args) && $update_args['first_name'] !== $current_wp_user->first_name) {
        $personal_info_changed = true;
    }
    if (!$personal_info_changed && array_key_exists('last_name', $update_args) && $update_args['last_name'] !== $current_wp_user->last_name) {
        $personal_info_changed = true;
    }
    if (!$personal_info_changed && array_key_exists('display_name', $update_args) && $update_args['display_name'] !== $current_wp_user->display_name) {
        $personal_info_changed = true;
    }

    if ($personal_info_changed) {
        $user_updated_personal = wp_update_user($update_args);
        if (is_wp_error($user_updated_personal)) {
            $errors = array_merge($errors, $user_updated_personal->get_error_messages());
        } else {
            $success_messages[] = __('Account details updated successfully.', 'extra-chill-community');
        }
    }

    // --- Password Change ---
    if (!empty($_POST['pass1'])) {
        $current_pass = isset($_POST['current_pass']) ? sanitize_text_field(wp_unslash($_POST['current_pass'])) : '';

        if (empty($current_pass)) {
            $errors[] = __('Current password is required to change password.', 'extra-chill-community');
        } elseif (!wp_check_password($current_pass, $current_wp_user->user_pass, $user_id)) {
            $errors[] = __('Current password is incorrect.', 'extra-chill-community');
        } elseif ($_POST['pass1'] !== $_POST['pass2']) {
            $errors[] = __('The new passwords do not match.', 'extra-chill-community');
        } else {
            $user_updated_password = wp_update_user(array(
                'ID' => $user_id,
                'user_pass' => sanitize_text_field(wp_unslash($_POST['pass1']))
            ));
            if (is_wp_error($user_updated_password)) {
                $errors = array_merge($errors, $user_updated_password->get_error_messages());
            } else {
                $success_messages[] = __('Password changed successfully.', 'extra-chill-community');
            }
        }
    }

    // --- Email Change (WordPress Native) ---
    if (!empty($_POST['email'])) {
        $new_email = sanitize_email(wp_unslash($_POST['email']));
        $current_email = $current_wp_user->user_email;

        if (!is_email($new_email)) {
            $errors[] = __('Please enter a valid email address.', 'extra-chill-community');
        } elseif ($new_email === $current_email) {
            $errors[] = __('New email address must be different from your current email.', 'extra-chill-community');
        } else {
            // Use WordPress native email change functionality
            // WordPress will handle validation, hash generation, storage, and verification email
            send_confirmation_on_profile_email();

            // Check if WordPress successfully initiated the change
            $pending_email = get_user_meta($user_id, '_new_user_email', true);
            if ($pending_email && isset($pending_email['newemail']) && $pending_email['newemail'] === $new_email) {
                $success_messages[] = sprintf(
                    /* translators: %s: new email address */
                    __('Verification email sent to %s. Please check your inbox and click the verification link to complete the change.', 'extra-chill-community'),
                    esc_html($new_email)
                );
            } else {
                $errors[] = __('Failed to send verification email. Please try again.', 'extra-chill-community');
            }
        }
    }

    // --- Subscriptions & Email Preferences ---
    if (isset($_POST['submit_user_settings'])) {
        $consented_artists = isset($_POST['artists_consented']) && is_array($_POST['artists_consented'])
            ? array_map('intval', $_POST['artists_consented'])
            : array();

        // Get all followed artists to determine which ones were unchecked
        $followed_artists_posts = function_exists('bp_get_user_followed_bands')
            ? bp_get_user_followed_bands($user_id, array('posts_per_page' => -1))
            : array();

        if (!empty($followed_artists_posts)) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'artist_subscribers';

            foreach ($followed_artists_posts as $artist_post) {
                $artist_id = $artist_post->ID;

                if (in_array($artist_id, $consented_artists, true)) {
                    // Add consent if not exists
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d AND artist_profile_id = %d AND source = 'platform_follow_consent'",
                        $user_id,
                        $artist_id
                    ));

                    if (!$exists) {
                        $wpdb->insert(
                            $table_name,
                            array(
                                'user_id' => $user_id,
                                'artist_profile_id' => $artist_id,
                                'source' => 'platform_follow_consent',
                                'subscribed_at' => current_time('mysql')
                            ),
                            array('%d', '%d', '%s', '%s')
                        );
                    }
                } else {
                    // Remove consent if exists
                    $wpdb->delete(
                        $table_name,
                        array(
                            'user_id' => $user_id,
                            'artist_profile_id' => $artist_id,
                            'source' => 'platform_follow_consent'
                        ),
                        array('%d', '%d', '%s')
                    );
                }
            }

            $success_messages[] = __('Subscription preferences updated.', 'extra-chill-community');
        }
    }

    // --- Feedback & Redirect ---
    if (!empty($errors)) {
        set_transient('user_settings_errors_' . $user_id, $errors, 60);
    }
    if (!empty($success_messages)) {
        set_transient('user_settings_success_' . $user_id, $success_messages, 60);
    }

    $current_tab_hash = isset($_POST['current_tab_hash']) ? sanitize_text_field(wp_unslash($_POST['current_tab_hash'])) : '';
    $redirect_url = get_permalink();

    if ($redirect_url && !is_wp_error($redirect_url)) {
        if (!empty($current_tab_hash) && strpos($current_tab_hash, '#') === 0) {
            $redirect_url .= $current_tab_hash;
        }
        wp_redirect(add_query_arg(array('settings-updated' => 'true'), $redirect_url));
        exit;
    } else {
        wp_redirect(home_url('/'));
        exit;
    }
}
add_action('template_redirect', 'extrachill_community_handle_settings_form', 5);