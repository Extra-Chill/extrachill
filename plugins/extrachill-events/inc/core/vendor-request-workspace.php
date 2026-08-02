<?php
/**
 * Vendor request public application and private coordinator workspace.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\VendorRequestAuthorization;
use ExtraChillEvents\Core\VendorRequestRepository;
use ExtraChillEvents\Core\VendorRequestService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Render the event action without exposing either party's identity. */
function extrachill_events_vendor_request_event_action( $post_id ): void {
	$event_id   = absint( $post_id );
	$repository = new VendorRequestRepository();
	$stored     = $repository->get_request_by_event( $event_id );
	$public     = ( new VendorRequestService( $repository ) )->public_request_for_event( $event_id );
	if ( is_array( $public ) ) {
		$url = get_home_url( function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'events' ) ) : null, '/vendor-apply/' . rawurlencode( $public['public_id'] ) . '/' );
		printf( '<a class="button-1 ec-vendor-request-cta" href="%s">%s</a>', esc_url( $url ), esc_html__( 'Apply as a vendor', 'extrachill-events' ) );
	}
	if ( ! is_user_logged_in() ) {
		return;
	}
	$authorization = new VendorRequestAuthorization();
	if ( is_array( $stored ) ) {
		$allowed = $authorization->authorize_organizer( $stored, get_current_user_id() );
		$url     = home_url( '/vendor-requests/' . $stored['id'] . '/' );
		$label   = __( 'Manage vendor applications', 'extrachill-events' );
	} else {
		$context = $authorization->event_context( $event_id );
		$draft   = is_array( $context ) ? array(
			'event_id'            => $event_id,
			'venue_term_id'       => $context['venue_term_id'],
			'coordinator_user_id' => get_current_user_id(),
		) : array();
		$allowed = is_array( $context ) ? $authorization->authorize_coordinator( $draft, get_current_user_id() ) : false;
		$url     = add_query_arg( 'event_id', $event_id, home_url( '/vendor-requests/' ) );
		$label   = __( 'Request vendors', 'extrachill-events' );
	}
	if ( true === $allowed ) {
		printf( '<a class="button-2 ec-vendor-request-manage" href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}
}

/** Handle nonce-protected private workspace mutations. */
function extrachill_events_handle_vendor_request_action(): void {
	$method = sanitize_key( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) );
	if ( ! extrachill_events_is_vendor_request_page() || 'post' !== $method ) {
		return;
	}
	$input = wp_unslash( $_POST );
	if ( ! wp_verify_nonce( sanitize_text_field( (string) ( $input['_wpnonce'] ?? '' ) ), 'extrachill_events_vendor_request' ) ) {
		wp_die( esc_html__( 'The vendor request form expired.', 'extrachill-events' ), '', array( 'response' => 403 ) );
	}
	$result = extrachill_events_process_vendor_request_action( $input, get_current_user_id() );
	$id     = absint( $input['request_id'] ?? ( is_array( $result ) ? ( $result['id'] ?? 0 ) : 0 ) );
	$url    = $id ? home_url( '/vendor-requests/' . $id . '/' ) : home_url( '/vendor-requests/' );
	wp_safe_redirect( add_query_arg( 'notice', is_wp_error( $result ) ? 'error' : 'updated', $url ) );
	exit;
}
add_action( 'template_redirect', 'extrachill_events_handle_vendor_request_action', 1 );

