<?php
/**
 * Events Homepage Feature Cards
 *
 * Two side-by-side cards below the city badges surfacing the platform's
 * personalization + contribution features: building a personal concert
 * archive (My Shows) and adding events to the calendar (Submit).
 *
 * @package ExtraChillEvents
 * @since 0.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="events-feature-cards ec-edge-gutter">
	<a class="events-feature-card events-feature-card--my-shows" href="<?php echo esc_url( home_url( '/my-shows/' ) ); ?>">
		<h2 class="events-feature-card__title"><?php esc_html_e( 'My Shows', 'extrachill-events' ); ?></h2>
		<p class="events-feature-card__body">
			<?php esc_html_e( 'Track the concerts you\'re going to and the ones you\'ve seen. Build your own concert archive over time.', 'extrachill-events' ); ?>
		</p>
		<span class="events-feature-card__cta"><?php esc_html_e( 'Start your archive &rarr;', 'extrachill-events' ); ?></span>
	</a>

	<a class="events-feature-card events-feature-card--submit" href="<?php echo esc_url( home_url( '/submit/' ) ); ?>">
		<h2 class="events-feature-card__title"><?php esc_html_e( 'Submit an Event', 'extrachill-events' ); ?></h2>
		<p class="events-feature-card__body">
			<?php esc_html_e( 'Playing a show or know one we\'re missing? Add it to the calendar so fans can find it.', 'extrachill-events' ); ?>
		</p>
		<span class="events-feature-card__cta"><?php esc_html_e( 'Submit a show &rarr;', 'extrachill-events' ); ?></span>
	</a>
</div>
