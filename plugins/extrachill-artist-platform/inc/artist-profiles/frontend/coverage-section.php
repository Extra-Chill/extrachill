<?php
/**
 * Artist Profile "Coverage" Section — article cards (registered by AP core)
 *
 * Upgrades the artist-profile hub's Coverage surface from a bare "Blog (N)"
 * summary button into a grid of ARTICLE CARDS (title + thumbnail + link to the
 * canonical article on extrachill.com), plus a "View all coverage →" doorway to
 * the artist's taxonomy archive on the main blog. Part of the unified section
 * model (extrachill-artist-platform#94): every cross-site surface on the profile
 * hub becomes cards + "View all", retiring the button-row.
 *
 * WHY THIS LIVES IN AP CORE (not extrachill-multisite)
 * ----------------------------------------------------
 * The artist site (blog 4) has ONLY extrachill-artist-platform active — the main
 * blog's plugins/theme are NOT loaded there, so we cannot call main-blog
 * functions directly. What we CAN do (and what the whole cross-site binding
 * system already does, e.g. extrachill-multisite's entity-links.php and the
 * Shows section in this plugin) is switch_to_blog( main ) and run a CORE
 * WP_Query: the `post` post type and the `artist` taxonomy resolve in the
 * switched context (they are registered network-wide, the same reason
 * extrachill/taxonomy-post-counts can count them cross-site). No new ability is
 * required — the article LIST comes from a core query inside the switched blog,
 * mirroring how the Shows section sources events via a switch to the events blog.
 *
 * The existing Coverage section owned by extrachill-multisite
 * (extrachill_register_artist_profile_coverage_section, priority 30) renders the
 * OLD button-row via extrachill_get_cross_site_artist_links(). To avoid a
 * double-render we UNHOOK that registration here (see below) and register this
 * cards section in its place at the same id/priority. Per #94 the button-row
 * primitive (extrachill_get_cross_site_artist_links) stays alive for the
 * taxonomy/author archive contexts that still want summary buttons — this change
 * only retires it ON THE PROFILE HUB. A companion PR in extrachill-multisite
 * should delete its now-superseded profile Coverage section registration so this
 * unhook can be removed (no reflexive back-compat: the unhook is a transitional
 * bridge, documented in the PR body, not a permanent shim).
 *
 * SEO / data-copy note: cards LINK to the CANONICAL article on extrachill.com.
 * NO article content is copied onto the artist site — this is a discovery
 * surface, not duplication. Same pattern as the Shows section.
 *
 * Shared primitives (#92 constraint): composes the SHARED theme card system
 * (`.related-tax-grid` / `.related-tax-card` / `.related-tax-header`, plus
 * `button-3 button-small`) — the same server-rendered cards the events "related"
 * module and the Shows section use. PHP-rendered (crawlable, fast first paint),
 * no React, no ad-hoc markup.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Number of coverage article cards to show on the profile hub.
 *
 * The full archive is one click away via "View all coverage →", so the hub
 * shows a capped, most-recent slice.
 */
if ( ! defined( 'EC_ARTIST_COVERAGE_CARD_LIMIT' ) ) {
	define( 'EC_ARTIST_COVERAGE_CARD_LIMIT', 6 );
}

/**
 * Register AP core's cards-based "Coverage" section on the artist profile hub,
 * and retire the multisite button-row Coverage section so they don't double up.
 *
 * The multisite section self-registers on the same filter at priority 10; by the
 * time this runs (priority 20 on the same filter) that registration is already
 * present in $sections, so we strip it by id before appending the cards version.
 * We ALSO remove_filter() the multisite registration callback outright so the
 * visibility gate / render never fire for it. Both are transitional: the
 * companion extrachill-multisite PR deletes its profile Coverage registration,
 * after which this unhook is dead code to remove.
 *
 * @param array[] $sections       Registered sections.
 * @param int     $artist_id      Artist profile post ID.
 * @param int     $artist_term_id Bound main-blog `artist` term_id.
 * @return array[]
 */
