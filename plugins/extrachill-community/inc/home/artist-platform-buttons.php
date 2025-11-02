<?php
/**
 * Artist Platform Integration Buttons
 *
 * Provides artist platform CTA buttons on community homepage via hook integration.
 * Checks user permissions using ec_can_create_artist_profiles() from extrachill-artist-platform plugin.
 *
 * Links:
 * - Support Forum: artist.extrachill.com/extra-chill
 * - Artist Platform: artist.extrachill.com (for creators)
 * - Join Flow: artist.extrachill.com/login/#tab-register?from_join=true (for visitors)
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add homepage buttons to the extrachill_community_home_after_forums hook
 *
 * Displays the Support Forum and Artist Platform buttons on the community homepage
 * based on user login status and permissions. Links to artist.extrachill.com.
 */
function ec_community_add_artist_platform_buttons() {
    ?>
    <div class="artist-platform-homepage-actions">
        <a href="https://artist.extrachill.com/extra-chill" class="button-2 button-medium">
            <?php esc_html_e('Support Forum', 'extra-chill-community'); ?>
        </a>

        <?php if (is_user_logged_in()) :
            $current_user = wp_get_current_user();
            // Check if user can create artist profiles (function provided by extrachill-artist-platform plugin)
            $can_create_artists = function_exists('ec_can_create_artist_profiles') ? ec_can_create_artist_profiles($current_user->ID) : false;

            if ($can_create_artists) :
                ?>
                <a href="https://artist.extrachill.com/" class="button-3 button-medium">
                    <?php esc_html_e('Artist Platform', 'extra-chill-community'); ?>
                </a>
            <?php endif;
        else :
            // For visitors, show join/sign up call to action ?>
            <a href="https://artist.extrachill.com/login/#tab-register?from_join=true" class="button-3 button-medium">
                <?php esc_html_e('Join Artist Platform', 'extra-chill-community'); ?>
            </a>
        <?php endif; ?>
    </div>
    <?php
}

// Hook into the community homepage action
add_action('extrachill_community_home_after_forums', 'ec_community_add_artist_platform_buttons');
