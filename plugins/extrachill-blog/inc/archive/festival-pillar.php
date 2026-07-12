<?php
/**
 * Main-site festival pillar composition.
 *
 * @package ExtraChillBlog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check whether the current request is a main-site festival archive.
 *
 * @return bool
 */
function extrachill_blog_is_festival_pillar() {
	$is_main_site = function_exists( 'ec_get_current_site_key' )
		? 'main' === ec_get_current_site_key()
		: 1 === (int) get_current_blog_id();

	return $is_main_site && is_tax( 'festival' );
}

/**
 * Replace generic taxonomy buttons with the festival pillar navigation.
 *
 * @return void
 */
function extrachill_blog_prepare_festival_pillar() {
	if ( ! extrachill_blog_is_festival_pillar() ) {
		return;
	}

	remove_action( 'extrachill_archive_below_description', 'extrachill_render_cross_site_taxonomy_links' );
	wp_enqueue_style( 'extrachill-blog-festival-pillar' );
}
add_action( 'wp', 'extrachill_blog_prepare_festival_pillar' );

/**
 * Render the festival's specialized network destinations.
 *
 * @return void
 */
function extrachill_blog_render_festival_network_routes() {
	if ( ! extrachill_blog_is_festival_pillar() || ! function_exists( 'extrachill_get_cross_site_term_links' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! ( $term instanceof WP_Term ) ) {
		return;
	}

	$network_links = extrachill_get_cross_site_term_links( $term, 'festival' );
	$links         = array();
	foreach ( $network_links as $link ) {
		if ( ! empty( $link['site_key'] ) ) {
			$links[ $link['site_key'] ] = $link;
		}
	}

	if ( empty( $links['wire'] ) && empty( $links['events'] ) ) {
		return;
	}

	/* translators: %s: festival name. */
	$navigation_label = sprintf( __( '%s across Extra Chill', 'extrachill-blog' ), $term->name );
	/* translators: %s: festival name. */
	$wire_title = sprintf( __( 'Latest %s updates', 'extrachill-blog' ), $term->name );
	/* translators: %s: number of Festival Wire stories. */
	$wire_count = ! empty( $links['wire'] ) ? sprintf( _n( '%s report, rumor, and announcement', '%s reports, rumors, and announcements', (int) $links['wire']['count'], 'extrachill-blog' ), number_format_i18n( (int) $links['wire']['count'] ) ) : '';
	/* translators: %s: festival name. */
	$events_title = sprintf( __( 'Upcoming %s dates', 'extrachill-blog' ), $term->name );
	/* translators: %s: number of upcoming events. */
	$events_count = ! empty( $links['events'] ) ? sprintf( _n( '%s upcoming event', '%s upcoming events', (int) $links['events']['count'], 'extrachill-blog' ), number_format_i18n( (int) $links['events']['count'] ) ) : '';
	?>
	<nav class="festival-pillar-routes" aria-label="<?php echo esc_attr( $navigation_label ); ?>">
		<?php if ( ! empty( $links['wire'] ) ) : ?>
			<a class="festival-pillar-route festival-pillar-route-wire" href="<?php echo esc_url( $links['wire']['url'] ); ?>">
				<span class="festival-pillar-route-kicker"><?php esc_html_e( 'Festival Wire', 'extrachill-blog' ); ?></span>
				<strong><?php echo esc_html( $wire_title ); ?></strong>
				<span><?php echo esc_html( $wire_count ); ?></span>
			</a>
		<?php endif; ?>

		<?php if ( ! empty( $links['events'] ) ) : ?>
			<a class="festival-pillar-route festival-pillar-route-events" href="<?php echo esc_url( $links['events']['url'] ); ?>">
				<span class="festival-pillar-route-kicker"><?php esc_html_e( 'Events', 'extrachill-blog' ); ?></span>
				<strong><?php echo esc_html( $events_title ); ?></strong>
				<span><?php echo esc_html( $events_count ); ?></span>
			</a>
		<?php endif; ?>
	</nav>
	<?php
}
add_action( 'extrachill_archive_below_description', 'extrachill_blog_render_festival_network_routes' );

/**
 * Frame the main archive loop as the festival's editorial coverage.
 *
 * @return void
 */
function extrachill_blog_render_festival_coverage_heading() {
	if ( ! extrachill_blog_is_festival_pillar() ) {
		return;
	}

	$term = get_queried_object();
	if ( ! ( $term instanceof WP_Term ) ) {
		return;
	}

	printf(
		'<header class="festival-pillar-coverage-header"><p>%s</p><h2>%s</h2></header>',
		esc_html__( 'From the publication', 'extrachill-blog' ),
		/* translators: %s: festival name. */
		esc_html( sprintf( __( 'Extra Chill coverage of %s', 'extrachill-blog' ), $term->name ) )
	);
}
add_action( 'extrachill_archive_above_posts', 'extrachill_blog_render_festival_coverage_heading', 20 );

/**
 * Render the existing network newsletter form after the archive content.
 *
 * @return void
 */
function extrachill_blog_render_festival_newsletter() {
	if ( ! extrachill_blog_is_festival_pillar() || is_paged() ) {
		return;
	}
	?>
	<section class="festival-pillar-newsletter" aria-labelledby="festival-pillar-newsletter-title">
		<h2 id="festival-pillar-newsletter-title"><?php esc_html_e( 'Stay in the festival loop', 'extrachill-blog' ); ?></h2>
		<p><?php esc_html_e( 'Get music news, festival updates, and the best of Extra Chill delivered to your inbox.', 'extrachill-blog' ); ?></p>
		<?php do_action( 'extrachill_render_newsletter_form', 'archive' ); ?>
	</section>
	<?php
}
add_action( 'extrachill_after_body_content', 'extrachill_blog_render_festival_newsletter', 8 );
