<?php
/**
 * Artist Platform Integration Button
 *
 * Displays prominent Artist Platform CTA on community homepage via hook integration.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add Artist Platform button to the community homepage after forums loop
 */
function ec_community_add_artist_platform_buttons() {
    ?>
    <div class="artist-platform-homepage-actions">
        <a href="https://artist.extrachill.com/" class="button-2 button-large">
            <?php esc_html_e('Artist Platform', 'extra-chill-community'); ?>
        </a>
    </div>
    <?php
}

add_action('extrachill_community_home_after_forums', 'ec_community_add_artist_platform_buttons');
