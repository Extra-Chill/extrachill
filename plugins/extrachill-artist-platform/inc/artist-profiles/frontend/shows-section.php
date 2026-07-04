<?php
/**
 * Artist Profile "Shows" Section (registered by AP core)
 *
 * Surfaces the events for a bound artist on the artist-profile hub, in the same
 * pattern the multisite Coverage section uses: a term-scoped section that
 * self-registers via the `ec_artist_profile_sections` filter. Because the
 * profile hub is a PUBLIC, SEO-critical, server-rendered surface (it must rank
 * for logged-out artist searches), this section is PHP-rendered and composes the
 * SHARED theme card primitives (`.related-tax-grid` / `.related-tax-card` /
 * `.related-tax-header`, plus `button-3 button-small`) — the same server-rendered
 * card system the events "related" module uses (see
 * ec_events_render_related_posts in extrachill-events). No ad-hoc markup, no
 * React, per the #92 shared-primitives constraint.
 *
 * Data-copy / SEO note: cards LINK to the CANONICAL event page on
 * events.extrachill.com. NO event content is copied onto the artist site — this
 * is a discovery surface, not a data duplication. Events remain the single
 * source of truth.
 *
 * Cross-blog context: events live on the events blog with their OWN `artist`
 * taxonomy. The bound $artist_term_id passed to the section callbacks is a
 * MAIN-blog term; we resolve the matching events-blog `artist` term by slug
 * (the canonical join key the whole binding system keys off). Every
 * switch_to_blog() is paired with restore_current_blog() in a try/finally so the
 * blog context can never leak.
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
 * Resolve the events-blog `artist` term_id for a bound artist.
 *
 * The bound $artist_term_id is a MAIN-blog term. Events carry their own `artist`
 * taxonomy on the events blog, so we resolve the events-blog term by matching the
 * main-blog term's slug (the canonical cross-blog join key). Both hops are
 * switch_to_blog()/restore_current_blog() paired in try/finally.
 *
 * @param int $artist_term_id Bound main-blog `artist` term_id.
 * @return array{blog_id:int,term_id:int,slug:string}|null Events blog id,
 *                                             events-blog artist term_id, and the
 *                                             shared artist slug, or null when
 *                                             unresolvable.
 */
function ec_artist_shows_resolve_events_term( $artist_term_id ) {
	$artist_term_id = (int) $artist_term_id;
	if ( $artist_term_id <= 0 ) {
		return null;
	}

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return null;
	}

	$main_blog_id   = ec_get_blog_id( 'main' );
	$events_blog_id = ec_get_blog_id( 'events' );
	if ( ! $main_blog_id || ! $events_blog_id ) {
		return null;
	}

	// 1. Resolve the artist slug from the bound main-blog term.
	$slug = '';
	switch_to_blog( $main_blog_id );
	try {
		$term = get_term( $artist_term_id, 'artist' );
		if ( $term && ! is_wp_error( $term ) ) {
			$slug = $term->slug;
		}
	} finally {
		restore_current_blog();
	}

	if ( empty( $slug ) ) {
		return null;
	}

	// 2. Resolve the matching events-blog `artist` term by slug.
	$events_term_id = 0;
	switch_to_blog( $events_blog_id );
	try {
		if ( taxonomy_exists( 'artist' ) ) {
			// get_term_by() returns WP_Term|array|false (never WP_Error).
			$events_term = get_term_by( 'slug', $slug, 'artist' );
			if ( $events_term instanceof WP_Term ) {
				$events_term_id = (int) $events_term->term_id;
			}
		}
	} finally {
		restore_current_blog();
	}

	if ( $events_term_id <= 0 ) {
		return null;
	}

	return array(
		'blog_id' => (int) $events_blog_id,
		'term_id' => $events_term_id,
		'slug'    => (string) $slug,
	);
}

/**
 * Build the canonical artist events-archive URL on the events site.
 *
 * Points at the events-blog `artist` taxonomy archive (events.extrachill.com/
 * artist/{slug}) — the same archive the events-blog artist term resolves to.
 * The events site base URL is resolved via ec_get_blog_id('events') +
 * get_site_url() rather than hardcoding the domain, so it stays correct across
 * environments. No-ops (returns '') when the slug or events blog is missing.
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
 * Gather renderable show-card data for a bound artist, scoped to the events blog.
 *
 * Runs the events query INSIDE the events-blog context and captures everything a
 * card needs (permalink, title, thumbnail, formatted date/time, taxonomy badges,
 * and — for past shows — the "I Was There" attendance affordance) while still on
 * the events blog, because permalinks, thumbnails, badge rendering, and the
 * attendance button all read the current blog. The returned arrays are plain
 * scalars/HTML safe to render after restore_current_blog().
 *
 * @param int $artist_term_id Bound main-blog `artist` term_id.
 * @return array{upcoming:array[],past:array[]} Card data grouped by scope.
 */
