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
 * This is a flexible feature-PROVIDER renderer. Page composition (button
 * ordering, when to show the import nudge, whether to de-emphasize the ticket
 * CTA) is NOT this function's concern — that lives in the composition layer
 * (extrachill-events). This renderer only exposes the presentation variants and
 * framing hooks the composition layer can arrange.
 *
 * All presentation/framing options travel in a single $args config array so the
 * composition layer selects the variant and overrides copy in one place. An
 * empty $args renders the standard inline toggle — that's the sensible default
 * for the primary call path, not a compatibility shim.
 *
 * @param int   $event_id Event post ID.
 * @param array $args     Presentation + framing config. Recognised keys:
 *                       - 'variant' (string) Presentation variant. Supported:
 *                         'default' (or omitted) — the standard inline
 *                         attendance toggle; 'past-hero' — an attendance-first
 *                         "hero" presentation with archive payoff framing,
 *                         intended for the peak "I was at this show" past-event
 *                         audience.
 *                       - 'hero_heading'      (string) Hero title copy. Only
 *                         used by the 'past-hero' variant.
 *                       - 'hero_subheading'   (string) Hero payoff copy. Only
 *                         used by the 'past-hero' variant.
 *                       - 'logged_out_heading'  (string) Signup-framed title
 *                         shown to logged-out visitors (composition can frame
 *                         this as "build your concert archive" instead of a
 *                         generic login wall).
 *                       - 'logged_out_subheading' (string) Signup-framed
 *                         sub-copy for logged-out visitors.
 *                       - 'redirect_to'       (string) URL to return the user
 *                         to after login/signup. Defaults to the current
 *                         request URL (JS resolves this at click time when
 *                         omitted).
 */
function ec_users_render_attendance_button( int $event_id, array $args = array() ) {
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

	$variant = isset( $args['variant'] ) && '' !== $args['variant']
		? (string) $args['variant']
		: 'default';

	// 'past-hero' wraps the standard mount in an attendance-first presentation
	// with archive payoff framing. The inner mount markup is the same as the
	// default path so the same React component (#ec-attendance-root) hydrates it
	// unchanged — only the surrounding presentation/copy differs.
	$is_hero = ( 'past-hero' === $variant );

	if ( $is_hero ) {
		if ( $is_logged_in ) {
			$heading = isset( $args['hero_heading'] ) && '' !== $args['hero_heading']
				? (string) $args['hero_heading']
				: __( 'Were you at this show?', 'extrachill-users' );
			$subhead = isset( $args['hero_subheading'] ) && '' !== $args['hero_subheading']
				? (string) $args['hero_subheading']
				: __( 'Add this show to your concert archive.', 'extrachill-users' );
		} else {
			// Logged-out: frame around building an archive (signup payoff) rather
			// than a generic login wall. Copy is caller-overridable.
			$heading = isset( $args['logged_out_heading'] ) && '' !== $args['logged_out_heading']
				? (string) $args['logged_out_heading']
				: __( 'Were you at this show?', 'extrachill-users' );
			$subhead = isset( $args['logged_out_subheading'] ) && '' !== $args['logged_out_subheading']
				? (string) $args['logged_out_subheading']
				: __( 'Sign up to build your concert archive — every show you\'ve seen, in one place.', 'extrachill-users' );
		}

		?>
		<div class="ec-attendance-hero">
			<p class="ec-attendance-hero__heading"><?php echo esc_html( $heading ); ?></p>
			<p class="ec-attendance-hero__subheading"><?php echo esc_html( $subhead ); ?></p>
			<?php
			ec_users_render_attendance_mount(
				$event_id,
				$blog_id,
				$timing,
				$is_marked,
				$count,
				$count_label,
				$is_logged_in,
				$label_set,
				$button_label,
				$button_class,
				$marked_class,
				$args
			);
			?>
		</div>
		<?php
		ec_users_render_event_attendees( $event_id, $blog_id );
		return;
	}

	// Default variant — the standard inline attendance toggle.
	ec_users_render_attendance_mount(
		$event_id,
		$blog_id,
		$timing,
		$is_marked,
		$count,
		$count_label,
		$is_logged_in,
		$label_set,
		$button_label,
		$button_class,
		$marked_class,
		$args
	);
	ec_users_render_event_attendees( $event_id, $blog_id );
}

