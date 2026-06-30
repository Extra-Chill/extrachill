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
	'headline'       => '',
	'description'    => '',
	'successMessage' => '',
	'buttonLabel'    => '',
	'systemPrompt'   => '',
);
$attributes = wp_parse_args( $attributes, $defaults );

$headline         = $attributes['headline'] ?? '';
$description      = $attributes['description'] ?? '';
$success_message  = $attributes['successMessage'] ?? '';
$button_label     = $attributes['buttonLabel'] ? $attributes['buttonLabel'] : __( 'Send Submission', 'data-machine-events' );
$system_prompt    = $attributes['systemPrompt'] ?? '';
$endpoint         = esc_url( rest_url( 'extrachill/v1/event-submissions' ) );
// Artist URL import endpoints moved from the data-machine-events substrate to
// this plugin's own extrachill/v1 namespace in extrachill-events#200.
$preview_endpoint = esc_url( rest_url( 'extrachill/v1/artist-url/preview' ) );
$submit_endpoint  = esc_url( rest_url( 'extrachill/v1/artist-url/submit' ) );
$rest_nonce       = wp_create_nonce( 'wp_rest' );
$form_id          = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'ec-event-form-' ) : 'ec-event-form-' . uniqid();

if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
	ec_enqueue_turnstile_script();
}

$success_attr = esc_attr( $success_message ? wp_strip_all_tags( $success_message ) : __( 'Thanks! We received your submission.', 'data-machine-events' ) );
?>

<div
	class="ec-event-submission"
	data-endpoint="<?php echo esc_url( $endpoint ); ?>"
	data-artist-url-preview="<?php echo esc_url( $preview_endpoint ); ?>"
	data-artist-url-submit="<?php echo esc_url( $submit_endpoint ); ?>"
	data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
	data-success-message="<?php echo $success_attr; ?>"
	data-system-prompt="<?php echo esc_attr( $system_prompt ); ?>"
