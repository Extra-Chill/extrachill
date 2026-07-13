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
