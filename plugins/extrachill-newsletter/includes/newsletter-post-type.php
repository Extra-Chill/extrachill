<?php
/**
 * Newsletter Custom Post Type Registration
 *
 * Handles the registration of the newsletter custom post type and related
 * admin functionality including meta boxes and post status handling.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register Newsletter custom post type
 *
 * Creates the newsletter custom post type with full WordPress features
 * including REST API support, archive pages, and admin integration.
 *
 * @since 1.0.0
 */
function create_newsletter_post_type() {
	register_post_type('newsletter', array(
		'labels' => array(
			'name' => __('Newsletters', 'extrachill-newsletter'),
			'singular_name' => __('Newsletter', 'extrachill-newsletter'),
			'add_new' => __('Create Newsletter', 'extrachill-newsletter'),
			'add_new_item' => __('Add New Newsletter', 'extrachill-newsletter'),
			'edit_item' => __('Edit Newsletter', 'extrachill-newsletter'),
			'new_item' => __('New Newsletter', 'extrachill-newsletter'),
			'view_item' => __('View Newsletter', 'extrachill-newsletter'),
			'search_items' => __('Search Newsletters', 'extrachill-newsletter'),
			'not_found' => __('No newsletters found', 'extrachill-newsletter'),
			'not_found_in_trash' => __('No newsletters found in trash', 'extrachill-newsletter'),
		),
		'public' => true,
		'has_archive' => true,
		'rewrite' => array('slug' => 'newsletters'),
		'supports' => array('title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'),
		'show_in_rest' => true,
		'menu_position' => 6,
		'menu_icon' => 'dashicons-email-alt',
		'capability_type' => 'post',
		'hierarchical' => false,
		'exclude_from_search' => false,
		'publicly_queryable' => true,
		'show_ui' => true,
		'show_in_menu' => true,
		'show_in_nav_menus' => true,
		'show_in_admin_bar' => true,
		'can_export' => true,
	));
}
add_action('init', 'create_newsletter_post_type');

/**
 * Check newsletter post conditions
 *
 * Validates whether newsletter operations should proceed based on
 * post status, type, and WordPress state conditions.
 *
 * @since 1.0.0
 * @param int $post_id WordPress post ID
 * @param WP_Post $post WordPress post object
 * @return bool True if conditions are met, false otherwise
 */
function check_newsletter_conditions($post_id, $post) {
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return false;
	}
	if (wp_is_post_revision($post_id) || 'newsletter' !== get_post_type($post_id) || 'publish' !== get_post_status($post_id)) {
		return false;
	}
	return true;
}

/**
 * Add Sendy integration meta box
 *
 * Adds administrative meta box for Sendy campaign management
 * to newsletter edit screens in wp-admin.
 *
 * @since 1.0.0
 */
function add_newsletter_sendy_meta_box() {
	add_meta_box(
		'newsletter_sendy_meta_box',
		__('Sendy Integration', 'extrachill-newsletter'),
		'newsletter_sendy_meta_box_html',
		'newsletter',
		'side',
		'high'
	);
}
add_action('add_meta_boxes', 'add_newsletter_sendy_meta_box');

/**
 * Render Sendy meta box HTML
 *
 * Outputs the meta box content with push to Sendy button
 * and JavaScript for AJAX campaign management.
 *
 * @since 1.0.0
 * @param WP_Post $post Current post object
 */
