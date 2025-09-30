<?php
/**
 * bbPress extension features for Extra Chill community platform.
 * Organized by functionality: admin, content, social, and user features.
 */

if (!defined('ABSPATH')) {
    exit;
}

include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/admin/bbpress-spam-adjustments.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/admin/custom-forum-descriptions.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/admin/forum-sections.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/admin/track-topic-views.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/bandcamp-embeds.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/editor/tinymce-customization.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/editor/tinymce-image-uploads.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/editor/topic-quick-reply.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/content-filters.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/quote.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/queries/homepage-queries.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/queries/recent-feed-queries.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/custom-topic-pagination.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/sorting.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/breadcrumbs.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/recent-feed.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/content/dynamic-menu.php';

include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/social/upvote.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/social/user-mention-api.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/social/following-feed.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/social/forum-badges.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/social/rank-system/point-calculation.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/social/rank-system/chill-forums-rank.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/social/notifications.php';

include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/users/custom-avatar.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/users/custom-user-profile.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/users/verification.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/users/settings/settings-content.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/users/settings/settings-form-handler.php';
include_once EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/users/online-users-count.php';