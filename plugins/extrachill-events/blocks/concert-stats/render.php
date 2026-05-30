<?php
/**
 * Concert Stats Block — Server Render
 *
 * Outputs a container div that React hydrates into.
 * Passes user context via data attributes.
 * Shows a loading skeleton while JS initializes.
 *
 * #110: When the block is rendering on /my-shows/ for a logged-in
 * owner, also emit a server-rendered data-machine-events calendar
 * block (month-grid mode) inside a sibling wrapper. The React app
 * toggles the sibling's `hidden` attribute when the Calendar tab is
 * active — no client-side calendar fetch required, the
 * `data_machine_events_calendar_query_args` filter callback in
 * `inc/core/my-shows-calendar-filter.php` scopes the underlying
 * WP_Query to events the user has marked attended.
 *
 * #111: Same pattern for the Map tab. Emits a data-machine-events
 * events-map block in `chronologicalRouteMode`. The filter callbacks
 * in `inc/core/my-shows-map-filter.php` scope the venue set to the
 * user's tracked venues (`data_machine_events_map_query_args`) and
 * attach per-venue tracked-event payloads in chronological order
 * (`data_machine_events_map_venues`), so the polyline + multi-date
 * popups render against the user's attendance timeline.
 *
 * Embedded blocks are rendered as *siblings* (not children) of the
 * React root so React's hydration / unmount cycles don't wipe them.
 * All nodes live inside a `<div class="ec-concert-stats-shell">`
 * outer container that the block wrapper attributes attach to.
 *
 * @package ExtraChillEvents
 * @since 0.18.0
 */

$user_id = ! empty( $attributes['userId'] ) ? (int) $attributes['userId'] : get_current_user_id();

// #126: when there's no explicit `userId` attribute (the usual case
// on /my-shows/) and the visitor is logged out, render a public
// marketing surface instead of nothing. The old behavior — return
// empty + force-redirect at template_redirect — left anonymous
// visitors with zero context. The marketing markup is intentionally
// server-rendered (no React) so search engines and link previews see
// a real page. Canonical @extrachill/components class names are
// emitted by hand so the same SCSS that styles the React-side
// primitives (imported at the top of style.scss) styles this surface
// for free.
if ( ! $user_id ) {
	if ( ! is_user_logged_in() ) {
		// Signup lives on the community site (canonical registration
		// surface for the platform). Login lives on the events site
		// itself when the My Shows page is being viewed; keep the
		// same `redirect_to=/my-shows/` pattern the deleted
		// auth-gate used so post-login the user lands back here.
		$signup_url = function_exists( 'ec_get_site_url' )
			? trailingslashit( ec_get_site_url( 'community' ) ) . 'register/'
			: wp_registration_url();
		$login_url  = function_exists( 'ec_get_site_url' )
			? trailingslashit( ec_get_site_url( 'events' ) ) . 'login/?redirect_to=' . rawurlencode( home_url( '/my-shows/' ) )
			: wp_login_url( home_url( '/my-shows/' ) );

		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class' => 'ec-concert-stats-shell ec-concert-stats-shell--marketing',
			)
		);
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via get_block_wrapper_attributes. ?>>
			<div class="ec-block-shell ec-block-shell--depth-0 ec-mobile-full-width-panel">
				<div class="ec-block-shell-inner ec-block-shell-inner--narrow">
					<div class="ec-block-shell-header">
						<div class="ec-block-shell-header__main">
							<div class="ec-block-shell-header__title">
								<?php esc_html_e( 'Your concert history, in one place', 'extrachill-events' ); ?>
							</div>
							<div class="ec-block-shell-header__description">
								<?php esc_html_e( 'My Shows lets you track every concert you\'ve been to and every one you\'re going to.', 'extrachill-events' ); ?>
							</div>
						</div>
					</div>
					<div class="ec-panel ec-panel--depth-1">
						<div class="ec-section ec-section--depth-2">
							<h3><?php esc_html_e( 'What you can do', 'extrachill-events' ); ?></h3>
							<ul>
								<li><?php esc_html_e( 'Mark events as \'Going\' or \'I Was There\' — across decades of past shows', 'extrachill-events' ); ?></li>
								<li><?php esc_html_e( 'See your concert history on a calendar and a map, with chronological tour routes for the artists you\'ve followed', 'extrachill-events' ); ?></li>
								<li><?php esc_html_e( 'Import your full history from setlist.fm or phish.net', 'extrachill-events' ); ?></li>
								<li><?php esc_html_e( 'Stats: shows, venues, artists, cities', 'extrachill-events' ); ?></li>
							</ul>
						</div>
						<div class="ec-action-row ec-action-row--center">
							<a href="<?php echo esc_url( $signup_url ); ?>" class="button-1 button-large">
								<?php esc_html_e( 'Sign Up', 'extrachill-events' ); ?>
							</a>
							<a href="<?php echo esc_url( $login_url ); ?>" class="button-2 button-large">
								<?php esc_html_e( 'Log In', 'extrachill-events' ); ?>
							</a>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
		return;
	}
	$user_id = get_current_user_id();
}

