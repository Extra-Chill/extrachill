<?php
/**
 * Data functions for managing artist roster plaintext members and pending invitations.
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Roster invite-status contract.
 *
 * These named constants are the single source of truth for the invite-status
 * enum produced by ec_add_pending_invitation() and consumed by the roster UI,
 * invitation emails, and the cross-network registration/acceptance flows in
 * extrachill-users.
 *
 * IMPORTANT: The string VALUES are a stable cross-plugin contract. They are
 * persisted in post meta (`_pending_invitations`) and matched by string
 * equality in extrachill-users. Do NOT change the values without a coordinated
 * data migration and updates to every consumer.
 *
 * Pending-invitation array shape (stored in `_pending_invitations` post meta):
 *   array(
 *     'id'           => string,  // 'inv_' . 12 random chars — stable removal key
 *     'display_name' => string,  // sanitized display name
 *     'email'        => string,  // sanitized email
 *     'token'        => string,  // 32-char acceptance token
 *     'status'       => string,  // one of EC_INVITE_STATUS_* below
 *     'invited_on'   => int,     // GMT Unix timestamp
 *   )
 *
 * @see ec_add_pending_invitation()
 */

/**
 * Invite status: the email belongs to an existing user account.
 *
 * Acceptance is handled in-place (the existing user clicks the link while
 * logged in); acceptance verifies/adds the artist membership meta.
 */
if ( ! defined( 'EC_INVITE_STATUS_EXISTING_ARTIST' ) ) {
	define( 'EC_INVITE_STATUS_EXISTING_ARTIST', 'invited_existing_artist' );
}

/**
 * Invite status: the email has no account yet.
 *
 * Acceptance routes through the registration flow in extrachill-users, which
 * creates the account and then links the artist membership.
 */
if ( ! defined( 'EC_INVITE_STATUS_NEW_USER' ) ) {
	define( 'EC_INVITE_STATUS_NEW_USER', 'invited_new_user' );
}

// --- Pending Invitation Functions ---

/**
 * Get pending invitations for an artist.
 * @param int $artist_id
 * @return array Array of pending invitation objects/arrays
 */
function ec_get_pending_invitations( $artist_id ) {
    $invitations = get_post_meta( $artist_id, '_pending_invitations', true );
    return is_array( $invitations ) ? $invitations : array();
}

/**
 * Generates a unique invitation token.
 * @return string The unique token.
 */
function ec_generate_invite_token() {
    return wp_generate_password( 32, false, false ); // 32 chars, no special chars
}

/**
 * Adds a pending invitation for an artist.
 * Checks if the email belongs to an existing user and if that user is an artist.
 * (Does not send email yet).
 * @param int $artist_id
 * @param string $display_name
 * @param string $email
 * @return bool|array|string False on general failure, new invitation entry array on success,
 *                             or a specific error string like 'error_not_artist' or 'error_already_pending'.
 */
function ec_add_pending_invitation( $artist_id, $display_name, $email ) { // Removed $status_hint as it will be determined internally
    if ( empty( $email ) || !is_email( $email ) ) {
        return false; // General validation failure
    }

    $invitations = ec_get_pending_invitations( $artist_id );

    // Check if email already has a pending invite for this artist
    foreach ( $invitations as $invite ) {
        if ( isset( $invite['email'] ) && strtolower($invite['email']) === strtolower($email) ) {
            return 'error_already_pending'; // Specific error for already pending
        }
    }

    $new_invite_id = 'inv_' . wp_generate_password( 12, false );
    $token = ec_generate_invite_token();
    $final_status = '';

    $existing_user_id = email_exists( $email );
    if ( $existing_user_id ) {
        // User exists. We will invite them. 
        // The acceptance process will handle adding 'user_is_artist' meta if needed.
        $final_status = EC_INVITE_STATUS_EXISTING_ARTIST; // Use this status; acceptance will verify/add meta.
    } else {
        // New user
        $final_status = EC_INVITE_STATUS_NEW_USER;
    }

    $new_invite_entry = array(
        'id'            => $new_invite_id,
        'display_name'  => sanitize_text_field( $display_name ),
        'email'         => sanitize_email( $email ),
        'token'         => $token,
        'status'        => sanitize_key( $final_status ), 
        'invited_on'    => time() // GMT Unix timestamp (replaces deprecated current_time( 'timestamp', true ))
    );

    $invitations[] = $new_invite_entry;
    if ( update_post_meta( $artist_id, '_pending_invitations', $invitations ) ) {
        return $new_invite_entry;
    }
    return false; // General failure to save meta
}

/**
 * Removes a pending invitation.
 * @param int $artist_id
 * @param string $pending_invite_id The unique ID of the invitation entry.
 * @return bool True on success, false on failure.
 */
function ec_remove_pending_invitation( $artist_id, $pending_invite_id ) {
    $invitations = ec_get_pending_invitations( $artist_id );
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
        return update_post_meta( $artist_id, '_pending_invitations', $updated_invitations );
    }
    return false;
}