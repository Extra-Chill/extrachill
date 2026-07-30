<?php
/**
 * Main-site artist editorial router.
 *
 * Artist Platform owns profiles. This archive only connects editorial coverage
 * to the published destinations already resolved by the Network layer.
 *
 * @package ExtraChillBlog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check whether the current request is a main-site artist archive.
 *
 * @return bool
 */
function extrachill_blog_is_artist_pillar() {
	return extrachill_blog_is_main_taxonomy_archive( 'artist' );
}

/**
 * Prepare the artist editorial router without changing the native archive loop.
 *
 * @return void
 */
function extrachill_blog_prepare_artist_pillar() {
	if ( ! extrachill_blog_is_artist_pillar() ) {
		return;
	}

	remove_action( 'extrachill_archive_below_description', 'extrachill_render_cross_site_taxonomy_links' );
	wp_enqueue_style( 'extrachill-blog-entity-pillar' );
}
add_action( 'wp', 'extrachill_blog_prepare_artist_pillar' );

/**
 * Render only the artist's live cross-site destinations.
 *
 * @return void
 */
function extrachill_blog_render_artist_network_routes() {
	if ( ! extrachill_blog_is_artist_pillar() || ! function_exists( 'extrachill_get_cross_site_term_links' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! ( $term instanceof WP_Term ) ) {
		return;
	}

	$links = array();
	foreach ( extrachill_get_cross_site_term_links( $term, 'artist' ) as $link ) {
		if ( ! empty( $link['site_key'] ) ) {
			$links[ $link['site_key'] ] = $link;
		}
	}

	$destinations = array(
		'artist'    => array(
			'label'       => __( 'Artist Platform', 'extrachill-blog' ),
			'description' => __( 'Official artist profile', 'extrachill-blog' ),
		),
		'events'    => array(
			'label'       => __( 'Events', 'extrachill-blog' ),
			'description' => __( 'Upcoming shows', 'extrachill-blog' ),
		),
		'community' => array(
			'label'       => __( 'Community', 'extrachill-blog' ),
			'description' => __( 'Artist discussions', 'extrachill-blog' ),
		),
		'shop'      => array(
			'label'       => __( 'Shop', 'extrachill-blog' ),
			'description' => __( 'Artist merchandise', 'extrachill-blog' ),
		),
	);
	$links        = array_intersect_key( $links, $destinations );
	if ( empty( $links ) ) {
		return;
	}
	?>
	<?php /* translators: %s: artist name. */ ?>
	<nav class="entity-pillar-routes" aria-label="<?php echo esc_attr( sprintf( __( '%s across Extra Chill', 'extrachill-blog' ), $term->name ) ); ?>">
		<?php foreach ( $destinations as $site_key => $destination ) : ?>
			<?php if ( empty( $links[ $site_key ] ) ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<a class="entity-pillar-route entity-pillar-route-<?php echo esc_attr( $site_key ); ?>" href="<?php echo esc_url( $links[ $site_key ]['url'] ); ?>">
				<span class="entity-pillar-route-kicker"><?php echo esc_html( $destination['label'] ); ?></span>
				<strong><?php echo esc_html( $destination['description'] ); ?></strong>
			</a>
		<?php endforeach; ?>
	</nav>
	<?php
}
add_action( 'extrachill_archive_below_description', 'extrachill_blog_render_artist_network_routes' );

/**
 * Build the artist archive's small, source-owned activity timeline.
 *
 * This is deliberately local to the editorial artist router. Events supply
 * date-aware rows through their existing read ability, while Community's
 * native artist archive supplies topic rows. The Artist Platform contributes
 * only its public profile update timestamp, never profile content.
 *
 * @param WP_Term $term Artist term.
 * @return array[] Renderable activity items.
 */
function extrachill_blog_get_artist_activity( $term ) {
	if ( ! ( $term instanceof WP_Term ) ) {
		return array();
	}

	$items = array_merge(
		extrachill_blog_get_artist_coverage_activity( $term ),
		extrachill_blog_get_artist_events_activity( $term ),
		extrachill_blog_get_artist_community_activity( $term ),
		extrachill_blog_get_artist_platform_activity( $term )
	);

	$items = extrachill_blog_sort_artist_activity( $items );

	return array_slice( $items, 0, 12 );
}

/**
 * Gather recent main-site coverage without changing the native archive loop.
 *
 * @param WP_Term $term Artist term.
 * @return array[] Activity items.
 */
function extrachill_blog_get_artist_coverage_activity( $term ) {
	$items = array();
	$posts = get_posts(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 4,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'artist',
					'field'    => 'term_id',
					'terms'    => (int) $term->term_id,
				),
			),
		)
	);

	foreach ( $posts as $post ) {
		$items[] = extrachill_blog_build_artist_activity_item(
			get_the_title( $post ),
			get_permalink( $post ),
			get_post_time( 'c', true, $post ),
			__( 'Editorial coverage', 'extrachill-blog' )
		);
	}

	return array_filter( $items );
}

