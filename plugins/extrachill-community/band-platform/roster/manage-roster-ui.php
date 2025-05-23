<?php
/**
 * Handles the display and specific UI logic for the "Manage Band Members" section 
 * on the frontend manage band profile page.
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

require_once dirname( __FILE__ ) . '/roster-data-functions.php'; // Include the new data functions file

/**
 * Displays the entire "Manage Band Members" interface.
 *
 * @param int $band_id The ID of the band profile being managed.
 * @param int $current_user_id The ID of the user currently viewing/editing the page.
 */
if (!function_exists('bp_display_manage_members_section')) {
function bp_display_manage_members_section( $band_id, $current_user_id ) {
    if ( ! $band_id || ! $current_user_id ) {
        echo '<p>' . esc_html__( 'Cannot display member management: Missing band or user information.', 'generatepress_child' ) . '</p>';
        return;
    }

    // Fetch all member types
    $linked_members_raw = bp_get_linked_members( $band_id ); // This function is from user-linking.php
    // $plaintext_members_raw = bp_get_plaintext_members( $band_id ); // REMOVED: No longer used
    $pending_invitations_raw = bp_get_pending_invitations( $band_id ); // This function is now from roster-data-functions.php

    $linked_user_ids = [];
    $processed_emails = []; 
    $invited_display_names = []; 
    $has_any_members = false;

    ?>
    <h2><?php esc_html_e( 'Band Roster', 'generatepress_child' ); ?></h2>
    
    <div id="bp-manage-members-section">
        
        <ul id="bp-unified-roster-list" class="bp-members-list">
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
                        <li data-user-id="<?php echo esc_attr( $user_info->ID ); ?>" class="bp-member-linked">
                            <?php echo get_avatar( $user_info->ID, 32 ); ?>
                            <span class="member-name"><?php echo esc_html( $user_info->display_name ); ?> (<?php echo esc_html( $user_info->user_login ); ?>)</span>
                            <span class="member-status-label">(Linked Account)</span>
                            <?php if ( $user_info->ID !== $current_user_id ) : ?>
                                <button type="button" class="button button-small bp-remove-member-button" data-user-id="<?php echo esc_attr( $user_info->ID ); ?>" title="<?php esc_attr_e( 'Remove this member from band', 'generatepress_child' ); ?>">&times; <?php esc_html_e('Remove', 'generatepress_child'); ?></button>
                            <?php else: ?>
                                <span class="is-current-user"><?php esc_html_e('You', 'generatepress_child'); ?></span>
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
                            $status_text = __( 'Invited (Existing User)', 'generatepress_child' );
                            break;
                        case 'invited_new_user':
                            $status_text = __( 'Invited (New User)', 'generatepress_child' );
                            break;
                        default:
                            $status_text = __( 'Invited (Status: ', 'generatepress_child' ) . esc_html( $invite['status'] ) . ')';
                    }
            ?>
                    <li data-invite-id="<?php echo esc_attr( $invite['id'] ); ?>" class="bp-member-pending-invite">
                        <span class="member-avatar-placeholder"></span> 
                        <span class="member-email"><?php echo esc_html( $invite['email'] ); ?></span>
                        <span class="member-status-label">(<?php echo esc_html( $status_text ); ?>: <?php echo esc_html( $invited_on_formatted ); ?>)</span>
                        <span class="member-actions">
                            <?php // TODO: Add Cancel Invite action ?>
                        </span>
                    </li>
            <?php 
                endforeach;
            endif;

            if ( ! $has_any_members ) :
            ?>
                <li class="no-members"><?php esc_html_e( 'No members listed for this band yet.', 'generatepress_child' ); ?></li>
            <?php endif; ?>
        </ul>

        <div id="bp-add-member-controls" style="margin-bottom: 20px;">
            <a href="#" id="bp-show-add-member-form-link" class="button"><?php esc_html_e('[+] Add Member', 'generatepress_child'); ?></a>
            <div id="bp-add-member-form-area" class="bp-add-member-form" style="display: none; margin-top: 15px;">
                <h4><?php esc_html_e('Invite New Member by Email', 'generatepress_child'); ?></h4>
                <div class="form-group">
                    <label for="bp-new-member-email-input" style="display:block; margin-bottom: 5px;">
                        <?php esc_html_e( 'Email Address:', 'generatepress_child' ); ?>
                    </label>
                    <input type="email" id="bp-new-member-email-input" name="bp_new_member_email" style="width: 100%; max-width: 300px; margin-bottom:10px;">
                </div>
                <button type="button" id="bp-ajax-invite-member-button" class="button button-primary"><?php esc_html_e('Send Invitation', 'generatepress_child'); ?></button>
                <a href="#" id="bp-cancel-add-member-form-link" style="margin-left: 10px; display: inline-block; vertical-align: middle;">
                    <?php esc_html_e('Cancel', 'generatepress_child'); ?>
                </a>
            </div>
        </div>
        
        <?php // Only remove_member_ids is needed now for main form submission for linked members ?>
        <input type="hidden" name="remove_member_ids" id="bp-remove-member-ids-frontend" value="">
    </div>

    <!-- Invitation Modal - Remove inline display:none -->
    <!-- Modal and plaintext member logic removed for simplification -->
    <?php
}
} // Close the function_exists check

?> 