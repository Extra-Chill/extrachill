<?php
/**
 * Guest list page — /events/{slug}/guest-list (extrachill#433).
 *
 * Name and password in, QR pass out. The email field is optional and only sends a
 * copy. The pass card is filled by assets/js/rsvp-pass.js from the issue
 * response, and refilled from the phone's local storage on later visits,
 * so a reload shows the same pass and never uses another spot.
 *
 * Ships no CSS of its own: theme classes plus the single-event composition
 * classes the event page already uses, allow-listed in
 * RsvpPassDesignSystemTest.php.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$event_id   = (int) get_queried_object_id();
$event      = get_post( $event_id );
$perk_text  = extrachill_events_get_perk_text( $event_id );
$guest_list = extrachill_events_get_guest_list_counts( $event_id );
$list_full  = ! empty( $guest_list['full'] );
$timing     = function_exists( 'ec_users_get_event_timing' ) ? ec_users_get_event_timing( $event_id, (int) get_current_blog_id() ) : '';
$is_past    = 'past' === $timing;

$spots_line = '';
if ( isset( $guest_list['spots_left'] ) && ! $list_full ) {
	$spots_line = sprintf(
		/* translators: 1: spots left, 2: total guest list spots. */
		_n( '%1$d of %2$d spot left.', '%1$d of %2$d spots left.', (int) $guest_list['spots_left'], 'extrachill-events' ),
		(int) $guest_list['spots_left'],
		(int) $guest_list['capacity']
	);
}

get_header();
?>
<main class="extrachill-content ec-guest-list-page">
	<div
		class="ec-guest-list ec-surface-card ec-card-vertical-padding"
		data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
	>
		<h1 class="ec-guest-list__heading"><?php esc_html_e( 'Guest List', 'extrachill-events' ); ?></h1>
		<p class="ec-guest-list__event">
			<a href="<?php echo esc_url( (string) get_permalink( $event_id ) ); ?>"><?php echo esc_html( $event ? get_the_title( $event ) : '' ); ?></a>
		</p>
		<?php if ( '' !== $perk_text ) : ?>
			<p class="ec-rsvp-pass__perk"><?php echo esc_html( $perk_text ); ?></p>
		<?php endif; ?>

		<?php if ( $is_past ) : ?>
			<p class="notice notice-info"><?php esc_html_e( 'This event has already happened.', 'extrachill-events' ); ?></p>
		<?php else : ?>
			<p class="ec-rsvp-perk-teaser" id="ec-guest-list-teaser-<?php echo esc_attr( (string) $event_id ); ?>">
				<?php
				if ( $list_full ) {
					esc_html_e( 'The guest list is full.', 'extrachill-events' );
				} else {
					esc_html_e( 'Enter the guest list password to get your pass.', 'extrachill-events' );
					if ( '' !== $spots_line ) {
						echo ' ' . esc_html( $spots_line );
					}
				}
				?>
			</p>

			<form
				id="ec-guest-list-form-<?php echo esc_attr( (string) $event_id ); ?>"
				class="ec-rsvp-guest"
				data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
				data-mode="guest-list"
				novalidate
				<?php echo $list_full ? 'hidden' : ''; ?>
			>
				<label for="ec-guest-list-name-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Your name', 'extrachill-events' ); ?></label>
				<input
					type="text"
					id="ec-guest-list-name-<?php echo esc_attr( (string) $event_id ); ?>"
					name="name"
					autocomplete="name"
					maxlength="100"
					required
				>
				<label for="ec-guest-list-password-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Guest list password', 'extrachill-events' ); ?></label>
				<input
					type="password"
					id="ec-guest-list-password-<?php echo esc_attr( (string) $event_id ); ?>"
					name="password"
					autocomplete="off"
					required
				>
				<label for="ec-guest-list-email-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Email (optional, to get a copy)', 'extrachill-events' ); ?></label>
				<input
					type="email"
					id="ec-guest-list-email-<?php echo esc_attr( (string) $event_id ); ?>"
					name="email"
					autocomplete="email"
				>
				<?php
				if ( function_exists( 'ec_render_turnstile_widget' ) ) {
					echo wp_kses_post( ec_render_turnstile_widget( array( 'data-appearance' => 'interaction-only' ) ) );
				}
				?>
				<button type="submit" class="button-1 button-large"><?php esc_html_e( 'Get my pass', 'extrachill-events' ); ?></button>
				<div class="notice ec-rsvp-guest__status" role="status" hidden></div>
			</form>
		<?php endif; ?>

		<div
			id="ec-guest-list-pass-<?php echo esc_attr( (string) $event_id ); ?>"
			class="ec-rsvp-pass"
			data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
			hidden
		>
			<p class="ec-rsvp-pass__perk ec-guest-list__name"></p>
			<p class="ec-rsvp-pass__label"><?php esc_html_e( "You're on the list. Show this at the door:", 'extrachill-events' ); ?></p>
			<img
				class="ec-rsvp-pass__qr"
				src=""
				alt="<?php esc_attr_e( 'QR code for this pass', 'extrachill-events' ); ?>"
				width="260"
				height="260"
			>
			<p class="ec-rsvp-pass__code"></p>
			<p class="ec-rsvp-guest__note"><?php esc_html_e( 'Screenshot this to be safe. It stays on this phone if you come back to this page.', 'extrachill-events' ); ?></p>
		</div>
	</div>
</main>
<?php
get_footer();
