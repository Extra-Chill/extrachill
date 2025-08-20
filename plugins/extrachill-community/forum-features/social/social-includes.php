<?php
/**
 * Social Features Centralized Loader
 * 
 * Loads all social functionality including interactions, following, and reputation systems.
 * This centralized approach makes it easy to manage social feature dependencies.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Social Interactions
include_once get_stylesheet_directory() . '/forum-features/social/interactions/upvote.php';
include_once get_stylesheet_directory() . '/forum-features/social/interactions/user-mention-api.php';
include_once get_stylesheet_directory() . '/forum-features/social/interactions/notifications.php';

// Following System
include_once get_stylesheet_directory() . '/forum-features/social/following/following-feed.php';
include_once get_stylesheet_directory() . '/forum-features/social/following/band-following.php';

// Reputation System
include_once get_stylesheet_directory() . '/forum-features/social/reputation/point-calculation.php';
include_once get_stylesheet_directory() . '/forum-features/social/reputation/chill-forums-rank.php';
include_once get_stylesheet_directory() . '/forum-features/social/reputation/forum-badges.php';