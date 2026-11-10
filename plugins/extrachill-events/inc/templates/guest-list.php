<?php
/**
 * Guest list page — /events/{slug}/guest-list (extrachill#433).
 *
 * Hosts (anyone who manages the event) get the door view instead
 * (templates/guest-list-door.php, extrachill#1246). Guests get the view for
 * the event's access mode: password mode asks for a name and password,
 * host_list mode says the host manages the list, going mode points to the
 * Going button. A pass link (?pass=) opens the pass in every mode with no
 * password prompt.
 *
 * Name and password in, QR pass out. The email field is optional and only sends a
 * copy. The pass card is filled by assets/js/rsvp-pass.js from the issue
 * response, and refilled from the phone's local storage on later visits,
 * so a reload shows the same pass and never uses another spot.
 *
 * Below the pass, bands and managers who weren't invited can ask the venue
 * for spots (extrachill#1129). The ask is inert until the venue accepts it.
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

$event_id = (int) get_queried_object_id();
$view     = extrachill_events_guest_list_page_view( $event_id, (int) get_current_user_id() );

// Hosts get the door view (extrachill#1246) and nothing else.
if ( 'door' === $view ) {
	get_header();
	?>
	<main class="extrachill-content ec-guest-list-page">
		<div class="content-column">
			<?php extrachill_events_render_guest_list_door( $event_id ); ?>
		</div>
	</main>
	<?php
	get_footer();
	return;
}

// A pass link (?pass=) opens the pass. It never shows a password prompt.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; the code is validated by the QR endpoint.
$pass_link      = isset( $_GET['pass'] ) && '' !== sanitize_text_field( wp_unslash( $_GET['pass'] ) );
$event          = get_post( $event_id );
$perk_text      = extrachill_events_get_perk_text( $event_id );
$perk_image_url = extrachill_events_get_pass_image_url( $event_id );
$perk_image_alt = '' !== $perk_image_url ? extrachill_events_get_pass_image_alt( $event_id ) : '';
// No art and no poster: a calendar-style event card instead (extrachill#1127).
// Rendered before get_header() so the calendar stylesheet lands in <head>.
$pass_card_html = '' === $perk_image_url ? extrachill_events_render_pass_card( $event_id ) : '';
$guest_list     = extrachill_events_get_guest_list_counts( $event_id );
$list_full      = ! empty( $guest_list['full'] );
$timing         = function_exists( 'ec_users_get_event_timing' ) ? ec_users_get_event_timing( $event_id, (int) get_current_blog_id() ) : '';
$is_past        = 'past' === $timing;

// "Sat, Oct 31 · 11:00 pm · Lo-Fi Brewing" so guests know they're in the right place.
$when_where = array();
$dates      = function_exists( 'datamachine_get_event_dates' ) ? datamachine_get_event_dates( $event_id ) : null;
if ( is_object( $dates ) && ! empty( $dates->start_datetime ) ) {
	$start        = strtotime( (string) $dates->start_datetime );
	$when_where[] = date_i18n( 'D, M j', $start );
	$when_where[] = date_i18n( (string) get_option( 'time_format' ), $start );
}
$venues = get_the_terms( $event_id, 'venue' );
if ( is_array( $venues ) && ! empty( $venues[0]->name ) ) {
	$when_where[] = $venues[0]->name;
}

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
	<div class="content-column">
	<?php
	if ( function_exists( 'extrachill_breadcrumbs' ) ) {
		extrachill_breadcrumbs();
	}
	?>
	<div
		class="ec-guest-list ec-surface-card ec-card-padding ec-narrow-column"
		data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
	>
		<h1 class="ec-guest-list__heading"><?php echo esc_html( '' !== $perk_text ? $perk_text : __( 'Guest list', 'extrachill-events' ) ); ?></h1>
		<p class="ec-guest-list__event">
			<a href="<?php echo esc_url( (string) get_permalink( $event_id ) ); ?>"><?php echo esc_html( $event ? get_the_title( $event ) : '' ); ?></a>
			<?php if ( $when_where ) : ?>
				<br><?php echo esc_html( implode( ' · ', $when_where ) ); ?>
			<?php endif; ?>
		</p>

		<?php if ( $is_past ) : ?>
			<p class="notice notice-info"><?php esc_html_e( 'This event has already happened.', 'extrachill-events' ); ?></p>
		<?php elseif ( 'pass_only' === $view ) : ?>
			<?php // Ticket-only event: the page just opens purchased pass links. ?>
		<?php elseif ( 'host_managed' === $view ) : ?>
			<p class="notice notice-info" id="ec-guest-list-teaser-<?php echo esc_attr( (string) $event_id ); ?>" <?php echo $pass_link ? 'hidden' : ''; ?>><?php esc_html_e( 'This guest list is managed by the host. Ask them to add your name, and they will send you a link to your pass.', 'extrachill-events' ); ?></p>
		<?php elseif ( 'going' === $view ) : ?>
			<p class="notice notice-info" id="ec-guest-list-teaser-<?php echo esc_attr( (string) $event_id ); ?>" <?php echo $pass_link ? 'hidden' : ''; ?>>
				<?php esc_html_e( 'Mark yourself Going on the event page to get your pass.', 'extrachill-events' ); ?>
				<a href="<?php echo esc_url( (string) get_permalink( $event_id ) ); ?>"><?php esc_html_e( 'Go to the event', 'extrachill-events' ); ?></a>
			</p>
		<?php else : ?>
			<p class="ec-rsvp-perk-teaser" id="ec-guest-list-teaser-<?php echo esc_attr( (string) $event_id ); ?>" <?php echo $pass_link ? 'hidden' : ''; ?>>
				<?php
				if ( $list_full ) {
					esc_html_e( 'The guest list is full.', 'extrachill-events' );
				} else {
					esc_html_e( 'Enter your name and the guest list password to get your pass.', 'extrachill-events' );
					if ( '' !== $spots_line ) {
						echo ' ' . esc_html( $spots_line );
					}
				}
				?>
			</p>

			<form
				id="ec-guest-list-form-<?php echo esc_attr( (string) $event_id ); ?>"
				class="ec-rsvp-guest ec-form-stack"
				data-event-id="<?php echo esc_attr( (string) $event_id ); ?>"
				data-mode="guest-list"
				novalidate
				<?php echo $list_full || $pass_link ? 'hidden' : ''; ?>
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
			<?php if ( '' !== $perk_image_url ) : ?>
			<img
				class="ec-rsvp-pass__art"
				src="<?php echo esc_url( $perk_image_url ); ?>"
				alt="<?php echo esc_attr( $perk_image_alt ); ?>"
				decoding="async"
			>
			<?php elseif ( '' !== $pass_card_html ) : ?>
				<?php echo $pass_card_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by extrachill_events_render_pass_card(). ?>
			<?php endif; ?>
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

		<?php if ( ! $is_past && in_array( $view, array( 'claim', 'host_managed' ), true ) ) : ?>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
			$request_sent = isset( $_GET['ec_request'] ) && 'sent' === sanitize_key( wp_unslash( $_GET['ec_request'] ) );
			?>
			<details class="ec-form-stack" id="ec-guest-list-request" <?php echo $request_sent ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Playing this show? Request guest list spots', 'extrachill-events' ); ?></summary>
				<?php if ( $request_sent ) : ?>
					<p class="notice notice-success" role="status"><?php esc_html_e( 'Request sent. The venue will email you when they decide. Nothing is on the list until they accept it.', 'extrachill-events' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'For bands and managers who were not sent a guest list link. The venue reviews every request.', 'extrachill-events' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ec-form-stack">
						<input type="hidden" name="action" value="ec_guest_party_request">
						<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
						<?php wp_nonce_field( 'ec_guest_party_request_' . $event_id ); ?>
						<div hidden aria-hidden="true">
							<label for="ec-guest-request-website-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Leave this empty', 'extrachill-events' ); ?></label>
							<input type="text" id="ec-guest-request-website-<?php echo esc_attr( (string) $event_id ); ?>" name="website" tabindex="-1" autocomplete="off">
						</div>
						<label for="ec-guest-request-name-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Band or management name', 'extrachill-events' ); ?></label>
						<input type="text" id="ec-guest-request-name-<?php echo esc_attr( (string) $event_id ); ?>" name="name" maxlength="100" required>
						<label for="ec-guest-request-email-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Email (the venue replies here)', 'extrachill-events' ); ?></label>
						<input type="email" id="ec-guest-request-email-<?php echo esc_attr( (string) $event_id ); ?>" name="email" autocomplete="email" required>
						<label for="ec-guest-request-spots-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Spots needed', 'extrachill-events' ); ?></label>
						<input type="number" id="ec-guest-request-spots-<?php echo esc_attr( (string) $event_id ); ?>" name="spots" min="1" max="<?php echo esc_attr( (string) EXTRACHILL_EVENTS_GUEST_LIST_REQUEST_MAX_SPOTS ); ?>" value="2" required>
						<label for="ec-guest-request-names-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Guest names (optional, one per line)', 'extrachill-events' ); ?></label>
						<textarea id="ec-guest-request-names-<?php echo esc_attr( (string) $event_id ); ?>" name="names" rows="4"></textarea>
						<label for="ec-guest-request-note-<?php echo esc_attr( (string) $event_id ); ?>"><?php esc_html_e( 'Note to the venue (optional)', 'extrachill-events' ); ?></label>
						<textarea id="ec-guest-request-note-<?php echo esc_attr( (string) $event_id ); ?>" name="note" rows="2" maxlength="500"></textarea>
						<button type="submit" class="button-2 button-medium"><?php esc_html_e( 'Send request', 'extrachill-events' ); ?></button>
					</form>
				<?php endif; ?>
			</details>
		<?php endif; ?>
	</div>
	</div>
</main>
<?php
get_footer();
