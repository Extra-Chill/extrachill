<?php
/**
 * Notification Bell and User Avatar Display
 * 
 * Renders notification bell and user avatar in header for logged-in users.
 * Provides caching for notifications to optimize performance.
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
                    // Conditional Band Management Link
                    $user_artist_ids = get_user_meta( $current_user_id, '_artist_profile_ids', true );
                    $base_manage_url = home_url( '/manage-artist-profiles/' ); 
                    $final_manage_url = $base_manage_url;

                    if ( ! empty( $user_artist_ids ) && is_array( $user_artist_ids ) ) {
                        // User has one or more artist profiles - find the most recently updated one
                        $latest_artist_id = 0;
                        $latest_modified_timestamp = 0;

                        foreach ( $user_artist_ids as $artist_id ) {
                            $artist_id_int = absint($artist_id);
                            if ( $artist_id_int > 0 ) {
                                $post_modified_gmt = get_post_field( 'post_modified_gmt', $artist_id_int, 'raw' );
                                if ( $post_modified_gmt ) {
                                    $current_timestamp = strtotime( $post_modified_gmt );
                                    if ( $current_timestamp > $latest_modified_timestamp ) {
                                        $latest_modified_timestamp = $current_timestamp;
                                        $latest_artist_id = $artist_id_int;
                                    }
                                }
                            }
                        }

                        if ( $latest_artist_id > 0 ) {
                            $final_manage_url = add_query_arg( 'artist_id', $latest_artist_id, $base_manage_url );
                        }
                        echo '<li><a href="' . esc_url( $final_manage_url ) . '">' . esc_html__( 'Manage Band(s)', 'extra-chill-community' ) . '</a></li>';
                    } else {
                        // User has no artist profiles - Link to Create, if they are an artist OR professional.
                        $is_artist = get_user_meta( $current_user_id, 'user_is_artist', true );
                        $is_professional = get_user_meta( $current_user_id, 'user_is_professional', true );
                        if ( $is_artist === '1' || $is_professional === '1' ) {
                            echo '<li><a href="' . esc_url( $base_manage_url ) . '">' . esc_html__( 'Create Artist Profile', 'extra-chill-community' ) . '</a></li>';
                        }
                    }

                    // Conditional Link Page Management Link
                    if ( ! empty( $user_artist_ids ) && is_array( $user_artist_ids ) ) {
                        $base_link_page_manage_url = home_url( '/manage-link-page/' );
                        $final_link_page_manage_url = $base_link_page_manage_url;

                        if ( $latest_artist_id > 0 ) {
                            $final_link_page_manage_url = add_query_arg( 'artist_id', $latest_artist_id, $base_link_page_manage_url );
                        }
                        echo '<li><a href="' . esc_url( $final_link_page_manage_url ) . '">' . esc_html__( 'Manage Link Page(s)', 'extra-chill-community' ) . '</a></li>';
                    }
                    ?>

                    <li><a href="<?php echo esc_url( home_url('/settings/') ); ?>"><?php esc_html_e( 'Settings', 'extra-chill-community' ); ?></a></li>
                    <li><a href="<?php echo wp_logout_url( home_url() ); ?>">Log Out</a></li>
                </ul>
            </div>
        </div> <!-- End user-avatar-container -->
    </div> <!-- End user-menu-wrapper -->
</div> <!-- End notification-bell-avatar-wrapper -->