<?php
/**
 * Events Homepage Feature Cards
 *
 * Cards below the city badges surface personalization and contribution tools,
 * plus venue operations for active venue members.
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

	<?php if ( is_user_logged_in() && ec_events_user_has_active_venue_membership( get_current_user_id() ) ) : ?>
		<a class="events-feature-card events-feature-card--manage-venue" href="<?php echo esc_url( ec_events_get_booking_console_url( 0 ) ); ?>">
			<h2 class="events-feature-card__title"><?php esc_html_e( 'Manage Venue', 'extrachill-events' ); ?></h2>
			<p class="events-feature-card__body">
				<?php esc_html_e( 'Review inquiries, manage holds, and keep your venue calendar up to date.', 'extrachill-events' ); ?>
			</p>
			<span class="events-feature-card__cta"><?php esc_html_e( 'Open venue workspace &rarr;', 'extrachill-events' ); ?></span>
		</a>
	<?php endif; ?>
</div>