function ec_register_artist_profile_coverage_cards_section( $sections, $artist_id, $artist_term_id ) {
	// Drop the multisite-registered button-row Coverage section (id 'coverage')
	// so the hub renders the cards version below instead of both.
	if ( is_array( $sections ) ) {
		$sections = array_values(
			array_filter(
				$sections,
				static function ( $section ) {
					return ! ( is_array( $section ) && isset( $section['id'] ) && 'coverage' === $section['id'] );
				}
			)
		);
	}

	$sections[] = array(
		'id'       => 'coverage',
		'label'    => __( 'Coverage', 'extrachill-artist-platform' ),
		'priority' => 30,
		'as_tab'   => false,
		'visible'  => 'ec_artist_profile_has_coverage',
		'render'   => 'ec_render_artist_profile_coverage_section',
	);

	return $sections;
}
// Priority 20 so the multisite registration (priority 10) is already in the
// array and can be stripped here.
add_filter( 'ec_artist_profile_sections', 'ec_register_artist_profile_coverage_cards_section', 20, 3 );

/**
 * Belt-and-suspenders: unhook the multisite button-row Coverage registration
 * entirely when it is present. Guarded on function_exists so nothing breaks if
 * the companion multisite PR has already removed it (graceful degradation, not
 * back-compat).
 */
add_action(
	'init',
	static function () {
		if ( function_exists( 'extrachill_register_artist_profile_coverage_section' ) ) {
			remove_filter( 'ec_artist_profile_sections', 'extrachill_register_artist_profile_coverage_section', 10 );
		}
	},
	20
);

/**
 * Resolve the shared artist slug for a profile, preferring the bound term.
 *
 * The coverage query keys off the artist slug (which equals the main-blog
 * `artist` term slug). When the term binding is available we resolve the slug
 * from the bound term so a profile rename can't desync coverage; otherwise we
 * fall back to the profile post slug. Mirrors the multisite
 * extrachill_get_artist_profile_coverage_slug() resolution so behaviour is
 * identical after the button→cards swap.
 *
 * @param int $artist_id      Artist profile post ID.
 * @param int $artist_term_id Bound main-blog `artist` term_id (0 if unbound).
 * @return string Artist slug, or '' if none can be resolved.
 */
function ec_artist_coverage_resolve_slug( $artist_id, $artist_term_id = 0 ) {
	$artist_term_id = (int) $artist_term_id;

	if ( $artist_term_id > 0 && function_exists( 'ec_get_blog_id' ) ) {
		$main_blog_id = ec_get_blog_id( 'main' );
		if ( $main_blog_id ) {
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
			if ( ! empty( $slug ) ) {
				return $slug;
			}
		}
	}

	$post = get_post( (int) $artist_id );
	if ( $post && 'artist_profile' === $post->post_type && ! empty( $post->post_name ) ) {
		return $post->post_name;
	}

	return '';
}

/**
 * Build the canonical coverage archive URL on the main site.
 *
 * Points at the main-blog `artist` taxonomy archive
 * (extrachill.com/artist/{slug}) — the "View all coverage →" doorway. The main
 * site base URL is resolved via ec_get_blog_id('main') + get_site_url() rather
 * than hardcoding the domain, so it stays correct across environments.
 *
 * @param string $slug Shared artist slug (equals the main-blog term slug).
 * @return string Absolute archive URL, or '' when unresolvable.
 */
function ec_artist_coverage_archive_url( $slug ) {
	$slug = (string) $slug;
	if ( '' === $slug || ! function_exists( 'ec_get_blog_id' ) ) {
		return '';
	}

	$main_blog_id = ec_get_blog_id( 'main' );
	if ( ! $main_blog_id ) {
		return '';
	}

	$base = get_site_url( (int) $main_blog_id );
	if ( empty( $base ) ) {
		return '';
	}

	return trailingslashit( $base ) . 'artist/' . rawurlencode( $slug );
}

/**
 * Gather renderable coverage-card data for a bound artist, scoped to the main
 * blog.
 *
 * Runs the article query INSIDE the main-blog context (switch_to_blog) and
 * captures everything a card needs (permalink, title, thumbnail, date) while
 * still on the main blog, because permalinks and thumbnails read the current
 * blog. The `artist` taxonomy + `post` type resolve in the switched context
 * (registered network-wide — the same reason the taxonomy-post-counts ability
 * can query them cross-site), so this needs NO main-blog plugin loaded and no
 * new ability. Returned arrays are plain scalars safe to render after
 * restore_current_blog().
 *
 * @param string $slug  Shared artist slug (main-blog `artist` term slug).
 * @param int    $limit Max cards to gather.
 * @return array[] Card payloads (each: permalink, title, image_url, date_str).
 */
