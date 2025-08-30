<?php
/**
 * Forum Features Master Loader
 * 
 * Complete overview and loader for all Extra Chill Community forum functionality.
 * This single file provides developers a comprehensive understanding of all features.
 * 
 * @package ExtraChillCommunity
 * @version 1.0.0
 * @author Chris Huber
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// ROOT LEVEL FEATURES - Core forum utilities and cross-cutting concerns
// =============================================================================

// No root-level files - all organized into logical subdirectories

// =============================================================================
// ADMIN FEATURES - Moderation, forum management, analytics & tracking  
// =============================================================================

// Moderation Features
include_once get_stylesheet_directory() . '/forum-features/admin/bbpress-spam-adjustments.php';
include_once get_stylesheet_directory() . '/forum-features/admin/restricted-forums.php';
include_once get_stylesheet_directory() . '/forum-features/admin/pinned-topic.php';
include_once get_stylesheet_directory() . '/forum-features/admin/team-members-mods.php';

// Forum Management
include_once get_stylesheet_directory() . '/forum-features/admin/custom-forum-descriptions.php';
include_once get_stylesheet_directory() . '/forum-features/admin/indexing-conditionals.php';
include_once get_stylesheet_directory() . '/forum-features/admin/forum-sections.php';

// Email Notifications
include_once get_stylesheet_directory() . '/forum-features/admin/notification-emails.php';

// Analytics & Tracking
include_once get_stylesheet_directory() . '/forum-features/admin/track-topic-views.php';

// =============================================================================
// CONTENT FEATURES - Embeds, editor customization, processing, forum content
// =============================================================================

// Content Embeds
include_once get_stylesheet_directory() . '/forum-features/content/bandcamp-embeds.php';

// Content Editor Customization
include_once get_stylesheet_directory() . '/forum-features/content/editor/tinymce-customization.php';
include_once get_stylesheet_directory() . '/forum-features/content/editor/tinymce-image-uploads.php';
include_once get_stylesheet_directory() . '/forum-features/content/editor/topic-quick-reply.php';

// Content Processing & Filters
include_once get_stylesheet_directory() . '/forum-features/content/content-filters.php';
include_once get_stylesheet_directory() . '/forum-features/content/quote.php';

// Content Queries
include_once get_stylesheet_directory() . '/forum-features/content/queries/homepage-queries.php';
include_once get_stylesheet_directory() . '/forum-features/content/queries/recent-feed-queries.php';

// Forum Content Display & Interaction
include_once get_stylesheet_directory() . '/forum-features/content/custom-topic-pagination.php';
include_once get_stylesheet_directory() . '/forum-features/content/sorting.php';
include_once get_stylesheet_directory() . '/forum-features/content/breadcrumbs.php';
include_once get_stylesheet_directory() . '/forum-features/content/recent-feed.php';

// Menu & Layout
include_once get_stylesheet_directory() . '/forum-features/content/dynamic-menu.php';
include_once get_stylesheet_directory() . '/forum-features/content/footer-functions.php';

// =============================================================================
// SOCIAL FEATURES - Interactions, following system, reputation system
// =============================================================================

// Social Interactions
include_once get_stylesheet_directory() . '/forum-features/social/upvote.php';
include_once get_stylesheet_directory() . '/forum-features/social/user-mention-api.php';

// Following System
include_once get_stylesheet_directory() . '/forum-features/social/following-feed.php';

// User Badges & Roles
include_once get_stylesheet_directory() . '/forum-features/social/forum-badges.php';

// Rank System (Point-Based Engagement)
include_once get_stylesheet_directory() . '/forum-features/social/rank-system/point-calculation.php';
include_once get_stylesheet_directory() . '/forum-features/social/rank-system/chill-forums-rank.php';

// =============================================================================
// USER FEATURES - Profiles, settings, verification
// =============================================================================

// User Profiles
include_once get_stylesheet_directory() . '/forum-features/users/custom-avatar.php';
include_once get_stylesheet_directory() . '/forum-features/users/custom-user-profile.php';
include_once get_stylesheet_directory() . '/forum-features/users/verification.php';

// User Settings
include_once get_stylesheet_directory() . '/forum-features/users/user-settings-handler.php';

// Online User Tracking
include_once get_stylesheet_directory() . '/forum-features/users/online-users-count.php';