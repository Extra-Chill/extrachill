<?php
/**
 * Artist Profile "Shows" Section (registered by AP core)
 *
 * Surfaces the events for a bound artist on the artist-profile hub. Because the
 * profile hub is a PUBLIC, SEO-critical, server-rendered surface (it must rank
 * for logged-out artist searches), this section is PHP-rendered.
 *
 * Cross-site data source: events live on the events blog, but that plugin's PHP
 * is NOT loaded on the artist blog (blog 4) — only extrachill-artist-platform
 * is active there. switch_to_blog() changes the DB context, not the loaded
 * code, so a direct data_machine_events_query_events() call here silently
 * renders nothing. Instead this section consumes the network-registered
 * `data-machine-events/events-by-term` ability (see data-machine-events#431),
 * which routes to the events-plugin implementation regardless of which blog the
 * caller is on. The ability returns PLAIN pre-resolved scalars (title,
 * permalink, venue name, formatted date/time) so this surface renders its own
 * markup with no cross-blog switching here.
 *
 * Presentation: a clean, date-forward LIST view (rows, not cards, not the
 * events calendar block). Two groups — "Upcoming Shows" then "Past Shows".
 *
 * Data-copy / SEO note: rows LINK to the CANONICAL event page on
 * events.extrachill.com. NO event content is duplicated as indexable content —
 * this is a discovery surface. Events remain the single source of truth.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register AP core's "Shows" section on the artist profile hub.
 *
 * @param array[] $sections       Registered sections.
 * @param int     $artist_id      Artist profile post ID.
 * @param int     $artist_term_id Bound main-blog `artist` term_id.
 * @return array[]
 */
function ec_register_artist_profile_shows_section( $sections, $artist_id, $artist_term_id ) {
	$sections[] = array(
		'id'       => 'shows',
		'label'    => __( 'Shows', 'extrachill-artist-platform' ),
		'priority' => 40,
		'as_tab'   => false,
		'visible'  => 'ec_artist_profile_has_shows',
		'render'   => 'ec_render_artist_profile_shows_section',
	);

	return $sections;
}
add_filter( 'ec_artist_profile_sections', 'ec_register_artist_profile_shows_section', 10, 3 );

/**
 * Resolve the shared artist slug for a bound artist.
 *
 * The bound $artist_term_id is a MAIN-blog `artist` term. Its slug is the
 * canonical cross-blog join key: the events-by-term ability looks the same
 * slug up on the events blog. Resolution is done inside a switch_to_blog()/
 * restore_current_blog() pair so blog context can never leak.
 *
 * @param int $artist_term_id Bound main-blog `artist` term_id.
 * @return string Artist slug, or '' when unresolvable.
 */
function ec_artist_shows_resolve_slug( $artist_term_id ) {
	$artist_term_id = (int) $artist_term_id;
	if ( $artist_term_id <= 0 || ! function_exists( 'ec_get_blog_id' ) ) {
		return '';
	}

	$main_blog_id = ec_get_blog_id( 'main' );
	if ( ! $main_blog_id ) {
		return '';
	}

	$slug = '';
	switch_to_blog( $main_blog_id );
	try {
		$term = get_term( $artist_term_id, 'artist' );
		if ( $term && ! is_wp_error( $term ) ) {
			$slug = (string) $term->slug;
		}
	} finally {
		restore_current_blog();
	}

	return $slug;
}

/**
 * Build the canonical artist events-archive URL on the events site.
 *
 * Points at the events-blog `artist` taxonomy archive (events.extrachill.com/
 * artist/{slug}) — the "View all shows" doorway. The events site base URL is
 * resolved via ec_get_blog_id('events') + get_site_url() rather than hardcoding
 * the domain, so it stays correct across environments. No-ops (returns '')
 * when the slug or events blog is missing.
 *
 * @param string $slug Shared artist slug (equals the events-blog term slug).
 * @return string Absolute archive URL, or '' when unresolvable.
 */
