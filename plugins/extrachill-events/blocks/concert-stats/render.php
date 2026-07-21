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

$on_my_shows = function_exists( 'is_page' ) && is_page( 'my-shows' );
$user_id     = ! empty( $attributes['userId'] ) ? (int) $attributes['userId'] : get_current_user_id();

// Public profile cards link to the canonical My Shows page with the profile
// owner's network user ID. Resolve that selection before the anonymous
// marketing fallback so every viewer sees the requested public history rather
// than their own dashboard (or the logged-out marketing state).
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only public route selection.
if ( $on_my_shows && isset( $_GET['user_id'] ) ) {
	$requested_user_id = is_scalar( $_GET['user_id'] )
		? absint( wp_unslash( $_GET['user_id'] ) )
		: 0;

	if ( $requested_user_id < 1 || ! get_userdata( $requested_user_id ) ) {
		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class' => 'ec-concert-stats-shell ec-concert-stats-shell--invalid-user',
			)
		);
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via get_block_wrapper_attributes. ?>>
			<div class="ec-block-shell ec-block-shell--depth-0 ec-mobile-full-width-panel">
				<div class="ec-block-shell-inner ec-block-shell-inner--narrow">
					<div class="ec-inline-status ec-inline-status--error" role="status">
						<?php esc_html_e( 'This concert history could not be found.', 'extrachill-events' ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
		return;
	}

	$user_id = $requested_user_id;
}
// phpcs:enable WordPress.Security.NonceVerification.Recommended

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
		$docs_url   = function_exists( 'ec_get_site_url' )
			? trailingslashit( ec_get_site_url( 'docs' ) ) . 'events-calendar/'
			: 'https://docs.extrachill.com/events-calendar/';

		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class' => 'ec-concert-stats-shell ec-concert-stats-shell--marketing',
			)
		);
		?>
		<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via get_block_wrapper_attributes. ?>>
			<div class="ec-block-shell ec-block-shell--depth-0 ec-mobile-full-width-panel">
				<div class="ec-block-shell-inner">
					<section class="ec-concert-stats-marketing__hero">
						<div class="ec-concert-stats-marketing__hero-copy">
							<p class="ec-concert-stats-marketing__eyebrow"><?php esc_html_e( 'Your live music life', 'extrachill-events' ); ?></p>
							<h1><?php esc_html_e( 'Every show has a story. Keep yours.', 'extrachill-events' ); ?></h1>
							<p class="ec-concert-stats-marketing__lede">
								<?php esc_html_e( 'My Shows turns the concerts you have seen and the ones still ahead into a personal archive you can explore by date, artist, venue, city, and route.', 'extrachill-events' ); ?>
							</p>
							<div class="ec-action-row">
								<a href="<?php echo esc_url( $signup_url ); ?>" class="button-1 button-large">
									<?php esc_html_e( 'Start My Shows', 'extrachill-events' ); ?>
								</a>
								<a href="#how-it-works" class="button-2 button-large">
									<?php esc_html_e( 'See How It Works', 'extrachill-events' ); ?>
								</a>
							</div>
							<p class="ec-concert-stats-marketing__assurance">
								<?php esc_html_e( 'Free Extra Chill account. You control what other people can see.', 'extrachill-events' ); ?>
							</p>
						</div>
						<div class="ec-concert-stats-marketing__preview" aria-label="<?php esc_attr_e( 'Example concert archive', 'extrachill-events' ); ?>">
							<div class="ec-concert-stats-marketing__preview-header">
								<span><?php esc_html_e( 'Your concert trail', 'extrachill-events' ); ?></span>
								<span class="ec-concert-stats-marketing__preview-status"><?php esc_html_e( 'Always growing', 'extrachill-events' ); ?></span>
							</div>
							<ol class="ec-concert-stats-marketing__trail">
								<li><span>2018</span><div class="ec-concert-stats-marketing__trail-copy"><?php esc_html_e( 'The first one you remember', 'extrachill-events' ); ?></div></li>
								<li><span>2023</span><div class="ec-concert-stats-marketing__trail-copy"><?php esc_html_e( 'The set you still talk about', 'extrachill-events' ); ?></div></li>
								<li><span><?php esc_html_e( 'Next', 'extrachill-events' ); ?></span><div class="ec-concert-stats-marketing__trail-copy"><?php esc_html_e( 'The show already on your calendar', 'extrachill-events' ); ?></div></li>
							</ol>
							<div class="ec-concert-stats-marketing__preview-tabs" aria-hidden="true">
								<span><?php esc_html_e( 'History', 'extrachill-events' ); ?></span>
								<span><?php esc_html_e( 'Calendar', 'extrachill-events' ); ?></span>
								<span><?php esc_html_e( 'Map', 'extrachill-events' ); ?></span>
								<span><?php esc_html_e( 'Stats', 'extrachill-events' ); ?></span>
							</div>
						</div>
					</section>

					<section id="how-it-works" class="ec-concert-stats-marketing__section">
						<p class="ec-concert-stats-marketing__eyebrow"><?php esc_html_e( 'Three simple moves', 'extrachill-events' ); ?></p>
						<h2><?php esc_html_e( 'Build an archive that gets better with every show', 'extrachill-events' ); ?></h2>
						<div class="ec-concert-stats-marketing__steps">
							<article><span>1</span><h3><?php esc_html_e( 'Find a show', 'extrachill-events' ); ?></h3><p><?php esc_html_e( 'Browse upcoming events or search decades of past concerts.', 'extrachill-events' ); ?></p></article>
							<article><span>2</span><h3><?php esc_html_e( 'Mark your place', 'extrachill-events' ); ?></h3><p><?php esc_html_e( 'Choose Going for what is ahead or I Was There for the nights already lived.', 'extrachill-events' ); ?></p></article>
							<article><span>3</span><h3><?php esc_html_e( 'Explore your history', 'extrachill-events' ); ?></h3><p><?php esc_html_e( 'Watch your calendar, map, route, and personal stats take shape.', 'extrachill-events' ); ?></p></article>
						</div>
					</section>

					<section class="ec-concert-stats-marketing__section ec-concert-stats-marketing__feature-grid">
						<article><p class="ec-concert-stats-marketing__feature-label"><?php esc_html_e( 'Remember', 'extrachill-events' ); ?></p><h3><?php esc_html_e( 'A chronological concert archive', 'extrachill-events' ); ?></h3><p><?php esc_html_e( 'Keep upcoming plans and past memories together without losing the details.', 'extrachill-events' ); ?></p></article>
						<article><p class="ec-concert-stats-marketing__feature-label"><?php esc_html_e( 'See', 'extrachill-events' ); ?></p><h3><?php esc_html_e( 'Calendar and map views', 'extrachill-events' ); ?></h3><p><?php esc_html_e( 'Move through years of shows and trace the cities and venues that shaped your route.', 'extrachill-events' ); ?></p></article>
						<article><p class="ec-concert-stats-marketing__feature-label"><?php esc_html_e( 'Learn', 'extrachill-events' ); ?></p><h3><?php esc_html_e( 'Stats with a human pulse', 'extrachill-events' ); ?></h3><p><?php esc_html_e( 'Discover your most-seen artists, most-visited venues, cities, and milestones.', 'extrachill-events' ); ?></p></article>
						<article><p class="ec-concert-stats-marketing__feature-label"><?php esc_html_e( 'Return', 'extrachill-events' ); ?></p><h3><?php esc_html_e( 'A home for what comes next', 'extrachill-events' ); ?></h3><p><?php esc_html_e( 'Keep upcoming shows close and receive an Extra Chill reminder before showtime.', 'extrachill-events' ); ?></p></article>
					</section>

					<section class="ec-concert-stats-marketing__section ec-concert-stats-marketing__split">
						<article class="ec-panel ec-panel--depth-1">
							<p class="ec-concert-stats-marketing__eyebrow"><?php esc_html_e( 'Already have a history?', 'extrachill-events' ); ?></p>
							<h2><?php esc_html_e( 'Bring the whole archive with you', 'extrachill-events' ); ?></h2>
							<p><?php esc_html_e( 'Import attended shows from setlist.fm or phish.net. My Shows matches what it can and adds missing events so the story does not start today.', 'extrachill-events' ); ?></p>
							<a href="<?php echo esc_url( $docs_url . 'importing-concert-history/' ); ?>"><?php esc_html_e( 'How concert imports work', 'extrachill-events' ); ?> &rarr;</a>
						</article>
						<article class="ec-panel ec-panel--depth-1">
							<p class="ec-concert-stats-marketing__eyebrow"><?php esc_html_e( 'Your history, your call', 'extrachill-events' ); ?></p>
							<h2><?php esc_html_e( 'Private by default for new accounts', 'extrachill-events' ); ?></h2>
							<p><?php esc_html_e( 'Choose whether people can view your past concert history and whether your identity appears on event attendee lists. Upcoming plans stay owner-only.', 'extrachill-events' ); ?></p>
							<a href="<?php echo esc_url( $docs_url . 'concert-history-privacy/' ); ?>"><?php esc_html_e( 'Understand privacy controls', 'extrachill-events' ); ?> &rarr;</a>
						</article>
					</section>

					<section class="ec-concert-stats-marketing__section ec-concert-stats-marketing__docs">
						<div>
							<p class="ec-concert-stats-marketing__eyebrow"><?php esc_html_e( 'No guesswork', 'extrachill-events' ); ?></p>
							<h2><?php esc_html_e( 'Start with the full My Shows guide', 'extrachill-events' ); ?></h2>
							<p><?php esc_html_e( 'Learn how marking, search, imports, public histories, and each dashboard view fit together.', 'extrachill-events' ); ?></p>
						</div>
						<a href="<?php echo esc_url( $docs_url . 'getting-started-with-my-shows/' ); ?>" class="button-2 button-large"><?php esc_html_e( 'Read the Guide', 'extrachill-events' ); ?></a>
					</section>

					<section class="ec-concert-stats-marketing__closing">
						<h2><?php esc_html_e( 'Your next favorite show belongs here.', 'extrachill-events' ); ?></h2>
						<p><?php esc_html_e( 'Start with one concert. The archive grows from there.', 'extrachill-events' ); ?></p>
						<div class="ec-action-row ec-action-row--center">
							<a href="<?php echo esc_url( $signup_url ); ?>" class="button-1 button-large"><?php esc_html_e( 'Create Free Account', 'extrachill-events' ); ?></a>
							<a href="<?php echo esc_url( $login_url ); ?>" class="button-2 button-large"><?php esc_html_e( 'Log In', 'extrachill-events' ); ?></a>
						</div>
					</section>
				</div>
			</div>
		</div>
		<?php
		return;
	}
	$user_id = get_current_user_id();
}

$events_url     = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'events' ) : home_url();
$is_own         = is_user_logged_in() && get_current_user_id() === $user_id;
$public_date_to = $is_own ? '' : current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );

// #110: Calendar tab is owner-only, and the server-side filter callback
// only activates on /my-shows/. Match both conditions before emitting
// the embedded calendar block so we don't ship dead markup elsewhere.
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
		data-events-url="<?php echo esc_attr( $events_url ); ?>"
		data-is-own="<?php echo esc_attr( $is_own ? '1' : '0' ); ?>"
		data-public-date-to="<?php echo esc_attr( $public_date_to ); ?>"
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