/** Execute one private form action through the domain service. */
function extrachill_events_process_vendor_request_action( array $input, int $user_id, ?VendorRequestService $service = null ) {
	$service = $service ? $service : new VendorRequestService();
	$action  = sanitize_key( (string) ( $input['vendor_request_action'] ?? '' ) );
	$key     = sanitize_text_field( (string) ( $input['idempotency_key'] ?? '' ) );
	if ( 'open' === $action ) {
		$categories = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) ( $input['categories'] ?? '' ) ) ) ) ) );
		return $service->open_request(
			absint( $input['event_id'] ?? 0 ),
			array(
				'categories'         => $categories,
				'power_required'     => ! empty( $input['power_required'] ),
				'insurance_required' => ! empty( $input['insurance_required'] ),
				'instructions'       => sanitize_textarea_field( (string) ( $input['instructions'] ?? '' ) ),
			),
			$key,
			$user_id
		);
	}
	if ( 'status' === $action ) {
		return $service->set_request_open( absint( $input['request_id'] ?? 0 ), ! empty( $input['open'] ), absint( $input['expected_version'] ?? 0 ), $key, $user_id );
	}
	if ( 'review' === $action ) {
		return $service->review_application( absint( $input['application_id'] ?? 0 ), sanitize_key( (string) ( $input['status'] ?? '' ) ), sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ), absint( $input['expected_version'] ?? 0 ), $key, $user_id );
	}
	if ( 'contact' === $action ) {
		return $service->contact_applicant( absint( $input['application_id'] ?? 0 ), sanitize_text_field( (string) ( $input['subject'] ?? '' ) ), sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ), $key, $user_id );
	}
	return new WP_Error( 'vendor_request_action_invalid', __( 'That vendor request action is unavailable.', 'extrachill-events' ), array( 'status' => 400 ) );
}

