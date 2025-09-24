<?php
/**
 * Plugin Name: ExtraChill News Wire
 * Description: Festival Wire custom post type and functionality for music festival coverage. Provides fast-loading archive with AJAX pagination, community tip submission system, and template overrides.
 * Version: 1.0
 * Author: Chris Huber
 * Text Domain: extrachill
 * Domain Path: /languages
 *
 * @package ExtraChillNewsWire
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define plugin constants
 */
define( 'FESTIVAL_WIRE_INCLUDE_DIR', plugin_dir_path(__FILE__) . 'includes/' );

// Include modularized files
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-post-type.php';
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-ajax.php';
require_once FESTIVAL_WIRE_INCLUDE_DIR . 'festival-wire-query-filters.php';

/**
 * Enqueue Festival Wire assets conditionally
 *
 * Loads CSS and JavaScript only on Festival Wire archive and single pages.
 * Includes AJAX localization for load-more functionality and tip form.
 *
 * @since 1.0.0
 */
function enqueue_festival_wire_assets() {
	global $wp_query; // Make sure global $wp_query is available

	// Archive pages: Load CSS, JS, and AJAX functionality
	if ( is_post_type_archive( 'festival_wire' ) ) {

		// Enqueue badge colors CSS first
		$badge_colors_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.css';
		if ( file_exists( $badge_colors_path ) ) {
			wp_enqueue_style(
				'badge-colors',
				plugin_dir_url(__FILE__) . 'assets/festival-wire.css',
				array(),
				filemtime( $badge_colors_path )
			);
		}

		// Main Festival Wire CSS
		$css_file_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.css';
		$css_file_uri  = plugin_dir_url(__FILE__) . 'assets/festival-wire.css';
		if ( file_exists( $css_file_path ) ) {
			wp_enqueue_style(
				'extrachill-festival-wire',
				$css_file_uri,
				array( 'badge-colors' ), // Make it dependent on badge-colors
				filemtime( $css_file_path )
			);
		}

		// Festival Wire JavaScript with AJAX support
		$js_file_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.js';
		$js_file_uri  = plugin_dir_url(__FILE__) . 'assets/festival-wire.js';
		if ( file_exists( $js_file_path ) ) {
			wp_enqueue_script(
				'extrachill-festival-wire',
				$js_file_uri,
				array( 'jquery' ), // Add dependencies if any
				filemtime( $js_file_path ),
				true // Load in footer
			);

			// AJAX localization for load-more and tip submission
			$localize_params = array(
				'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'tip_nonce'       => wp_create_nonce( 'festival_wire_tip_nonce' ),
				'load_more_nonce' => wp_create_nonce( 'festival_wire_load_more_nonce' ),
				'query_vars'      => json_encode( $wp_query->query_vars ),
				'max_pages'       => $wp_query->max_num_pages
			);

			// Localize script for AJAX functionality
			wp_localize_script(
				'extrachill-festival-wire',
				'festivalWireParams',
				$localize_params
			);
		}
	} elseif ( is_singular( 'festival_wire' ) ) {
		// Single pages: Load CSS, JS, and tip form functionality
		// Enqueue badge colors CSS first
		$badge_colors_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.css';
		if ( file_exists( $badge_colors_path ) ) {
			wp_enqueue_style(
				'badge-colors',
				plugin_dir_url(__FILE__) . 'assets/festival-wire.css',
				array(),
				filemtime( $badge_colors_path )
			);
		}
		
		// Main Festival Wire CSS for single pages
		$css_file_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.css';
		$css_file_uri  = plugin_dir_url(__FILE__) . 'assets/festival-wire.css';
		if ( file_exists( $css_file_path ) ) {
			wp_enqueue_style(
				'extrachill-festival-wire',
				$css_file_uri,
				array( 'badge-colors' ), // Make it dependent on badge-colors
				filemtime( $css_file_path )
			);
		}
		// Festival Wire JavaScript for single pages
		$js_file_path = plugin_dir_path(__FILE__) . 'assets/festival-wire.js';
		$js_file_uri  = plugin_dir_url(__FILE__) . 'assets/festival-wire.js';
		if ( file_exists( $js_file_path ) ) {
			wp_enqueue_script(
				'extrachill-festival-wire',
				$js_file_uri,
				array( 'jquery' ),
				filemtime( $js_file_path ),
				true
			);

			// AJAX localization for tip submission on single pages
			$localize_params = array(
				'ajaxurl'   => admin_url( 'admin-ajax.php' ),
				'tip_nonce' => wp_create_nonce( 'festival_wire_tip_nonce' ),
			);
			wp_localize_script(
				'extrachill-festival-wire',
				'festivalWireParams',
				$localize_params
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'enqueue_festival_wire_assets' );

/**
 * Display Festival Wire ticker on homepage
 *
 * Hooks into theme's after-hero location to display Festival Wire ticker.
 * Uses plugin template for consistent rendering and data management.
 *
 * @since 1.0.0
 */
function display_festival_wire_ticker() {
	// Load ticker template from plugin
	include plugin_dir_path( __FILE__ ) . 'templates/homepage-ticker.php';
}
add_action( 'extrachill_after_hero', 'display_festival_wire_ticker' );

/**
 * Template loader for Festival Wire post type
 *
 * Overrides WordPress template hierarchy to use plugin templates
 * for Festival Wire archive and single pages. Ensures consistent
 * display regardless of active theme.
 *
 * @since 1.0.0
 * @param string $template Current template path
 * @return string Modified template path
 */
function festival_wire_template_loader( $template ) {
	// Override templates for Festival Wire post type
	if ( is_post_type_archive( 'festival_wire' ) ) {
		$plugin_template = locate_festival_wire_template( 'archive-festival_wire.php' );
		if ( $plugin_template ) {
			return $plugin_template;
		}
	} elseif ( is_singular( 'festival_wire' ) ) {
		$plugin_template = locate_festival_wire_template( 'single-festival_wire.php' );
		if ( $plugin_template ) {
			return $plugin_template;
		}
	}

	return $template;
}
add_filter( 'template_include', 'festival_wire_template_loader' );

/**
 * Locate Festival Wire template file
 *
 * Searches for template files in the plugin's templates directory.
 * Used by template loader to override theme templates.
 *
 * @since 1.0.0
 * @param string $template_name Template filename to locate
 * @return string|false Path to template file, or false if not found
 */
function locate_festival_wire_template( $template_name ) {
	$plugin_template_path = plugin_dir_path( __FILE__ ) . 'templates/' . $template_name;

	if ( file_exists( $plugin_template_path ) ) {
		return $plugin_template_path;
	}

	return false;
}

/**
 * Festival Wire Migration Tools
 *
 * One-time migration utilities for converting tags to festival taxonomy
 * and reassigning post authors. Added to Tools menu in wp-admin.
 *
 * @since 1.0.0
 */
add_action('admin_menu', function() {
	add_management_page(
		'Festival Wire Migration',
		'Festival Wire Migration',
		'manage_options',
		'festival-wire-migration',
		'festival_wire_migration_admin_page'
	);
});

/**
 * Migration admin page interface
 *
 * Provides forms for tag-to-festival migration and author reassignment.
 * Includes safety confirmations and migration reports.
 *
 * @since 1.0.0
 */
function festival_wire_migration_admin_page() {
	if (!current_user_can('manage_options')) {
		wp_die('You do not have sufficient permissions to access this page.');
	}

	// Handle tag migration
	$tag_migration_done = get_option('festival_wire_migration_done');
	if (isset($_POST['festival_wire_migrate']) && check_admin_referer('festival_wire_migrate_action')) {
		$report = festival_wire_perform_tag_to_festival_migration();
		update_option('festival_wire_migration_done', 1);
		echo '<div class="notice notice-success"><p><strong>Tag Migration complete!</strong></p>';
		if (!empty($report)) {
			echo '<ul>';
			foreach ($report as $line) {
				echo '<li>' . esc_html($line) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
		$tag_migration_done = true;
	}

	// Handle author migration
	$author_migration_done = get_option('festival_wire_author_migration_done');
	if (isset($_POST['festival_wire_author_migrate']) && check_admin_referer('festival_wire_author_migrate_action')) {
		$new_author_id = intval($_POST['new_author_id']);
		if ($new_author_id > 0) {
			$report = festival_wire_perform_author_migration($new_author_id);
			update_option('festival_wire_author_migration_done', 1);
			echo '<div class="notice notice-success"><p><strong>Author Migration complete!</strong></p>';
			if (!empty($report)) {
				echo '<ul>';
				foreach ($report as $line) {
					echo '<li>' . esc_html($line) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div>';
			$author_migration_done = true;
		} else {
			echo '<div class="notice notice-error"><p><strong>Error:</strong> Please select a valid author.</p></div>';
		}
	}

	// Get Festival Wire post count for display
	global $wpdb;
	$festival_wire_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'festival_wire'");
	?>
	<div class="wrap">
		<h1>Festival Wire Migration Tools</h1>

		<!-- Tag Migration Section -->
		<h2>Tag to Festival Migration</h2>
		<?php if ($tag_migration_done): ?>
			<div class="notice notice-success"><p><strong>Tag migration already completed.</strong></p></div>
		<?php else: ?>
			<p>This will migrate all tags currently attached to any Festival Wire post to the new <strong>festival</strong> taxonomy. The tags will be removed from all posts and deleted if unused. This action is one-time and cannot be undone.</p>
			<form method="post">
				<?php wp_nonce_field('festival_wire_migrate_action'); ?>
				<input type="submit" name="festival_wire_migrate" class="button button-primary" value="Migrate Festival Wire Tags to Festivals" onclick="return confirm('Are you sure? This cannot be undone.');">
			</form>
		<?php endif; ?>

		<hr style="margin: 30px 0;">

		<!-- Author Migration Section -->
		<h2>Festival Wire Author Migration</h2>
		<?php if ($author_migration_done): ?>
			<div class="notice notice-success"><p><strong>Author migration already completed.</strong></p></div>
		<?php else: ?>
			<p>This will reassign ALL Festival Wire posts (currently <strong><?php echo number_format($festival_wire_count); ?> posts</strong>) to a selected author. This action is one-time and cannot be undone.</p>
			<form method="post">
				<?php wp_nonce_field('festival_wire_author_migrate_action'); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="new_author_id">Select New Author:</label></th>
						<td>
							<?php
							wp_dropdown_users(array(
								'name' => 'new_author_id',
								'id' => 'new_author_id',
								'show_option_none' => 'Select an author...',
								'option_none_value' => 0
							));
							?>
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" name="festival_wire_author_migrate" class="button button-primary" value="Migrate All Festival Wire Authors" onclick="return confirm('Are you sure you want to reassign all <?php echo number_format($festival_wire_count); ?> Festival Wire posts? This cannot be undone.');">
				</p>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Perform tag to festival taxonomy migration
 *
 * Migrates all tags attached to Festival Wire posts to the festival taxonomy.
 * Removes tags from posts and deletes unused tags.
 *
 * @since 1.0.0
 * @return array Migration report with detailed results
 */
function festival_wire_perform_tag_to_festival_migration() {
	global $wpdb;
	$report = array();
	// 1. Get all tag IDs attached to any festival_wire post
	$tag_ids = $wpdb->get_col("
		SELECT DISTINCT tr.term_taxonomy_id
		FROM {$wpdb->term_relationships} tr
		JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
		JOIN {$wpdb->posts} p ON tr.object_id = p.ID
		WHERE p.post_type = 'festival_wire' AND tt.taxonomy = 'post_tag'
	");
	if (empty($tag_ids)) {
		$report[] = 'No tags found attached to festival_wire posts.';
		return $report;
	}
	foreach ($tag_ids as $tt_id) {
		$tag = $wpdb->get_row($wpdb->prepare(
			"SELECT t.term_id, t.name, t.slug FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id WHERE tt.term_taxonomy_id = %d",
			$tt_id
		));
		if (!$tag) continue;
		// Create festival term if not exists
		$festival_term = term_exists($tag->slug, 'festival');
		if (!$festival_term) {
			$festival_term = wp_insert_term($tag->name, 'festival', array('slug' => $tag->slug));
		}
		$festival_term_id = is_array($festival_term) ? $festival_term['term_id'] : $festival_term;
		// Get all posts (any type) with this tag
		$post_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = %d",
			$tt_id
		));
		if (empty($post_ids)) continue;
		// Attach festival term to all these posts
		foreach ($post_ids as $post_id) {
			wp_set_object_terms($post_id, intval($festival_term_id), 'festival', true);
			wp_remove_object_terms($post_id, intval($tag->term_id), 'post_tag');
		}
		// Optionally, delete tag if no longer used
		$count = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
			$tt_id
		));
		if ($count === 0) {
			wp_delete_term($tag->term_id, 'post_tag');
			$report[] = sprintf('Migrated and deleted tag "%s" (slug: %s).', $tag->name, $tag->slug);
		} else {
			$report[] = sprintf('Migrated tag "%s" (slug: %s), but it is still used elsewhere.', $tag->name, $tag->slug);
		}
	}
	return $report;
}

/**
 * Perform bulk author migration for Festival Wire posts
 *
 * Reassigns all Festival Wire posts to a specified author.
 * Used for consolidating post ownership.
 *
 * @since 1.0.0
 * @param int $new_author_id WordPress user ID to assign as author
 * @return array Migration report with results
 */
function festival_wire_perform_author_migration($new_author_id) {
	global $wpdb;
	$report = array();

	// Validate the author ID exists
	$author = get_userdata($new_author_id);
	if (!$author) {
		$report[] = 'Error: Invalid author ID provided.';
		return $report;
	}

	// Get current count of Festival Wire posts
	$total_posts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'festival_wire'");

	if ($total_posts == 0) {
		$report[] = 'No Festival Wire posts found to migrate.';
		return $report;
	}

	// Perform the bulk update
	$updated = $wpdb->update(
		$wpdb->posts,
		array('post_author' => $new_author_id),
		array('post_type' => 'festival_wire'),
		array('%d'),
		array('%s')
	);

	if ($updated === false) {
		$report[] = 'Error: Database update failed.';
	} else {
		$report[] = sprintf('Successfully migrated %d Festival Wire posts to author: %s (%s).',
			$updated,
			$author->display_name,
			$author->user_login
		);

		if ($updated != $total_posts) {
			$report[] = sprintf('Note: Expected %d posts but updated %d posts.', $total_posts, $updated);
		}
	}

	return $report;
}
/**
 * Plugin Architecture
 *
 * Core functionality is modularized across separate include files:
 *
 * - festival-wire-post-type.php: Custom post type and taxonomy registration
 * - festival-wire-ajax.php: AJAX handlers for load-more and tip submission
 * - festival-wire-query-filters.php: Query modifications and custom variables
 *
 * Templates are located in /templates/ directory and override theme templates.
 * Assets are enqueued conditionally based on post type context.
 */