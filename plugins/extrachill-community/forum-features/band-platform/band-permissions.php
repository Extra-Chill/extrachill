<?php
/**
 * Handles dynamic permissions for Band Profiles and associated forums.
 */

/**
 * Filters a user's capabilities based on their band memberships.
 *
 * Grants capabilities like editing band profiles and moderating associated band forums.
 *
 * @param array   $allcaps An array of all the user's capabilities.
 * @param array   $caps    Array of capabilities being checked, usually contains meta caps.
 * @param array   $args    Adds context to the capability check:
 *                         - $args[0] capability name
 *                         - $args[1] user ID
 *                         - $args[2] object ID (e.g., post ID, comment ID, term ID)
 * @param WP_User $user    The user object.
 * @return array Filtered array of the user's capabilities.
 */
function bp_filter_user_capabilities( $allcaps, $caps, $args, $user ) {
    $user_id = $user->ID;
    $cap     = $args[0]; // The capability being checked
    $post_id = isset( $args[2] ) ? $args[2] : null; // Post ID, if applicable

    // Log every capability check that goes through this filter for a specific user or cap if needed for debugging
    // error_log(sprintf("[Band Permissions] Checking cap: %s for user: %d, post_id: %s", $cap, $user_id, is_null($post_id) ? 'N/A' : $post_id));

    // Grant 'create_band_profiles' capability if user is an artist or can edit pages
    if ( $cap === 'create_band_profiles' ) {
        if ( user_can( $user_id, 'edit_pages' ) || get_user_meta( $user_id, 'user_is_artist', true ) === '1' ) {
            $allcaps[$cap] = true;
            // error_log(sprintf("[Band Permissions] Granted create_band_profiles for user %d", $user_id));
            return $allcaps;
        }
    }

    // Check for band-specific capabilities
    if ( $post_id && get_post_type( $post_id ) === 'band_profile' ) {
        error_log(sprintf("[Band Permissions DEBUG] Cap check on band_profile. User: %d, Cap: %s, Band Profile ID: %d", $user_id, $cap, $post_id));

        // Get linked band IDs for the user
        $linked_band_ids = get_user_meta( $user_id, '_band_profile_ids', true );
        if ( ! is_array( $linked_band_ids ) ) {
            $linked_band_ids = array();
        }

        // Is the user a member of this specific band profile?
        $is_member_of_this_band = in_array( $post_id, $linked_band_ids );
        error_log(sprintf("[Band Permissions DEBUG] User %d is member of band %d: %s", $user_id, $post_id, $is_member_of_this_band ? 'Yes' : 'No'));

        if ( $is_member_of_this_band ) {
            // Capabilities for linked band members for THEIR OWN band profile
            if ( in_array( $cap, array( 'edit_post', 'delete_post', 'read_post', 'publish_post', 'manage_band_members' ) ) ) {
                $allcaps[$cap] = true;
                error_log(sprintf("[Band Permissions DEBUG] Granted cap '%s' to user %d for their band %d", $cap, $user_id, $post_id));
            }
        }

        // --- bbPress Forum Specific Capabilities based on Band Profile settings ---
        $band_forum_id = get_post_meta( $post_id, '_band_forum_id', true );
        if ( $band_forum_id && isset($args[2]) && $args[2] == $band_forum_id ) {
            // This condition means the capability check is for the associated band forum ID
            error_log(sprintf("[Band Permissions DEBUG] Cap check on band_forum. User: %d, Cap: %s, Forum ID: %d (linked to Band %d)", $user_id, $cap, $band_forum_id, $post_id));
            if ( $is_member_of_this_band ) {
                // Grant broad forum moderation capabilities to band members for their own band's forum
                $member_forum_caps = [
                    'spectate', 'participate', 'read_private_forums', 'publish_topics', 'edit_topics', 
                    'publish_replies', 'edit_replies', 'delete_topics', 'delete_replies', 
                    'moderate', // Keycap for many forum actions
                    'throttle', // Bypass flood protection
                    'assign_topic_tags', 'edit_topic_tags',
                    'edit_others_topics', 'edit_others_replies', // Allow editing others within their forum
                    'delete_others_topics', 'delete_others_replies', // Allow deleting others within their forum
                ];
                if ( in_array( $cap, $member_forum_caps ) ) {
                    $allcaps[$cap] = true;
                    error_log(sprintf("[Band Permissions DEBUG] Granted forum cap '%s' to band member %d for their band forum %d", $cap, $user_id, $band_forum_id));
                }
            }
            
            // Public topic creation logic for the band's forum
            if ( $cap === 'publish_topics' ) {
                $allow_public_creation = get_post_meta( $post_id, '_allow_public_topic_creation', true );
                error_log(sprintf("[Band Permissions DEBUG] Forum cap 'publish_topics' check. Allow public for band %d: %s", $post_id, $allow_public_creation === '1' ? 'Yes' : 'No'));
                if ( $allow_public_creation === '1' ) {
                    // If public creation is allowed, anyone with the site-wide bbp_topic_creatable can post.
                    // We don't explicitly grant publish_topics here based on this setting alone if they don't have bbp_topic_creatable.
                    // Instead, we ensure that if they *can* create topics generally, this setting doesn't block them.
                    // The actual check by bbPress for bbp_new_topic_handler will use current_user_can( 'publish_topics', $forum_id )
                    // If a non-member has 'publish_topics' generally, they would be allowed unless we specifically deny.
                    // This filter PRIMARILY GRANTS. It doesn't typically deny unless $allcaps[$cap] is set to false.
                    // So, if a user is NOT a member, but public creation IS allowed, and they have site-wide publish_topics,
                    // they should be able to. If they are a member, they already get it from $member_forum_caps.
                } else {
                    // Public creation is NOT allowed. Only members should be able to post.
                    // If the user is not a member, ensure they DONT get publish_topics for this forum, even if they have it globally.
                    if ( ! $is_member_of_this_band && !user_can($user_id, 'manage_options') /* Allow admins always */ ) {
                        // $allcaps[$cap] = false; // CAREFUL: This can be too broad. bbPress handles this by default if it can't find a grant.
                        // For now, we rely on the fact that if they are not a member, they won't get the grant from $member_forum_caps.
                        // And if public is off, bbPress default permission checks for publish_topics in this specific forum_id should fail for non-members.
                        error_log(sprintf("[Band Permissions DEBUG] Public topics OFF for band %d. User %d is NOT a member. publish_topics will rely on other grants or lack thereof.", $post_id, $user_id));
                    }
                }
            }
        }
    }
    return $allcaps;
}
add_filter( 'user_has_cap', 'bp_filter_user_capabilities', 10, 4 ); 