function ec_artist_shows_gather_cards( $artist_term_id ) {
	$empty = array(
		'upcoming' => array(),
		'past'     => array(),
	);

	if ( ! function_exists( 'data_machine_events_query_events' ) ) {
		return $empty;
	}

	$resolved = ec_artist_shows_resolve_events_term( $artist_term_id );
	if ( null === $resolved ) {
		return $empty;
	}

	$events_blog_id = $resolved['blog_id'];
	$events_term_id = $resolved['term_id'];

	$cards = $empty;

	switch_to_blog( $events_blog_id );
	try {
		$tax_filters = array( 'artist' => array( $events_term_id ) );

		// Upcoming: soonest first. Past: most recent first.
		$scopes = array(
			'upcoming' => 'ASC',
			'past'     => 'DESC',
		);

		foreach ( $scopes as $scope => $order ) {
			$result = data_machine_events_query_events(
				array(
					'scope'       => $scope,
					'tax_filters' => $tax_filters,
					'per_page'    => 12,
					'order'       => $order,
				)
			);

			$posts = isset( $result['posts'] ) && is_array( $result['posts'] ) ? $result['posts'] : array();
			foreach ( $posts as $event_post ) {
				if ( ! ( $event_post instanceof WP_Post ) ) {
					continue;
				}
				$cards[ $scope ][] = ec_artist_shows_build_card( $event_post, $scope );
			}
		}
	} finally {
		restore_current_blog();
	}

	return $cards;
}

/**
 * Build the render-ready card payload for a single event post.
 *
 * MUST be called while switched to the events blog — it reads permalinks,
 * thumbnails, badge markup, and (for past shows) the attendance button, all of
 * which resolve against the current blog.
 *
 * @param WP_Post $event_post Event post (events blog).
 * @param string  $scope      'upcoming' | 'past'.
 * @return array Card payload of pre-resolved scalars/HTML.
 */
