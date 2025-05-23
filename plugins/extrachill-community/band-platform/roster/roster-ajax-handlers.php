<?php
// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

require_once dirname( __FILE__ ) . '/roster-data-functions.php'; // Include the data functions file

/**
 * AJAX handler for inviting a member by email.
 */
add_action( 'wp_ajax_bp_ajax_invite_member_by_email', 'bp_ajax_invite_member_by_email' );

function bp_ajax_invite_member_by_email() {
    check_ajax_referer( 'bp_ajax_invite_member_by_email_nonce', 'nonce' );
    $band_id = isset( $_POST['band_id'] ) ? absint( $_POST['band_id'] ) : 0;
    $invite_email = isset( $_POST['invite_email'] ) ? sanitize_email( $_POST['invite_email'] ) : '';
    if ( ! $band_id || ! is_email( $invite_email ) ) {
        wp_send_json_error( array( 'message' => 'Missing or invalid parameters (band ID or email).' ) );
    }
    if ( ! current_user_can( 'edit_post', $band_id ) ) {
        wp_send_json_error( array( 'message' => 'You do not have permission to manage members for this band.' ) );
    }
    $linked_members = bp_get_linked_members( $band_id );
    if (is_array($linked_members)) {
        foreach ($linked_members as $linked_member_obj) {
            if (isset($linked_member_obj->ID)) {
                $user_info = get_userdata($linked_member_obj->ID);
                if ($user_info && strtolower($user_info->user_email) === strtolower($invite_email)) {
                    wp_send_json_error( array( 'message' => 'This email address is already linked to a member of this band.' ) );
                }
            }
        }
    }
    $new_invitation_result = bp_add_pending_invitation( $band_id, '', $invite_email );
    if ( is_array( $new_invitation_result ) && isset( $new_invitation_result['id'] ) ) {
        $invite = $new_invitation_result;
        $band_post = get_post( $band_id );
        $band_name = $band_post ? $band_post->post_title : 'the band';
        $mail_sent_successfully = bp_send_band_invitation_email( 
            $invite['email'], 
            $band_name, 
            '', // No display name
            $invite['token'],
            $band_id,
            $invite['status']
        );
        $invited_on_formatted = date_i18n( get_option( 'date_format' ), $invite['invited_on'] );
        $status_text = '';
        switch ( $invite['status'] ) {
            case 'invited_existing_artist':
                $status_text = __( 'Invited (Existing User)', 'generatepress_child' );
                break;
            case 'invited_new_user':
                $status_text = __( 'Invited (New User)', 'generatepress_child' );
                break;
            default:
                $status_text = __( 'Invited (Status: ', 'generatepress_child' ) . esc_html( $invite['status'] ) . ')';
        }
        ob_start();
        $existing_user_id = email_exists( $invite['email'] );
        if ( $existing_user_id ) {
            $user_info = get_userdata( $existing_user_id );
            if ( $user_info ) {
                $profile_url = function_exists('bbp_get_user_profile_url') ? bbp_get_user_profile_url($user_info->ID) : '';
                ?>
                <li data-invite-id="<?php echo esc_attr( $invite['id'] ); ?>" class="bp-member-pending-invite existing-user">
                    <?php echo get_avatar( $user_info->ID, 60 ); ?>
                    <span class="member-name">
                        <a href="<?php echo esc_url($profile_url); ?>"><?php echo esc_html($user_info->display_name); ?></a>
                        <span class="member-username">(@<?php echo esc_html($user_info->user_login); ?>)</span>
                    </span>
                    <span class="member-status-label">(<?php echo esc_html( $status_text ); ?>: <?php echo esc_html( $invited_on_formatted ); ?>)</span>
                    <span class="member-actions">
                        <?php // Future: Add Cancel Invite action ?>
                    </span>
                </li>
                <?php
            }
        } else {
            ?>
            <li data-invite-id="<?php echo esc_attr( $invite['id'] ); ?>" class="bp-member-pending-invite">
                <span class="member-avatar-placeholder"></span> 
                <span class="member-email"><?php echo esc_html( $invite['email'] ); ?></span>
                <span class="member-status-label">(<?php echo esc_html( $status_text ); ?>: <?php echo esc_html( $invited_on_formatted ); ?>)</span>
                <span class="member-actions">
                    <?php // Future: Add Cancel Invite action ?>
                </span>
            </li>
            <?php
        }
        $updated_roster_item_html = ob_get_clean();
        wp_send_json_success( array( 
            'message' => __( 'Invitation successfully processed.', 'generatepress_child' ),
            'updated_roster_item_html' => $updated_roster_item_html,
            'invitation_data' => $invite 
        ) );
    } else {
        $error_message = __( 'Could not create a pending invitation. An unknown error occurred.', 'generatepress_child' );
        if ( is_string( $new_invitation_result ) ) {
            switch ( $new_invitation_result ) {
                case 'error_already_pending':
                    $error_message = __( 'An invitation has already been sent to this email address for this band.', 'generatepress_child' );
                    break;
            }
        }
        wp_send_json_error( array( 'message' => $error_message ) );
    }
}

// Make sure the email sending function is available
$roster_emails_file = dirname( __FILE__ ) . '/band-invitation-emails.php';
if ( file_exists( $roster_emails_file ) ) {
    require_once $roster_emails_file;
} else {
    // Fallback or error logging if the email functions file is missing
    error_log('Error: Band invitation email functions file is missing.');
    if( !function_exists('bp_send_band_invitation_email') ) {
        function bp_send_band_invitation_email() {
            error_log('Dummy bp_send_band_invitation_email called because real one is missing.');
            return false; 
        }
    }
}

?> 