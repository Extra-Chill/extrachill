<?php
/**
 * Homepage Events Card
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

$location_counts = extrachill_blog_get_location_event_counts();
?>
<div class="home-network-card" aria-labelledby="events-header">
	<h2 class="home-network-card-header" id="events-header">Events Calendar</h2>
	<p class="home-network-card-description">
		Discover concerts, festivals, and music events in your city. Organized by location, you can easily find events near you. For DIY artists, also submit your own events to share with the Extra Chill network.
	</p>
	<?php if ( ! empty( $location_counts ) ) : ?>
		<div class="taxonomy-badges" style="justify-content: center;">
			<?php foreach ( $location_counts as $location ) : ?>
				<a href="<?php echo esc_url( $location['url'] ); ?>" class="taxonomy-badge location-badge location-<?php echo esc_attr( $location['slug'] ); ?>">
					<?php echo esc_html( $location['name'] ); ?> (<?php echo esc_html( $location['count'] ); ?>)
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<div class="home-network-card-cta">
		<a href="https://events.extrachill.com" class="button-2 button-medium">Browse Events</a>
	</div>
</div>
