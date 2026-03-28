<?php
/**
 * Concert tracking button rendering and asset loading.
 *
 * Renders the attendance toggle button on event detail pages.
 * Uses the theme's button class system (button-2/button-3 + button-large)
 * to stay consistent with ticket and share buttons in the action row.
 *
 * The button label is derived from event timing:
 *   - Upcoming → "Going"
 *   - Ongoing  → "Check In"
 *   - Past     → "I Was There"
 *
 * @package ExtraChill\Users
 * @since 0.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the attendance button for an event.
 *
 * Called from extrachill-events integration hook, which fires inside
 * the data_machine_events_action_buttons action in the Event Details block.
 *
 * @param int $event_id Event post ID.
 */
function ec_users_render_attendance_button( int $event_id ) {
	$blog_id = get_current_blog_id();
	$timing  = ec_users_get_event_timing( $event_id );
	$count   = ec_users_get_event_mark_count( $event_id, $blog_id );

	// Determine button label based on timing.
	$labels = array(
		'upcoming' => array(
			'default' => __( 'Going', 'extrachill-users' ),
			'active'  => __( 'Going', 'extrachill-users' ),
		),
		'ongoing'  => array(
			'default' => __( 'Check In', 'extrachill-users' ),
			'active'  => __( 'Checked In', 'extrachill-users' ),
		),
		'past'     => array(
			'default' => __( 'I Was There', 'extrachill-users' ),
			'active'  => __( 'I Was There', 'extrachill-users' ),
		),
	);

	$label_set   = $labels[ $timing ] ?? $labels['past'];
	$is_marked   = false;
	$action      = 'login';
	$count_label = ec_users_format_count_label( $count, $timing );

	if ( is_user_logged_in() ) {
		$is_marked = ec_users_is_event_marked( get_current_user_id(), $event_id, $blog_id );
		$action    = 'toggle';
	}

	$button_label = $is_marked ? $label_set['active'] : $label_set['default'];

	// Theme button classes: button-2 (green accent) when active, button-3 (neutral) when not.
	$button_class = $is_marked ? 'button-2' : 'button-3';
	$marked_class = $is_marked ? ' ec-attendance--marked' : '';

	?>
	<div class="ec-attendance<?php echo esc_attr( $marked_class ); ?>"
		 data-event-id="<?php echo esc_attr( $event_id ); ?>"
		 data-blog-id="<?php echo esc_attr( $blog_id ); ?>"
		 data-timing="<?php echo esc_attr( $timing ); ?>"
		 data-label-default="<?php echo esc_attr( $label_set['default'] ); ?>"
		 data-label-active="<?php echo esc_attr( $label_set['active'] ); ?>">
		<button class="ec-attendance__button <?php echo esc_attr( $button_class ); ?> button-large"
				data-action="<?php echo esc_attr( $action ); ?>"
				type="button">
			<?php if ( $is_marked ) : ?>
				<span class="ec-attendance__check" aria-hidden="true">&#10003;</span>
			<?php endif; ?>
			<span class="ec-attendance__label"><?php echo esc_html( $button_label ); ?></span>
		</button>
		<?php if ( $count > 0 ) : ?>
			<span class="ec-attendance__count"><?php echo esc_html( $count_label ); ?></span>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Enqueue concert tracking assets on single event pages.
 */
function ec_users_enqueue_concert_tracking_assets() {
	// Only load on single event pages within the events site.
	if ( ! is_singular( 'data_machine_events' ) ) {
		return;
	}

	$css_path = EXTRACHILL_USERS_PLUGIN_DIR . 'assets/css/concert-tracking.css';
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'extrachill-users-concert-tracking',
			EXTRACHILL_USERS_PLUGIN_URL . 'assets/css/concert-tracking.css',
			array(),
			filemtime( $css_path ),
			'all'
		);
	}

	$js_path = EXTRACHILL_USERS_PLUGIN_DIR . 'assets/js/concert-tracking.js';
	if ( file_exists( $js_path ) ) {
		wp_enqueue_script(
			'extrachill-users-concert-tracking',
			EXTRACHILL_USERS_PLUGIN_URL . 'assets/js/concert-tracking.js',
			array( 'wp-api-fetch' ),
			filemtime( $js_path ),
			true
		);

		wp_localize_script(
			'extrachill-users-concert-tracking',
			'ecConcertTracking',
			array(
				'loginUrl'   => wp_login_url(),
				'isLoggedIn' => is_user_logged_in(),
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'ec_users_enqueue_concert_tracking_assets' );