function ec_artist_coverage_gather_cards( $slug, $limit = EC_ARTIST_COVERAGE_CARD_LIMIT ) {
	$slug  = (string) $slug;
	$limit = max( 1, (int) $limit );
	if ( '' === $slug || ! function_exists( 'ec_get_blog_id' ) ) {
		return array();
	}

	$main_blog_id = ec_get_blog_id( 'main' );
	if ( ! $main_blog_id ) {
		return array();
	}

	$cards = array();

	switch_to_blog( (int) $main_blog_id );
	try {
		if ( ! taxonomy_exists( 'artist' ) ) {
			return array();
		}

		$term = get_term_by( 'slug', $slug, 'artist' );
		if ( ! ( $term instanceof WP_Term ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => $limit,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'artist',
						'field'    => 'term_id',
						'terms'    => (int) $term->term_id,
					),
				),
			)
		);

		foreach ( $query->posts as $article ) {
			if ( ! ( $article instanceof WP_Post ) ) {
				continue;
			}
			$cards[] = ec_artist_coverage_build_card( $article );
		}
	} finally {
		restore_current_blog();
	}

	return $cards;
}

/**
 * Build the render-ready card payload for a single coverage article.
 *
 * MUST be called while switched to the main blog — it reads the permalink and
 * thumbnail, both of which resolve against the current blog.
 *
 * @param WP_Post $article Coverage article (main blog).
 * @return array{permalink:string,title:string,image_url:string,date_str:string}
 */
function ec_artist_coverage_build_card( $article ) {
	$permalink = get_permalink( $article );
	$title     = get_the_title( $article );
	$image_url = get_the_post_thumbnail_url( $article, 'medium_large' );
	$date_str  = get_the_date( '', $article );

	return array(
		'permalink' => (string) $permalink,
		'title'     => (string) $title,
		'image_url' => $image_url ? (string) $image_url : '',
		'date_str'  => (string) $date_str,
	);
}

/**
 * Visibility gate for the Coverage section.
 *
 * Hides the section when the artist has no coverage articles so new/unbound
 * artists never see an empty block (mirrors the Shows section's gate).
 *
 * @param int $artist_id      Artist profile post ID.
 * @param int $artist_term_id Bound main-blog `artist` term_id.
 * @return bool
 */
function ec_artist_profile_has_coverage( $artist_id, $artist_term_id = 0 ) {
	$slug = ec_artist_coverage_resolve_slug( $artist_id, $artist_term_id );
	if ( '' === $slug ) {
		return false;
	}

	// Only need to know if at least one exists.
	$cards = ec_artist_coverage_gather_cards( $slug, 1 );

	return ! empty( $cards );
}

/**
 * Render the Coverage section for the artist profile hub.
 *
 * Coverage articles render as a card grid using the shared `.related-tax-*`
 * primitives; every card LINKS to the canonical article on extrachill.com (no
 * SEO duplication). A "View all coverage →" link closes the section, completing
 * the discovery doorway to the full canonical artist archive. Server-side
 * render, no AJAX.
 *
 * @param int $artist_id      Artist profile post ID.
 * @param int $artist_term_id Bound main-blog `artist` term_id.
 * @return void
 */
function ec_render_artist_profile_coverage_section( $artist_id, $artist_term_id = 0 ) {
	$slug = ec_artist_coverage_resolve_slug( $artist_id, $artist_term_id );
	if ( '' === $slug ) {
		return;
	}

	$cards = ec_artist_coverage_gather_cards( $slug );
	if ( empty( $cards ) ) {
		return;
	}

	echo '<div class="artist-coverage-section">';
	echo '<h2 class="section-title">' . esc_html__( 'Coverage', 'extrachill-artist-platform' ) . '</h2>';
	?>
	<div class="related-tax-section artist-coverage-group">
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

						<a href="<?php echo esc_url( $card['permalink'] ); ?>" class="button-3 button-small"><?php esc_html_e( 'Read →', 'extrachill-artist-platform' ); ?></a>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php

	// "View all coverage" doorway to the full canonical artist archive on the
	// main site. Guarded so a missing slug/blog no-ops cleanly.
	$archive_url = ec_artist_coverage_archive_url( $slug );
	if ( '' !== $archive_url ) {
		echo '<div class="artist-coverage-view-all">';
		printf(
			'<a href="%s" class="button-3 button-small">%s</a>',
			esc_url( $archive_url ),
			esc_html__( 'View all coverage →', 'extrachill-artist-platform' )
		);
		echo '</div>';
	}

	echo '</div>'; // .artist-coverage-section
}
