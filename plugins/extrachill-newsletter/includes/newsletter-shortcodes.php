<?php
/**
 * Newsletter Shortcodes
 *
 * Provides shortcodes for displaying newsletter content throughout
 * the site including recent newsletters widget and subscription forms.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recent Newsletters Shortcode
 *
 * Displays a list of recent newsletters with titles, links, and dates.
 * Used in sidebar widgets and other content areas.
 *
 * @since 1.0.0
 * @param array $atts Shortcode attributes
 * @return string HTML output for recent newsletters
 */
function recent_newsletters_shortcode($atts = array()) {
	// Parse shortcode attributes with defaults
	$atts = shortcode_atts(array(
		'count' => 3,
		'show_dates' => 'true',
		'show_view_all' => 'true',
		'title' => 'Recent Newsletters'
	), $atts, 'recent_newsletters');

	// Start output buffering
	ob_start();

	// Query for recent newsletters
	$newsletter_args = array(
		'post_type' => 'newsletter',
		'posts_per_page' => intval($atts['count']),
		'post_status' => 'publish',
		'orderby' => 'date',
		'order' => 'DESC',
	);

	$newsletter_query = new WP_Query($newsletter_args);

	if ($newsletter_query->have_posts()) {
		echo '<div class="recent-newsletters-widget">';

		// Display title if provided
		if (!empty($atts['title'])) {
			echo '<h3 class="widget-title"><span>' . esc_html($atts['title']) . '</span></h3>';
		}

		echo '<ul class="recent-newsletters-list">';

		while ($newsletter_query->have_posts()) {
			$newsletter_query->the_post();
			echo '<li class="recent-newsletter-item">';
			echo '<a href="' . get_permalink() . '" class="recent-newsletter-link">';
			echo '<strong>' . get_the_title() . '</strong>';
			echo '</a>';

			// Show date if enabled
			if ($atts['show_dates'] === 'true') {
				echo '<br><span class="newsletter-date">Sent on ' . get_the_date() . '</span>';
			}

			echo '</li>';
		}

		echo '</ul>';

		// Show "View All" link if enabled
		if ($atts['show_view_all'] === 'true') {
			$archive_url = get_post_type_archive_link('newsletter');
			if ($archive_url) {
				echo '<a href="' . esc_url($archive_url) . '" class="view-all-newsletters">';
				echo esc_html__('View All Newsletters', 'extrachill-newsletter');
				echo '</a>';
			}
		}

		echo '</div>';
	} else {
		echo '<div class="recent-newsletters-widget no-newsletters">';
		echo '<p>' . esc_html__('No newsletters found.', 'extrachill-newsletter') . '</p>';
		echo '</div>';
	}

	// Reset post data
	wp_reset_postdata();

	// Return buffered content
	return ob_get_clean();
}
add_shortcode('recent_newsletters', 'recent_newsletters_shortcode');

/**
 * Newsletter Subscription Form Shortcode
 *
 * Displays a newsletter subscription form that can be embedded
 * anywhere in content or widgets.
 *
 * @since 1.0.0
 * @param array $atts Shortcode attributes
 * @return string HTML output for subscription form
 */