/**
 * Gather Events-owned rows through its date-aware read ability.
 *
 * @param WP_Term $term Artist term.
 * @return array[] Activity items.
 */
function extrachill_blog_get_artist_events_activity( $term ) {
	if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return array();
	}

	$force_loopback = static function () {
		return true;
	};
	add_filter( 'ec_cross_site_use_http_loopback', $force_loopback, 10 );
	$result = ec_cross_site_rest_request(
		'events',
		'GET',
		'/wp-abilities/v1/abilities/data-machine-events/events-by-term/run',
		array(
			'query' => array(
				'input' => array(
					'taxonomy'  => 'artist',
					'term_slug' => $term->slug,
					'scope'     => 'all',
					'limit'     => 4,
				),
			),
		)
	);
	remove_filter( 'ec_cross_site_use_http_loopback', $force_loopback, 10 );

	if ( is_wp_error( $result ) || ! is_array( $result ) ) {
		return array();
	}

	$items = array();
	foreach ( array_merge( $result['upcoming'] ?? array(), $result['past'] ?? array() ) as $event ) {
		$event_context = array_filter(
			array(
				isset( $event['venue_name'] ) ? $event['venue_name'] : '',
				isset( $event['time_display'] ) ? $event['time_display'] : '',
			)
		);
		$items[]       = extrachill_blog_build_artist_activity_item(
			isset( $event['title'] ) ? $event['title'] : '',
			isset( $event['permalink'] ) ? $event['permalink'] : '',
			isset( $event['date_iso'] ) ? $event['date_iso'] : '',
			__( 'Events', 'extrachill-blog' ),
			isset( $event['date_display'] ) ? $event['date_display'] : '',
			implode( ', ', $event_context ),
			isset( $event['timing'] ) ? $event['timing'] : '',
			isset( $event['relationships'] ) ? $event['relationships'] : array()
		);
	}

	return array_filter( $items );
}

/**
 * Gather the native Community artist archive's recent topics.
 *
 * Community intentionally exposes this surface as an archive, not a REST
 * collection. Match that archive's topic and post-status scope here.
 *
 * @param WP_Term $term Artist term.
 * @return array[] Activity items.
 */
function extrachill_blog_get_artist_community_activity( $term ) {
	if ( ! function_exists( 'ec_get_blog_id' ) || ! ec_get_blog_id( 'community' ) ) {
		return array();
	}

	$items = array();
	switch_to_blog( (int) ec_get_blog_id( 'community' ) );
	try {
		$community_term = get_term_by( 'slug', $term->slug, 'artist' );
		if ( ! ( $community_term instanceof WP_Term ) ) {
			return array();
		}

		$topics = get_posts(
			array(
				'post_type'           => 'topic',
				'post_status'         => array( 'publish', 'closed' ),
				'posts_per_page'      => 4,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'artist',
						'field'    => 'term_id',
						'terms'    => (int) $community_term->term_id,
					),
				),
			)
		);

		foreach ( $topics as $topic ) {
			$items[] = extrachill_blog_build_artist_activity_item(
				get_the_title( $topic ),
				extrachill_blog_get_artist_community_topic_permalink( $topic ),
				get_post_time( 'c', true, $topic ),
				__( 'Community discussion', 'extrachill-blog' )
			);
		}
	} finally {
		restore_current_blog();
	}

	return array_filter( $items );
}

/**
 * Resolve a Community topic's canonical bbPress permalink.
 *
 * @param WP_Post $topic Community topic post.
 * @return string Canonical topic URL.
 */
