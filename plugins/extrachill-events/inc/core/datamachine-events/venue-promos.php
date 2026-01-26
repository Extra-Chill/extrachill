<?php
/**
 * Venue Promos
 *
 * Venue-specific promotional content (Farm Friends membership).
 *
 * @package ExtraChillEvents
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize venue promo hooks
 */
function extrachill_events_init_venue_promos() {
	add_action( 'datamachine_events_after_price_display', 'extrachill_events_display_farm_friends_promo', 10, 2 );
}

/**
 * Display Farm Friends membership promo for Music Farm events
 *
 * Shows promotional content for Farm Friends subscription when viewing
 * events at the Music Farm venue on events.extrachill.com.
 *
 * @param int    $post_id Event post ID.
 * @param string $price   Event price string.
 */
function extrachill_events_display_farm_friends_promo( $post_id, $price ) {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( ! $events_blog_id || get_current_blog_id() !== $events_blog_id ) {
		return;
	}

	$venue_terms = get_the_terms( $post_id, 'venue' );
	if ( ! $venue_terms || is_wp_error( $venue_terms ) ) {
		return;
	}

	$venue_slug = $venue_terms[0]->slug;
	if ( $venue_slug !== 'music-farm' ) {
		return;
	}

	$promo_url = 'https://musicfarm.com/farm-friends-subscription/';

	?>
	<div class="farm-friends-promo">
		<a href="<?php echo esc_url( $promo_url ); ?>" class="taxonomy-badge promo-badge" target="_blank" rel="noopener">
			<?php esc_html_e( 'Free for Farm Friends', 'extrachill-events' ); ?>
		</a>
	</div>
	<?php
}
