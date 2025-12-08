<?php
/**
 * Custom User Profile Display
 *
 * Extends bbPress user profiles with cross-site data aggregation from main site.
 * Uses the canonical blog ID provider to access extrachill.com (main site) post counts and comments.
 *
 * Cross-Site Data:
 * - User post count from main site (displayed as "articles")
 * - User comments from main site blog
 * - Links to author archive: extrachill.com/author/{slug}/
 *
 * Integration: Always uses try/finally pattern with restore_current_blog() for safety.
 *
 * @package ExtraChillCommunity
 */

function display_main_site_post_count_on_profile() {
    $user_id = bbp_get_displayed_user_id();

    $main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
    if ( ! $main_blog_id ) {
        return;
    }

    switch_to_blog( $main_blog_id );
    try {
        $post_count = count_user_posts( $user_id, 'post', true );
    } finally {
        restore_current_blog();
    }

    if ($post_count > 0) {
        $user_info = get_userdata($user_id);
        $author_slug = $user_info ? $user_info->user_nicename : null;
        $author_url = "https://extrachill.com/author/{$author_slug}/"; // Adjust URL structure as needed

        echo "<p><b>Extra Chill Articles:</b> $post_count <a href='" . esc_url($author_url) . "'>(View All)</a></p>";
    }
}

// Function to display music fan details
function display_music_fan_details() {
    // Music Fan Section variables
    $favorite_artists = get_user_meta(bbp_get_displayed_user_id(), 'favorite_artists', true);
    $top_concerts = get_user_meta(bbp_get_displayed_user_id(), 'top_concerts', true);
    $top_venues = get_user_meta(bbp_get_displayed_user_id(), 'top_venues', true);

    // Wrap the existing conditional block in a card
    if ($favorite_artists || $top_concerts || $top_venues ) :
        ?>
        <div class="card">
            <div class="card-header">
                <h3><?php esc_html_e('Music Fan Details', 'extra-chill-community'); ?></h3>
            </div>
            <div class="card-body">
                <?php if ($favorite_artists) : ?>
                    <p><strong><?php esc_html_e('Favorite Artists:', 'extra-chill-community'); ?></strong> <?php echo nl2br(esc_html($favorite_artists)); ?></p>
                <?php endif; ?>

                <?php if ($top_concerts) : ?>
                    <p><strong><?php esc_html_e('Top Concerts:', 'extra-chill-community'); ?></strong> <?php echo nl2br(esc_html($top_concerts)); ?></p>
                <?php endif; ?>

                <?php if ($top_venues) : ?>
                    <p><strong><?php esc_html_e('Top Venues:', 'extra-chill-community'); ?></strong> <?php echo nl2br(esc_html($top_venues)); ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif;
}

// Hook the display functions to run after bbPress is loaded
add_action('bbp_init', 'display_music_fan_details');

// =============================================================================
// bbPress User Role & Title Customization (moved from bbpress-customization.php)
// =============================================================================

// Utility function to get the edit profile URL
function extrachill_get_edit_profile_url($user_id, $profile_type) {
    // This function should return the URL for editing the specified profile type.
    return home_url("/edit-profile/?profile_type={$profile_type}&user_id={$user_id}");
}

// Load the function after bbPress is fully loaded
add_action( 'after_setup_theme', 'override_bbp_user_role_after_bbp_load' );

function override_bbp_user_role_after_bbp_load() {
    // Hook into bbPress filter after it's available
    add_filter( 'bbp_get_user_display_role', 'override_bbp_user_forum_role', 10, 2 );
}

function override_bbp_user_forum_role( $role, $user_id ) {
    $custom_title = get_user_meta( $user_id, 'ec_custom_title', true );
    return ! empty( $custom_title ) ? $custom_title : 'Extra Chillian';
}