function newsletter_subscription_form_shortcode($atts = array()) {
	// Parse shortcode attributes with defaults
	$atts = shortcode_atts(array(
		'list' => 'archive',
		'title' => 'Subscribe to Our Newsletter',
		'description' => 'Get updates delivered to your inbox.',
		'placeholder' => 'Enter your email address',
		'button_text' => 'Subscribe',
		'show_past_link' => 'true',
		'css_class' => 'newsletter-subscription-form'
	), $atts, 'newsletter_subscription_form');

	// Generate unique form ID
	$form_id = 'newsletter_form_' . wp_generate_password(8, false);

	// Start output buffering
	ob_start();

	echo '<div class="' . esc_attr($atts['css_class']) . '">';

	// Display title if provided
	if (!empty($atts['title'])) {
		echo '<h3 class="newsletter-form-title">' . esc_html($atts['title']) . '</h3>';
	}

	// Display description if provided
	if (!empty($atts['description'])) {
		echo '<p class="newsletter-form-description">' . esc_html($atts['description']) . '</p>';
	}

	// Newsletter subscription form
	echo '<form id="' . esc_attr($form_id) . '" class="newsletter-shortcode-form">';
	echo '<div class="newsletter-form-fields">';
	echo '<label for="' . esc_attr($form_id) . '_email" class="sr-only">' . esc_html__('Email Address', 'extrachill-newsletter') . '</label>';
	echo '<input type="email" id="' . esc_attr($form_id) . '_email" name="email" placeholder="' . esc_attr($atts['placeholder']) . '" required>';
	echo '<input type="hidden" name="action" value="submit_newsletter_shortcode_form">';
	echo '<input type="hidden" name="list" value="' . esc_attr($atts['list']) . '">';
	wp_nonce_field('newsletter_shortcode_nonce', 'newsletter_nonce_field');
	echo '<button type="submit" class="newsletter-submit-button">' . esc_html($atts['button_text']) . '</button>';
	echo '</div>';
	echo '<div class="newsletter-form-feedback" style="display:none;"></div>';
	echo '</form>';

	// Show past newsletters link if enabled
	if ($atts['show_past_link'] === 'true') {
		$archive_url = get_post_type_archive_link('newsletter');
		if ($archive_url) {
			echo '<p class="newsletter-past-link">';
			echo '<a href="' . esc_url($archive_url) . '">' . esc_html__('See past newsletters', 'extrachill-newsletter') . '</a>';
			echo '</p>';
		}
	}

	echo '</div>';

	// Add JavaScript for form handling
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		const form = document.getElementById('<?php echo esc_js($form_id); ?>');
		if (form) {
			form.addEventListener('submit', function(e) {
				e.preventDefault();

				const submitButton = form.querySelector('button[type="submit"]');
				const feedback = form.querySelector('.newsletter-form-feedback');
				const emailInput = form.querySelector('input[name="email"]');
				const formData = new FormData(form);

				// Disable button and show loading state
				submitButton.disabled = true;
				const originalText = submitButton.textContent;
				submitButton.textContent = '<?php echo esc_js(__('Subscribing...', 'extrachill-newsletter')); ?>';
				feedback.style.display = 'none';

				fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
					method: 'POST',
					body: formData
				})
				.then(response => response.json())
				.then(data => {
					feedback.style.display = 'block';

					if (data.success) {
						feedback.textContent = data.data || '<?php echo esc_js(__('Successfully subscribed!', 'extrachill-newsletter')); ?>';
						feedback.className = 'newsletter-form-feedback success';
						emailInput.value = '';
					} else {
						feedback.textContent = data.data || '<?php echo esc_js(__('Subscription failed. Please try again.', 'extrachill-newsletter')); ?>';
						feedback.className = 'newsletter-form-feedback error';
					}

					// Reset button
					submitButton.disabled = false;
					submitButton.textContent = originalText;
				})
				.catch(error => {
					feedback.style.display = 'block';
					feedback.textContent = '<?php echo esc_js(__('An error occurred. Please try again.', 'extrachill-newsletter')); ?>';
					feedback.className = 'newsletter-form-feedback error';

					// Reset button
					submitButton.disabled = false;
					submitButton.textContent = originalText;
				});
			});
		}
	});
	</script>
	<?php

	// Return buffered content
	return ob_get_clean();
}
add_shortcode('newsletter_subscription_form', 'newsletter_subscription_form_shortcode');

/**
 * Newsletter Archive Link Shortcode
 *
 * Simple shortcode to display a link to the newsletter archive.
 * Useful for navigation and content areas.
 *
 * @since 1.0.0
 * @param array $atts Shortcode attributes
 * @return string HTML output for archive link
 */
function newsletter_archive_link_shortcode($atts = array()) {
	// Parse shortcode attributes with defaults
	$atts = shortcode_atts(array(
		'text' => 'View All Newsletters',
		'css_class' => 'newsletter-archive-link'
	), $atts, 'newsletter_archive_link');

	$archive_url = get_post_type_archive_link('newsletter');

	if (!$archive_url) {
		return '';
	}

	return sprintf(
		'<a href="%s" class="%s">%s</a>',
		esc_url($archive_url),
		esc_attr($atts['css_class']),
		esc_html($atts['text'])
	);
}
add_shortcode('newsletter_archive_link', 'newsletter_archive_link_shortcode');

/**
 * Newsletter Count Shortcode
 *
 * Displays the total number of published newsletters.
 * Useful for statistics and "about" sections.
 *
 * @since 1.0.0
 * @param array $atts Shortcode attributes
 * @return string Newsletter count
 */
