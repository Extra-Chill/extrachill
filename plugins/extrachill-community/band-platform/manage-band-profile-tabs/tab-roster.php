<?php
/**
 * Template Part: Roster Tab for Manage Band Profile
 *
 * Loaded from page-templates/manage-band-profile.php
 */

defined( 'ABSPATH' ) || exit;

// Ensure variables from parent scope are available
global $edit_mode, $target_band_id;

// The following variables are expected to be set in the parent scope (manage-band-profile.php)
// $edit_mode (bool)
// $target_band_id (int)

?>

<div class="band-profile-content-card">
    <div class="bp-notice bp-notice-info" style="margin-bottom: 1.5em;">
        <p><?php esc_html_e( "All members added to this roster will have permissions to moderate the band's forum, manage this band profile, and manage the associated Extrachill.link page.", 'extra-chill-community' ); ?></p>
    </div>
    <?php 
    // --- MANAGE MEMBERS SECTION (Edit Mode Only) ---
    if ( $edit_mode && $target_band_id > 0 ) :
        $current_user_id = get_current_user_id();
        // --- Call the dedicated function to display the members section ---
        if ( function_exists( 'bp_display_manage_members_section' ) ) {
            bp_display_manage_members_section( $target_band_id, $current_user_id );
        } else {
            echo '<p>' . esc_html__('Error: Member management UI could not be loaded.', 'extra-chill-community') . '</p>';
        }
    else : 
        // Should not happen if tab is only shown in edit mode, but as a fallback:
        echo '<p>' . esc_html__('Member management is available when editing an existing band profile.', 'extra-chill-community') . '</p>';
    endif; 
    ?>
</div> 