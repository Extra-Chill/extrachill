<?php
/**
 * Forum Archive Display Management
 *
 * Admin functionality for controlling which forums display in the forum
 * archive (/forums/) list. Adds a checkbox to forum edit pages.
 *
 * This is an ARCHIVE-visibility control, NOT a homepage control. The
 * community homepage moved to the feed-first layout + Browse Rooms chip row
 * (#65/#66), which selects forums by post_status — it does NOT read this
 * meta. The one live consumer of this flag is bbpress/loop-forums.php, which
 * renders the forum archive list.
 *
 * Naming note: the stored meta key is still `_show_on_homepage` — a leftover
 * from before the forum list moved off the homepage onto the forum archive.
 * The stored-key rename (with data migration) is tracked separately (#137);
 * this file keeps the user-facing copy, function names, and ability surface
 * honest about the archive meaning in the meantime.
 *
 * @package ExtraChillCommunity
 * @version 1.0.0
 */

// Prevent direct access
if ( ! defined('ABSPATH') ) {
	exit;
}

// WordPress meta box registration (working pattern)
function extrachill_add_forum_archive_display_meta_box() {
	add_meta_box(
		'extrachill_forum_archive_display',
		__( 'Forum Archive Display', 'extra-chill-community' ),
		'extrachill_forum_archive_display_meta_box_callback',
		'forum',
		'side',
		'high'
	);
}
add_action('add_meta_boxes', 'extrachill_add_forum_archive_display_meta_box');

// Meta box callback function
function extrachill_forum_archive_display_meta_box_callback($post) {
	// Stored key is still `_show_on_homepage` (legacy name; rename tracked in #137).
	$show_in_archive = get_post_meta($post->ID, '_show_on_homepage', true);

	// Add nonce field for security
	wp_nonce_field('forum_archive_display_metabox', 'forum_archive_display_nonce');
	?>
	<p>
		<label for="show_in_forum_archive">
			<input type="checkbox" name="_show_in_forum_archive" id="show_in_forum_archive" value="1" <?php checked($show_in_archive, '1'); ?> />
			<?php esc_html_e('Show in forum archive', 'extra-chill-community'); ?>
		</label>
		<br />
		<span class="description"><?php esc_html_e('Display this forum in the forum archive (/forums/) list.', 'extra-chill-community'); ?></span>
	</p>
	<?php
}


// Function to save the forum-archive display setting.
function save_forum_archive_display($post_id) {
	// Check if this is an autosave
	if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
		return;
	}

	// Verify nonce for security
	if ( ! isset($_POST['forum_archive_display_nonce']) || ! wp_verify_nonce($_POST['forum_archive_display_nonce'], 'forum_archive_display_metabox') ) {
		return;
	}

	// Check if current user can edit this post
	if ( ! current_user_can('edit_post', $post_id) ) {
		return;
	}

	// Only process for forum post type
	if ( get_post_type($post_id) !== bbp_get_forum_post_type() ) {
		return;
	}

	// Stored key is still `_show_on_homepage` (legacy name; rename tracked in #137).
	if ( isset($_POST['_show_in_forum_archive']) ) {
		update_post_meta($post_id, '_show_on_homepage', '1');
	} else {
		delete_post_meta($post_id, '_show_on_homepage');
	}
}
add_action('save_post', 'save_forum_archive_display');