function extrachill_blog_get_artist_community_topic_permalink( $topic ) {
	$topic_id = $topic instanceof WP_Post ? (int) $topic->ID : 0;
	if ( $topic_id && function_exists( 'bbp_get_topic_permalink' ) ) {
		return bbp_get_topic_permalink( $topic_id );
	}

	return $topic_id ? get_permalink( $topic_id ) : '';
}

/**
 * Gather the canonical Artist Platform profile's public update timestamp.
 *
 * @param WP_Term $term Artist term.
 * @return array[] Activity items.
 */
function extrachill_blog_get_artist_platform_activity( $term ) {
	if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return array();
	}

	$force_loopback = static function () {
		return true;
	};
	add_filter( 'ec_cross_site_use_http_loopback', $force_loopback, 10 );
	$profiles = ec_cross_site_rest_request(
		'artist',
		'GET',
		'/wp/v2/artist_profile',
		array(
			'query' => array(
				'slug'     => $term->slug,
				'per_page' => 1,
				'_fields'  => 'link,modified_gmt,modified',
			),
		)
	);
	remove_filter( 'ec_cross_site_use_http_loopback', $force_loopback, 10 );

	if ( is_wp_error( $profiles ) || empty( $profiles[0] ) || ! is_array( $profiles[0] ) ) {
		return array();
	}

	$profile = $profiles[0];
	return array_filter(
		array(
			extrachill_blog_build_artist_activity_item(
				__( 'Artist profile updated', 'extrachill-blog' ),
				isset( $profile['link'] ) ? $profile['link'] : '',
				isset( $profile['modified_gmt'] ) ? $profile['modified_gmt'] : ( $profile['modified'] ?? '' ),
				__( 'Artist Platform', 'extrachill-blog' )
			),
		)
	);
}

/**
 * Create a timeline item only when its native link and date are available.
 *
 * @param string $title        Item title.
 * @param string $url          Canonical source URL.
 * @param string $date         ISO-compatible date.
 * @param string $source       Source label.
 * @param string $date_display Source-formatted date, when available.
 * @param string $context      Source-owned venue and time context, when available.
 * @param string $timing       Source-owned upcoming or past timing, when available.
 * @param array  $relationships Events-owned venue, location, and festival relationships.
 * @return array|null Activity item.
 */
function extrachill_blog_build_artist_activity_item( $title, $url, $date, $source, $date_display = '', $context = '', $timing = '', $relationships = array() ) {
	$timestamp = strtotime( $date );
	if ( '' === $title || '' === $url || ! $timestamp ) {
		return null;
	}

	return array(
		'title'         => (string) $title,
		'url'           => (string) $url,
		'date'          => gmdate( 'c', $timestamp ),
		'date_display'  => '' !== $date_display ? (string) $date_display : wp_date( get_option( 'date_format' ), $timestamp ),
		'source'        => (string) $source,
		'context'       => (string) $context,
		'timing'        => (string) $timing,
		'relationships' => is_array( $relationships ) ? $relationships : array(),
		'timestamp'     => $timestamp,
	);
}

/**
 * Return renderable taxonomy badges from Events-owned relationship objects.
 *
 * @param array $relationships Event relationship data.
 * @return array[] Badge data.
 */
function extrachill_blog_get_artist_activity_badges( $relationships ) {
	if ( ! is_array( $relationships ) ) {
		return array();
	}

	$badges = array();
	foreach (
		array(
			'venue'    => 'venue-badge',
			'location' => 'location-badge',
			'festival' => 'festival-badge',
		) as $relationship_type => $badge_class
	) {
		$relationship = $relationships[ $relationship_type ] ?? null;
		if ( ! is_array( $relationship ) || empty( $relationship['name'] ) || empty( $relationship['url'] ) ) {
			continue;
		}

		$label   = 'location' === $relationship_type && ! empty( $relationship['display'] )
			? $relationship['display']
			: $relationship['name'];
		$classes = array( 'taxonomy-badge', $badge_class );
		if ( ! empty( $relationship['slug'] ) ) {
			$classes[] = $relationship_type . '-' . sanitize_html_class( $relationship['slug'] );
		}

		$badges[] = array(
			'label' => (string) $label,
			'url'   => (string) $relationship['url'],
			'class' => implode( ' ', $classes ),
		);
	}

	return $badges;
}

