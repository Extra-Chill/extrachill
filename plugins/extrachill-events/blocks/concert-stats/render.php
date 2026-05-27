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
 * The embedded calendar is rendered as a *sibling* (not a child) of
 * the React root so React's hydration / unmount cycles don't wipe it.
 * Both nodes live inside a `<div class="ec-concert-stats-shell">`
 * outer container that the block wrapper attributes attach to.
 *
 * @package ExtraChillEvents
 * @since 0.18.0
 */

$user_id = ! empty( $attributes['userId'] ) ? (int) $attributes['userId'] : get_current_user_id();

// Don't render for logged-out users when no explicit userId is set.
if ( ! $user_id ) {
	if ( ! is_user_logged_in() ) {
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
$render_embedded_calendar = $is_own
	&& function_exists( 'is_page' )
	&& is_page( 'my-shows' );

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'             => 'ec-concert-stats-shell',
		'data-has-calendar' => esc_attr( $render_embedded_calendar ? '1' : '0' ),
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
			echo do_blocks( '<!-- wp:data-machine-events/calendar {"displayMode":"month-grid","showFilters":false,"showSearch":false,"showDateFilter":false} /-->' );
			?>
		</div>
	<?php endif; ?>
</div>
