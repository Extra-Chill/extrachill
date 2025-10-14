<?php
/**
 * Newsletter Hook Functions
 *
 * Provides hook-based newsletter display functions for integration
 * with the ExtraChill theme's action-based architecture.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sidebar Recent Newsletters Hook Function
 *
 * Clean hook-based function for displaying recent newsletters in sidebar.
 * Outputs HTML directly for use with theme action hooks.
 *
 * @since 1.0.0
 */
if ( ! function_exists( 'extrachill_sidebar_recent_newsletters' ) ) :
	function extrachill_sidebar_recent_newsletters() {
		// Query for recent newsletters
		$newsletter_args = array(
			'post_type' => 'newsletter',
			'posts_per_page' => 3,
			'post_status' => 'publish',
			'orderby' => 'date',
			'order' => 'DESC',
		);

		$newsletter_query = new WP_Query($newsletter_args);

		if ($newsletter_query->have_posts()) {
			echo '<div class="sidebar-card recent-newsletters-widget">';
			echo '<h3 class="widget-title">' . esc_html__('Recent Newsletters', 'extrachill-newsletter') . '</h3>';
			echo '<ul class="recent-newsletters-list">';

			while ($newsletter_query->have_posts()) {
				$newsletter_query->the_post();
				echo '<li>';
				echo '<a href="' . esc_url(get_permalink()) . '" class="newsletter-link">';
				echo '<strong>' . esc_html(get_the_title()) . '</strong>';
				echo '<span class="newsletter-date">' . esc_html(get_the_date()) . '</span>';
				echo '</a>';
				echo '</li>';
			}

			echo '</ul>';

			// View all link
			$newsletter_archive_url = get_post_type_archive_link('newsletter');
			if ($newsletter_archive_url) {
				echo '<a href="' . esc_url($newsletter_archive_url) . '" class="view-all-link">';
				echo esc_html__('View All Newsletters', 'extrachill-newsletter') . ' →';
				echo '</a>';
			}

			echo '</div>';
		}

		// Reset post data
		wp_reset_postdata();
	}
endif;

// Hook into sidebar bottom
add_action( 'extrachill_sidebar_bottom', 'extrachill_sidebar_recent_newsletters', 10 );

/**
 * Newsletter Archive Subscription Form
 *
 * Displays subscription form on newsletter archive pages only.
 * Hooks into theme's archive template between description and posts.
 *
 * @since 1.0.0
 */
function extrachill_newsletter_archive_form() {
	if ( ! is_post_type_archive( 'newsletter' ) ) {
		return;
	}

	$settings = get_site_option( 'extrachill_newsletter_settings', array() );
	if ( empty( $settings['enable_archive'] ) ) {
		return;
	}
	?>
	<div class="newsletter-subscribe-form">
		<h2><?php _e( 'Subscribe to Our Newsletter', 'extrachill-newsletter' ); ?></h2>
		<form id="newsletterArchiveForm" class="newsletter-form newsletter-section-form">
			<label for="newsletter_archive_email"><?php _e( 'Email:', 'extrachill-newsletter' ); ?></label><br>
			<input type="email" id="newsletter_archive_email" name="email" required>
			<input type="hidden" name="action" value="submit_newsletter_form">
			<?php wp_nonce_field( 'newsletter_nonce', 'newsletter_nonce_field' ); ?>
			<button type="submit" class="submit-button"><?php _e( 'Subscribe', 'extrachill-newsletter' ); ?></button>
		</form>
		<p><?php _e( 'Explore past Extra Chill newsletters below.', 'extrachill-newsletter' ); ?></p>
	</div>
	<?php
}
add_action( 'extrachill_archive_below_description', 'extrachill_newsletter_archive_form', 10 );

/**
 * Override post meta display for newsletter post type
 *
 * Displays "Sent on {date}" instead of default theme post meta.
 * Assumes: if published, it has been sent (no separate tracking needed).
 *
 * @since 1.0.0
 * @param string $output Default meta HTML
 * @param int $post_id Post ID
 * @param string $post_type Post type
 * @return string Modified meta HTML
 */
function newsletter_custom_post_meta( $output, $post_id, $post_type ) {
	if ( $post_type !== 'newsletter' ) {
		return $output; // Use default for all other post types
	}

	// Published = Sent (core assumption)
	$sent_date = get_the_date( 'F j, Y', $post_id );

	return sprintf(
		'<div class="below-entry-meta newsletter-meta"><div class="below-entry-meta-left"><div class="meta-top-row">Sent on <time class="entry-date published" datetime="%s">%s</time></div></div></div>',
		esc_attr( get_the_date( 'c', $post_id ) ),
		esc_html( $sent_date )
	);
}
add_filter( 'extrachill_post_meta', 'newsletter_custom_post_meta', 10, 3 );