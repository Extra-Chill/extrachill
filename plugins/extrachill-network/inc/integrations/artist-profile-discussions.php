<?php
/**
 * Community discussions on canonical artist profiles.
 *
 * Network owns this integration because it is active on the Artist site and can
 * resolve Community data without loading Community or bbPress there.
 *
 * @package ExtraChillNetwork
 */

defined( 'ABSPATH' ) || exit;

/** Maximum recent discussions rendered on an artist profile. */
const EXTRACHILL_NETWORK_ARTIST_TOPIC_LIMIT = 4;

/**
 * Register the cross-site discussion section through Artist Platform's seam.
 *
 * @param array[] $sections Registered artist-profile sections.
 * @return array[]
 */
function extrachill_network_register_artist_discussions_section( $sections ) {
	$sections[] = array(
		'id'       => 'discussions',
		'label'    => __( 'Discussions', 'extrachill-network' ),
		'priority' => 50,
		'as_tab'   => false,
		'visible'  => 'extrachill_network_artist_discussions_visible',
		'render'   => 'extrachill_network_render_artist_discussions_section',
	);

	return $sections;
}
add_filter( 'ec_artist_profile_sections', 'extrachill_network_register_artist_discussions_section', 10, 3 );

/**
 * Resolve the bound main-site artist term to its network join key.
 *
 * @param int $artist_term_id Main-site artist term ID.
 * @return string Artist slug, or an empty string when unavailable.
 */
function extrachill_network_resolve_bound_artist_slug( $artist_term_id ) {
	$artist_term_id = (int) $artist_term_id;
	$main_blog_id   = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
	if ( $artist_term_id <= 0 || $main_blog_id <= 0 ) {
		return '';
	}

	$slug = '';
	switch_to_blog( $main_blog_id );
	try {
		if ( ! taxonomy_exists( 'artist' ) ) {
			return '';
		}

		$term = get_term( $artist_term_id, 'artist' );
		if ( $term instanceof WP_Term ) {
			$slug = (string) $term->slug;
		}
	} finally {
		restore_current_blog();
	}

	return $slug;
}

/**
 * Resolve Community's persisted canonical topic route without bbPress code.
 *
 * This must run in Community blog context. The rewrite-rule check proves the
 * configured destination still maps to the `topic` post type; stale or missing
 * configuration fails closed rather than emitting a guessed URL.
 *
 * @return string Relative topic base, without leading or trailing slashes.
 */
function extrachill_network_get_community_topic_base() {
	$topic_slug = sanitize_title( (string) get_option( '_bbp_topic_slug', '' ) );
	if ( '' === $topic_slug ) {
		return '';
	}

	$parts = array();
	if ( (bool) get_option( '_bbp_include_root', false ) ) {
		$root_slug = sanitize_title( (string) get_option( '_bbp_root_slug', '' ) );
		if ( '' === $root_slug ) {
			return '';
		}
		$parts[] = $root_slug;
	}
	$parts[] = $topic_slug;
	$base    = implode( '/', $parts );
	$rules   = get_option( 'rewrite_rules', array() );

	if ( ! is_array( $rules ) ) {
		return '';
	}

	foreach ( $rules as $regex => $query ) {
		if (
			str_starts_with( (string) $regex, preg_quote( $base, '#' ) . '/' ) &&
			preg_match( '/(?:^|[?&])topic=\\$matches\\[1\\](?:&|$)/', (string) $query )
		) {
			return $base;
		}
	}

	return '';
}

/**
 * Build a canonical Community topic URL from destination-owned routing data.
 *
 * This must run in Community blog context.
 *
 * @param WP_Post $topic     Community topic post.
 * @param string  $topic_base Verified topic route base.
 * @return string Canonical URL, or an empty string when invalid.
 */
function extrachill_network_get_community_topic_url( $topic, $topic_base ) {
	if (
		! ( $topic instanceof WP_Post ) ||
		'topic' !== $topic->post_type ||
		! in_array( $topic->post_status, array( 'publish', 'closed' ), true ) ||
		'' === (string) $topic->post_name ||
		'' === $topic_base
	) {
		return '';
	}

	$site_url = get_site_url( get_current_blog_id() );
	if ( ! is_string( $site_url ) || '' === $site_url ) {
		return '';
	}

	return user_trailingslashit(
		trailingslashit( $site_url ) . $topic_base . '/' . $topic->post_name
	);
}

/**
 * Gather a canonical Community archive and bounded topic-card payloads.
 *
 * Main-site and Community term IDs are site-local, so the shared slug is used
 * as the join key. All destination-dependent values are captured before the
 * blog context is restored.
 *
 * @param int $artist_id      Artist profile post ID.
 * @param int $artist_term_id Bound main-site artist term ID.
 * @return array{archive_url:string,artist_name:string,topics:array[]}
 */
