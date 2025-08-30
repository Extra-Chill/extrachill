<?php

/**
 * Email templates and functions for user email change verification system
 * Follows WordPress patterns and existing email template styles
 */

if ( ! function_exists( 'extrachill_send_email_change_verification' ) ) {
    /**
     * Send verification email to new email address
     * 
     * @param int $user_id User ID
     * @param string $new_email New email address to verify
     * @param string $verification_hash Secure verification hash
     * @return bool Success status
     */
    function extrachill_send_email_change_verification( $user_id, $new_email, $verification_hash ) {
        $user_data = get_userdata( $user_id );
        if ( ! $user_data ) {
            return false;
        }

        $username = $user_data->user_login;
        $current_email = $user_data->user_email;
        
        // Generate verification URL
        $verification_url = add_query_arg( 
            array( 'verify_email_change' => $verification_hash ), 
            home_url( '/settings/' )
        );

        // Email subject
        /* translators: Site name */
        $subject = sprintf( __( 'Confirm your new email address - %s', 'extra-chill-community' ), get_bloginfo( 'name' ) );

        // Email body with HTML formatting matching existing styles
        $message = "<html><body>";
        $message .= "<p>" . sprintf( 
            /* translators: %s: username */
            __( 'Hello <strong>%s</strong>,', 'extra-chill-community' ), 
            esc_html( $username ) 
        ) . "</p>";
        
        $message .= "<p>" . sprintf(
            /* translators: 1: current email, 2: new email */
            __( 'You have requested to change your email address from <strong>%1$s</strong> to <strong>%2$s</strong>.', 'extra-chill-community' ),
            esc_html( $current_email ),
            esc_html( $new_email )
        ) . "</p>";
        
        $message .= "<p>" . __( 'To complete this change, please click the verification link below:', 'extra-chill-community' ) . "</p>";
        
        $message .= "<p><a href='" . esc_url( $verification_url ) . "' style='background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 3px; display: inline-block;'>" . 
                    __( 'Verify New Email Address', 'extra-chill-community' ) . "</a></p>";
        
        $message .= "<p>" . __( 'If the button above doesn\'t work, copy and paste this link into your browser:', 'extra-chill-community' ) . "<br>";
        $message .= "<code>" . esc_url( $verification_url ) . "</code></p>";
        
        $message .= "<p><strong>" . __( 'Important Security Information:', 'extra-chill-community' ) . "</strong></p>";
        $message .= "<ul>";
        $message .= "<li>" . __( 'This verification link will expire in 48 hours', 'extra-chill-community' ) . "</li>";
        $message .= "<li>" . __( 'Your current email address will remain active until verification is complete', 'extra-chill-community' ) . "</li>";
        $message .= "<li>" . __( 'After verification, you will be logged out of all devices for security', 'extra-chill-community' ) . "</li>";
        $message .= "</ul>";
        
        $message .= "<p>" . __( 'If you did not request this email change, please ignore this message and your email address will remain unchanged.', 'extra-chill-community' ) . "</p>";
        
        $message .= "<p>" . __( 'Questions? Contact us at chubes@extrachill.com', 'extra-chill-community' ) . "</p>";
        
        $message .= "<p>" . __( 'Thanks,', 'extra-chill-community' ) . "<br>";
        $message .= __( 'The Extra Chill Community Team', 'extra-chill-community' ) . "</p>";
        $message .= "</body></html>";

        // Headers for HTML content and custom From matching existing pattern
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: Extra Chill <chubes@extrachill.com>'
        );

        // Send the email
        $sent = wp_mail( $new_email, $subject, $message, $headers );
        
        // Log the attempt for debugging
        if ( ! $sent ) {
            error_log( "Email change verification email failed to send to {$new_email} for user {$user_id}" );
        }
        
        return $sent;
    }
}

if ( ! function_exists( 'extrachill_send_email_change_confirmation' ) ) {
    /**
     * Send confirmation email to new email address after successful verification
     * 
     * @param int $user_id User ID
     * @param string $old_email Previous email address
     * @param string $new_email New email address (now active)
     * @return bool Success status
     */
    function extrachill_send_email_change_confirmation( $user_id, $old_email, $new_email ) {
        $user_data = get_userdata( $user_id );
        if ( ! $user_data ) {
            return false;
        }

        $username = $user_data->user_login;
        
        // Email subject
        /* translators: Site name */
        $subject = sprintf( __( 'Email address successfully changed - %s', 'extra-chill-community' ), get_bloginfo( 'name' ) );

        // Email body with HTML formatting
        $message = "<html><body>";
        $message .= "<p>" . sprintf( 
            /* translators: %s: username */
            __( 'Hello <strong>%s</strong>,', 'extra-chill-community' ), 
            esc_html( $username ) 
        ) . "</p>";
        
        $message .= "<p><strong>" . __( 'Your email address has been successfully changed!', 'extra-chill-community' ) . "</strong></p>";
        
        $message .= "<p>" . sprintf(
            /* translators: 1: old email, 2: new email */
            __( 'Previous email: <strong>%1$s</strong><br>New email: <strong>%2$s</strong>', 'extra-chill-community' ),
            esc_html( $old_email ),
            esc_html( $new_email )
        ) . "</p>";
        
        $message .= "<p><strong>" . __( 'Security Notice:', 'extra-chill-community' ) . "</strong></p>";
        $message .= "<ul>";
        $message .= "<li>" . __( 'You have been logged out of all devices as a security precaution', 'extra-chill-community' ) . "</li>";
        $message .= "<li>" . __( 'Please log in again using your username and password', 'extra-chill-community' ) . "</li>";
        $message .= "<li>" . __( 'All future notifications will be sent to this new email address', 'extra-chill-community' ) . "</li>";
        $message .= "</ul>";
        
        $message .= "<p><a href='" . esc_url( home_url( '/login/' ) ) . "' style='background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 3px; display: inline-block;'>" . 
                    __( 'Log In Now', 'extra-chill-community' ) . "</a></p>";
        
        $message .= "<p>" . __( 'If you did not request this change, please contact us immediately at chubes@extrachill.com', 'extra-chill-community' ) . "</p>";
        
        $message .= "<p>" . __( 'Welcome to your updated account!', 'extra-chill-community' ) . "</p>";
        
        $message .= "<p>" . __( 'Thanks,', 'extra-chill-community' ) . "<br>";
        $message .= __( 'The Extra Chill Community Team', 'extra-chill-community' ) . "</p>";
        $message .= "</body></html>";

        // Headers for HTML content and custom From
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: Extra Chill <chubes@extrachill.com>'
        );

        // Send the email to the NEW address
        $sent = wp_mail( $new_email, $subject, $message, $headers );
        
        // Log the attempt for debugging
        if ( ! $sent ) {
            error_log( "Email change confirmation email failed to send to {$new_email} for user {$user_id}" );
        }
        
        return $sent;
    }
}