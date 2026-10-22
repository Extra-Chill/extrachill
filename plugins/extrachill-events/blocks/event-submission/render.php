<?php
/**
 * Event Submission Block Render
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$defaults   = array(
	'landing'        => true,
	'headline'       => '',
	'description'    => '',
	'successMessage' => '',
	'buttonLabel'    => '',
	'systemPrompt'   => '',
);
$attributes = wp_parse_args( isset( $attributes ) && is_array( $attributes ) ? $attributes : array(), $defaults );

$headline         = $attributes['headline'] ?? '';
$description      = $attributes['description'] ?? '';
$success_message  = $attributes['successMessage'] ?? '';
$button_label     = $attributes['buttonLabel'] ? $attributes['buttonLabel'] : __( 'Send Submission', 'extrachill-events' );
$system_prompt    = $attributes['systemPrompt'] ?? '';
$endpoint         = esc_url( rest_url( 'extrachill/v1/event-submissions' ) );
$preview_endpoint = esc_url( rest_url( 'extrachill/v1/event-source/preview' ) );
$submit_endpoint  = esc_url( rest_url( 'extrachill/v1/event-source/submit' ) );
$rest_nonce       = wp_create_nonce( 'wp_rest' );
$venue_search     = esc_url( rest_url( 'wp/v2/venue' ) );
$form_id          = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'ec-event-form-' ) : 'ec-event-form-' . uniqid();

if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
	ec_enqueue_turnstile_script();
}

$prefill = function_exists( 'extrachill_events_submit_prefill' )
	? extrachill_events_submit_prefill()
	: array(
		'venue' => '',
		'city'  => '',
	);

$show_landing  = ! empty( $attributes['landing'] ) && function_exists( 'extrachill_events_render_submit_landing' );
$import_anchor = defined( 'EXTRACHILL_EVENTS_SUBMIT_IMPORT_ANCHOR' ) ? EXTRACHILL_EVENTS_SUBMIT_IMPORT_ANCHOR : 'ec-event-source-import';

$success_attr = $success_message ? wp_strip_all_tags( $success_message ) : __( 'Thanks! We received your submission.', 'extrachill-events' );
?>

<?php
if ( $show_landing ) {
	echo extrachill_events_render_submit_landing( $form_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the renderer.
}
?>

<div
	class="ec-event-submission"
	data-endpoint="<?php echo esc_url( $endpoint ); ?>"
	data-event-source-preview="<?php echo esc_url( $preview_endpoint ); ?>"
	data-event-source-submit="<?php echo esc_url( $submit_endpoint ); ?>"
	data-venue-search="<?php echo esc_url( $venue_search ); ?>"
	data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
	data-success-message="<?php echo esc_attr( $success_attr ); ?>"
	data-system-prompt="<?php echo esc_attr( $system_prompt ); ?>"
>
	<div class="ec-event-submission__inner">
		<?php // The landing already frames the page; don't repeat a headline under it (#987). ?>
		<?php if ( $headline && ! $show_landing ) : ?>
			<h3 class="ec-event-submission__headline"><?php echo wp_kses_post( $headline ); ?></h3>
		<?php endif; ?>

		<?php if ( $description && ! $show_landing ) : ?>
			<div class="ec-event-submission__description"><?php echo wp_kses_post( wpautop( $description ) ); ?></div>
		<?php endif; ?>

		<?php if ( is_user_logged_in() ) : ?>
			<?php $import_skips_review = class_exists( \ExtraChillEvents\Abilities\SubmissionTrustPolicy::class ) && \ExtraChillEvents\Abilities\SubmissionTrustPolicy::isStaff( get_current_user_id() ); ?>
		<div class="ec-event-submission__url-import" id="<?php echo esc_attr( $import_anchor ); ?>" data-state="idle" data-skips-review="<?php echo $import_skips_review ? '1' : '0'; ?>">
			<div class="ec-event-submission__url-import-intro">
				<label for="<?php echo esc_attr( $form_id ); ?>-event-source-url">
					<strong><?php esc_html_e( 'Have a reliable event source?', 'extrachill-events' ); ?></strong>
				</label>
				<p class="ec-event-submission__url-import-hint">
					<?php esc_html_e( 'Try an artist tour page, venue calendar, or festival schedule. We will test it and review reliable recurring sources; use the manual form below for one event.', 'extrachill-events' ); ?>
				</p>
				<div class="ec-event-submission__url-import-row">
					<input
						type="url"
						id="<?php echo esc_attr( $form_id ); ?>-event-source-url"
						class="ec-event-submission__url-import-input"
						placeholder="https://"
						autocomplete="off"
					/>
					<button type="button" class="button-2 button-medium ec-event-submission__url-import-try">
						<?php esc_html_e( 'Try URL', 'extrachill-events' ); ?>
					</button>
				</div>
				<div class="ec-event-submission__url-import-status" aria-live="polite"></div>
			</div>

			<div class="ec-event-submission__url-import-confirm" hidden>
				<p class="ec-event-submission__url-import-summary"></p>
				<ul class="ec-event-submission__url-import-events"></ul>
				<div class="ec-event-submission__url-import-actions">
					<button type="button" class="button-1 button-medium ec-event-submission__url-import-submit">
						<?php echo $import_skips_review ? esc_html__( 'Add to calendar', 'extrachill-events' ) : esc_html__( 'Submit for review', 'extrachill-events' ); ?>
					</button>
					<button type="button" class="button-3 button-medium ec-event-submission__url-import-cancel">
						<?php esc_html_e( 'Cancel and use manual form', 'extrachill-events' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php endif; ?>
		<?php if ( ! is_user_logged_in() ) : ?>
			<?php
			$submit_url       = get_permalink();
			$submit_url       = false !== $submit_url ? $submit_url : home_url( '/' );
			$registration_url = function_exists( 'extrachill_users_get_registration_url' )
				? extrachill_users_get_registration_url()
				: wp_registration_url();
			$join_url         = function_exists( 'ec_users_login_url_with_redirect' )
				? ec_users_login_url_with_redirect( $registration_url, $submit_url )
				: add_query_arg( 'redirect_to', rawurlencode( $submit_url ), $registration_url );
			$login_url        = function_exists( 'ec_get_site_url' )
				? trailingslashit( ec_get_site_url( 'events' ) ) . 'login/?redirect_to=' . rawurlencode( $submit_url )
				: wp_login_url( $submit_url );
			?>
			<div class="ec-event-submission__url-import" id="<?php echo esc_attr( $import_anchor ); ?>">
				<strong><?php esc_html_e( 'Have a reliable event source?', 'extrachill-events' ); ?></strong>
				<p class="ec-event-submission__url-import-hint">
					<?php esc_html_e( "Import a whole artist tour, venue calendar, or festival schedule at once — free with an Extra Chill account. We'll let you know when it's approved and live.", 'extrachill-events' ); ?>
					<?php esc_html_e( 'Use the manual form below for one event.', 'extrachill-events' ); ?>
				</p>
				<div class="ec-event-submission__url-import-actions">
					<a class="button-1 button-medium" href="<?php echo esc_url( $join_url ); ?>"><?php esc_html_e( 'Join', 'extrachill-events' ); ?></a>
					<a class="button-2 button-medium" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'extrachill-events' ); ?></a>
				</div>
			</div>
		<?php endif; ?>

		<form
			id="<?php echo esc_attr( $form_id ); ?>"
			class="ec-event-submission__form"
			data-ec-track="events_submission_form"
			method="post"
			enctype="multipart/form-data"
			autocomplete="off"
		>
			<?php if ( is_user_logged_in() ) : ?>
				<div class="ec-event-submission__user-info">
					<?php
					printf(
						/* translators: %s: user display name */
						esc_html__( 'Submitting as %s', 'extrachill-events' ),
						'<strong>' . esc_html( wp_get_current_user()->display_name ) . '</strong>'
					);
					?>
				</div>
			<?php endif; ?>

			<?php if ( ! is_user_logged_in() ) : ?>
			<div class="ec-event-submission__grid">
					<div class="ec-event-submission__field">
						<label for="<?php echo esc_attr( $form_id ); ?>-contact-name">
							<?php esc_html_e( 'Your Name', 'extrachill-events' ); ?>
							<span aria-hidden="true">*</span>
						</label>
						<input type="text" name="contact_name" id="<?php echo esc_attr( $form_id ); ?>-contact-name" required />
					</div>

					<div class="ec-event-submission__field">
						<label for="<?php echo esc_attr( $form_id ); ?>-contact-email">
							<?php esc_html_e( 'Contact Email', 'extrachill-events' ); ?>
							<span aria-hidden="true">*</span>
						</label>
						<input type="email" name="contact_email" id="<?php echo esc_attr( $form_id ); ?>-contact-email" aria-describedby="<?php echo esc_attr( $form_id ); ?>-contact-email-hint" required />
						<p class="ec-event-submission__field-hint" id="<?php echo esc_attr( $form_id ); ?>-contact-email-hint"><?php esc_html_e( 'We’ll connect your show to a free Extra Chill account for this email and send you a link to set a password.', 'extrachill-events' ); ?></p>
					</div>
			</div>
			<?php endif; ?>

			<div class="ec-event-submission__field ec-event-submission__field--file">
				<label for="<?php echo esc_attr( $form_id ); ?>-flyer">
					<?php esc_html_e( 'Have a flyer? Upload it and we’ll fill in the rest.', 'extrachill-events' ); ?>
				</label>
				<input type="file" name="flyer" id="<?php echo esc_attr( $form_id ); ?>-flyer" accept="image/*,.pdf" aria-describedby="<?php echo esc_attr( $form_id ); ?>-flyer-hint" />
				<p class="ec-event-submission__field-hint" id="<?php echo esc_attr( $form_id ); ?>-flyer-hint"><?php esc_html_e( 'JPG, PNG, WebP or PDF. With a flyer, everything below is optional: we read the title, date and venue from it.', 'extrachill-events' ); ?></p>
			</div>

			<div class="ec-event-submission__grid">
				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-event-title">
						<?php esc_html_e( 'Event Title', 'extrachill-events' ); ?>
						<span aria-hidden="true" data-ec-flyer-optional-marker>*</span>
					</label>
					<input type="text" name="event_title" id="<?php echo esc_attr( $form_id ); ?>-event-title" required data-ec-flyer-optional />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-event-date">
						<?php esc_html_e( 'Event Date', 'extrachill-events' ); ?>
						<span aria-hidden="true" data-ec-flyer-optional-marker>*</span>
					</label>
					<input type="date" name="event_date" id="<?php echo esc_attr( $form_id ); ?>-event-date" required data-ec-flyer-optional />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-event-time">
						<?php esc_html_e( 'Show Time', 'extrachill-events' ); ?>
					</label>
					<input type="time" name="event_time" id="<?php echo esc_attr( $form_id ); ?>-event-time" aria-describedby="<?php echo esc_attr( $form_id ); ?>-event-time-hint" />
					<p class="ec-event-submission__field-hint" id="<?php echo esc_attr( $form_id ); ?>-event-time-hint"><?php esc_html_e( 'When the music starts, not when doors open.', 'extrachill-events' ); ?></p>
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-venue">
						<?php esc_html_e( 'Venue', 'extrachill-events' ); ?>
					</label>
					<div class="ec-event-submission__venue">
						<input
							type="text"
							name="venue_name"
							id="<?php echo esc_attr( $form_id ); ?>-venue"
							value="<?php echo esc_attr( $prefill['venue'] ); ?>"
							role="combobox"
							aria-autocomplete="list"
							aria-expanded="false"
							aria-controls="<?php echo esc_attr( $form_id ); ?>-venue-suggestions"
							aria-describedby="<?php echo esc_attr( $form_id ); ?>-venue-hint"
						/>
						<input type="hidden" name="venue_term_id" value="" />
						<ul class="ec-event-submission__venue-suggestions" id="<?php echo esc_attr( $form_id ); ?>-venue-suggestions" role="listbox" hidden></ul>
					</div>
					<p class="ec-event-submission__field-hint" id="<?php echo esc_attr( $form_id ); ?>-venue-hint"><?php esc_html_e( 'Pick a venue from the list if it shows up. New venue? Just type its name.', 'extrachill-events' ); ?></p>
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-city">
						<?php esc_html_e( 'City / Region', 'extrachill-events' ); ?>
					</label>
					<input type="text" name="event_city" id="<?php echo esc_attr( $form_id ); ?>-city" value="<?php echo esc_attr( $prefill['city'] ); ?>" />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-lineup">
						<?php esc_html_e( 'Lineup / Headliners', 'extrachill-events' ); ?>
					</label>
					<input type="text" name="event_lineup" id="<?php echo esc_attr( $form_id ); ?>-lineup" />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-link">
						<?php esc_html_e( 'Ticket or Info Link', 'extrachill-events' ); ?>
					</label>
					<input type="url" name="event_link" id="<?php echo esc_attr( $form_id ); ?>-link" placeholder="https://" />
				</div>
			</div>

			<div class="ec-event-submission__field ec-event-submission__field--full">
				<label for="<?php echo esc_attr( $form_id ); ?>-details">
					<?php esc_html_e( 'Additional Details', 'extrachill-events' ); ?>
				</label>
				<textarea name="notes" id="<?php echo esc_attr( $form_id ); ?>-details" rows="4"></textarea>
			</div>

			<div class="ec-event-submission__turnstile">
				<?php
				if ( function_exists( 'ec_render_turnstile_widget' ) ) {
					echo wp_kses_post( ec_render_turnstile_widget( array( 'data-appearance' => 'always' ) ) );
				} else {
					esc_html_e( 'Security challenge unavailable. Please contact support.', 'extrachill-events' );
				}
				?>
			</div>

			<div class="ec-event-submission__actions">
				<button type="submit" class="button-1 button-large">
					<?php echo esc_html( $button_label ); ?>
				</button>
				<div class="ec-event-submission__status" aria-live="polite"></div>
			</div>
		</form>
	</div>
</div>

<?php
if ( $show_landing && function_exists( 'extrachill_events_render_submit_how_it_works' ) ) {
	echo extrachill_events_render_submit_how_it_works(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the renderer.
}
