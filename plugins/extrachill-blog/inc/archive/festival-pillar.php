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
 * Check whether the current request is a main-site taxonomy archive.
 *
 * @param string $taxonomy Taxonomy slug.
 * @return bool
 */
function extrachill_blog_is_main_taxonomy_archive( $taxonomy ) {
	$is_main_site = function_exists( 'ec_get_current_site_key' )
		? 'main' === ec_get_current_site_key()
		: 1 === (int) get_current_blog_id();

	return $is_main_site && is_tax( $taxonomy );
}

/**
 * Check whether the current request is a main-site festival archive.
 *
 * @return bool
 */
function extrachill_blog_is_festival_pillar() {
	return extrachill_blog_is_main_taxonomy_archive( 'festival' );
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
	wp_enqueue_style( 'extrachill-blog-entity-pillar' );
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

	if ( empty( $links['wire'] ) && empty( $links['events'] ) && empty( $links['community'] ) ) {
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
	/* translators: %s: festival name. */
	$community_title = sprintf( __( 'Discuss %s with the community', 'extrachill-blog' ), $term->name );
	/* translators: %s: number of Community topics. */
	$community_count = ! empty( $links['community'] ) ? sprintf( _n( '%s festival discussion', '%s festival discussions', (int) $links['community']['count'], 'extrachill-blog' ), number_format_i18n( (int) $links['community']['count'] ) ) : '';
	?>
	<nav class="entity-pillar-routes" aria-label="<?php echo esc_attr( $navigation_label ); ?>">
		<?php if ( ! empty( $links['wire'] ) ) : ?>
			<a class="entity-pillar-route entity-pillar-route-wire" href="<?php echo esc_url( $links['wire']['url'] ); ?>">
				<span class="entity-pillar-route-kicker"><?php esc_html_e( 'Festival Wire', 'extrachill-blog' ); ?></span>
				<strong><?php echo esc_html( $wire_title ); ?></strong>
				<span><?php echo esc_html( $wire_count ); ?></span>
			</a>
		<?php endif; ?>

		<?php if ( ! empty( $links['events'] ) ) : ?>
			<a class="entity-pillar-route entity-pillar-route-events" href="<?php echo esc_url( $links['events']['url'] ); ?>">
				<span class="entity-pillar-route-kicker"><?php esc_html_e( 'Events', 'extrachill-blog' ); ?></span>
				<strong><?php echo esc_html( $events_title ); ?></strong>
				<span><?php echo esc_html( $events_count ); ?></span>
			</a>
		<?php endif; ?>

		<?php if ( ! empty( $links['community'] ) ) : ?>
			<a class="entity-pillar-route entity-pillar-route-community" href="<?php echo esc_url( $links['community']['url'] ); ?>">
				<span class="entity-pillar-route-kicker"><?php esc_html_e( 'Community', 'extrachill-blog' ); ?></span>
				<strong><?php echo esc_html( $community_title ); ?></strong>
				<span><?php echo esc_html( $community_count ); ?></span>
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
		'<header class="entity-pillar-coverage-header"><p>%s</p><h2>%s</h2></header>',
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
	<section class="entity-pillar-newsletter" aria-labelledby="festival-pillar-newsletter-title">
		<h2 id="festival-pillar-newsletter-title"><?php esc_html_e( 'Stay in the festival loop', 'extrachill-blog' ); ?></h2>
		<p><?php esc_html_e( 'Get music news, festival updates, and the best of Extra Chill delivered to your inbox.', 'extrachill-blog' ); ?></p>
		<?php do_action( 'extrachill_render_newsletter_form', 'archive' ); ?>
	</section>
	<?php
}
add_action( 'extrachill_after_body_content', 'extrachill_blog_render_festival_newsletter', 8 );
