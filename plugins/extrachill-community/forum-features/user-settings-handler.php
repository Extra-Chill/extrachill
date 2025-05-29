<?php
// --- Form Processing for Account Settings Page ---
if ( ! function_exists( 'extrachill_handle_settings_page_forms' ) ) {
    function extrachill_handle_settings_page_forms() {
        if ( ! is_user_logged_in() ) {
            return;
        }
        $user_id = get_current_user_id();

        // Unified Settings Form Processing
        if ( isset( $_POST['submit_user_settings'] ) && isset( $_POST['_wpnonce_update_user_settings'] ) ) {
            if ( ! wp_verify_nonce( sanitize_key($_POST['_wpnonce_update_user_settings']), 'update-user-settings_' . $user_id ) ) {
                wp_die( esc_html__( 'Security check failed for user settings.', 'generatepress_child' ) );
            }

            $errors = array();
            $success_messages = array();
            $update_args = array( 'ID' => $user_id );

            // --- Account Details --- 
            if ( isset( $_POST['first_name'] ) ) {
                $update_args['first_name'] = sanitize_text_field( $_POST['first_name'] );
            }
            if ( isset( $_POST['last_name'] ) ) {
                $update_args['last_name'] = sanitize_text_field( $_POST['last_name'] );
            }
            if ( isset( $_POST['display_name'] ) ) {
                $update_args['display_name'] = sanitize_text_field( $_POST['display_name'] );
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
                $user_updated_personal = wp_update_user( $update_args );
                if ( is_wp_error( $user_updated_personal ) ) {
                    $errors = array_merge($errors, $user_updated_personal->get_error_messages());
                } else {
                    $success_messages[] = __( 'Account details updated successfully.', 'generatepress_child' );
                }
            }

            // --- Account Security (Password Change) --- 
            if ( ! empty( $_POST['pass1'] ) ) {
                if ( $_POST['pass1'] !== $_POST['pass2'] ) {
                    $errors[] = __( 'The new passwords do not match.', 'generatepress_child' );
                } else {
                    $user_updated_password = wp_update_user( array( 'ID' => $user_id, 'user_pass' => sanitize_text_field($_POST['pass1']) ) );
                    if ( is_wp_error( $user_updated_password ) ) {
                        $errors = array_merge($errors, $user_updated_password->get_error_messages());
                    } else {
                        $success_messages[] = __( 'Password changed successfully.', 'generatepress_child' );
                    }
                }
            }

            // --- Subscriptions & Email Preferences --- 
            if ( isset( $_POST['band_email_consent'] ) || isset( $_POST['submit_user_settings'] ) ) { 
                $new_email_permissions = array();
                $followed_bands_on_page = isset($_POST['followed_bands_on_page']) && is_array($_POST['followed_bands_on_page']) ? array_map('intval', $_POST['followed_bands_on_page']) : [];

                if (isset($_POST['band_email_consent']) && is_array($_POST['band_email_consent'])){
                    foreach ( $_POST['band_email_consent'] as $band_id_str => $consent_value ) {
                        $band_id = intval($band_id_str);
                        if (in_array($band_id, $followed_bands_on_page, true)) { 
                             $new_email_permissions[ $band_id ] = ($consent_value === '1');
                        }
                    }
                }
                foreach ($followed_bands_on_page as $band_id) {
                    if (!isset($new_email_permissions[$band_id])) {
                        $new_email_permissions[$band_id] = false;
                    }
                }

                $existing_permissions = get_user_meta( $user_id, '_band_follow_email_permissions', true );
                if ( !is_array($existing_permissions) ) $existing_permissions = array();
                
                $final_permissions = $existing_permissions;
                foreach ($new_email_permissions as $band_id => $consent) {
                    $final_permissions[$band_id] = $consent;
                }

                update_user_meta( $user_id, '_band_follow_email_permissions', $final_permissions );
                $success_messages[] = __( 'Subscription preferences updated.', 'generatepress_child' );
            }

            // --- Feedback & Redirect --- 
            if ( ! empty( $errors ) ) {
                set_transient( 'user_settings_errors_' . $user_id, $errors, 60 );
            }
            if ( ! empty( $success_messages ) ) {
                set_transient( 'user_settings_success_' . $user_id, $success_messages, 60 );
            }

            $current_tab_hash = isset($_POST['current_tab_hash']) ? sanitize_text_field($_POST['current_tab_hash']) : '';
            // Construct the redirect URL using the page permalink and the tab hash
            $redirect_url = get_permalink(); // Assuming this is called from the context of the settings page, or you have the page ID
            if ($redirect_url && !is_wp_error($redirect_url)) {
                 if (!empty($current_tab_hash) && strpos($current_tab_hash, '#') === 0) {
                     $redirect_url .= $current_tab_hash;
                 }
                 wp_redirect( add_query_arg( array('settings-updated' => 'true'), $redirect_url ) );
                 exit;
            } else {
                wp_redirect( home_url('/') ); // Fallback redirect
                exit;
            }

        } else {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            }
        }
    }
}
add_action( 'template_redirect', 'extrachill_handle_settings_page_forms', 5 ); 