<?php
/**
 * Band Invitation Email Functions
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __FILE__ ) . '/../user-linking.php'; // For bp_add_band_membership
require_once dirname( __FILE__ ) . '/roster-data-functions.php'; // For bp_get_pending_invitations, bp_remove_pending_invitation

/**
 * Sends an invitation email to a potential band member.
 *
 * @param string $recipient_email The email address of the invitee.
 * @param string $band_name The name of the band.
 * @param string $member_display_name The display name for the member (as initially entered).
 * @param string $invitation_token The unique token for the invitation.
 * @param int    $band_id The ID of the band.
 * @param string $invitation_status Status of the invitation (e.g., 'invited_new_user', 'invited_existing_artist').
 * @return bool True if the email was sent successfully, false otherwise.
 */
function bp_send_band_invitation_email( $recipient_email, $band_name, $member_display_name, $invitation_token, $band_id, $invitation_status ) {
    $inviter_display = 'A band member';
    if ( is_user_logged_in() ) {
        $inviter = wp_get_current_user();
        $inviter_display = $inviter->display_name ? $inviter->display_name : $inviter->user_login;
    }
    if ( ! is_email( $recipient_email ) ) {
        error_log( 'Band Invitation Email: Invalid recipient email: ' . $recipient_email );
        return false;
    }

    // Construct the invitation link
    $invitation_base_url = home_url( '/' );
    if ( $invitation_status === 'invited_new_user' ) {
        $invitation_link = add_query_arg( array(
            'action' => 'bp_accept_invite',
            'token' => $invitation_token,
            'band_id' => $band_id,
        ), trailingslashit( $invitation_base_url ) . 'register/' );
    } else {
        $invitation_link = add_query_arg( array(
            'action' => 'bp_accept_invite',
            'token' => $invitation_token,
            'band_id' => $band_id
        ), get_permalink( $band_id ) );
    }

    $subject_template = __( 'You\'re invited to join %1$s on %2$s!', 'generatepress_child' );
    $subject = sprintf( $subject_template, esc_html( $band_name ), get_bloginfo( 'name' ) );

    $message_lines = array();
    // Greeting: use recipient's display name if they are an existing user
    $recipient_user = get_user_by('email', $recipient_email);
    if ( $recipient_user ) {
        $recipient_name = $recipient_user->display_name ? $recipient_user->display_name : $recipient_user->user_login;
        $message_lines[] = sprintf( __( 'Hello %s,', 'generatepress_child' ), esc_html( $recipient_name ) );
    } elseif ( !empty($member_display_name) ) {
        $message_lines[] = sprintf( __( 'Hello %s,', 'generatepress_child' ), esc_html( $member_display_name ) );
    } else {
        $message_lines[] = __( 'Hello,', 'generatepress_child' );
    }
    $message_lines[] = '';
    // Main invitation line
    $message_lines[] = sprintf( __( '%1$s has invited you to join the band \'%2$s\' on %3$s.', 'generatepress_child' ), esc_html($inviter_display), esc_html( $band_name ), get_bloginfo( 'name' ) );
    if ( $invitation_status === 'invited_new_user' ) {
        $message_lines[] = __( 'To accept this invitation and create your account, please click the link below:', 'generatepress_child' );
    } else {
        $message_lines[] = __( 'To accept this invitation and join the band, please click the link below:', 'generatepress_child' );
    }
    $message_lines[] = $invitation_link;
    $message_lines[] = '';
    $message_lines[] = sprintf( __( 'If you were not expecting this invitation, please ignore this email.', 'generatepress_child' ) );
    $message_lines[] = '';
    $message_lines[] = sprintf( __( 'Regards,', 'generatepress_child' ) );
    $message_lines[] = get_bloginfo( 'name' );

    $message = implode( "\r\n", $message_lines );

    $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

    // Set the 'From' name and email for this email only
    add_filter('wp_mail_from_name', function() { return 'Extra Chill Community'; });
    add_filter('wp_mail_from', function() { return 'bands@community.extrachill.com'; });
    $sent = wp_mail( $recipient_email, $subject, $message, $headers );
    remove_all_filters('wp_mail_from_name');
    remove_all_filters('wp_mail_from');

    if ( ! $sent ) {
        global $ts_mail_errors;
        global $phpmailer;
        if ( !is_array( $ts_mail_errors ) ) $ts_mail_errors = array();
        if ( isset( $phpmailer ) ) {
            if ( !empty( $phpmailer->ErrorInfo ) ) {
                $ts_mail_errors[] = $phpmailer->ErrorInfo;
                error_log( 'Band Invitation Email Error (PHPMailer): ' . $phpmailer->ErrorInfo );
            }
        }
        error_log( 'Band Invitation Email: wp_mail() failed to send to ' . $recipient_email . ' for band ID ' . $band_id );
    }

    return $sent;
}

