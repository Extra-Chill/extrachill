<?php
/**
 * Plugin Activation
 *
 * Auto-creates required pages and forums when plugin is activated.
 * Checks for existing pages/forums to prevent duplicates.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Main activation handler
 *
 * Creates required pages and forums on plugin activation.
 * Checks for bbPress before proceeding.
 */
function extrachill_community_activate() {
	add_option('extrachill_community_do_activation_setup', 1);

	if (!function_exists('bbp_insert_forum')) {
		deactivate_plugins(plugin_basename(EXTRACHILL_COMMUNITY_PLUGIN_FILE));
		wp_die(esc_html__('Extra Chill Community requires bbPress to be activated.', 'extra-chill-community'));
	}

	extrachill_community_run_activation_setup();
}

function extrachill_community_run_activation_setup() {
	delete_option('extrachill_community_do_activation_setup');
	
	extrachill_create_community_pages();
	extrachill_create_community_forums();
}

add_action(
	'plugins_loaded',
	function() {
		if (!get_option('extrachill_community_do_activation_setup')) {
			return;
		}

		if (!function_exists('bbp_insert_forum')) {
			return;
		}

		extrachill_community_run_activation_setup();
	},
	20
);

/**
 * Create required community pages
 *
 * Creates 5 pages: Settings, Notifications, Recent, Leaderboard, Blog Comments.
 * Skips creation if page with slug already exists.
 *
 * @return array Array of created page IDs
 */
function extrachill_create_community_pages() {
	$pages = array(
		array(
			'title'    => 'Settings',
			'slug'     => 'settings',
			'template' => 'default',
			'content'  => '',
		),
		array(
			'title'    => 'Notifications',
			'slug'     => 'notifications',
			'template' => 'default',
			'content'  => '',
		),
		array(
			'title'    => 'Recent Activity',
			'slug'     => 'recent',
			'template' => 'page-templates/recent-feed-template.php',
			'content'  => 'Community Recent Activity',
		),
		array(
			'title'    => 'Leaderboard',
			'slug'     => 'leaderboard',
			'template' => 'default',
			'content'  => '<!-- wp:extrachill/leaderboard {"perPage":25} /-->',
		),
		array(
			'title'    => 'Blog Comments',
			'slug'     => 'blog-comments',
			'template' => 'page-templates/main-blog-comments-feed.php',
			'content'  => 'Comments from the main blog',
		),
	);

	$created_page_ids = array();

	foreach ($pages as $page_data) {
		$existing_page = get_page_by_path( $page_data['slug'], OBJECT, 'page' );
		if ( $existing_page ) {
			if ( $page_data['slug'] !== 'leaderboard' ) {
				continue;
			}

			update_post_meta( $existing_page->ID, '_wp_page_template', 'default' );
			wp_update_post(
				array(
					'ID'           => $existing_page->ID,
					'post_content' => $page_data['content'],
				)
			);

			continue;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => $page_data['title'],
				'post_name'    => $page_data['slug'],
				'post_content' => $page_data['content'],
				'post_status'  => 'publish',
			)
		);

		if (!is_wp_error($page_id)) {
			$created_page_ids[] = $page_id;

			// Assign template if not default
			if ($page_data['template'] !== 'default') {
				update_post_meta($page_id, '_wp_page_template', $page_data['template']);
			}
		}
	}

	return $created_page_ids;
}

/**
 * Create required community forums
 *
 * Creates 2 forums: Local Scenes, Music Discussion.
 * Skips creation if forum with slug already exists.
 *
 * @return array Array of created forum IDs
 */
function extrachill_create_community_forums() {
	$forums = array(
		array(
			'title'       => 'Local Scenes',
			'slug'        => 'local-scenes',
			'description' => 'Discuss local music scenes and discover what\'s happening near you',
		),
		array(
			'title'       => 'Music Discussion',
			'slug'        => 'music-discussion',
			'description' => 'General music discussion, recommendations, and talk about what you\'re listening to',
		),
	);

	$created_forum_ids = array();

	foreach ($forums as $forum_data) {
		if (extrachill_forum_exists_by_slug($forum_data['slug'])) {
			continue;
		}

		$forum_id = bbp_insert_forum(
			array(
				'post_title'   => $forum_data['title'],
				'post_name'    => $forum_data['slug'],
				'post_content' => $forum_data['description'],
			)
		);

		if (!is_wp_error($forum_id)) {
			$created_forum_ids[] = $forum_id;
		}
	}

	return $created_forum_ids;
}

/**
 * Check if page with slug already exists
 *
 * @param string $slug Page slug to check
 * @return bool True if page exists, false otherwise
 */
function extrachill_page_exists_by_slug($slug) {
	$page = get_page_by_path($slug, OBJECT_K, 'page');
	return !empty($page);
}

/**
 * Check if forum with slug already exists
 *
 * @param string $slug Forum slug to check
 * @return bool True if forum exists, false otherwise
 */
function extrachill_forum_exists_by_slug($slug) {
	$forum = get_page_by_path($slug, OBJECT_K, bbp_get_forum_post_type());
	return !empty($forum);
}