function newsletter_count_shortcode($atts = array()) {
	// Parse shortcode attributes with defaults
	$atts = shortcode_atts(array(
		'format' => 'number', // 'number', 'text'
		'text_format' => '%d newsletters published'
	), $atts, 'newsletter_count');

	$newsletter_count = wp_count_posts('newsletter');
	$published_count = $newsletter_count->publish;

	if ($atts['format'] === 'text') {
		return sprintf(esc_html($atts['text_format']), $published_count);
	}

	return $published_count;
}
add_shortcode('newsletter_count', 'newsletter_count_shortcode');

/**
 * AJAX Handler: Newsletter Shortcode Form Submission
 *
 * Handles subscription requests from shortcode forms.
 * Supports different list targeting based on shortcode parameters.
 *
 * @since 1.0.0
 */
function handle_submit_newsletter_shortcode_form() {
	// Verify nonce
	if (!check_ajax_referer('newsletter_shortcode_nonce', 'newsletter_nonce_field', false)) {
		wp_send_json_error(__('Security check failed', 'extrachill-newsletter'));
		return;
	}

	// Sanitize and validate email
	$email = sanitize_email($_POST['email']);
	if (!is_email($email)) {
		wp_send_json_error(__('Please enter a valid email address', 'extrachill-newsletter'));
		return;
	}

	// Get list parameter (default to archive)
	$list = sanitize_text_field($_POST['list']);
	if (!in_array($list, array('archive', 'popup', 'homepage', 'main'))) {
		$list = 'archive';
	}

	// Subscribe to specified list
	$result = subscribe_email_to_sendy($email, $list);

	if ($result['success']) {
		wp_send_json_success($result['message']);
	} else {
		wp_send_json_error($result['message']);
	}
}
add_action('wp_ajax_submit_newsletter_shortcode_form', 'handle_submit_newsletter_shortcode_form');
add_action('wp_ajax_nopriv_submit_newsletter_shortcode_form', 'handle_submit_newsletter_shortcode_form');

/**
 * Register newsletter shortcodes with WordPress
 *
 * Ensures all newsletter shortcodes are available for use in
 * content, widgets, and template files.
 *
 * @since 1.0.0
 */
function register_newsletter_shortcodes() {
	// Additional shortcode registration if needed
	// All shortcodes are registered with add_shortcode() calls above

	// Allow shortcodes in text widgets
	add_filter('widget_text', 'do_shortcode');

	// Allow shortcodes in custom fields (if needed)
	// add_filter('the_content', 'do_shortcode');
}
add_action('init', 'register_newsletter_shortcodes');

/**
 * Newsletter shortcode help documentation
 *
 * Provides usage examples and parameter documentation for
 * newsletter shortcodes. Can be displayed in admin help sections.
 *
 * @since 1.0.0
 * @return array Shortcode documentation
 */
function get_newsletter_shortcode_help() {
	return array(
		'recent_newsletters' => array(
			'description' => 'Display a list of recent newsletters',
			'parameters' => array(
				'count' => 'Number of newsletters to show (default: 3)',
				'show_dates' => 'Show publication dates (default: true)',
				'show_view_all' => 'Show "View All" link (default: true)',
				'title' => 'Widget title (default: "Recent Newsletters")'
			),
			'example' => '[recent_newsletters count="5" title="Latest Updates"]'
		),
		'newsletter_subscription_form' => array(
			'description' => 'Display a newsletter subscription form',
			'parameters' => array(
				'list' => 'Sendy list to subscribe to (archive, popup, homepage, main)',
				'title' => 'Form title',
				'description' => 'Form description text',
				'placeholder' => 'Email input placeholder text',
				'button_text' => 'Submit button text',
				'show_past_link' => 'Show link to past newsletters (default: true)'
			),
			'example' => '[newsletter_subscription_form title="Stay Updated" button_text="Join Us"]'
		),
		'newsletter_archive_link' => array(
			'description' => 'Display a link to the newsletter archive',
			'parameters' => array(
				'text' => 'Link text (default: "View All Newsletters")',
				'css_class' => 'CSS class for styling'
			),
			'example' => '[newsletter_archive_link text="Browse Past Issues"]'
		),
		'newsletter_count' => array(
			'description' => 'Display the total number of published newsletters',
			'parameters' => array(
				'format' => 'Output format: "number" or "text"',
				'text_format' => 'Text format with %d placeholder for count'
			),
			'example' => '[newsletter_count format="text" text_format="We\'ve published %d newsletters!"]'
		)
	);
}