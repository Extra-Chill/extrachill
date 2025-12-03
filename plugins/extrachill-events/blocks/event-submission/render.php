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
	'flowId'         => '',
	'successMessage' => '',
	'buttonLabel'    => '',
);
$attributes = wp_parse_args( $attributes, $defaults );

$flow_id         = preg_replace( '/[^0-9]/', '', (string) ( $attributes['flowId'] ?? '' ) );
$headline        = $attributes['headline'] ?? '';
$description     = $attributes['description'] ?? '';
$success_message = $attributes['successMessage'] ?? '';
$button_label    = $attributes['buttonLabel'] ?: __( 'Send Submission', 'datamachine-events' );
$endpoint        = esc_url( rest_url( 'extrachill/v1/event-submissions' ) );
$form_id         = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'ec-event-form-' ) : 'ec-event-form-' . uniqid();

if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
	ec_enqueue_turnstile_script();
}

$success_attr = esc_attr( $success_message ? wp_strip_all_tags( $success_message ) : __( 'Thanks! We received your submission.', 'datamachine-events' ) );
?>

<div
	class="ec-event-submission"
	data-endpoint="<?php echo esc_url( $endpoint ); ?>"
	data-flow-id="<?php echo esc_attr( $flow_id ); ?>"
	data-success-message="<?php echo $success_attr; ?>"
>
	<div class="ec-event-submission__inner">
		<?php if ( $headline ) : ?>
			<h3 class="ec-event-submission__headline"><?php echo wp_kses_post( $headline ); ?></h3>
		<?php endif; ?>

		<?php if ( $description ) : ?>
			<div class="ec-event-submission__description"><?php echo wp_kses_post( wpautop( $description ) ); ?></div>
		<?php endif; ?>

		<?php if ( empty( $flow_id ) ) : ?>
			<div class="ec-event-submission__notice">
				<?php esc_html_e( 'Flow ID missing. Update the block settings so submissions can be processed.', 'datamachine-events' ); ?>
			</div>
		<?php endif; ?>

		<form
			id="<?php echo esc_attr( $form_id ); ?>"
			class="ec-event-submission__form"
			method="post"
			enctype="multipart/form-data"
			autocomplete="off"
		>
			<input type="hidden" name="flow_id" value="<?php echo esc_attr( $flow_id ); ?>" />

			<?php if ( is_user_logged_in() ) : ?>
				<div class="ec-event-submission__user-info">
					<?php
					printf(
						/* translators: %s: user display name */
						esc_html__( 'Submitting as %s', 'datamachine-events' ),
						'<strong>' . esc_html( wp_get_current_user()->display_name ) . '</strong>'
					);
					?>
				</div>
			<?php endif; ?>

			<div class="ec-event-submission__grid">
				<?php if ( ! is_user_logged_in() ) : ?>
					<div class="ec-event-submission__field">
						<label for="<?php echo esc_attr( $form_id ); ?>-contact-name">
							<?php esc_html_e( 'Your Name', 'datamachine-events' ); ?>
							<span aria-hidden="true">*</span>
						</label>
						<input type="text" name="contact_name" id="<?php echo esc_attr( $form_id ); ?>-contact-name" required />
					</div>

					<div class="ec-event-submission__field">
						<label for="<?php echo esc_attr( $form_id ); ?>-contact-email">
							<?php esc_html_e( 'Contact Email', 'datamachine-events' ); ?>
							<span aria-hidden="true">*</span>
						</label>
						<input type="email" name="contact_email" id="<?php echo esc_attr( $form_id ); ?>-contact-email" required />
					</div>
				<?php endif; ?>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-event-title">
						<?php esc_html_e( 'Event Title', 'datamachine-events' ); ?>
						<span aria-hidden="true">*</span>
					</label>
					<input type="text" name="event_title" id="<?php echo esc_attr( $form_id ); ?>-event-title" required />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-event-date">
						<?php esc_html_e( 'Event Date', 'datamachine-events' ); ?>
						<span aria-hidden="true">*</span>
					</label>
					<input type="date" name="event_date" id="<?php echo esc_attr( $form_id ); ?>-event-date" required />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-event-time">
						<?php esc_html_e( 'Event Time', 'datamachine-events' ); ?>
					</label>
					<input type="time" name="event_time" id="<?php echo esc_attr( $form_id ); ?>-event-time" />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-venue">
						<?php esc_html_e( 'Venue', 'datamachine-events' ); ?>
					</label>
					<input type="text" name="venue_name" id="<?php echo esc_attr( $form_id ); ?>-venue" />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-city">
						<?php esc_html_e( 'City / Region', 'datamachine-events' ); ?>
					</label>
					<input type="text" name="event_city" id="<?php echo esc_attr( $form_id ); ?>-city" />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-lineup">
						<?php esc_html_e( 'Lineup / Headliners', 'datamachine-events' ); ?>
					</label>
					<input type="text" name="event_lineup" id="<?php echo esc_attr( $form_id ); ?>-lineup" />
				</div>

				<div class="ec-event-submission__field">
					<label for="<?php echo esc_attr( $form_id ); ?>-link">
						<?php esc_html_e( 'Ticket or Info Link', 'datamachine-events' ); ?>
					</label>
					<input type="url" name="event_link" id="<?php echo esc_attr( $form_id ); ?>-link" placeholder="https://" />
				</div>
			</div>

			<div class="ec-event-submission__field ec-event-submission__field--full">
				<label for="<?php echo esc_attr( $form_id ); ?>-details">
					<?php esc_html_e( 'Additional Details', 'datamachine-events' ); ?>
				</label>
				<textarea name="notes" id="<?php echo esc_attr( $form_id ); ?>-details" rows="4"></textarea>
			</div>

			<div class="ec-event-submission__field ec-event-submission__field--file">
				<label for="<?php echo esc_attr( $form_id ); ?>-flyer">
					<?php esc_html_e( 'Flyer Upload (JPG, PNG, WebP, PDF)', 'datamachine-events' ); ?>
				</label>
				<input type="file" name="flyer" id="<?php echo esc_attr( $form_id ); ?>-flyer" accept="image/*,.pdf" />
			</div>

			<div class="ec-event-submission__turnstile">
				<?php
				if ( function_exists( 'ec_render_turnstile_widget' ) ) {
					echo wp_kses_post( ec_render_turnstile_widget() );
				} else {
					esc_html_e( 'Security challenge unavailable. Please contact support.', 'datamachine-events' );
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