/** Render either a coordinator index or one authorized private workspace. */
function extrachill_events_render_vendor_request_workspace(): void {
	if ( ! is_user_logged_in() ) {
		auth_redirect();
		return;
	}
	$repository    = new VendorRequestRepository();
	$authorization = new VendorRequestAuthorization();
	$service       = new VendorRequestService( $repository, $authorization );
	$request_id    = absint( get_query_var( 'ec_vendor_request', 0 ) );
	$request       = $request_id ? $repository->get_request( $request_id ) : null;
	if ( ! is_array( $request ) ) {
		$event_id  = absint( $_GET['event_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only canonical event selector.
		$context   = $event_id ? $authorization->event_context( $event_id ) : null;
		$candidate = is_array( $context ) ? array(
			'event_id'            => $event_id,
			'venue_term_id'       => $context['venue_term_id'],
			'coordinator_user_id' => get_current_user_id(),
		) : array();
		if ( ! is_array( $context ) || true !== $authorization->authorize_coordinator( $candidate, get_current_user_id() ) ) {
			extrachill_events_render_vendor_request_unavailable();
			return;
		}
		$existing = $repository->get_request_by_event( $event_id );
		if ( is_array( $existing ) ) {
			wp_safe_redirect( home_url( '/vendor-requests/' . $existing['id'] . '/' ) );
			return;
		}
		extrachill_events_render_vendor_request_open_form( $event_id );
		return;
	}
	if ( true !== $authorization->authorize_organizer( $request, get_current_user_id() ) ) {
		extrachill_events_render_vendor_request_unavailable();
		return;
	}
	$applications = $service->list_applications( $request_id, get_current_user_id() );
	if ( is_wp_error( $applications ) ) {
		extrachill_events_render_vendor_request_unavailable();
		return;
	}
	$post = get_post( $request['event_id'] );
	?>
	<section class="ec-vendor-request" data-vendor-request-workspace>
		<header class="ec-vendor-request__hero">
			<p class="ec-vendor-request__eyebrow"><?php esc_html_e( 'Private coordinator workspace', 'extrachill-events' ); ?></p>
			<h1><?php echo esc_html( $post ? $post->post_title : __( 'Vendor applications', 'extrachill-events' ) ); ?></h1>
			<span class="ec-vendor-request__status"><?php echo esc_html( $request['status'] ); ?></span>
		</header>
		<?php extrachill_events_vendor_request_notice(); ?>
		<?php if ( get_current_user_id() === (int) $request['coordinator_user_id'] ) : ?>
			<section class="ec-vendor-request__section"><h2><?php esc_html_e( 'Application window', 'extrachill-events' ); ?></h2>
				<form method="post" class="ec-vendor-request__inline-form"><?php wp_nonce_field( 'extrachill_events_vendor_request' ); ?><input type="hidden" name="vendor_request_action" value="status"><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request['id'] ); ?>"><input type="hidden" name="expected_version" value="<?php echo esc_attr( (string) $request['version'] ); ?>"><input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><input type="hidden" name="open" value="<?php echo 'open' === $request['status'] ? '0' : '1'; ?>"><button class="button-1" type="submit"><?php echo esc_html( 'open' === $request['status'] ? __( 'Close applications', 'extrachill-events' ) : __( 'Reopen applications', 'extrachill-events' ) ); ?></button></form>
			</section>
		<?php endif; ?>
		<section class="ec-vendor-request__section"><h2><?php esc_html_e( 'Applicants', 'extrachill-events' ); ?></h2>
			<?php
			if ( empty( $applications ) ) :
				?>
				<p><?php esc_html_e( 'No vendor applications yet.', 'extrachill-events' ); ?></p><?php endif; ?>
			<div class="ec-vendor-request__cards">
			<?php
			foreach ( $applications as $application ) :
				extrachill_events_render_vendor_application_card( $application );
endforeach;
			?>
			</div>
		</section>
	</section>
	<?php
}

/** Render one private application card. */
function extrachill_events_render_vendor_application_card( array $application ): void {
	$power     = $application['power_needs'] ? $application['power_needs'] : __( 'None stated', 'extrachill-events' );
	$insurance = $application['insurance_notes'] ? $application['insurance_notes'] : __( 'None stated', 'extrachill-events' );
	?>
	<article class="ec-vendor-request__card">
		<h3><?php echo esc_html( $application['business_name'] ); ?></h3><p><strong><?php echo esc_html( $application['category'] ); ?></strong> · <?php echo esc_html( $application['status'] ); ?></p>
		<dl><dt><?php esc_html_e( 'Footprint', 'extrachill-events' ); ?></dt><dd><?php echo esc_html( $application['footprint'] ); ?></dd><dt><?php esc_html_e( 'Power', 'extrachill-events' ); ?></dt><dd><?php echo esc_html( $power ); ?></dd><dt><?php esc_html_e( 'Insurance / permits', 'extrachill-events' ); ?></dt><dd><?php echo esc_html( $insurance ); ?></dd></dl>
		<p><?php echo nl2br( esc_html( $application['message'] ) ); ?></p>
		<?php
		if ( is_array( $application['contact'] ) ) :
			?>
			<dl class="ec-vendor-request__contact"><dt><?php esc_html_e( 'Contact', 'extrachill-events' ); ?></dt><dd><?php echo esc_html( $application['contact']['name'] ); ?> · <?php echo esc_html( $application['contact']['email'] ); ?>
			<?php
			if ( ! empty( $application['contact']['phone'] ) ) :
				?>
			· <?php echo esc_html( $application['contact']['phone'] ); ?><?php endif; ?></dd></dl>
			<?php
else :
	?>
	<p class="ec-vendor-request__privacy"><?php esc_html_e( 'The applicant withdrew contact consent.', 'extrachill-events' ); ?></p><?php endif; ?>
		<form method="post" class="ec-vendor-request__form"><?php wp_nonce_field( 'extrachill_events_vendor_request' ); ?><input type="hidden" name="vendor_request_action" value="review"><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $application['request_id'] ); ?>"><input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $application['id'] ); ?>"><input type="hidden" name="expected_version" value="<?php echo esc_attr( (string) $application['version'] ); ?>"><input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><label><?php esc_html_e( 'Review status', 'extrachill-events' ); ?><select name="status">
		<?php
		foreach ( array( 'submitted', 'reviewing', 'accepted', 'declined' ) as $status ) :
			?>
			<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $application['status'], $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Private notes', 'extrachill-events' ); ?><textarea name="notes" rows="3"><?php echo esc_textarea( (string) $application['private_notes'] ); ?></textarea></label><button class="button-2" type="submit"><?php esc_html_e( 'Save review', 'extrachill-events' ); ?></button></form>
		<?php
		if ( is_array( $application['contact'] ) && 'withdrawn' !== $application['status'] ) :
			?>
			<form method="post" class="ec-vendor-request__form"><?php wp_nonce_field( 'extrachill_events_vendor_request' ); ?><input type="hidden" name="vendor_request_action" value="contact"><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $application['request_id'] ); ?>"><input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $application['id'] ); ?>"><input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><label><?php esc_html_e( 'Subject', 'extrachill-events' ); ?><input name="subject" required maxlength="255"></label><label><?php esc_html_e( 'Managed message', 'extrachill-events' ); ?><textarea name="message" required rows="4"></textarea></label><p class="ec-vendor-request__privacy"><?php esc_html_e( 'Sent by Extra Chill Bot on Chris Huber’s behalf, with Chris copied. Your private email address is not disclosed.', 'extrachill-events' ); ?></p><button class="button-2" type="submit"><?php esc_html_e( 'Queue message', 'extrachill-events' ); ?></button></form><?php endif; ?>
	</article>
	<?php
}

