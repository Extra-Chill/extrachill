<?php
/**
 * Concert History Profile Card
 *
 * Bridges the My Shows concert-tracking data (events site, blog 7) onto the
 * bbPress community user profile. Renders real tracked concert history —
 * total shows, top artists/venues/cities — replacing the dead free-text
 * `top_concerts` usermeta field as the canonical concert record.
 *
 * Data source: the public `extrachill/get-user-concert-stats` ability,
 * dispatched cross-site to the events site via in-process REST
 * (ec_cross_site_rest_request). The tracking table is network-scoped, but the
 * taxonomy enrichment (artist/venue/city names) requires events-site context,
 * so we always route through the events site.
 *
 * @package ExtraChillCommunity
 * @since 0.x
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetch a user's aggregate concert stats from the events site.
 *
 * Calls the public get-user-concert-stats ability via in-process cross-site
 * REST dispatch. Returns null on any failure (missing primitive, cross-site
 * error, malformed payload) so the renderer can distinguish unavailable data
 * from a legitimate zero-show response.
 *
 * @param int $user_id User ID.
 * @return array|null Stats payload, or null on failure / unavailable.
 */
function ec_community_get_concert_stats( int $user_id ): ?array {
	if ( $user_id <= 0 ) {
		return null;
	}

	// Authorization must precede the cross-site request so private data is never
	// fetched for an unauthorized viewer.
	if ( ! function_exists( 'extrachill_users_can_view_concert_history' ) || ! extrachill_users_can_view_concert_history( $user_id ) ) {
		return null;
	}

	if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return null;
	}

	$response = ec_cross_site_rest_request(
		'events',
		'GET',
		'/extrachill/v1/concert-tracking/user/' . $user_id . '/stats',
		array( 'user_id' => $user_id )
	);

	if ( is_wp_error( $response ) || ! is_array( $response ) || ! isset( $response['total_shows'] ) || ! is_numeric( $response['total_shows'] ) ) {
		return null;
	}

	return $response;
}

/**
 * Build the My Shows URL for a given user on the events site.
 *
 * @param int $user_id User ID.
 * @return string Absolute URL to the user's concert history, or '' if events
 *                site URL cannot be resolved.
 */
function ec_community_my_shows_url( int $user_id ): string {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return '';
	}

	$events_blog_id = ec_get_blog_id( 'events' );
	if ( ! $events_blog_id ) {
		return '';
	}

	$base = get_home_url( $events_blog_id, '/my-shows/' );

	// Own profile links to the bare page; viewing someone else's appends the
	// user_id so the My Shows block renders their history.
	if ( (int) get_current_user_id() === $user_id ) {
		return $base;
	}

	return add_query_arg( 'user_id', $user_id, $base );
}

/**
 * Render a top-list (artists / venues / cities) as a comma-separated line.
 *
 * Items carrying a `url` (artist profile / venue / city archive, supplied by
 * the concert-stats ability via the canonical cross-site linker) render as
 * links, turning the community profile's concert history into a launchpad into
 * the rest of the network. Items without a url render as plain text.
 *
 * @param array $items Each item: array{ name:string, slug:string, count:int, url?:string }.
 * @param int   $limit Max items to render.
 * @return string Escaped HTML, or '' if the list is empty.
 */
function ec_community_render_top_list( array $items, int $limit = 5 ): string {
	if ( empty( $items ) ) {
		return '';
	}

	$rendered = array();
	foreach ( array_slice( $items, 0, $limit ) as $item ) {
		if ( empty( $item['name'] ) ) {
			continue;
		}

		$name = esc_html( $item['name'] );
		$url  = isset( $item['url'] ) ? (string) $item['url'] : '';

		$rendered[] = ( '' !== $url )
			? '<a href="' . esc_url( $url ) . '">' . $name . '</a>'
			: $name;
	}

	return implode( ', ', $rendered );
}

/**
 * Display the Concert History card on the bbPress user profile.
 *
 * Pulls real tracked shows from the events site and renders an aggregate
 * summary with a link to the full My Shows history. Visitors see no card when
 * data is unavailable; owners see an explicit unavailable state. For a profile
 * owner with zero tracked shows, renders a soft call-to-action instead.
 */
