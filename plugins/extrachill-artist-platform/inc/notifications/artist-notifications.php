<?php
/**
 * Artist Forum Notifications
 *
 * Captures notifications for artist members when topics/replies are posted
 * in their artist forums. Uses the extrachill_notify action provided by
 * the Extra Chill Community plugin.
 *
 * @package ExtraChillArtistPlatform
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Notify artist members of new topics in their artist forum
 *
 * @param int $topic_id Topic post ID
 * @param int $forum_id Forum ID
 * @param array $anonymous_data Anonymous user data
 * @param int $topic_author_id Topic author user ID
 */
function ec_artist_notify_new_topic($topic_id, $forum_id, $anonymous_data, $topic_author_id) {
    // Check if forum is associated with an artist profile
    $artist_profile_id = get_post_meta($forum_id, '_associated_artist_profile_id', true);

    if (empty($artist_profile_id)) {
        return; // Not an artist forum
    }

    // Get artist member IDs
    $artist_member_ids = get_post_meta($artist_profile_id, '_artist_member_ids', true);

    if (empty($artist_member_ids) || !is_array($artist_member_ids)) {
        return; // No artist members found
    }

    // Filter out topic author from notification recipients
    $artist_member_ids = array_filter($artist_member_ids, function($member_id) use ($topic_author_id) {
        return (int)$member_id !== (int)$topic_author_id;
    });

    if (empty($artist_member_ids)) {
        return; // No members to notify after filtering
    }

    // Get topic data
    $topic_title = get_the_title($topic_id);
    $topic_link = get_permalink($topic_id);

    // Send notification to all artist members
    do_action('extrachill_notify', $artist_member_ids, [
        'actor_id'    => $topic_author_id,
        'type'        => 'new_artist_topic',
        'topic_title' => $topic_title,
        'link'        => $topic_link,
        'post_id'     => $topic_id,
    ]);
}
add_action('bbp_new_topic', 'ec_artist_notify_new_topic', 20, 4);

/**
 * Notify artist members of new replies in their artist forum
 *
 * @param int $reply_id Reply post ID
 * @param int $topic_id Topic post ID
 * @param int $forum_id Forum ID
 * @param array $anonymous_data Anonymous user data
 * @param int $reply_author_id Reply author user ID
 */
function ec_artist_notify_new_reply($reply_id, $topic_id, $forum_id, $anonymous_data, $reply_author_id) {
    // Check if forum is associated with an artist profile
    $artist_profile_id = get_post_meta($forum_id, '_associated_artist_profile_id', true);

    if (empty($artist_profile_id)) {
        return; // Not an artist forum
    }

    // Get artist member IDs
    $artist_member_ids = get_post_meta($artist_profile_id, '_artist_member_ids', true);

    if (empty($artist_member_ids) || !is_array($artist_member_ids)) {
        return; // No artist members found
    }

    // Filter out reply author from notification recipients
    $artist_member_ids = array_filter($artist_member_ids, function($member_id) use ($reply_author_id) {
        return (int)$member_id !== (int)$reply_author_id;
    });

    if (empty($artist_member_ids)) {
        return; // No members to notify after filtering
    }

    // Get topic and reply data
    $topic_title = get_the_title($topic_id);
    $reply_link = bbp_get_reply_url($reply_id);

    // Send notification to all artist members
    do_action('extrachill_notify', $artist_member_ids, [
        'actor_id'    => $reply_author_id,
        'type'        => 'new_artist_reply',
        'topic_title' => $topic_title,
        'link'        => $reply_link,
        'post_id'     => $topic_id,
        'item_id'     => $reply_id,
    ]);
}
add_action('bbp_new_reply', 'ec_artist_notify_new_reply', 20, 5);
