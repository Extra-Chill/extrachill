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

	$label_set    = $labels[ $timing ] ?? $labels['past'];
	$is_logged_in = is_user_logged_in();
	$is_marked    = false;
	$count_label  = ec_users_format_count_label( $count, $timing );

	if ( $is_logged_in ) {
		$is_marked = ec_users_is_event_marked( get_current_user_id(), $event_id, $blog_id );
	}

	$button_label = $is_marked ? $label_set['active'] : $label_set['default'];

	// Theme button classes: button-2 (green accent) when marked, button-3 (neutral) when not.
	$button_class = $is_marked ? 'button-2' : 'button-3';
	$marked_class = $is_marked ? ' ec-attendance--marked' : '';

	// Server-render the first-paint markup so the button is visible (and
	// SEO/no-JS friendly) before the React mount hydrates. The mount root
	// carries the initial state as data attributes; the React component
	// (blocks/concert-attendance) owns all interaction after hydration —
	// there is no imperative data-action control surface anymore.
	?>
	<div id="ec-attendance-root"
		class="ec-attendance<?php echo esc_attr( $marked_class ); ?>"
		data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
		data-blog-id="<?php echo esc_attr( (string) $blog_id ); ?>"
		data-timing="<?php echo esc_attr( $timing ); ?>"
		data-marked="<?php echo $is_marked ? '1' : '0'; ?>"
		data-count="<?php echo esc_attr( (string) $count ); ?>"
		data-count-label="<?php echo esc_attr( $count > 0 ? $count_label : '' ); ?>"
		data-is-logged-in="<?php echo $is_logged_in ? '1' : '0'; ?>"
		data-login-url="<?php echo esc_attr( wp_login_url() ); ?>"
		data-label-default="<?php echo esc_attr( $label_set['default'] ); ?>"
		data-label-active="<?php echo esc_attr( $label_set['active'] ); ?>">
		<button class="ec-attendance__button <?php echo esc_attr( $button_class ); ?> button-medium"
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
	ec_users_render_event_attendees( $event_id, $blog_id );
}

/**
 * Render the "who's going / who was there" attendee strip for an event.
 *
 * Server-rendered (SEO-friendly, no JS dependency) avatar row linking each
 * attendee to their community profile — turning the events page into a feeder
 * for the community "living room" and surfacing the attendee social graph that
 * the get-event-attendance ability already computes. Skips silently when there
 * are no attendees.
 *
 * @param int $event_id Event post ID.
 * @param int $blog_id  Blog ID.
 */
function ec_users_render_event_attendees( int $event_id, int $blog_id ) {
	if ( ! function_exists( 'ec_users_get_event_attendees' ) ) {
		return;
	}

	$attendees = ec_users_get_event_attendees( $event_id, $blog_id, 12 );
	if ( empty( $attendees ) ) {
		return;
	}

	?>
	<div class="ec-attendance-list">
		<span class="ec-attendance-list__label">
			<?php echo esc_html( _n( 'Going', 'Going', count( $attendees ), 'extrachill-users' ) ); ?>
		</span>
		<ul class="ec-attendance-list__avatars">
			<?php foreach ( $attendees as $attendee ) : ?>
				<?php
				$name   = isset( $attendee['display_name'] ) ? (string) $attendee['display_name'] : '';
				$avatar = isset( $attendee['avatar_url'] ) ? (string) $attendee['avatar_url'] : '';
				$url    = isset( $attendee['profile_url'] ) ? (string) $attendee['profile_url'] : '';
				if ( '' === $avatar ) {
					continue;
				}
				$img = sprintf(
					'<img class="ec-attendance-list__avatar" src="%s" alt="%s" width="32" height="32" loading="lazy" />',
					esc_url( $avatar ),
					esc_attr( $name )
				);
				?>
				<li class="ec-attendance-list__item">
					<?php if ( '' !== $url ) : ?>
						<a href="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( $name ); ?>">
							<?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url/esc_attr above. ?>
						</a>
					<?php else : ?>
						<?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url/esc_attr above. ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
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
			(string) filemtime( $css_path ),
			'all'
		);
	}

	// Headless React mount for the attendance button. Built via @wordpress/scripts
	// (blocks/concert-attendance/src/index.js → build/concert-attendance). The
	// generated .asset.php supplies dependency handles (wp-element, wp-api-fetch,
	// wp-dom-ready, …) and a content-hash version, so no manual dependency list
	// or window global is needed. Replaces the legacy data-* IIFE.
	$script_path  = EXTRACHILL_USERS_PLUGIN_DIR . 'build/concert-attendance/index.js';
	$asset_path   = EXTRACHILL_USERS_PLUGIN_DIR . 'build/concert-attendance/index.asset.php';

	if ( file_exists( $script_path ) && file_exists( $asset_path ) ) {
		$asset = require $asset_path;

		wp_enqueue_script(
			'extrachill-users-concert-attendance',
			EXTRACHILL_USERS_PLUGIN_URL . 'build/concert-attendance/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations(
			'extrachill-users-concert-attendance',
			'extrachill-users'
		);
	}
}
add_action( 'wp_enqueue_scripts', 'ec_users_enqueue_concert_tracking_assets' );
