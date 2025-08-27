<?php
/**
 * bbPress Spam Detection Adjustments
 * 
 * Reduces false positives in bbPress spam detection, particularly for posts containing URLs.
 * Provides whitelist functionality for trusted domains and user role exemptions.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whitelist of trusted domains that should not trigger spam detection
 */
function ec_get_trusted_domains() {
    return apply_filters('ec_trusted_domains', [
        'spotify.com',
        'open.spotify.com',
        'bandcamp.com',
        'soundcloud.com',
        'youtube.com',
        'youtu.be',
        'apple.com',
        'music.apple.com',
        'extrachill.com',
        'extrachill.link',
        'community.extrachill.com'
    ]);
}

/**
 * Check if content contains only trusted domain links
 */
function ec_content_has_only_trusted_links($content) {
    $trusted_domains = ec_get_trusted_domains();
    
    // Extract all URLs from content
    preg_match_all('/https?:\/\/[^\s<>"]+/i', $content, $matches);
    
    if (empty($matches[0])) {
        return true; // No links found, not a link-based spam issue
    }
    
    foreach ($matches[0] as $url) {
        $domain = parse_url($url, PHP_URL_HOST);
        $is_trusted = false;
        
        foreach ($trusted_domains as $trusted_domain) {
            if ($domain === $trusted_domain || strpos($domain, '.' . $trusted_domain) !== false) {
                $is_trusted = true;
                break;
            }
        }
        
        if (!$is_trusted) {
            return false; // Found untrusted link
        }
    }
    
    return true; // All links are from trusted domains
}

/**
 * Check if user should be exempt from strict spam detection
 */
function ec_user_exempt_from_spam_detection($user_id = 0) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    if (!$user_id) {
        return false;
    }
    
    // Exempt administrators and moderators
    if (user_can($user_id, 'manage_options') || user_can($user_id, 'moderate_comments')) {
        return true;
    }
    
    // Exempt users with bbPress moderation capabilities
    if (function_exists('bbp_is_user_keymaster') && bbp_is_user_keymaster($user_id)) {
        return true;
    }
    
    if (function_exists('bbp_is_user_moderator') && bbp_is_user_moderator($user_id)) {
        return true;
    }
    
    // Exempt established community members (users with 10+ points)
    if (function_exists('extrachill_get_user_total_points')) {
        $user_points = extrachill_get_user_total_points($user_id);
        if ($user_points >= 10) {
            return true;
        }
    }
    
    return apply_filters('ec_user_exempt_from_spam_detection', false, $user_id);
}

/**
 * Adjust reply spam detection
 */
function ec_adjust_reply_spam_detection($is_spam, $reply_id = 0, $anonymous_data = array(), $reply_author = '', $reply_email = '', $reply_url = '') {
    // If already marked as not spam, don't override
    if (!$is_spam) {
        return $is_spam;
    }
    
    // Get reply content
    $reply_content = get_post_field('post_content', $reply_id);
    if (!$reply_content) {
        return $is_spam;
    }
    
    // Check if user is exempt
    $user_id = get_post_field('post_author', $reply_id);
    if (ec_user_exempt_from_spam_detection($user_id)) {
        return false; // Not spam for exempt users
    }
    
    // Check if content only contains trusted domain links
    if (ec_content_has_only_trusted_links($reply_content)) {
        return false; // Not spam if only trusted links
    }
    
    return $is_spam;
}
add_filter('bbp_is_reply_spam', 'ec_adjust_reply_spam_detection', 10, 6);

/**
 * Adjust topic spam detection
 */
function ec_adjust_topic_spam_detection($is_spam, $topic_id = 0, $anonymous_data = array(), $topic_author = '', $topic_email = '', $topic_url = '') {
    // If already marked as not spam, don't override
    if (!$is_spam) {
        return $is_spam;
    }
    
    // Get topic content
    $topic_content = get_post_field('post_content', $topic_id);
    if (!$topic_content) {
        return $is_spam;
    }
    
    // Check if user is exempt
    $user_id = get_post_field('post_author', $topic_id);
    if (ec_user_exempt_from_spam_detection($user_id)) {
        return false; // Not spam for exempt users
    }
    
    // Check if content only contains trusted domain links
    if (ec_content_has_only_trusted_links($topic_content)) {
        return false; // Not spam if only trusted links
    }
    
    return $is_spam;
}
add_filter('bbp_is_topic_spam', 'ec_adjust_topic_spam_detection', 10, 6);

/**
 * Reduce spam detection sensitivity for posts with music-related keywords
 */
function ec_reduce_spam_sensitivity_for_music_content($is_spam, $content) {
    if (!$is_spam) {
        return $is_spam;
    }
    
    // Music-related keywords that suggest legitimate content
    $music_keywords = [
        'album', 'single', 'EP', 'track', 'song', 'artist', 'band', 'music', 
        'release', 'drop', 'playlist', 'listen', 'stream', 'spotify', 'bandcamp',
        'soundcloud', 'apple music', 'hip-hop', 'r&b', 'rap', 'charleston',
        '#gxldapproved', 'gxld', 'underground'
    ];
    
    $content_lower = strtolower($content);
    $music_keyword_count = 0;
    
    foreach ($music_keywords as $keyword) {
        if (strpos($content_lower, strtolower($keyword)) !== false) {
            $music_keyword_count++;
        }
    }
    
    // If content has 3+ music keywords, likely legitimate music discussion
    if ($music_keyword_count >= 3) {
        return false;
    }
    
    return $is_spam;
}

/**
 * Apply music content spam reduction to replies
 */
function ec_reduce_reply_spam_for_music($is_spam, $reply_id = 0, $anonymous_data = array(), $reply_author = '', $reply_email = '', $reply_url = '') {
    if (!$is_spam) {
        return $is_spam;
    }
    
    $reply_content = get_post_field('post_content', $reply_id);
    return ec_reduce_spam_sensitivity_for_music_content($is_spam, $reply_content);
}
add_filter('bbp_is_reply_spam', 'ec_reduce_reply_spam_for_music', 5, 6);

/**
 * Apply music content spam reduction to topics
 */  
function ec_reduce_topic_spam_for_music($is_spam, $topic_id = 0, $anonymous_data = array(), $topic_author = '', $topic_email = '', $topic_url = '') {
    if (!$is_spam) {
        return $is_spam;
    }
    
    $topic_content = get_post_field('post_content', $topic_id);
    return ec_reduce_spam_sensitivity_for_music_content($is_spam, $topic_content);
}
add_filter('bbp_is_topic_spam', 'ec_reduce_topic_spam_for_music', 5, 6);