function ec_community_display_concert_history() {
	$user_id = bbp_get_displayed_user_id();
	if ( ! $user_id ) {
		return;
	}

	$is_own = ( (int) get_current_user_id() === (int) $user_id );
	$stats  = ec_community_get_concert_stats( (int) $user_id );

	if ( null === $stats ) {
		if ( ! $is_own ) {
			return;
		}
		?>
		<div class="bbp-user-profile-card ec-concert-history-card ec-concert-history-unavailable">
			<h3><?php esc_html_e( 'Concert History', 'extra-chill-community' ); ?></h3>
			<p><?php esc_html_e( 'Your concert history is temporarily unavailable. Please try again later.', 'extra-chill-community' ); ?></p>
		</div>
		<?php
		return;
	}

	$total_shows = (int) $stats['total_shows'];

	// No tracked shows: show a CTA on the owner's profile, hide for visitors.
	if ( $total_shows < 1 ) {
		if ( ! $is_own ) {
			return;
		}

		$my_shows_url = ec_community_my_shows_url( (int) $user_id );
		if ( ! $my_shows_url ) {
			return;
		}
		?>
		<div class="bbp-user-profile-card ec-concert-history-card ec-concert-history-empty">
			<h3><?php esc_html_e( 'Concert History', 'extra-chill-community' ); ?></h3>
			<p><?php esc_html_e( 'You haven\'t tracked any shows yet. Build your concert history — every show you\'ve been to, in one place.', 'extra-chill-community' ); ?></p>
			<p><a class="button-1 button-small" href="<?php echo esc_url( $my_shows_url ); ?>"><?php esc_html_e( 'Start your concert history →', 'extra-chill-community' ); ?></a></p>
		</div>
		<?php
		return;
	}

	$my_shows_url   = ec_community_my_shows_url( (int) $user_id );
	$unique_venues  = isset( $stats['unique_venues'] ) ? (int) $stats['unique_venues'] : 0;
	$unique_artists = isset( $stats['unique_artists'] ) ? (int) $stats['unique_artists'] : 0;
	$unique_cities  = isset( $stats['unique_cities'] ) ? (int) $stats['unique_cities'] : 0;

	$top_artists = ec_community_render_top_list( $stats['top_artists'] ?? array() );
	$top_venues  = ec_community_render_top_list( $stats['top_venues'] ?? array() );
	$top_cities  = ec_community_render_top_list( $stats['top_cities'] ?? array() );

	$heading = $is_own
		? __( 'My Concert History', 'extra-chill-community' )
		: __( 'Concert History', 'extra-chill-community' );
	?>
	<div class="bbp-user-profile-card ec-concert-history-card">
		<h3><?php echo esc_html( $heading ); ?></h3>
		<ul class="ec-concert-stats">
			<li>
				<span class="ec-concert-stat-value"><?php echo esc_html( number_format_i18n( $total_shows ) ); ?></span>
				<span class="ec-concert-stat-label"><?php echo esc_html( _n( 'show', 'shows', $total_shows, 'extra-chill-community' ) ); ?></span>
			</li>
			<?php if ( $unique_artists > 0 ) : ?>
				<li>
					<span class="ec-concert-stat-value"><?php echo esc_html( number_format_i18n( $unique_artists ) ); ?></span>
					<span class="ec-concert-stat-label"><?php echo esc_html( _n( 'artist', 'artists', $unique_artists, 'extra-chill-community' ) ); ?></span>
				</li>
			<?php endif; ?>
			<?php if ( $unique_venues > 0 ) : ?>
				<li>
					<span class="ec-concert-stat-value"><?php echo esc_html( number_format_i18n( $unique_venues ) ); ?></span>
					<span class="ec-concert-stat-label"><?php echo esc_html( _n( 'venue', 'venues', $unique_venues, 'extra-chill-community' ) ); ?></span>
				</li>
			<?php endif; ?>
			<?php if ( $unique_cities > 0 ) : ?>
				<li>
					<span class="ec-concert-stat-value"><?php echo esc_html( number_format_i18n( $unique_cities ) ); ?></span>
					<span class="ec-concert-stat-label"><?php echo esc_html( _n( 'city', 'cities', $unique_cities, 'extra-chill-community' ) ); ?></span>
				</li>
			<?php endif; ?>
		</ul>

		<?php if ( $top_artists ) : ?>
			<p><strong><?php esc_html_e( 'Top Artists:', 'extra-chill-community' ); ?></strong> <?php echo wp_kses_post( $top_artists ); ?></p>
		<?php endif; ?>
		<?php if ( $top_venues ) : ?>
			<p><strong><?php esc_html_e( 'Top Venues:', 'extra-chill-community' ); ?></strong> <?php echo wp_kses_post( $top_venues ); ?></p>
		<?php endif; ?>
		<?php if ( $top_cities ) : ?>
			<p><strong><?php esc_html_e( 'Top Cities:', 'extra-chill-community' ); ?></strong> <?php echo wp_kses_post( $top_cities ); ?></p>
		<?php endif; ?>

		<?php if ( $my_shows_url ) : ?>
			<p class="ec-concert-history-cta">
				<a href="<?php echo esc_url( $my_shows_url ); ?>">
					<?php
					echo esc_html(
						$is_own
							? __( 'View your full concert history →', 'extra-chill-community' )
							: __( 'View full concert history →', 'extra-chill-community' )
					);
					?>
				</a>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

// Render below the About card, after the contribution heatmap (priority 1)
// and above the Recent Conversations feed (priority 99).
add_action( 'bbp_template_after_user_profile', 'ec_community_display_concert_history', 5 );