function extrachill_network_get_artist_discussions( $artist_id, $artist_term_id ) {
	static $memo = array();

	$artist_id      = (int) $artist_id;
	$artist_term_id = (int) $artist_term_id;
	$key            = $artist_id . ':' . $artist_term_id;
	$empty          = array(
		'archive_url' => '',
		'artist_name' => '',
		'topics'      => array(),
	);

	if ( isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}
	$memo[ $key ] = $empty;

	if ( $artist_id <= 0 || 'artist_profile' !== get_post_type( $artist_id ) ) {
		return $empty;
	}

	$slug              = extrachill_network_resolve_bound_artist_slug( $artist_term_id );
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'community' ) : 0;
	$community_site    = $community_blog_id > 0 ? get_site( $community_blog_id ) : null;
	if (
		'' === $slug ||
		! ( $community_site instanceof WP_Site ) ||
		$community_site->deleted ||
		$community_site->archived ||
		$community_site->spam
	) {
		return $empty;
	}

	switch_to_blog( $community_blog_id );
	try {
		if ( ! taxonomy_exists( 'artist' ) ) {
			return $empty;
		}

		$term = get_term_by( 'slug', $slug, 'artist' );
		if ( ! ( $term instanceof WP_Term ) ) {
			return $empty;
		}

		$archive_url = get_term_link( $term );
		$topic_base  = extrachill_network_get_community_topic_base();
		if ( is_wp_error( $archive_url ) || '' === (string) $archive_url || '' === $topic_base ) {
			return $empty;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'topic',
				'post_status'            => array( 'publish', 'closed' ),
				'posts_per_page'         => EXTRACHILL_NETWORK_ARTIST_TOPIC_LIMIT,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'artist',
						'field'    => 'term_id',
						'terms'    => (int) $term->term_id,
					),
				),
			)
		);

		$topics = array();
		foreach ( $query->posts as $topic ) {
			$url = extrachill_network_get_community_topic_url( $topic, $topic_base );
			if ( '' === $url ) {
				continue;
			}

			$topics[] = array(
				'url'   => $url,
				'title' => wp_strip_all_tags( html_entity_decode( (string) get_the_title( $topic ), ENT_QUOTES, get_bloginfo( 'charset' ) ) ),
				'date'  => (string) get_the_date( '', $topic ),
			);
		}

		$memo[ $key ] = array(
			'archive_url' => (string) $archive_url,
			'artist_name' => (string) $term->name,
			'topics'      => $topics,
		);
	} finally {
		restore_current_blog();
	}

	return $memo[ $key ];
}

/**
 * Hide the section when no canonical Community destination can be resolved.
 *
 * @param int $artist_id      Artist profile post ID.
 * @param int $artist_term_id Bound main-site artist term ID.
 * @return bool
 */
function extrachill_network_artist_discussions_visible( $artist_id, $artist_term_id ) {
	$data = extrachill_network_get_artist_discussions( $artist_id, $artist_term_id );

	return '' !== $data['archive_url'];
}

/**
 * Render recent discussion cards and the canonical Community archive link.
 *
 * @param int $artist_id      Artist profile post ID.
 * @param int $artist_term_id Bound main-site artist term ID.
 * @return void
 */
function extrachill_network_render_artist_discussions_section( $artist_id, $artist_term_id ) {
	$data = extrachill_network_get_artist_discussions( $artist_id, $artist_term_id );
	if ( '' === $data['archive_url'] ) {
		return;
	}

	echo '<section class="artist-discussions-section">';
	echo '<h2 class="section-title">' . esc_html__( 'Discussions', 'extrachill-network' ) . '</h2>';

	if ( ! empty( $data['topics'] ) ) {
		echo '<div class="related-tax-section artist-discussions-group"><div class="related-tax-grid">';
		foreach ( $data['topics'] as $topic ) {
			?>
			<div class="related-tax-card artist-discussion-card">
				<h4 class="related-tax-title"><a href="<?php echo esc_url( $topic['url'] ); ?>"><?php echo esc_html( $topic['title'] ); ?></a></h4>
				<div class="related-tax-meta">
					<?php if ( '' !== $topic['date'] ) : ?>
						<div class="ec-related-meta-item"><span><?php echo esc_html( $topic['date'] ); ?></span></div>
					<?php endif; ?>
					<a href="<?php echo esc_url( $topic['url'] ); ?>" class="button-3 button-small"><?php esc_html_e( 'Join discussion', 'extrachill-network' ); ?></a>
				</div>
			</div>
			<?php
		}
		echo '</div></div>';
	} else {
		echo '<p class="artist-discussions-empty">' . esc_html__( 'No discussions yet. Start the conversation in the Community.', 'extrachill-network' ) . '</p>';
	}

	printf(
		'<div class="artist-discussions-view-all"><a href="%1$s" class="button-3 button-small">%2$s</a></div>',
		esc_url( $data['archive_url'] ),
		esc_html__( 'View artist discussions', 'extrachill-network' )
	);
	echo '</section>';
}
