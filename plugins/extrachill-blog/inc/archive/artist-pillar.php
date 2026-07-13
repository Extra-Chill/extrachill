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
		$items[] = extrachill_blog_build_artist_activity_item(
			isset( $event['title'] ) ? $event['title'] : '',
			isset( $event['permalink'] ) ? $event['permalink'] : '',
			isset( $event['date_iso'] ) ? $event['date_iso'] : '',
			__( 'Events', 'extrachill-blog' ),
			isset( $event['date_display'] ) ? $event['date_display'] : ''
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
				get_permalink( $topic ),
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
 * @return array|null Activity item.
 */
function extrachill_blog_build_artist_activity_item( $title, $url, $date, $source, $date_display = '' ) {
	$timestamp = strtotime( $date );
	if ( '' === $title || '' === $url || ! $timestamp ) {
		return null;
	}

	return array(
		'title'        => (string) $title,
		'url'          => (string) $url,
		'date'         => gmdate( 'c', $timestamp ),
		'date_display' => '' !== $date_display ? (string) $date_display : wp_date( get_option( 'date_format' ), $timestamp ),
		'source'       => (string) $source,
		'timestamp'    => $timestamp,
	);
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
						<p><?php echo esc_html( $item['source'] ); ?></p>
						<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
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

/**
 * Render the private artist subscription control.
 *
 * @return void
 */
function extrachill_blog_render_artist_subscription_control() {
	if ( ! extrachill_blog_is_artist_pillar() || is_paged() ) {
		return;
	}

	$term = get_queried_object();
	if ( ! ( $term instanceof WP_Term ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		?>
		<section class="entity-pillar-subscription" aria-labelledby="artist-pillar-subscription-title">
			<h2 id="artist-pillar-subscription-title"><?php esc_html_e( 'Get artist updates', 'extrachill-blog' ); ?></h2>
			<p><?php esc_html_e( 'Log in to subscribe to private Extra Chill notifications for this artist.', 'extrachill-blog' ); ?></p>
			<a class="entity-pillar-subscription-button" href="<?php echo esc_url( wp_login_url( get_term_link( $term ) ) ); ?>"><?php esc_html_e( 'Log in to subscribe', 'extrachill-blog' ); ?></a>
		</section>
		<?php
		return;
	}

	wp_enqueue_script( 'extrachill-blog-entity-subscriptions' );
	?>
	<section class="entity-pillar-subscription" data-entity-subscription-control aria-labelledby="artist-pillar-subscription-title">
		<h2 id="artist-pillar-subscription-title"><?php esc_html_e( 'Get artist updates', 'extrachill-blog' ); ?></h2>
		<p><?php esc_html_e( 'Subscribe to private Extra Chill notifications for new editorial coverage of this artist.', 'extrachill-blog' ); ?></p>
		<button
			class="entity-pillar-subscription-button"
			type="button"
			aria-pressed="false"
			disabled
			data-entity-subscription
			data-entity-type="artist"
			data-taxonomy="artist"
			data-slug="<?php echo esc_attr( $term->slug ); ?>"
			data-endpoint="<?php echo esc_url( rest_url( 'wp-abilities/v1/abilities/' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
		>
			<?php esc_html_e( 'Subscribe to updates', 'extrachill-blog' ); ?>
		</button>
		<p class="entity-pillar-subscription-status" aria-live="polite"></p>
	</section>
	<?php
}
add_action( 'extrachill_archive_below_description', 'extrachill_blog_render_artist_subscription_control', 5 );