>
	<div class="ec-event-submission__inner">
		<?php if ( $headline ) : ?>
			<h3 class="ec-event-submission__headline"><?php echo wp_kses_post( $headline ); ?></h3>
		<?php endif; ?>

		<?php if ( $description ) : ?>
			<div class="ec-event-submission__description"><?php echo wp_kses_post( wpautop( $description ) ); ?></div>
		<?php endif; ?>

		<?php if ( is_user_logged_in() ) : ?>
		<div class="ec-event-submission__url-import" data-state="idle">
			<div class="ec-event-submission__url-import-intro">
				<label for="<?php echo esc_attr( $form_id ); ?>-artist-url">
					<strong><?php esc_html_e( 'Have an artist tour page?', 'data-machine-events' ); ?></strong>
				</label>
				<p class="ec-event-submission__url-import-hint">
					<?php esc_html_e( "Paste the URL and we'll import all their events automatically. Leave blank to submit a single event manually below.", 'data-machine-events' ); ?>
				</p>
				<div class="ec-event-submission__url-import-row">
					<input
						type="url"
						id="<?php echo esc_attr( $form_id ); ?>-artist-url"
						class="ec-event-submission__url-import-input"
						placeholder="https://"
						autocomplete="off"
					/>
					<button type="button" class="button button-secondary ec-event-submission__url-import-try">
						<?php esc_html_e( 'Try URL', 'data-machine-events' ); ?>
					</button>
				</div>
				<div class="ec-event-submission__url-import-status" aria-live="polite"></div>
			</div>

			<div class="ec-event-submission__url-import-confirm" hidden>
				<p class="ec-event-submission__url-import-summary"></p>
				<ul class="ec-event-submission__url-import-events"></ul>
				<div class="ec-event-submission__url-import-actions">
					<button type="button" class="button-1 ec-event-submission__url-import-submit">
						<?php esc_html_e( 'Submit for review', 'data-machine-events' ); ?>
					</button>
					<button type="button" class="button button-link ec-event-submission__url-import-cancel">
						<?php esc_html_e( 'Cancel and use manual form', 'data-machine-events' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<form
			id="<?php echo esc_attr( $form_id ); ?>"
			class="ec-event-submission__form"
			method="post"
			enctype="multipart/form-data"
			autocomplete="off"
		>
			<?php if ( is_user_logged_in() ) : ?>
				<div class="ec-event-submission__user-info">
					<?php
					printf(
						/* translators: %s: user display name */
						esc_html__( 'Submitting as %s', 'data-machine-events' ),
						'<strong>' . esc_html( wp_get_current_user()->display_name ) . '</strong>'
					);
					?>
				</div>
			<?php endif; ?>

			<div class="ec-event-submission__grid">
				<?php if ( ! is_user_logged_in() ) : ?>
					<div class="ec-event-submission__field">
						<label for="<?php echo esc_attr( $form_id ); ?>-contact-name">
							<?php esc_html_e( 'Your Name', 'data-machine-events' ); ?>
							<span aria-hidden="true">*</span>
						</label>
						<input type="text" name="contact_name" id="<?php echo esc_attr( $form_id ); ?>-contact-name" required />
					</div>

					<div class="ec-event-submission__field">
						<label for="<?php echo esc_attr( $form_id ); ?>-contact-email">
							<?php esc_html_e( 'Contact Email', 'data-machine-events' ); ?>
							<span aria-hidden="true">*</span>
						</label>
						<input type="email" name="contact_email" id="<?php echo esc_attr( $form_id ); ?>-contact-email" required />
					</div>
				<?php endif; ?>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-event-title">
						<?php esc_html_e( 'Event Title', 'data-machine-events' ); ?>
						<span aria-hidden="true">*</span>
					</label>
					<input type="text" name="event_title" id="<?php echo esc_attr( $form_id ); ?>-event-title" required />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-event-date">
						<?php esc_html_e( 'Event Date', 'data-machine-events' ); ?>
						<span aria-hidden="true">*</span>
					</label>
					<input type="date" name="event_date" id="<?php echo esc_attr( $form_id ); ?>-event-date" required />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-event-time">
						<?php esc_html_e( 'Event Time', 'data-machine-events' ); ?>
					</label>
					<input type="time" name="event_time" id="<?php echo esc_attr( $form_id ); ?>-event-time" />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-venue">
						<?php esc_html_e( 'Venue', 'data-machine-events' ); ?>
					</label>
					<input type="text" name="venue_name" id="<?php echo esc_attr( $form_id ); ?>-venue" />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-city">
						<?php esc_html_e( 'City / Region', 'data-machine-events' ); ?>
					</label>
					<input type="text" name="event_city" id="<?php echo esc_attr( $form_id ); ?>-city" />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-lineup">
						<?php esc_html_e( 'Lineup / Headliners', 'data-machine-events' ); ?>
					</label>
					<input type="text" name="event_lineup" id="<?php echo esc_attr( $form_id ); ?>-lineup" />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-link">
						<?php esc_html_e( 'Ticket or Info Link', 'data-machine-events' ); ?>
					</label>
					<input type="url" name="event_link" id="<?php echo esc_attr( $form_id ); ?>-link" placeholder="https://" />
				</div>
			</div>

			<div class="ec-event-submission__field ec-event-submission__field--full">
				<label for="<?php echo esc_attr( $form_id ); ?>-details">
					<?php esc_html_e( 'Additional Details', 'data-machine-events' ); ?>
				</label>
				<textarea name="notes" id="<?php echo esc_attr( $form_id ); ?>-details" rows="4"></textarea>
			</div>

			<div class="ec-event-submission__field ec-event-submission__field--file">
				<label for="<?php echo esc_attr( $form_id ); ?>-flyer">
					<?php esc_html_e( 'Flyer Upload (JPG, PNG, WebP, PDF)', 'data-machine-events' ); ?>
				</label>
				<input type="file" name="flyer" id="<?php echo esc_attr( $form_id ); ?>-flyer" accept="image/*,.pdf" />
			</div>

			<div class="ec-event-submission__turnstile">
				<?php
				if ( function_exists( 'ec_render_turnstile_widget' ) ) {
					echo wp_kses_post( ec_render_turnstile_widget( array( 'data-appearance' => 'always' ) ) );
				} else {
					esc_html_e( 'Security challenge unavailable. Please contact support.', 'data-machine-events' );
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
