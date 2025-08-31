<?php
/**
 * Template Part: Profile Managers Tab for Manage Artist Profile
 *
 * Loaded from page-templates/manage-artist-profile.php
 */

defined( 'ABSPATH' ) || exit;

// Extract arguments passed from ec_render_template
$edit_mode = $edit_mode ?? false;
$target_artist_id = $target_artist_id ?? 0;
$artist_post_title = $artist_post_title ?? '';

?>

<div class="artist-profile-content-card">
    <div class="bp-notice bp-notice-info" style="margin-bottom: 1.5em;">
        <p><?php esc_html_e( "Profile managers can moderate the artist's forum, edit this artist profile, and manage the associated Extrachill.link page.", 'extrachill-artist-platform' ); ?></p>
    </div>
    <?php 
    // --- MANAGE PROFILE MANAGERS SECTION (Edit Mode Only) ---
    if ( $edit_mode && $target_artist_id > 0 ) :
        $current_user_id = get_current_user_id();
        
        // Get current profile managers using simple user meta lookup
        $current_managers = array();
        if ( function_exists( 'bp_get_linked_members' ) ) {
            $managers_raw = bp_get_linked_members( $target_artist_id );
            if ( ! empty( $managers_raw ) ) {
                foreach ( $managers_raw as $manager ) {
                    $current_managers[] = $manager;
                }
            }
        }
        ?>
        
        <h3><?php esc_html_e( 'Current Profile Managers', 'extrachill-artist-platform' ); ?></h3>
        
        <?php if ( ! empty( $current_managers ) ) : ?>
            <ul class="profile-managers-list" style="list-style: none; padding: 0;">
                <?php foreach ( $current_managers as $manager ) : 
                    $user_info = get_userdata( $manager->ID );
                    if ( $user_info ) :
                ?>
                    <li style="display: flex; justify-content: space-between; align-items: center; padding: 8px; border: 1px solid #ddd; margin: 4px 0;">
                        <span>
                            <strong><?php echo esc_html( $user_info->display_name ); ?></strong>
                            <span style="color: #666;">(<?php echo esc_html( $user_info->user_email ); ?>)</span>
                        </span>
                        <?php if ( $user_info->ID !== $current_user_id ) : ?>
                            <button type="button" class="button button-small remove-manager-btn" 
                                    data-user-id="<?php echo esc_attr( $user_info->ID ); ?>"
                                    data-artist-id="<?php echo esc_attr( $target_artist_id ); ?>">
                                <?php esc_html_e( 'Remove', 'extrachill-artist-platform' ); ?>
                            </button>
                        <?php else : ?>
                            <span style="color: #666; font-style: italic;"><?php esc_html_e( '(You)', 'extrachill-artist-platform' ); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endif; endforeach; ?>
            </ul>
        <?php else : ?>
            <p><?php esc_html_e( 'No additional profile managers assigned.', 'extrachill-artist-platform' ); ?></p>
        <?php endif; ?>
        
        <div style="margin-top: 20px;">
            <h4><?php esc_html_e( 'Add Profile Manager', 'extrachill-artist-platform' ); ?></h4>
            <p style="color: #666; font-size: 14px;"><?php esc_html_e( 'Search for a user by username or email to add them as a profile manager.', 'extrachill-artist-platform' ); ?></p>
            
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="text" id="user-search-input" placeholder="<?php esc_attr_e( 'Username or email...', 'extrachill-artist-platform' ); ?>" 
                       style="flex: 1; max-width: 300px;" />
                <button type="button" id="add-manager-btn" class="button button-primary" 
                        data-artist-id="<?php echo esc_attr( $target_artist_id ); ?>">
                    <?php esc_html_e( 'Add Manager', 'extrachill-artist-platform' ); ?>
                </button>
            </div>
            <div id="user-search-results" style="margin-top: 10px;"></div>
        </div>
        
        <?php
    else : 
        // Should not happen if tab is only shown in edit mode, but as a fallback:
        echo '<p>' . esc_html__('Profile manager access is available when editing an existing artist profile.', 'extrachill-artist-platform') . '</p>';
    endif; 
    ?>
</div> 