function ec_artist_shows_build_card( $event_post, $scope ) {
	$permalink  = get_permalink( $event_post );
	$title      = get_the_title( $event_post );
	$image_url  = get_the_post_thumbnail_url( $event_post, 'medium_large' );

	$badges_html = function_exists( 'data_machine_events_render_taxonomy_badges' )
		? data_machine_events_render_taxonomy_badges( $event_post->ID )
		: '';

	// Format date/time via the events public API when available.
	$date_str = '';
	$time_str = '';
	if ( function_exists( 'data_machine_events_parse_event_data' ) ) {
		$event_data = data_machine_events_parse_event_data( $event_post );
		if ( is_array( $event_data ) && ! empty( $event_data['startDate'] ) ) {
			$start_time = ! empty( $event_data['startTime'] ) ? $event_data['startTime'] : '00:00:00';
			try {
				$date_obj = new DateTime( $event_data['startDate'] . ' ' . $start_time, wp_timezone() );
				$date_str = $date_obj->format( 'D, M j, Y' );
				$time_str = $date_obj->format( 'g:i A' );
			} catch ( Exception $e ) {
				$date_str = '';
				$time_str = '';
			}
		}
	}

	// Past-show "I Was There" affordance. Owned by extrachill-users; guard with
	// function_exists so this no-ops cleanly when that plugin is inactive. The
	// button reads get_current_blog_id() internally, so it must be captured here
	// while still switched to the events blog.
	$attendance_html = '';
	if ( 'past' === $scope && function_exists( 'ec_users_render_attendance_button' ) ) {
		ob_start();
		ec_users_render_attendance_button( (int) $event_post->ID );
		$attendance_html = ob_get_clean();
	}

	return array(
		'permalink'       => (string) $permalink,
		'title'           => (string) $title,
		'image_url'       => $image_url ? (string) $image_url : '',
		'badges_html'     => (string) $badges_html,
		'date_str'        => (string) $date_str,
		'time_str'        => (string) $time_str,
		'attendance_html' => (string) $attendance_html,
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

	$cards = ec_artist_shows_gather_cards( $artist_term_id );

	return ! empty( $cards['upcoming'] ) || ! empty( $cards['past'] );
}

/**
 * Render the Shows section for the artist profile hub.
 *
 * Upcoming shows render as one card group, past shows as a second. Every card
 * LINKS to the canonical event page on events.extrachill.com (no SEO
 * duplication) and composes the shared `.related-tax-*` card primitives. Past
 * cards surface the "I Was There" attendance button when extrachill-users is
 * active. A "View all shows" link closes the section, completing the discovery
 * doorway to the full canonical artist events archive (the section caps at 12
 * upcoming + 12 past cards). Server-side render, no AJAX.
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

	$cards = ec_artist_shows_gather_cards( $artist_term_id );
	if ( empty( $cards['upcoming'] ) && empty( $cards['past'] ) ) {
		return;
	}

	echo '<div class="artist-shows-section">';
	echo '<h2 class="section-title">' . esc_html__( 'Shows', 'extrachill-artist-platform' ) . '</h2>';

	if ( ! empty( $cards['upcoming'] ) ) {
		ec_render_artist_shows_group(
			__( 'Upcoming Shows', 'extrachill-artist-platform' ),
			$cards['upcoming']
		);
	}

	if ( ! empty( $cards['past'] ) ) {
		ec_render_artist_shows_group(
			__( 'Past Shows', 'extrachill-artist-platform' ),
			$cards['past']
		);
	}

	// "View all shows" doorway to the full canonical artist events archive. The
	// resolver already computed the shared slug when gathering cards; reuse it to
	// build the events-site archive URL. Guarded so a missing slug/blog no-ops
	// cleanly (the section itself is already visibility-gated on having shows).
	$resolved = ec_artist_shows_resolve_events_term( $artist_term_id );
	$slug     = ( null !== $resolved && ! empty( $resolved['slug'] ) ) ? $resolved['slug'] : '';
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
 * Render a single group of show cards using the shared card primitives.
 *
 * Mirrors the structure/classes of ec_events_render_related_posts so any
 * improvement to the shared `.related-tax-*` card system lifts this surface too.
 *
 * @param string  $heading Group heading (e.g. "Upcoming Shows").
 * @param array[] $cards   Pre-resolved card payloads from ec_artist_shows_build_card().
 * @return void
 */
function ec_render_artist_shows_group( $heading, $cards ) {
	if ( empty( $cards ) ) {
		return;
	}
	?>
	<div class="related-tax-section artist-shows-group">
		<h3 class="related-tax-header"><?php echo esc_html( $heading ); ?></h3>

		<div class="related-tax-grid">
			<?php foreach ( $cards as $card ) : ?>
				<div class="related-tax-card">
					<?php if ( ! empty( $card['image_url'] ) ) : ?>
						<div class="related-tax-thumb">
							<a href="<?php echo esc_url( $card['permalink'] ); ?>">
								<img src="<?php echo esc_url( $card['image_url'] ); ?>" alt="<?php echo esc_attr( $card['title'] ); ?>" loading="lazy">
							</a>
						</div>
					<?php endif; ?>

					<?php
					// Badge markup is pre-built by data_machine_events_render_taxonomy_badges
					// (escaped internally); output as-is.
					echo $card['badges_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					<h4 class="related-tax-title">
						<a href="<?php echo esc_url( $card['permalink'] ); ?>"><?php echo esc_html( $card['title'] ); ?></a>
					</h4>

					<div class="related-tax-meta">
						<?php if ( ! empty( $card['date_str'] ) ) : ?>
							<div class="ec-related-meta-item">
								<?php if ( function_exists( 'ec_icon' ) ) { echo ec_icon( 'calendar' ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span><?php echo esc_html( $card['date_str'] ); ?></span>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $card['time_str'] ) ) : ?>
							<div class="ec-related-meta-item">
								<?php if ( function_exists( 'ec_icon' ) ) { echo ec_icon( 'clock' ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span><?php echo esc_html( $card['time_str'] ); ?></span>
							</div>
						<?php endif; ?>

						<a href="<?php echo esc_url( $card['permalink'] ); ?>" class="button-3 button-small"><?php esc_html_e( 'More Info', 'extrachill-artist-platform' ); ?></a>
					</div>

					<?php if ( ! empty( $card['attendance_html'] ) ) : ?>
						<div class="artist-shows-attendance">
							<?php
							// Pre-built by ec_users_render_attendance_button (escaped internally).
							echo $card['attendance_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}