function ec_artist_shows_archive_url( $slug ) {
	$slug = (string) $slug;
	if ( '' === $slug || ! function_exists( 'ec_get_blog_id' ) ) {
		return '';
	}

	$events_blog_id = ec_get_blog_id( 'events' );
	if ( ! $events_blog_id ) {
		return '';
	}

	$base = get_site_url( (int) $events_blog_id );
	if ( empty( $base ) ) {
		return '';
	}

	return trailingslashit( $base ) . 'artist/' . rawurlencode( $slug );
}

/**
 * Gather the artist's shows via the cross-site events-by-term ability.
 *
 * Resolves the artist slug from the bound main-blog term, then calls the
 * network-registered `data-machine-events/events-by-term` ability with
 * `taxonomy => 'artist'`. The ability resolves everything (permalinks, venue
 * names, formatted dates) on the events blog and hands back plain scalars this
 * surface can render directly — which is why it works from blog 4 where the
 * events plugin's PHP is not loaded.
 *
 * @param int $artist_term_id Bound main-blog `artist` term_id.
 * @return array{upcoming:array[],past:array[]} Event rows grouped by scope.
 */
function ec_artist_shows_gather( $artist_term_id ) {
	$empty = array(
		'upcoming' => array(),
		'past'     => array(),
	);

	// Abilities API must be present. Guard for graceful degradation on any blog
	// where it is not loaded.
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return $empty;
	}

	$slug = ec_artist_shows_resolve_slug( $artist_term_id );
	if ( '' === $slug ) {
		return $empty;
	}

	$ability = wp_get_ability( 'data-machine-events/events-by-term' );
	if ( ! $ability ) {
		return $empty;
	}

	$result = $ability->execute(
		array(
			'taxonomy'  => 'artist',
			'term_slug' => $slug,
			'scope'     => 'all',
			'limit'     => 12,
		)
	);

	if ( is_wp_error( $result ) || ! is_array( $result ) ) {
		return $empty;
	}

	if ( empty( $result['found'] ) ) {
		return $empty;
	}

	return array(
		'upcoming' => isset( $result['upcoming'] ) && is_array( $result['upcoming'] ) ? $result['upcoming'] : array(),
		'past'     => isset( $result['past'] ) && is_array( $result['past'] ) ? $result['past'] : array(),
	);
}

/**
 * Visibility gate for the Shows section.
 *
 * Hides the whole section when the artist has no bound term or zero events, so
 * new artists never see an empty block (mirrors the Coverage section's gate).
 *
 * @param int $artist_id      Artist profile post ID.
 * @param int $artist_term_id Bound main-blog `artist` term_id.
 * @return bool
 */
function ec_artist_profile_has_shows( $artist_id, $artist_term_id = 0 ) {
	$artist_term_id = (int) $artist_term_id;
	if ( $artist_term_id <= 0 ) {
		return false;
	}

	$shows = ec_artist_shows_gather( $artist_term_id );

	return ! empty( $shows['upcoming'] ) || ! empty( $shows['past'] );
}

/**
 * Render the Shows section for the artist profile hub.
 *
 * Upcoming shows render as one list group, past shows as a second. Every row
 * LINKS to the canonical event page on events.extrachill.com (no SEO
 * duplication). Past rows surface the "I Was There" attendance affordance when
 * extrachill-users is active. A "View all shows →" link closes the section,
 * completing the discovery doorway to the full canonical artist events archive
 * (the section caps at 12 upcoming + 12 past). Server-side render, no AJAX.
 *
 * @param int $artist_id      Artist profile post ID.
 * @param int $artist_term_id Bound main-blog `artist` term_id.
 * @return void
 */