/** Render the exact coordinator's request configuration form. */
function extrachill_events_render_vendor_request_open_form( int $event_id ): void {
	?>
	<section class="ec-vendor-request"><header class="ec-vendor-request__hero"><p class="ec-vendor-request__eyebrow"><?php esc_html_e( 'Event coordinator', 'extrachill-events' ); ?></p><h1><?php esc_html_e( 'Request vendors', 'extrachill-events' ); ?></h1></header><section class="ec-vendor-request__section"><form method="post" class="ec-vendor-request__form"><?php wp_nonce_field( 'extrachill_events_vendor_request' ); ?><input type="hidden" name="vendor_request_action" value="open"><input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>"><input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><label><?php esc_html_e( 'Vendor categories', 'extrachill-events' ); ?><input name="categories" placeholder="Food, Art, Merchandise" aria-describedby="vendor-category-help"></label><p id="vendor-category-help" class="ec-vendor-request__privacy"><?php esc_html_e( 'Comma-separated. Leave blank to accept any category.', 'extrachill-events' ); ?></p><label><?php esc_html_e( 'Applicant instructions', 'extrachill-events' ); ?><textarea name="instructions" rows="4"></textarea></label><label><input type="checkbox" name="power_required" value="1"> <?php esc_html_e( 'Require power details', 'extrachill-events' ); ?></label><label><input type="checkbox" name="insurance_required" value="1"> <?php esc_html_e( 'Require insurance / permit notes', 'extrachill-events' ); ?></label><button class="button-1" type="submit"><?php esc_html_e( 'Open vendor applications', 'extrachill-events' ); ?></button></form></section></section>
	<?php
}

/** Render the public application without coordinator identity or contact. */
function extrachill_events_render_vendor_application(): void {
	$public_id  = sanitize_text_field( (string) get_query_var( 'ec_vendor_apply', '' ) );
	$repository = new VendorRequestRepository();
	$request    = $repository->get_request_by_public_id( $public_id );
	if ( ! is_array( $request ) || 'open' !== $request['status'] ) {
		extrachill_events_render_vendor_request_unavailable( __( 'Vendor applications are closed', 'extrachill-events' ) );
		return;
	}
	$post       = get_post( $request['event_id'] );
	$categories = (array) ( $request['policy']['categories'] ?? array() );
	$endpoint   = rest_url( 'extrachill/v1/events/' . $request['event_id'] . '/vendor-applications' );
	if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
		ec_enqueue_turnstile_script();
	}
	wp_enqueue_script( 'extrachill-events-vendor-application', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/vendor-application.js', array(), (string) filemtime( EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/js/vendor-application.js' ), true );
	?>
	<section class="ec-vendor-request" data-vendor-application data-endpoint="<?php echo esc_url( $endpoint ); ?>">
		<header class="ec-vendor-request__hero"><p class="ec-vendor-request__eyebrow"><?php esc_html_e( 'Vendor application', 'extrachill-events' ); ?></p><h1><?php echo esc_html( $post ? $post->post_title : __( 'Event vendor application', 'extrachill-events' ) ); ?></h1>
		<?php
		if ( ! empty( $request['policy']['instructions'] ) ) :
			?>
			<p><?php echo nl2br( esc_html( $request['policy']['instructions'] ) ); ?></p><?php endif; ?></header>
		<div class="ec-vendor-request__notice" role="status" aria-live="polite" hidden data-vendor-application-status></div>
		<section class="ec-vendor-request__section"><form class="ec-vendor-request__form" data-vendor-application-form><input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $request['event_id'] ); ?>"><input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><label><?php esc_html_e( 'Business name', 'extrachill-events' ); ?><input name="business_name" required maxlength="255" autocomplete="organization"></label><label><?php esc_html_e( 'Point-of-contact name', 'extrachill-events' ); ?><input name="contact_name" required maxlength="255" autocomplete="name"></label><label><?php esc_html_e( 'Point-of-contact email', 'extrachill-events' ); ?><input type="email" name="contact_email" required maxlength="255" autocomplete="email"></label><label><?php esc_html_e( 'Phone (optional, private)', 'extrachill-events' ); ?><input type="tel" name="contact_phone" maxlength="64" autocomplete="tel"></label><label><?php esc_html_e( 'Vendor category', 'extrachill-events' ); ?>
		<?php
		if ( $categories ) :
			?>
			<select name="category" required><option value=""><?php esc_html_e( 'Select a category', 'extrachill-events' ); ?></option>
			<?php
			foreach ( $categories as $category ) :
				?>
			<option value="<?php echo esc_attr( $category ); ?>"><?php echo esc_html( $category ); ?></option><?php endforeach; ?></select>
			<?php
