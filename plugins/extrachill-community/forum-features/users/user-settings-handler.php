<?php
// --- Email Change Verification Handler ---
if ( ! function_exists( 'extrachill_handle_email_change_verification' ) ) {
    function extrachill_handle_email_change_verification() {
        // Check for email verification hash in URL
        if ( isset( $_GET['verify_email_change'] ) && ! empty( $_GET['verify_email_change'] ) ) {
            $verification_hash = sanitize_text_field( $_GET['verify_email_change'] );
            
            // Validate the hash and get change data
            $change_data = extrachill_validate_email_change_hash( $verification_hash );
            
            if ( $change_data ) {
                $user_id = $change_data['user_id'];
                $new_email = $change_data['new_email'];
                $old_email = $change_data['old_email'];
                
                // Update user email in database
                $user_updated = wp_update_user( array(
                    'ID' => $user_id,
                    'user_email' => $new_email
                ) );
                
                if ( ! is_wp_error( $user_updated ) ) {
                    // Email change successful
                    
                    // Record timestamp of successful change
                    update_user_meta( $user_id, '_last_email_change', current_time( 'timestamp' ) );
                    
                    // Invalidate all user sessions for security
                    if ( class_exists( 'WP_Session_Tokens' ) ) {
                        $session_tokens = WP_Session_Tokens::get_instance( $user_id );
                        $session_tokens->destroy_all();
                    }
                    
                    // Invalidate cross-domain session tokens
                    if ( function_exists( 'invalidate_user_sessions_on_email_change' ) ) {
                        invalidate_user_sessions_on_email_change( $user_id );
                    }
                    
                    // Send confirmation email to new address
                    extrachill_send_email_change_confirmation( $user_id, $old_email, $new_email );
                    
                    // Remove pending change
                    extrachill_remove_pending_email_change( $user_id );
                    
                    // Set success message
                    set_transient( 'email_change_success_' . $user_id, array(
                        'message' => sprintf(
                            /* translators: %s: new email address */
                            __( 'Your email address has been successfully changed to %s. For security, you have been logged out of all devices. Please log in again.', 'extra-chill-community' ),
                            esc_html( $new_email )
                        )
                    ), 300 ); // 5 minutes
                    
                    // Redirect to login page with success message
                    wp_redirect( add_query_arg( array( 'email_changed' => '1' ), home_url( '/login/' ) ) );
                    exit;
                    
                } else {
                    // Error updating user email
                    $error_message = __( 'Failed to update email address. Please try again or contact support.', 'extra-chill-community' );
                    set_transient( 'email_change_error_' . $user_id, $error_message, 300 );
                    
                    // Redirect to settings page with error
                    wp_redirect( add_query_arg( array( 'email_error' => '1' ), home_url( '/settings/' ) ) );
                    exit;
                }
            } else {
                // Invalid or expired hash
                $error_message = __( 'Invalid or expired email verification link. Please request a new email change.', 'extra-chill-community' );
                
                if ( is_user_logged_in() ) {
                    $user_id = get_current_user_id();
                    set_transient( 'email_change_error_' . $user_id, $error_message, 300 );
                    wp_redirect( add_query_arg( array( 'email_error' => '1' ), home_url( '/settings/' ) ) );
                } else {
                    wp_redirect( add_query_arg( array( 'message' => 'email_verify_failed' ), home_url( '/login/' ) ) );
                }
                exit;
            }
        }
    }
}
add_action( 'template_redirect', 'extrachill_handle_email_change_verification', 1 ); // High priority

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
                wp_die( esc_html__( 'Security check failed for user settings.', 'extra-chill-community' ) );
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
                    $success_messages[] = __( 'Account details updated successfully.', 'extra-chill-community' );
                }
            }

            // --- Account Security (Password Change) --- 
            if ( ! empty( $_POST['pass1'] ) ) {
                if ( $_POST['pass1'] !== $_POST['pass2'] ) {
                    $errors[] = __( 'The new passwords do not match.', 'extra-chill-community' );
                } else {
                    $user_updated_password = wp_update_user( array( 'ID' => $user_id, 'user_pass' => sanitize_text_field($_POST['pass1']) ) );
                    if ( is_wp_error( $user_updated_password ) ) {
                        $errors = array_merge($errors, $user_updated_password->get_error_messages());
                    } else {
                        $success_messages[] = __( 'Password changed successfully.', 'extra-chill-community' );
                    }
                }
            }

            // --- Email Address Change ---
            if ( ! empty( $_POST['new_email'] ) ) {
                $new_email = sanitize_email( wp_unslash( $_POST['new_email'] ) );
                $current_email = $current_wp_user->user_email;
                
                // Validate new email format
                if ( ! is_email( $new_email ) ) {
                    $errors[] = __( 'Please enter a valid email address.', 'extra-chill-community' );
                } elseif ( $new_email === $current_email ) {
                    $errors[] = __( 'New email address must be different from your current email.', 'extra-chill-community' );
                } elseif ( ! extrachill_can_user_change_email( $user_id ) ) {
                    $errors[] = __( 'You can only change your email address once per 24 hours. Please try again later.', 'extra-chill-community' );
                } elseif ( ! extrachill_is_email_available( $new_email, $user_id ) ) {
                    $errors[] = __( 'This email address is already in use or pending verification for another account.', 'extra-chill-community' );
                } else {
                    // Generate verification hash
                    $verification_hash = extrachill_generate_email_change_hash( $user_id, $new_email );
                    
                    // Store pending change
                    $stored = extrachill_store_pending_email_change( $user_id, $new_email, $verification_hash );
                    
                    if ( $stored ) {
                        // Send verification email
                        $email_sent = extrachill_send_email_change_verification( $user_id, $new_email, $verification_hash );
                        
                        if ( $email_sent ) {
                            $success_messages[] = sprintf(
                                /* translators: %s: new email address */
                                __( 'Verification email sent to %s. Please check your inbox and click the verification link to complete the change.', 'extra-chill-community' ),
                                esc_html( $new_email )
                            );
                        } else {
                            // Remove pending change if email failed
                            extrachill_remove_pending_email_change( $user_id );
                            $errors[] = __( 'Failed to send verification email. Please try again or contact support if the problem persists.', 'extra-chill-community' );
                        }
                    } else {
                        $errors[] = __( 'Failed to process email change request. Please try again.', 'extra-chill-community' );
                    }
                }
            }

            // --- Subscriptions & Email Preferences --- 
            if ( isset( $_POST['artist_email_consent'] ) || isset( $_POST['submit_user_settings'] ) ) { 
                $new_email_permissions = array();
                $followed_artists_on_page = isset($_POST['followed_artists_on_page']) && is_array($_POST['followed_artists_on_page']) ? array_map('intval', $_POST['followed_artists_on_page']) : [];

                if (isset($_POST['artist_email_consent']) && is_array($_POST['artist_email_consent'])){
                    foreach ( $_POST['artist_email_consent'] as $artist_id_str => $consent_value ) {
                        $artist_id = intval($artist_id_str);
                        if (in_array($artist_id, $followed_artists_on_page, true)) { 
                             $new_email_permissions[ $artist_id ] = ($consent_value === '1');
                        }
                    }
                }
                foreach ($followed_artists_on_page as $artist_id) {
                    if (!isset($new_email_permissions[$artist_id])) {
                        $new_email_permissions[$artist_id] = false;
                    }
                }

                $existing_permissions = get_user_meta( $user_id, '_artist_follow_email_permissions', true );
                if ( !is_array($existing_permissions) ) $existing_permissions = array();
                
                $final_permissions = $existing_permissions;
                foreach ($new_email_permissions as $artist_id => $consent) {
                    $final_permissions[$artist_id] = $consent;
                }

                update_user_meta( $user_id, '_artist_follow_email_permissions', $final_permissions );
                $success_messages[] = __( 'Subscription preferences updated.', 'extra-chill-community' );
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