function ec_render_artist_profile_shows_section( $artist_id, $artist_term_id = 0 ) {
	$artist_term_id = (int) $artist_term_id;
	if ( $artist_term_id <= 0 ) {
		return;
	}

	$shows = ec_artist_shows_gather( $artist_term_id );
	if ( empty( $shows['upcoming'] ) && empty( $shows['past'] ) ) {
		return;
	}

	echo '<div class="artist-shows-section">';
	echo '<h2 class="section-title">' . esc_html__( 'Shows', 'extrachill-artist-platform' ) . '</h2>';

	if ( ! empty( $shows['upcoming'] ) ) {
		ec_render_artist_shows_group(
			__( 'Upcoming Shows', 'extrachill-artist-platform' ),
			$shows['upcoming']
		);
	}

	if ( ! empty( $shows['past'] ) ) {
		ec_render_artist_shows_group(
			__( 'Past Shows', 'extrachill-artist-platform' ),
			$shows['past']
		);
	}

	// "View all shows" doorway to the full canonical artist events archive.
	// Reuse the shared slug the ability keyed off. Guarded so a missing
	// slug/blog no-ops cleanly (the section is already visibility-gated).
	$slug        = ec_artist_shows_resolve_slug( $artist_term_id );
	$archive_url = ec_artist_shows_archive_url( $slug );
	if ( '' !== $archive_url ) {
		echo '<div class="artist-shows-view-all">';
		printf(
			'<a href="%s" class="button-3 button-small">%s</a>',
			esc_url( $archive_url ),
			esc_html__( 'View all shows →', 'extrachill-artist-platform' )
		);
		echo '</div>';
	}

	echo '</div>'; // .artist-shows-section
}

/**
 * Render a single group of shows as a clean, date-forward LIST.
 *
 * Each row shows the formatted date (and time when known), the event title
 * linked to the CANONICAL event page, and the venue name. Past rows surface the
 * "I Was There" attendance affordance inline when extrachill-users is active
 * (guarded with function_exists so it no-ops cleanly otherwise). Rows lean on
 * existing theme typography/spacing tokens via the small `.artist-shows-list`
 * styles in artist-profile.css — no card grid, no events-plugin CSS.
 *
 * @param string  $heading Group heading (e.g. "Upcoming Shows").
 * @param array[] $shows   Event rows from the events-by-term ability. Each is
 *                         a plain array: event_id, title, permalink, venue_name,
 *                         date_iso, date_display, time_display, timing.
 * @return void
 */
function ec_render_artist_shows_group( $heading, $shows ) {
	if ( empty( $shows ) ) {
		return;
	}
	?>
	<div class="artist-shows-group">
		<h3 class="artist-shows-heading"><?php echo esc_html( $heading ); ?></h3>

		<ul class="artist-shows-list">
			<?php foreach ( $shows as $show ) : ?>
				<?php
				$permalink    = isset( $show['permalink'] ) ? (string) $show['permalink'] : '';
				$title        = isset( $show['title'] ) ? (string) $show['title'] : '';
				$venue_name   = isset( $show['venue_name'] ) ? (string) $show['venue_name'] : '';
				$date_display = isset( $show['date_display'] ) ? (string) $show['date_display'] : '';
				$time_display = isset( $show['time_display'] ) ? (string) $show['time_display'] : '';
				$date_iso     = isset( $show['date_iso'] ) ? (string) $show['date_iso'] : '';
				$event_id     = isset( $show['event_id'] ) ? (int) $show['event_id'] : 0;
				$is_past      = isset( $show['timing'] ) && 'past' === $show['timing'];

				if ( '' === $title || '' === $permalink ) {
					continue;
				}
				?>
				<li class="artist-show-row">
					<div class="artist-show-when">
						<?php if ( '' !== $date_display ) : ?>
							<time class="artist-show-date"<?php echo '' !== $date_iso ? ' datetime="' . esc_attr( $date_iso ) . '"' : ''; ?>><?php echo esc_html( $date_display ); ?></time>
						<?php endif; ?>
						<?php if ( '' !== $time_display ) : ?>
							<span class="artist-show-time"><?php echo esc_html( $time_display ); ?></span>
						<?php endif; ?>
					</div>

					<div class="artist-show-detail">
						<a class="artist-show-title" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
						<?php if ( '' !== $venue_name ) : ?>
							<span class="artist-show-venue"><?php echo esc_html( $venue_name ); ?></span>
						<?php endif; ?>
					</div>

					<?php
					// Past-row "I Was There" affordance. Owned by extrachill-users
					// (active network-wide); guard with function_exists so this
					// no-ops cleanly if that plugin is ever inactive on a blog.
					if ( $is_past && $event_id > 0 && function_exists( 'ec_users_render_attendance_button' ) ) :
						?>
						<div class="artist-show-attendance">
							<?php ec_users_render_attendance_button( $event_id ); ?>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
}
