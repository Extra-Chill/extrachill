<?php
/**
 * Handles the display and specific UI logic for the "Manage Artist Members" section 
 * on the frontend manage artist profile page.
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

require_once dirname( __FILE__ ) . '/roster-data-functions.php'; // Include the new data functions file

/**
 * Displays the entire "Manage Artist Members" interface.
 *
 * @param int $artist_id The ID of the artist profile being managed.
 * @param int $current_user_id The ID of the user currently viewing/editing the page.
 */
if (!function_exists('ec_display_manage_members_section')) {
function ec_display_manage_members_section( $artist_id, $current_user_id ) {
    if ( ! $artist_id || ! $current_user_id ) {
        echo '<p>' . esc_html__( 'Cannot display member management: Missing artist or user information.', 'extrachill-artist-platform' ) . '</p>';
        return;
    }

    $linked_members_raw = ec_get_linked_members( $artist_id );
    $pending_invitations_raw = ec_get_pending_invitations( $artist_id );

    $linked_user_ids = [];
    $processed_emails = []; 
    $invited_display_names = []; 
    $has_any_members = false;

    ?>
    <h2><?php esc_html_e( 'Artist Roster', 'extrachill-artist-platform' ); ?></h2>
    
    <div id="ec-manage-members-section">
        
        <ul id="ec-unified-roster-list" class="ec-members-list">
            <?php 
            // 1. Display Linked Members
            if ( ! empty( $linked_members_raw ) ) :
                foreach ( $linked_members_raw as $member_obj ) : 
                    $user_info = get_userdata( $member_obj->ID );
                    if ( $user_info ) :
                        $has_any_members = true;
                        $linked_user_ids[] = $user_info->ID;
                        $processed_emails[] = strtolower($user_info->user_email);
            ?>
                        <li data-user-id="<?php echo esc_attr( $user_info->ID ); ?>" class="ec-member-linked">
                            <?php echo get_avatar( $user_info->ID, 32 ); ?>
                            <span class="member-name"><?php echo esc_html( $user_info->display_name ); ?> (<?php echo esc_html( $user_info->user_login ); ?>)</span>
                            <span class="member-status-label">(Linked Account)</span>
                            <?php if ( $user_info->ID !== $current_user_id ) : ?>
                                <button type="button" class="button-2 button-small ec-remove-member-button" data-user-id="<?php echo esc_attr( $user_info->ID ); ?>" title="<?php esc_attr_e( 'Remove this member from artist', 'extrachill-artist-platform' ); ?>">&times; <?php esc_html_e('Remove', 'extrachill-artist-platform'); ?></button>
                            <?php else: ?>
                                <span class="is-current-user"><?php esc_html_e('You', 'extrachill-artist-platform'); ?></span>
                            <?php endif; ?>
                        </li>
            <?php 
                    endif;
                endforeach;
            endif;

            // 2. Display Pending Invitations (for users not already linked)
            if ( ! empty( $pending_invitations_raw ) ) :
                foreach ( $pending_invitations_raw as $invite ) :
                    if ( in_array( strtolower($invite['email']), $processed_emails ) ) {
                        continue;
                    }
                    $has_any_members = true;
                    $processed_emails[] = strtolower($invite['email']); 

                    $invited_on_formatted = date_i18n( get_option( 'date_format' ), $invite['invited_on'] );
                    $status_text = '';
                    switch ( $invite['status'] ) {
                        case 'invited_existing_artist':
                            $status_text = __( 'Invited (Existing User)', 'extrachill-artist-platform' );
                            break;
                        case 'invited_new_user':
                            $status_text = __( 'Invited (New User)', 'extrachill-artist-platform' );
                            break;
                        default:
                            $status_text = __( 'Invited (Status: ', 'extrachill-artist-platform' ) . esc_html( $invite['status'] ) . ')';
                    }
            ?>
                    <li data-invite-id="<?php echo esc_attr( $invite['id'] ); ?>" class="ec-member-pending-invite">
                        <span class="member-avatar-placeholder"></span> 
                        <span class="member-email"><?php echo esc_html( $invite['email'] ); ?></span>
                        <span class="member-status-label">(<?php echo esc_html( $status_text ); ?>: <?php echo esc_html( $invited_on_formatted ); ?>)</span>
                        <span class="member-actions">
                            <?php /* Future: Add Cancel Invite action */ ?>
                        </span>
                    </li>
            <?php 
                endforeach;
            endif;

            if ( ! $has_any_members ) :
            ?>
                <li class="no-members"><?php esc_html_e( 'No members listed for this artist yet.', 'extrachill-artist-platform' ); ?></li>
            <?php endif; ?>
        </ul>

        <div id="ec-add-member-controls" style="margin-bottom: 20px;">
            <a href="#" id="ec-show-add-member-form-link" class="button-2 button-medium"><?php esc_html_e('[+] Add Member', 'extrachill-artist-platform'); ?></a>
            <div id="ec-add-member-form-area" class="ec-add-member-form" style="display: none; margin-top: 15px;">
                <h4><?php esc_html_e('Invite New Member by Email', 'extrachill-artist-platform'); ?></h4>
                <div class="form-group">
                    <label for="ec-new-member-email-input" style="display:block; margin-bottom: 5px;">
                        <?php esc_html_e( 'Email Address:', 'extrachill-artist-platform' ); ?>
                    </label>
                    <input type="email" id="ec-new-member-email-input" name="ec_new_member_email" style="width: 100%; max-width: 300px; margin-bottom:10px;">
                </div>
                <button type="button" id="ec-send-invite-member-button" class="button-2 button-medium"><?php esc_html_e('Send Invitation', 'extrachill-artist-platform'); ?></button>
                <a href="#" id="ec-cancel-add-member-form-link" style="margin-left: 10px; display: inline-block; vertical-align: middle;">
                    <?php esc_html_e('Cancel', 'extrachill-artist-platform'); ?>
                </a>
            </div>
        </div>
        
        <?php // Only remove_member_ids is needed now for main form submission for linked members ?>
        <input type="hidden" name="remove_member_ids" id="ec-remove-member-ids-frontend" value="">
    </div>

    <!-- Invitation Modal - Remove inline display:none -->
    <!-- Modal and plaintext member logic removed for simplification -->
    <?php
}
} // Close the function_exists check

?> 