else :
	?>
	<input name="category" required maxlength="191"><?php endif; ?></label><label><?php esc_html_e( 'Website or social link (optional)', 'extrachill-events' ); ?><input type="url" name="website_url" maxlength="2000"></label><label><?php esc_html_e( 'Booth footprint', 'extrachill-events' ); ?><input name="footprint" required maxlength="255" placeholder="10 × 10 feet"></label><label><?php esc_html_e( 'Power needs', 'extrachill-events' ); ?><textarea name="power_needs" rows="3" <?php echo ! empty( $request['policy']['power_required'] ) ? 'required' : ''; ?>></textarea></label><label><?php esc_html_e( 'Insurance / permit notes', 'extrachill-events' ); ?><textarea name="insurance_notes" rows="3" <?php echo ! empty( $request['policy']['insurance_required'] ) ? 'required' : ''; ?>></textarea></label><label><?php esc_html_e( 'Message', 'extrachill-events' ); ?><textarea name="message" required rows="5" maxlength="5000"></textarea></label><label class="ec-vendor-request__consent"><input type="checkbox" name="contact_consent" value="1" required> <?php esc_html_e( 'I consent to share the contact fields above only with the authorized coordinator and event organizers for this event. I can withdraw this application and revoke contact access.', 'extrachill-events' ); ?></label><div data-vendor-turnstile>
	<?php
	if ( function_exists( 'ec_render_turnstile_widget' ) ) {
		echo wp_kses_post( ec_render_turnstile_widget( array( 'data-appearance' => 'always' ) ) ); }
	?>
</div><button class="button-1" type="submit"><?php esc_html_e( 'Submit vendor application', 'extrachill-events' ); ?></button></form></section>
	</section>
	<?php
}

function extrachill_events_vendor_request_notice(): void {
	$notice = sanitize_key( (string) ( $_GET['notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only status.
	if ( $notice ) {
		printf( '<div class="ec-vendor-request__notice ec-vendor-request__notice--%1$s" role="status">%2$s</div>', esc_attr( 'error' === $notice ? 'error' : 'success' ), esc_html( 'error' === $notice ? __( 'The action could not be completed.', 'extrachill-events' ) : __( 'Vendor request updated.', 'extrachill-events' ) ) );
	}
}

function extrachill_events_render_vendor_request_unavailable( string $title = '' ): void {
	$heading = '' !== $title ? $title : __( 'Workspace unavailable', 'extrachill-events' );
	printf( '<section class="ec-vendor-request"><div class="ec-vendor-request__notice ec-vendor-request__notice--error" role="alert"><h1>%s</h1><p>%s</p></div></section>', esc_html( $heading ), esc_html__( 'This vendor request is closed, missing, or not authorized for your account.', 'extrachill-events' ) );
}

/** Load vendor UI only on its public/private surfaces. */
function extrachill_events_enqueue_vendor_request_assets(): void {
	if ( extrachill_events_is_vendor_request_page() || extrachill_events_is_vendor_application_page() || is_singular( 'data_machine_events' ) ) {
		wp_enqueue_style( 'extrachill-events-vendor-request', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/vendor-request.css', array(), (string) filemtime( EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/vendor-request.css' ) );
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_events_enqueue_vendor_request_assets' );
add_action( 'data_machine_events_action_buttons', 'extrachill_events_vendor_request_event_action', 20, 1 );
