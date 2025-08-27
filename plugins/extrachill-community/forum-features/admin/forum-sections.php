<?php
/**
 * Forum Section Management
 * 
 * Admin functionality for categorizing forums into sections (top, middle, none).
 * Adds metabox dropdown to forum edit pages and handles saving section assignments.
 * 
 * @package ExtraChillCommunity
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Function to add a dropdown to the forum edit page
function add_section_dropdown_to_forum_edit() {
    $value = get_post_meta(get_the_ID(), '_bbp_forum_section', true);
    ?>
    <p>
        <label for="bbp_forum_section"><?php _e('Section', 'bbpress'); ?></label>
        <select name="_bbp_forum_section" id="bbp_forum_section">
            <option value="top" <?php selected($value, 'top'); ?>>Top</option>
            <option value="middle" <?php selected($value, 'middle'); ?>>Middle</option>
            <option value="none" <?php selected($value, 'none'); ?>>None (Hidden)</option>
        </select>
    </p>
    <?php
}
add_action('bbp_forum_metabox', 'add_section_dropdown_to_forum_edit');

// Function to save the dropdown selection
function save_forum_section( $forum_id ) {
    if (isset($_POST['_bbp_forum_section'])) {
        update_post_meta($forum_id, '_bbp_forum_section', sanitize_text_field(wp_unslash($_POST['_bbp_forum_section'])));
    }
}
add_action('bbp_forum_attributes_metabox_save', 'save_forum_section');