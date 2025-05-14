<?php
/**
 * Data functions for managing band roster plaintext members and pending invitations.
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// --- Pending Invitation Functions ---

/**
 * Get pending invitations for a band.
 * @param int $band_id
 * @return array Array of pending invitation objects/arrays
 */
function bp_get_pending_invitations( $band_id ) {
    $invitations = get_post_meta( $band_id, '_pending_invitations', true );
    return is_array( $invitations ) ? $invitations : array();
}

/**
 * Generates a unique invitation token.
 * @return string The unique token.
 */
function bp_generate_invite_token() {
    return wp_generate_password( 32, false, false ); // 32 chars, no special chars
}

/**
 * Adds a pending invitation for a band.
 * Checks if the email belongs to an existing user and if that user is an artist.
 * (Does not send email yet).
 * @param int $band_id
 * @param string $display_name
 * @param string $email
 * @return bool|array|string False on general failure, new invitation entry array on success,
 *                             or a specific error string like 'error_not_artist' or 'error_already_pending'.
 */
function bp_add_pending_invitation( $band_id, $display_name, $email ) { // Removed $status_hint as it will be determined internally
    if ( empty( $email ) || !is_email( $email ) ) {
        return false; // General validation failure
    }

    $invitations = bp_get_pending_invitations( $band_id );

    // Check if email already has a pending invite for this band
    foreach ( $invitations as $invite ) {
        if ( isset( $invite['email'] ) && strtolower($invite['email']) === strtolower($email) ) {
            return 'error_already_pending'; // Specific error for already pending
        }
    }

    $new_invite_id = 'inv_' . wp_generate_password( 12, false );
    $token = bp_generate_invite_token();
    $final_status = '';

    $existing_user_id = email_exists( $email );
    if ( $existing_user_id ) {
        // User exists. We will invite them. 
        // The acceptance process will handle adding 'user_is_artist' meta if needed.
        $final_status = 'invited_existing_artist'; // Use this status; acceptance will verify/add meta.
    } else {
        // New user
        $final_status = 'invited_new_user';
    }

    $new_invite_entry = array(
        'id'            => $new_invite_id,
        'display_name'  => sanitize_text_field( $display_name ),
        'email'         => sanitize_email( $email ),
        'token'         => $token,
        'status'        => sanitize_key( $final_status ), 
        'invited_on'    => current_time( 'timestamp', true ) // GMT timestamp
    );

    $invitations[] = $new_invite_entry;
    if ( update_post_meta( $band_id, '_pending_invitations', $invitations ) ) {
        return $new_invite_entry;
    }
    return false; // General failure to save meta
}

/**
 * Removes a pending invitation.
 * @param int $band_id
 * @param string $pending_invite_id The unique ID of the invitation entry.
 * @return bool True on success, false on failure.
 */
function bp_remove_pending_invitation( $band_id, $pending_invite_id ) {
    $invitations = bp_get_pending_invitations( $band_id );
    $updated_invitations = array();
    $found = false;
    foreach ( $invitations as $invite ) {
        if ( isset( $invite['id'] ) && $invite['id'] === $pending_invite_id ) {
            $found = true;
            continue; // Skip this invitation
        }
        $updated_invitations[] = $invite;
    }
    if ( $found ) {
        return update_post_meta( $band_id, '_pending_invitations', $updated_invitations );
    }
    return false;
}