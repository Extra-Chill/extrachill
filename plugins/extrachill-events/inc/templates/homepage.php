<?php
/**
 * Events Homepage Content
 *
 * Homepage content for events.extrachill.com.
 * Hooked via extrachill_homepage_content action.
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

extrachill_breadcrumbs();
?>

<article class="events-homepage">
	<div class="inside-article">
		<header>
			<h1 class="page-title"><?php esc_html_e( 'Live Music Calendar', 'extrachill-events' ); ?></h1>
		</header>

		<div class="entry-content" itemprop="text">
			<div class="events-calendar-container full-width-content">
				<?php
				$homepage_id = get_option( 'page_on_front' );

				if ( $homepage_id ) {
					$homepage = get_post( $homepage_id );
					if ( $homepage ) {
						echo apply_filters( 'the_content', $homepage->post_content );
					}
				}
				?>
			</div>
		</div><!-- .entry-content -->
	</div><!-- .inside-article -->
</article><!-- .events-homepage -->