$blog_id    = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : get_current_blog_id();
$events_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'events' ) : home_url();
$is_own     = is_user_logged_in() && get_current_user_id() === $user_id;

// #110: Calendar tab is owner-only, and the server-side filter callback
// only activates on /my-shows/. Match both conditions before emitting
// the embedded calendar block so we don't ship dead markup elsewhere.
$on_my_shows              = function_exists( 'is_page' ) && is_page( 'my-shows' );
$render_embedded_calendar = $is_own && $on_my_shows;

// #111: Same gate for the Map tab. The events-map block in
// chronologicalRouteMode depends on the my-shows-map-filter callbacks,
// which are themselves gated on is_page('my-shows') + logged-in owner.
$render_embedded_map = $is_own && $on_my_shows;

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'             => 'ec-concert-stats-shell',
		'data-has-calendar' => esc_attr( $render_embedded_calendar ? '1' : '0' ),
		'data-has-map'      => esc_attr( $render_embedded_map ? '1' : '0' ),
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via get_block_wrapper_attributes. ?>>
	<div
		class="ec-concert-stats"
		data-user-id="<?php echo esc_attr( $user_id ); ?>"
		data-blog-id="<?php echo esc_attr( $blog_id ); ?>"
		data-events-url="<?php echo esc_attr( $events_url ); ?>"
		data-is-own="<?php echo esc_attr( $is_own ? '1' : '0' ); ?>"
		data-has-calendar="<?php echo esc_attr( $render_embedded_calendar ? '1' : '0' ); ?>"
		data-has-map="<?php echo esc_attr( $render_embedded_map ? '1' : '0' ); ?>"
	>
		<div class="ec-concert-stats__loading">
			<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--header"></div>
			<div class="ec-concert-stats__skeleton-row">
				<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--stat"></div>
				<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--stat"></div>
				<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--stat"></div>
				<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--stat"></div>
			</div>
			<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--tabs"></div>
			<div class="ec-concert-stats__skeleton ec-concert-stats__skeleton--list"></div>
		</div>
	</div>

	<?php if ( $render_embedded_calendar ) : ?>
		<div class="ec-concert-stats__embedded-calendar" data-tab="calendar" hidden>
			<?php
			// The filter in inc/core/my-shows-calendar-filter.php
			// auto-gates on is_page('my-shows'), so we don't need to
			// manage filter add/remove around this do_blocks() call —
			// the gate prevents leaking the My-Shows constraint into
			// any other calendar instance that might (theoretically)
			// also render on this same page.
			//
			// showFilters / showSearch / showDateFilter disabled — the
			// concert-stats block already owns the surrounding chrome
			// (year filter in the header, tabs); a second filter bar
			// would be redundant. The calendar block's own
			// prev/next/today nav still renders.
			echo do_blocks( '<!-- wp:data-machine-events/calendar {"displayMode":"month-grid","showFilters":false,"showSearch":false,"showDateFilter":false} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block render output is trusted.
			?>
		</div>
	<?php endif; ?>

	<?php if ( $render_embedded_map ) : ?>
		<div class="ec-concert-stats__embedded-map" data-tab="map" hidden>
			<?php
			// Same auto-gating pattern as the calendar wrapper above.
			// The my-shows-map-filter callbacks scope the venue set +
			// per-venue events to the user's tracked shows; the events-map
			// block stays generic and unaware of the concert-tracking
			// table. chronologicalRouteMode = true: polyline + first/last
			// marker styling + multi-date popups against the user's
			// attendance timeline. height=500 matches the calendar
			// block's vertical footprint so tab swapping doesn't shift
			// page layout.
			echo do_blocks( '<!-- wp:data-machine-events/events-map {"chronologicalRouteMode":true,"height":500} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block render output is trusted.
			?>
		</div>
	<?php endif; ?>
</div>