/**
 * Render the attendance mount root (first-paint markup + React hydration hook).
 *
 * Extracted from ec_users_render_attendance_button() so every presentation
 * variant shares one canonical mount. A data-redirect-to attribute is emitted
 * only when a caller passes an explicit redirect override; otherwise the React
 * component falls back to the current request URL at click time.
 *
 * @param int    $event_id     Event post ID.
 * @param int    $blog_id      Blog ID.
 * @param string $timing       Event timing bucket.
 * @param bool   $is_marked    Whether the current user has marked attendance.
 * @param int    $count        Attendee count.
 * @param string $count_label  Formatted count label.
 * @param bool   $is_logged_in Whether a user is logged in.
 * @param array  $label_set    Default/active label pair for this timing.
 * @param string $button_label Resolved button label.
 * @param string $button_class Theme button class.
 * @param string $marked_class Extra container class when marked.
 * @param array  $args         Framing overrides (see ec_users_render_attendance_button()).
 */
function ec_users_render_attendance_mount(
	int $event_id,
	int $blog_id,
	string $timing,
	bool $is_marked,
	int $count,
	string $count_label,
	bool $is_logged_in,
	array $label_set,
	string $button_label,
	string $button_class,
	string $marked_class,
	array $args = array()
) {
	// Optional explicit post-login redirect target. When omitted the React
	// component falls back to the current request URL (its existing behavior),
	// so the default markup stays unchanged.
	$redirect_to = isset( $args['redirect_to'] ) && '' !== $args['redirect_to']
		? (string) $args['redirect_to']
		: '';

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
		<?php if ( '' !== $redirect_to ) : ?>
		data-redirect-to="<?php echo esc_attr( $redirect_to ); ?>"
		<?php endif; ?>
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
 * Resolve the deep-link URL into the concert-import flow on /my-shows/.
 *
 * The import UI lives on the events site's /my-shows/ page, surfaced as the
 * "Import" tab of the concert-stats block. That block reads the active tab from
 * the `tab` query arg (see its view.js), so `?tab=import` lands a logged-in user
 * directly in the import flow. Logged-out users are sent through login first
 * with the same destination preserved as the post-login redirect.
 *
 * @return string Absolute URL into the import flow, or '' if the events site
 *                cannot be resolved.
 */
function ec_users_concert_import_url(): string {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;

	if ( $events_blog_id > 0 && function_exists( 'get_home_url' ) ) {
		$base = (string) get_home_url( $events_blog_id, '/my-shows/' );
	} else {
		$base = home_url( '/my-shows/' );
	}

	$import_url = add_query_arg( 'tab', 'import', $base );

	// Logged-out visitors can't reach a personal page directly — route them
	// through login and bounce back into the import flow afterward.
	if ( ! is_user_logged_in() ) {
		return wp_login_url( $import_url );
	}

	return $import_url;
}

/**
 * Render the compact "import your concert history" nudge module.
 *
 * Self-contained feature-PROVIDER partial. It only RENDERS the nudge and wires
 * it to the existing setlist.fm / phish.net import flow (via the /my-shows/
 * import tab). The composition layer (extrachill-events) decides WHERE and WHEN
 * to call it — e.g. after a logged-in user marks "I Was There" on a past event.
 * This function makes no placement or timing decisions.
 *
 * @param array $args Optional copy overrides. Recognised keys:
 *                   - 'heading' (string) Nudge title. Defaults to
 *                     "Been to more shows?".
 *                   - 'body'    (string) Supporting copy. Defaults to a prompt
 *                     to import concert history from setlist.fm / phish.net.
 *                   - 'cta'     (string) Call-to-action label. Defaults to
 *                     "Import your concert history".
 */
function ec_users_render_import_nudge( array $args = array() ) {
	$import_url = ec_users_concert_import_url();
	if ( '' === $import_url ) {
		return;
	}

	$heading = isset( $args['heading'] ) && '' !== $args['heading']
		? (string) $args['heading']
		: __( 'Been to more shows?', 'extrachill-users' );
	$body    = isset( $args['body'] ) && '' !== $args['body']
		? (string) $args['body']
		: __( 'Pull your full concert history from setlist.fm or phish.net — every show, matched to Extra Chill events automatically.', 'extrachill-users' );
	$cta     = isset( $args['cta'] ) && '' !== $args['cta']
		? (string) $args['cta']
		: __( 'Import your concert history', 'extrachill-users' );

	?>
	<div class="ec-import-nudge">
		<div class="ec-import-nudge__body">
			<p class="ec-import-nudge__heading"><?php echo esc_html( $heading ); ?></p>
			<p class="ec-import-nudge__text"><?php echo esc_html( $body ); ?></p>
		</div>
		<a class="ec-import-nudge__cta button-1 button-medium" href="<?php echo esc_url( $import_url ); ?>">
			<?php echo esc_html( $cta ); ?>
		</a>
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
	$script_path = EXTRACHILL_USERS_PLUGIN_DIR . 'build/concert-attendance/index.js';
	$asset_path  = EXTRACHILL_USERS_PLUGIN_DIR . 'build/concert-attendance/index.asset.php';

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
