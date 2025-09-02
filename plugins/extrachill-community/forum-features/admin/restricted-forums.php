<?php
/**
 * Forum Access Restrictions
 * 
 * Adds admin interface for setting forum posting requirements based on
 * user roles (artists, industry professionals). Controls who can post
 * in specific forums through custom meta fields.
 * 
 * @package Extra ChillCommunity
 */
// Meta box registration removed - posting restrictions deprecated
// Preserving save functionality to maintain existing meta data


// Save the states of the checkboxes
function extrachill_save_postdata($post_id) {
    $meta_keys = ['_require_artist', '_require_professional'];

    foreach ($meta_keys as $meta_key) {
        if (array_key_exists($meta_key, $_POST)) {
            update_post_meta($post_id, $meta_key, $_POST[$meta_key]);
        } else {
            delete_post_meta($post_id, $meta_key);
        }
    }
}
add_action('save_post', 'extrachill_save_postdata');


// Posting restriction functionality removed - no longer used
// Meta fields preserved for potential future use


// Optionally, you could remove redundant main query exclusions if the consolidated function covers all cases.