function newsletter_sendy_meta_box_html($post) {
	wp_nonce_field('newsletter_sendy_nonce_action', 'newsletter_sendy_nonce_field');

	echo '<p>' . __('Push this newsletter to Sendy as an email campaign.', 'extrachill-newsletter') . '</p>';
	echo '<button type="button" class="button button-primary" id="push_newsletter_to_sendy">' . __('Push to Sendy', 'extrachill-newsletter') . '</button>';

	// Get campaign status if available
	$campaign_id = get_post_meta($post->ID, '_sendy_campaign_id', true);
	if ($campaign_id) {
		echo '<p><small>' . sprintf(__('Campaign ID: %s', 'extrachill-newsletter'), $campaign_id) . '</small></p>';
	}

	// Add JavaScript for AJAX handling
	?>
	<script type="text/javascript">
	document.getElementById('push_newsletter_to_sendy').addEventListener('click', function() {
		var button = this;
		var originalText = button.textContent;

		// Disable button and show loading state
		button.disabled = true;
		button.textContent = '<?php echo esc_js(__('Pushing...', 'extrachill-newsletter')); ?>';

		var postId = <?php echo json_encode($post->ID); ?>;
		var ajaxUrl = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
		var data = new URLSearchParams({
			action: 'push_newsletter_to_sendy_ajax',
			post_id: postId,
			nonce: <?php echo json_encode(wp_create_nonce('push_newsletter_to_sendy_nonce')); ?>
		});

		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: data
		})
		.then(response => response.json())
		.then(data => {
			// Reset button state
			button.disabled = false;
			button.textContent = originalText;

			if (data.success) {
				alert('<?php echo esc_js(__('Successfully pushed to Sendy!', 'extrachill-newsletter')); ?>');

				// Reload the page to show updated campaign ID
				window.location.reload();
			} else {
				alert('<?php echo esc_js(__('Error:', 'extrachill-newsletter')); ?> ' + (data.data || '<?php echo esc_js(__('An undefined error occurred', 'extrachill-newsletter')); ?>'));
			}
		})
		.catch(error => {
			// Reset button state
			button.disabled = false;
			button.textContent = originalText;

			console.error('Fetch error:', error);
			alert('<?php echo esc_js(__('Network error:', 'extrachill-newsletter')); ?> ' + error.message);
		});
	});
	</script>
	<?php
}

/**
 * Save newsletter meta box data
 *
 * Handles saving of newsletter meta box data when posts are saved.
 * Currently used for Sendy campaign ID storage.
 *
 * @since 1.0.0
 * @param int $post_id WordPress post ID
 */
function save_newsletter_meta_box_data($post_id) {
	// Verify nonce
	if (!isset($_POST['newsletter_sendy_nonce_field']) || !wp_verify_nonce($_POST['newsletter_sendy_nonce_field'], 'newsletter_sendy_nonce_action')) {
		return;
	}

	// Check user permissions
	if (!current_user_can('edit_post', $post_id)) {
		return;
	}

	// Prevent auto-save
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}

	// Only process newsletter posts
	if ('newsletter' !== get_post_type($post_id)) {
		return;
	}

	// Additional meta data processing can be added here if needed
}
add_action('save_post', 'save_newsletter_meta_box_data');

/**
 * Add newsletter columns to admin list
 *
 * Customizes the newsletter post list in wp-admin to show
 * relevant information like campaign status and send date.
 *
 * @since 1.0.0
 * @param array $columns Existing columns
 * @return array Modified columns
 */
function newsletter_admin_columns($columns) {
	// Add custom columns after title
	$new_columns = array();
	foreach ($columns as $key => $value) {
		$new_columns[$key] = $value;
		if ($key == 'title') {
			$new_columns['campaign_status'] = __('Campaign Status', 'extrachill-newsletter');
			$new_columns['send_date'] = __('Send Date', 'extrachill-newsletter');
		}
	}
	return $new_columns;
}
add_filter('manage_newsletter_posts_columns', 'newsletter_admin_columns');

/**
 * Populate custom newsletter admin columns
 *
 * Fills the custom columns with appropriate data for each newsletter.
 *
 * @since 1.0.0
 * @param string $column Column name
 * @param int $post_id Post ID
 */
function newsletter_admin_column_content($column, $post_id) {
	switch ($column) {
		case 'campaign_status':
			$campaign_id = get_post_meta($post_id, '_sendy_campaign_id', true);
			if ($campaign_id) {
				echo '<span style="color: green;">✓ ' . __('Sent to Sendy', 'extrachill-newsletter') . '</span>';
				echo '<br><small>ID: ' . esc_html($campaign_id) . '</small>';
			} else {
				echo '<span style="color: orange;">○ ' . __('Not sent', 'extrachill-newsletter') . '</span>';
			}
			break;
		case 'send_date':
			echo get_the_date('M j, Y', $post_id);
			break;
	}
}
add_action('manage_newsletter_posts_custom_column', 'newsletter_admin_column_content', 10, 2);

/**
 * Make newsletter admin columns sortable
 *
 * Allows sorting by custom columns in the newsletter admin list.
 *
 * @since 1.0.0
 * @param array $columns Sortable columns
 * @return array Modified sortable columns
 */
function newsletter_admin_sortable_columns($columns) {
	$columns['send_date'] = 'date';
	$columns['campaign_status'] = 'campaign_status';
	return $columns;
}
add_filter('manage_edit-newsletter_sortable_columns', 'newsletter_admin_sortable_columns');