<?php
/**
 * Homepage Events Card
 *
 * City badges with live counts render in the top grid ("Top Event Markets"),
 * so this card focuses on the mission + submission CTA.
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

$events_site_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'events' ) : 'https://events.extrachill.com';
$browse_url      = extrachill_blog_bridge_url( $events_site_url, 'events' );
$submit_url      = extrachill_blog_bridge_url( trailingslashit( $events_site_url ) . 'submit', 'events' );
?>
<div class="home-network-card" aria-labelledby="events-header">
	<h2 class="home-network-card-header" id="events-header"><?php esc_html_e( 'Events Calendar', 'extrachill-blog' ); ?></h2>
	<p class="home-network-card-description">
		<?php esc_html_e( 'Discover concerts, festivals, and music events in your city — free to browse, no login wall. DIY artists can submit their own events to share with the Extra Chill network.', 'extrachill-blog' ); ?>
	</p>
	<div class="home-network-card-cta home-network-card-cta-row">
		<a href="<?php echo esc_url( $browse_url ); ?>" class="button-2 button-medium ec-cross-site-link"><?php esc_html_e( 'Browse Events', 'extrachill-blog' ); ?></a>
		<a href="<?php echo esc_url( $submit_url ); ?>" class="button-3 button-medium ec-cross-site-link"><?php esc_html_e( 'Submit an Event', 'extrachill-blog' ); ?></a>
	</div>
</div>