/**
 * Render Events-owned taxonomy badges for an activity row.
 *
 * @param array $relationships Event relationship data.
 * @return void
 */
function extrachill_blog_render_artist_activity_badges( $relationships ) {
	$badges = extrachill_blog_get_artist_activity_badges( $relationships );
	if ( empty( $badges ) ) {
		return;
	}
	?>
	<div class="taxonomy-badges entity-pillar-activity-badges">
		<?php foreach ( $badges as $badge ) : ?>
			<a href="<?php echo esc_url( $badge['url'] ); ?>" class="<?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></a>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Sort activity newest-first.
 *
 * @param array[] $items Activity items.
 * @return array[] Sorted activity items.
 */
function extrachill_blog_sort_artist_activity( $items ) {
	usort(
		$items,
		static function ( $left, $right ) {
			return (int) $right['timestamp'] <=> (int) $left['timestamp'];
		}
	);

	return $items;
}

/**
 * Render an artist-specific chronological activity timeline.
 *
 * @return void
 */
function extrachill_blog_render_artist_activity() {
	if ( ! extrachill_blog_is_artist_pillar() || is_paged() ) {
		return;
	}

	$term = get_queried_object();
	if ( ! ( $term instanceof WP_Term ) ) {
		return;
	}

	$items = extrachill_blog_get_artist_activity( $term );
	if ( empty( $items ) ) {
		return;
	}
	?>
	<section class="entity-pillar-activity" aria-labelledby="artist-activity-title">
		<h2 id="artist-activity-title"><?php esc_html_e( 'Artist activity', 'extrachill-blog' ); ?></h2>
		<ol class="entity-pillar-activity-list">
			<?php foreach ( $items as $item ) : ?>
				<li class="entity-pillar-activity-item">
					<time datetime="<?php echo esc_attr( $item['date'] ); ?>"><?php echo esc_html( $item['date_display'] ); ?></time>
					<div>
						<p><?php echo esc_html( $item['source'] ); ?><?php echo ! empty( $item['timing'] ) ? esc_html( ' - ' . ucfirst( $item['timing'] ) ) : ''; ?></p>
						<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
						<?php if ( ! empty( $item['context'] ) ) : ?>
							<span class="entity-pillar-activity-context"><?php echo esc_html( $item['context'] ); ?></span>
						<?php endif; ?>
						<?php extrachill_blog_render_artist_activity_badges( $item['relationships'] ?? array() ); ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
	</section>
	<?php
}
add_action( 'extrachill_archive_below_description', 'extrachill_blog_render_artist_activity', 15 );

/**
 * Frame the native archive loop as editorial coverage.
 *
 * @return void
 */
function extrachill_blog_render_artist_coverage_heading() {
	if ( ! extrachill_blog_is_artist_pillar() ) {
		return;
	}

	$term = get_queried_object();
	if ( ! ( $term instanceof WP_Term ) ) {
		return;
	}

	printf(
		'<header class="entity-pillar-coverage-header"><p>%s</p><h2>%s</h2></header>',
		esc_html__( 'From the publication', 'extrachill-blog' ),
		/* translators: %s: artist name. */
		esc_html( sprintf( __( 'Extra Chill coverage of %s', 'extrachill-blog' ), $term->name ) )
	);
}
add_action( 'extrachill_archive_above_posts', 'extrachill_blog_render_artist_coverage_heading', 20 );

/** Render account-owned artist preferences. */
function extrachill_blog_render_artist_subscription_control() {
	if ( ! extrachill_blog_is_artist_pillar() || is_paged() ) {
		return;
	}

	$term = get_queried_object();
	if ( ! ( $term instanceof WP_Term ) ) {
		return;
	}
	$docs_url = function_exists( 'ec_get_site_url' ) ? trailingslashit( ec_get_site_url( 'docs' ) ) . 'artist-platform/artist-preferences/' : '';
	$term_url = get_term_link( $term );
	$term_url = is_wp_error( $term_url ) ? home_url( '/' ) : $term_url;

	if ( ! is_user_logged_in() ) {
		?>
		<section class="entity-pillar-subscription" aria-labelledby="artist-pillar-preferences-title">
			<h2 id="artist-pillar-preferences-title"><?php esc_html_e( 'Artist preferences', 'extrachill-blog' ); ?></h2>
			<p>
				<?php esc_html_e( 'Control artist alerts and email access.', 'extrachill-blog' ); ?>
				<?php if ( $docs_url ) : ?>
					<a href="<?php echo esc_url( $docs_url ); ?>"><?php esc_html_e( 'How preferences work', 'extrachill-blog' ); ?></a>
				<?php endif; ?>
			</p>
			<a class="button-1 button-medium entity-pillar-subscription-button" href="<?php echo esc_url( wp_login_url( $term_url ) ); ?>"><?php esc_html_e( 'Log in to manage', 'extrachill-blog' ); ?></a>
		</section>
		<?php
		return;
	}

	wp_enqueue_script( 'extrachill-blog-entity-subscriptions' );
	?>
	<section class="entity-pillar-subscription entity-pillar-preferences" aria-labelledby="artist-pillar-preferences-title">
		<div class="entity-pillar-preferences__header">
			<h2 id="artist-pillar-preferences-title"><?php esc_html_e( 'Artist preferences', 'extrachill-blog' ); ?></h2>
			<p>
				<?php esc_html_e( 'Control artist alerts and email access.', 'extrachill-blog' ); ?>
				<?php if ( $docs_url ) : ?>
					<a href="<?php echo esc_url( $docs_url ); ?>"><?php esc_html_e( 'How preferences work', 'extrachill-blog' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<div class="entity-pillar-preferences__controls">
			<div class="entity-pillar-preferences__control" data-entity-subscription-control>
				<div class="entity-pillar-preferences__copy">
					<h3><?php esc_html_e( 'Artist notifications', 'extrachill-blog' ); ?></h3>
					<p class="entity-pillar-subscription-status" aria-live="polite"><?php esc_html_e( 'Checking...', 'extrachill-blog' ); ?></p>
				</div>
				<button
				class="button-1 button-medium entity-pillar-subscription-button"
				type="button"
				aria-pressed="false"
				disabled
				data-entity-subscription
				data-entity-type="artist"
				data-taxonomy="artist"
				data-slug="<?php echo esc_attr( $term->slug ); ?>"
				data-endpoint="<?php echo esc_url( rest_url( 'wp-abilities/v1/abilities/' ) ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				data-on-label="<?php esc_attr_e( 'Turn off', 'extrachill-blog' ); ?>"
				data-off-label="<?php esc_attr_e( 'Turn on', 'extrachill-blog' ); ?>"
				data-on-status="<?php esc_attr_e( 'On', 'extrachill-blog' ); ?>"
				data-off-status="<?php esc_attr_e( 'Off', 'extrachill-blog' ); ?>"
				><?php esc_html_e( 'Turn on', 'extrachill-blog' ); ?></button>
			</div>
			<div class="entity-pillar-preferences__control" data-entity-subscription-control>
				<div class="entity-pillar-preferences__copy">
					<h3><?php esc_html_e( 'Artist email list', 'extrachill-blog' ); ?></h3>
					<p class="entity-pillar-subscription-status" aria-live="polite"><?php esc_html_e( 'Checking...', 'extrachill-blog' ); ?></p>
				</div>
				<button
				class="button-1 button-medium entity-pillar-subscription-button"
				type="button"
				aria-pressed="false"
				disabled
				data-entity-subscription
				data-entity-type="artist-email-sharing"
				data-taxonomy="artist"
				data-slug="<?php echo esc_attr( $term->slug ); ?>"
				data-endpoint="<?php echo esc_url( rest_url( 'wp-abilities/v1/abilities/' ) ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				data-on-label="<?php esc_attr_e( 'Stop sharing', 'extrachill-blog' ); ?>"
				data-off-label="<?php esc_attr_e( 'Share email', 'extrachill-blog' ); ?>"
				data-on-status="<?php esc_attr_e( 'Shared with this artist', 'extrachill-blog' ); ?>"
				data-off-status="<?php esc_attr_e( 'Not shared with this artist', 'extrachill-blog' ); ?>"
				><?php esc_html_e( 'Share email', 'extrachill-blog' ); ?></button>
			</div>
		</div>
	</section>
	<?php
}
add_action( 'extrachill_archive_below_description', 'extrachill_blog_render_artist_subscription_control', 5 );
