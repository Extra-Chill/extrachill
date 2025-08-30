<?php
/**
 * Notification Bell and User Avatar Display
 * 
 * Renders notification bell and user avatar in header for logged-in users.
 * Provides caching for notifications to optimize performance.
 * Uses ec_avatar_menu_items filter to allow plugins to add custom dropdown menu items.
 * 
 * @package ExtraChillCommunity
 * @subpackage ForumFeatures\Content
 */

if ( ! is_user_logged_in() ) {
    return;
}

global $extrachill_notifications_cache;

$current_user_id = get_current_user_id();
$current_user = wp_get_current_user();

// Check if notifications are cached
if ($extrachill_notifications_cache === null) {
    // Fetch notifications and store in cache
    $extrachill_notifications_cache = get_user_meta($current_user_id, 'extrachill_notifications', true) ?: [];
}
$notifications = $extrachill_notifications_cache;

// Filter unread notifications for the count
$unread_count = count(array_filter($notifications, function ($notification) {
    return !$notification['read'];
}));
?>

<div class="notification-bell-avatar-wrapper">
    <!-- User menu wrapper -->
    <div class="user-menu-wrapper" aria-haspopup="true">
        <!-- Notification bell -->
        <div class="notification-bell-icon">
            <a href="/notifications" title="Notifications">
                <i class="fa-solid fa-bell"></i>
                <?php if ($unread_count > 0) : ?>
                    <span class="notification-count"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </a>
        </div>

        <!-- User avatar container -->
        <div class="user-avatar-container">
            <a href="<?php echo bbp_get_user_profile_url($current_user_id); ?>" class="user-avatar-link">
                <?php echo get_avatar($current_user_id, 40); ?>
            </a>
            <button class="user-avatar-button"></button>

            <!-- Dropdown menu -->
            <div class="user-dropdown-menu">
                <ul>
                    <li><a href="<?php echo bbp_get_user_profile_url($current_user_id); ?>">View Profile</a></li>
                    <li><a href="<?php echo bbp_get_user_profile_edit_url($current_user_id); ?>">Edit Profile</a></li>

                    <?php
                    // Apply filter to allow plugins to add custom menu items
                    $custom_menu_items = apply_filters( 'ec_avatar_menu_items', array(), $current_user_id );
                    
                    // Sort menu items by priority
                    if ( ! empty( $custom_menu_items ) && is_array( $custom_menu_items ) ) {
                        usort( $custom_menu_items, function( $a, $b ) {
                            $priority_a = isset( $a['priority'] ) ? (int) $a['priority'] : 10;
                            $priority_b = isset( $b['priority'] ) ? (int) $b['priority'] : 10;
                            return $priority_a <=> $priority_b;
                        });
                        
                        // Render custom menu items
                        foreach ( $custom_menu_items as $menu_item ) {
                            if ( isset( $menu_item['url'] ) && isset( $menu_item['label'] ) ) {
                                printf(
                                    '<li><a href="%s">%s</a></li>',
                                    esc_url( $menu_item['url'] ),
                                    esc_html( $menu_item['label'] )
                                );
                            }
                        }
                    }
                    ?>

                    <li><a href="<?php echo esc_url( home_url('/settings/') ); ?>"><?php esc_html_e( 'Settings', 'extra-chill-community' ); ?></a></li>
                    <li><a href="<?php echo wp_logout_url( home_url() ); ?>">Log Out</a></li>
                </ul>
            </div>
        </div> <!-- End user-avatar-container -->
    </div> <!-- End user-menu-wrapper -->
</div> <!-- End notification-bell-avatar-wrapper -->