/**
 * Placeholder function for handling the acceptance of an invitation.
 * This would be hooked to 'init' or 'template_redirect' to check for the token.
 */
function bp_handle_invitation_acceptance() {
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'bp_accept_invite' && isset( $_GET['token'] ) && isset( $_GET['band_id'] ) ) {
        $token   = sanitize_text_field( $_GET['token'] );
        $band_id = absint( $_GET['band_id'] );
        $redirect_url = get_permalink( $band_id );

        if ( ! $redirect_url ) {
            // Fallback if band profile doesn't exist for some reason
            $redirect_url = home_url('/');
        }

        // 1. User must be logged in
        if ( ! is_user_logged_in() ) {
            // Redirect to custom login page, then back to this acceptance URL
            $current_url = home_url( add_query_arg( $_GET, '' ) ); // This is the URL with token, band_id etc.
            $custom_login_page_url = home_url( '/login/' ); // IMPORTANT: Ensure '/login/' is your actual custom login page slug
            // Pass the current URL (acceptance link) as 'redirect_to' parameter for the custom login page to handle after successful login.
            wp_safe_redirect( add_query_arg( 'redirect_to', urlencode( $current_url ), $custom_login_page_url ) );
            exit;
        }

        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;

        // 2. Verify token and band_id: 
        $pending_invitations = bp_get_pending_invitations( $band_id );
        $valid_invite = null;
        $invite_key_to_remove = null;

        if ( ! empty( $pending_invitations ) ) {
            foreach ( $pending_invitations as $key => $invite ) {
                if ( isset( $invite['token'] ) && $invite['token'] === $token ) {
                    // Check invite status - only 'invited_existing_artist' is relevant here for now
                    if ( isset( $invite['status'] ) && $invite['status'] === 'invited_existing_artist' ) {
                        // Check email match
                        if ( isset( $invite['email'] ) && strtolower( $invite['email'] ) === strtolower( $current_user->user_email ) ) {
                            $valid_invite = $invite;
                            $invite_key_to_remove = $key; // Store the original key/ID of the invite for removal
                            break;
                        }
                    }
                }
            }
        }

        if ( ! $valid_invite ) {
            wp_safe_redirect( add_query_arg( array( 'invite_error' => 'invalid_token' ), $redirect_url ) );
            exit;
        }

        // 4. Link User to Band & Remove Pending Invite
        if ( bp_add_band_membership( $user_id, $band_id ) ) {
            // Use the specific ID of the invitation for removal
            if ( bp_remove_pending_invitation( $band_id, $valid_invite['id'] ) ) {
                wp_safe_redirect( add_query_arg( array( 'invite_accepted' => '1' ), $redirect_url ) );
                exit;
            } else {
                // Failed to remove invite, but user was added. Log this.
                error_log("Band Invite: User $user_id added to band $band_id, but failed to remove pending invite ID " . $valid_invite['id']);
                wp_safe_redirect( add_query_arg( array( 'invite_accepted' => '1', 'invite_warning' => 'cleanup_failed' ), $redirect_url ) );
                exit;
            }
        } else {
            // Failed to add band membership
            wp_safe_redirect( add_query_arg( array( 'invite_error' => 'membership_failed' ), $redirect_url ) );
            exit;
        }
    }
}
add_action( 'init', 'bp_handle_invitation_acceptance' ); 