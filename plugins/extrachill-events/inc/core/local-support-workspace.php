<?php
/**
 * Private Local Support workspace UI.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\LocalSupportAuthorization;
use ExtraChillEvents\Core\LocalSupportRepository;
use ExtraChillEvents\Core\LocalSupportWorkspace;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supply the exact private route to the notification adapter.
 *
 * @param mixed $url Existing URL value.
 * @param array $request Local Support request.
 * @param int   $recipient_id Authorized recipient ID.
 * @return string Workspace URL.
 */
function extrachill_events_local_support_workspace_url( $url, array $request, int $recipient_id ) {
	unset( $url, $recipient_id );
	return get_home_url( (int) ec_get_blog_id( 'events' ), '/local-support/' . absint( $request['id'] ) . '/' );
}
if ( ! defined( 'EXTRACHILL_EVENTS_LOCAL_SUPPORT_SKIP_HOOKS' ) ) {
	add_filter( 'extrachill_events_local_support_workspace_url', 'extrachill_events_local_support_workspace_url', 10, 3 );
}

/** Process nonce-protected forms before any template output. */
function extrachill_events_handle_local_support_action(): void {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( ! extrachill_events_is_local_support_page() || 'POST' !== strtoupper( $method ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'extrachill_events_local_support' ) ) {
		wp_die( esc_html__( 'This local support action expired. Refresh and try again.', 'extrachill-events' ), 403 );
	}
	$input  = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
	$result = extrachill_events_process_local_support_action( $input, get_current_user_id() );
	$id     = is_array( $result ) ? absint( $result['request_id'] ?? $result['id'] ?? $input['request_id'] ?? 0 ) : absint( $input['request_id'] ?? 0 );
	$query  = array( 'notice' => is_wp_error( $result ) ? ( 'local_support_version_conflict' === $result->get_error_code() ? 'conflict' : 'error' ) : 'updated' );
	if ( ! empty( $input['artist_term_id'] ) ) {
		$query['artist_id'] = absint( $input['artist_term_id'] );
	}
	wp_safe_redirect( add_query_arg( $query, home_url( $id ? '/local-support/' . $id . '/' : '/local-support/' ) ) );
	exit;
}
add_action( 'template_redirect', 'extrachill_events_handle_local_support_action', 1 );

/**
 * Process sanitized workspace input through the canonical domain adapter.
 *
 * @param array                      $input Sanitized form input.
 * @param int                        $user_id Acting user ID.
 * @param LocalSupportWorkspace|null $workspace Optional deterministic test adapter.
 * @return array|WP_Error Updated record or error.
 */
function extrachill_events_process_local_support_action( array $input, int $user_id, ?LocalSupportWorkspace $workspace = null ) {
	$action    = sanitize_key( (string) ( $input['local_support_action'] ?? '' ) );
	$workspace = $workspace ? $workspace : new LocalSupportWorkspace();
	return $workspace->act( $action, $input, $user_id );
}

/**
 * Add an organizer-only contextual action without exposing request state.
 *
 * @param int $event_id Canonical event ID.
 */
function extrachill_events_local_support_event_action( $event_id ): void {
	if ( ! is_user_logged_in() || ! is_singular( 'data_machine_events' ) || empty( extrachill_events_local_support_organizer_options( absint( $event_id ), get_current_user_id() ) ) ) {
		return;
	}
	$request = ( new LocalSupportRepository() )->get_request_by_event( absint( $event_id ) );
	$url     = is_array( $request ) ? home_url( '/local-support/' . absint( $request['id'] ) . '/' ) : add_query_arg( 'event_id', absint( $event_id ), home_url( '/local-support/' ) );
	printf( '<a class="button-2 button-medium" href="%s">%s</a>', esc_url( $url ), esc_html__( 'Find local support', 'extrachill-events' ) );
}
add_action( 'data_machine_events_action_buttons', 'extrachill_events_local_support_event_action', 20, 1 );

/**
 * Resolve exact venue or attached-artist organizer identities for an event.
 *
 * @param int $event_id Canonical event ID.
 * @param int $user_id Acting user ID.
 * @return array Authorized organizer choices.
 */
function extrachill_events_local_support_organizer_options( int $event_id, int $user_id ): array {
	$authorization = new LocalSupportAuthorization();
	$context       = $authorization->event_context( $event_id );
	if ( is_wp_error( $context ) ) {
		return array();
	}
	$options = array();
	$venue   = get_term( (int) $context['venue_term_id'], 'venue' );
	$request = array(
		'event_id'       => $event_id,
		'venue_term_id'  => (int) $context['venue_term_id'],
		'organizer_type' => 'venue',
		'organizer_id'   => (int) $context['venue_term_id'],
	);
	if ( true === $authorization->authorize_organizer( $request, $user_id ) ) {
		$options[] = array(
			'type'  => 'venue',
			'id'    => (int) $context['venue_term_id'],
			'label' => $venue instanceof WP_Term ? $venue->name : __( 'Venue', 'extrachill-events' ),
		);
	}
	foreach ( (array) wp_get_object_terms( $event_id, 'artist', array( 'fields' => 'ids' ) ) as $artist_term_id ) {
		$request['organizer_type'] = 'artist';
		$request['organizer_id']   = absint( $artist_term_id );
		if ( true === $authorization->authorize_organizer( $request, $user_id ) ) {
			$term      = get_term( absint( $artist_term_id ), 'artist' );
			$options[] = array(
				'type'  => 'artist',
				'id'    => absint( $artist_term_id ),
				'label' => $term instanceof WP_Term ? $term->name : __( 'Touring artist', 'extrachill-events' ),
			);
		}
	}
	return $options;
}

/** Render the current private workspace or a non-enumerating denial. */
function extrachill_events_render_local_support_workspace(): void {
	wp_enqueue_style( 'extrachill-events-local-support', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/local-support.css', array(), EXTRACHILL_EVENTS_VERSION );
	wp_enqueue_script( 'extrachill-events-local-support', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/local-support.js', array(), EXTRACHILL_EVENTS_VERSION, true );
	if ( ! is_user_logged_in() ) {
		printf( '<section class="ec-local-support ec-block-shell"><h1>%s</h1><p>%s</p><a class="button-1" href="%s">%s</a></section>', esc_html__( 'Local Support', 'extrachill-events' ), esc_html__( 'Sign in to open your private request workspace.', 'extrachill-events' ), esc_url( wp_login_url( home_url( '/local-support/' ) ) ), esc_html__( 'Sign in', 'extrachill-events' ) );
		return;
	}
	$request_id = absint( get_query_var( 'ec_local_support_request', 0 ) );
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only route context; mutations require a nonce.
	$artist_id = isset( $_GET['artist_id'] ) ? absint( wp_unslash( $_GET['artist_id'] ) ) : 0;
	$event_id  = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;
	$notice    = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( ! $request_id ) {
		extrachill_events_render_local_support_open( $event_id );
		return;
	}
	$model = ( new LocalSupportWorkspace() )->read( $request_id, $artist_id, get_current_user_id() );
	if ( is_wp_error( $model ) ) {
		status_header( in_array( $model->get_error_code(), array( 'local_support_forbidden', 'local_support_not_found' ), true ) ? 404 : 503 );
		extrachill_events_render_local_support_unavailable();
		return;
	}
	?>
	<section class="ec-local-support ec-block-shell" data-local-support-workspace>
		<header class="ec-local-support__hero"><p class="ec-local-support__eyebrow"><?php esc_html_e( 'Private event workspace', 'extrachill-events' ); ?></p><h1><?php echo esc_html( $model['event']['title'] ); ?></h1><p><?php echo esc_html( $model['event']['venue'] ); ?> <a href="<?php echo esc_url( $model['event']['permalink'] ); ?>"><?php esc_html_e( 'View event', 'extrachill-events' ); ?></a></p><span class="ec-local-support__status"><?php echo esc_html( ucfirst( $model['request']['status'] ) ); ?></span></header>
		<?php extrachill_events_local_support_notice( $notice ); ?>
		<?php
		if ( 'organizer' === $model['role'] ) {
			extrachill_events_render_local_support_organizer( $model );
		} elseif ( 'artist_selection' === $model['role'] ) {
			extrachill_events_render_local_support_artist_selection( $model );
		} else {
			extrachill_events_render_local_support_artist( $model );
		}
		?>
	</section>
	<?php
}

/** Render the shared non-enumerating unauthorized/not-found state. */
function extrachill_events_render_local_support_unavailable(): void {
	echo '<section class="ec-local-support ec-block-shell" role="alert"><h1>' . esc_html__( 'Workspace unavailable', 'extrachill-events' ) . '</h1><p>' . esc_html__( 'This request is unavailable or you no longer have access.', 'extrachill-events' ) . '</p></section>';
}

/**
 * Let a manager choose the exact eligible artist represented in this request.
 *
 * @param array $model Authorized workspace model.
 */
function extrachill_events_render_local_support_artist_selection( array $model ): void {
	?>
	<div class="ec-local-support__section">
		<h2><?php esc_html_e( 'Choose an artist', 'extrachill-events' ); ?></h2>
		<p><?php esc_html_e( 'Respond for one exact artist you manage. Each artist controls availability through Artist Manager.', 'extrachill-events' ); ?></p>
		<div class="ec-local-support__cards">
			<?php foreach ( $model['candidates'] as $candidate ) : ?>
				<a class="ec-local-support__artist-card" href="<?php echo esc_url( add_query_arg( 'artist_id', (int) $candidate['artist_term_id'], home_url( '/local-support/' . (int) $model['request']['id'] . '/' ) ) ); ?>">
					<?php
					if ( ! empty( $candidate['profile_image_url'] ) ) :
						?>
						<img src="<?php echo esc_url( $candidate['profile_image_url'] ); ?>" alt="" /><?php endif; ?>
					<strong><?php echo esc_html( $candidate['name'] ); ?></strong>
					<span><?php echo esc_html( implode( ' / ', array_filter( array( $candidate['genre'] ?? '', $candidate['local_city'] ?? '' ) ) ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

/**
 * Render exact-event request creation.
 *
 * @param int $event_id Canonical event ID.
 */
function extrachill_events_render_local_support_open( int $event_id ): void {
	$options = $event_id ? extrachill_events_local_support_organizer_options( $event_id, get_current_user_id() ) : array();
	$post    = $event_id ? get_post( $event_id ) : null;
	if ( ! $post instanceof WP_Post || empty( $options ) ) {
		status_header( 404 );
		echo '<section class="ec-local-support ec-block-shell" role="alert"><h1>' . esc_html__( 'Workspace unavailable', 'extrachill-events' ) . '</h1><p>' . esc_html__( 'Open Local Support from an event you organize.', 'extrachill-events' ) . '</p></section>';
		return;
	}
	?>
	<section class="ec-local-support ec-block-shell"><p class="ec-local-support__eyebrow"><?php esc_html_e( 'Create opportunity', 'extrachill-events' ); ?></p><h1><?php echo esc_html( $post->post_title ); ?></h1><p><?php esc_html_e( 'Invite eligible local artists to privately express interest. Contact information stays hidden unless an artist separately grants request-specific consent.', 'extrachill-events' ); ?></p>
		<form method="post" class="ec-local-support__form"><?php wp_nonce_field( 'extrachill_events_local_support' ); ?><input type="hidden" name="local_support_action" value="open" /><input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" /><input type="hidden" name="idempotency_key" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>" /><label for="local-support-organizer"><?php esc_html_e( 'Open this request as', 'extrachill-events' ); ?></label><select id="local-support-organizer" data-organizer-select>
		<?php
		foreach ( $options as $option ) :
			?>
			<option value="<?php echo esc_attr( $option['type'] . ':' . $option['id'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option><?php endforeach; ?></select><input type="hidden" name="organizer_type" value="<?php echo esc_attr( $options[0]['type'] ); ?>" data-organizer-type /><input type="hidden" name="organizer_id" value="<?php echo esc_attr( $options[0]['id'] ); ?>" data-organizer-id /><button class="button-1" type="submit" data-loading-label="Opening request..."><?php esc_html_e( 'Open local support request', 'extrachill-events' ); ?></button></form>
	</section>
	<?php
}

/**
 * Render organizer controls and response-only shortlist.
 *
 * @param array $model Authorized workspace model.
 */
function extrachill_events_render_local_support_organizer( array $model ): void {
	$request     = $model['request'];
	$transitions = array(
		'open'   => array(
			'paused' => __( 'Pause responses', 'extrachill-events' ),
			'filled' => __( 'Mark filled', 'extrachill-events' ),
			'closed' => __( 'Close request', 'extrachill-events' ),
		),
		'paused' => array(
			'open'   => __( 'Resume responses', 'extrachill-events' ),
			'closed' => __( 'Close request', 'extrachill-events' ),
		),
		'filled' => array( 'closed' => __( 'Close request', 'extrachill-events' ) ),
	);
	?>
	<div class="ec-local-support__section"><h2><?php esc_html_e( 'Request controls', 'extrachill-events' ); ?></h2><div class="ec-local-support__actions">
	<?php
	foreach ( $transitions[ $request['status'] ] ?? array() as $status => $label ) {
		extrachill_events_local_support_action_form( 'request', $request['id'], 0, $request['version'], $status, $label ); }
	?>
	</div></div>
	<div class="ec-local-support__section"><h2><?php esc_html_e( 'Interested artists', 'extrachill-events' ); ?></h2><p><?php esc_html_e( 'Only artists who responded are shown. Contact details appear only while request-specific consent is active.', 'extrachill-events' ); ?></p>
		<?php
		if ( empty( $model['interests'] ) ) :
			?>
			<div class="ec-local-support__empty"><strong><?php esc_html_e( 'No responses yet', 'extrachill-events' ); ?></strong><p><?php esc_html_e( 'Eligible artists can respond from their private notification link.', 'extrachill-events' ); ?></p></div><?php endif; ?>
		<div class="ec-local-support__cards">
		<?php
		foreach ( $model['interests'] as $interest ) :
			?>
			<article class="ec-local-support__artist-card">
			<?php
			if ( ! empty( $interest['artist']['profile_image_url'] ) ) :
				?>
			<img src="<?php echo esc_url( $interest['artist']['profile_image_url'] ); ?>" alt="" /><?php endif; ?><div><h3><?php echo esc_html( $interest['artist']['name'] ); ?></h3><p><?php echo esc_html( implode( ' / ', array_filter( array( $interest['artist']['genre'] ?? '', $interest['artist']['local_city'] ?? '' ) ) ) ); ?></p><span class="ec-local-support__status"><?php echo esc_html( ucfirst( $interest['status'] ) ); ?></span></div>
			<?php
			if ( is_array( $interest['contact'] ?? null ) ) :
				?>
				<dl class="ec-local-support__contact">
				<?php
				foreach ( $interest['contact'] as $field => $value ) :
					?>
				<div><dt><?php echo esc_html( ucfirst( $field ) ); ?></dt><dd><?php echo 'email' === $field ? '<a href="mailto:' . esc_attr( $value ) . '">' . esc_html( $value ) . '</a>' : esc_html( $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches escape output. ?></dd></div><?php endforeach; ?></dl>
				<?php
else :
	?>
	<p class="ec-local-support__privacy"><?php esc_html_e( 'Contact not shared', 'extrachill-events' ); ?></p><?php endif; ?>
			<div class="ec-local-support__actions">
			<?php
			if ( 'interested' === $interest['status'] ) {
				extrachill_events_local_support_action_form( 'interest_status', $request['id'], $interest['id'], $interest['version'], 'shortlisted', __( 'Shortlist', 'extrachill-events' ) );
				extrachill_events_local_support_action_form( 'interest_status', $request['id'], $interest['id'], $interest['version'], 'declined', __( 'Decline', 'extrachill-events' ) );
			} elseif ( 'shortlisted' === $interest['status'] ) {
				extrachill_events_local_support_action_form( 'interest_status', $request['id'], $interest['id'], $interest['version'], 'selected', __( 'Select artist', 'extrachill-events' ) );
				extrachill_events_local_support_action_form( 'interest_status', $request['id'], $interest['id'], $interest['version'], 'declined', __( 'Decline', 'extrachill-events' ) ); }
			?>
			</div></article><?php endforeach; ?></div>
	</div>
	<?php
}

/**
 * Render artist interest separately from explicit contact disclosure.
 *
 * @param array $model Authorized workspace model.
 */
function extrachill_events_render_local_support_artist( array $model ): void {
	$request  = $model['request'];
	$interest = $model['interest'];
	$user     = wp_get_current_user();
	$active   = $interest && in_array( $interest['status'], array( 'interested', 'shortlisted', 'selected' ), true );
	?>
	<div class="ec-local-support__section"><h2><?php echo esc_html( $model['artist']['name'] ); ?></h2>
		<?php
		if ( ! $interest && 'open' === $request['status'] ) :
			?>
			<p><?php esc_html_e( 'Expressing interest tells the organizer you want to discuss this slot. It does not share contact details.', 'extrachill-events' ); ?></p><?php extrachill_events_local_support_action_form( 'interest', $request['id'], 0, 0, '', __( "I'm interested", 'extrachill-events' ), (int) $model['artist']['artist_term_id'] ); ?>
			<?php
		elseif ( ! $interest ) :
			?>
			<div class="ec-local-support__empty"><strong><?php esc_html_e( 'Responses are not open', 'extrachill-events' ); ?></strong></div>
			<?php
		else :
			?>
			<p><?php /* translators: %s is the artist's interest status. */ printf( esc_html__( 'Your response is currently %s.', 'extrachill-events' ), esc_html( $interest['status'] ) ); ?></p>
			<?php
			if ( ! empty( $model['eligible'] ) && $active ) {
				extrachill_events_local_support_action_form( 'interest_status', $request['id'], $interest['id'], $interest['version'], 'withdrawn', __( 'Withdraw interest', 'extrachill-events' ), (int) $model['artist']['artist_term_id'] ); }
			?>
<?php endif; ?>
	</div>
	<?php
	if ( $interest && ( is_array( $interest['contact'] ?? null ) || ( ! empty( $model['eligible'] ) && $active ) ) ) :
		?>
		<div class="ec-local-support__section"><h2><?php esc_html_e( 'Contact sharing', 'extrachill-events' ); ?></h2><p><?php esc_html_e( "Contact sharing is separate from interest. Preview and choose the exact fields this request's organizer may see. You can revoke access at any time.", 'extrachill-events' ); ?></p>
		<?php
		if ( is_array( $interest['contact'] ?? null ) ) :
			?>
			<div class="ec-local-support__consent-preview"><strong><?php esc_html_e( 'Currently shared', 'extrachill-events' ); ?></strong><ul>
			<?php
			foreach ( $interest['contact'] as $field => $value ) :
				?>
			<li><?php echo esc_html( ucfirst( $field ) . ': ' . $value ); ?></li><?php endforeach; ?></ul></div><?php extrachill_events_local_support_consent_form( $model, false ); ?>
			<?php
		else :
			?>
			<form method="post" class="ec-local-support__form" data-consent-form><?php extrachill_events_local_support_consent_fields( $model, true ); ?>
			<?php
			foreach ( array(
				'name'  => $user->display_name,
				'email' => $user->user_email,
				'phone' => '',
			) as $field => $value ) :
				?>
			<label><span><?php echo esc_html( ucfirst( $field ) ); ?></span><input type="<?php echo 'email' === $field ? 'email' : ( 'phone' === $field ? 'tel' : 'text' ); ?>" name="contact_<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $value ); ?>" data-contact-field="<?php echo esc_attr( $field ); ?>" /><span><input type="checkbox" name="fields[]" value="<?php echo esc_attr( $field ); ?>" data-consent-field /> <?php esc_html_e( 'Share this field', 'extrachill-events' ); ?></span></label><?php endforeach; ?><div class="ec-local-support__consent-preview" aria-live="polite"><strong><?php esc_html_e( 'Organizer preview', 'extrachill-events' ); ?></strong><p data-consent-preview><?php esc_html_e( 'No contact fields selected.', 'extrachill-events' ); ?></p></div><button class="button-1" type="submit" data-loading-label="Sharing selected contact..."><?php esc_html_e( 'Share selected contact', 'extrachill-events' ); ?></button></form><?php endif; ?>
	</div><?php endif; ?>
	<?php
}

/**
 * Render shared hidden fields for a consent mutation.
 *
 * @param array $model Authorized workspace model.
 * @param bool  $granted Whether contact consent is granted.
 */
function extrachill_events_local_support_consent_fields( array $model, bool $granted ): void {
	$interest = $model['interest'];
	wp_nonce_field( 'extrachill_events_local_support' );
	foreach ( array(
		'local_support_action' => 'consent',
		'granted'              => $granted ? '1' : '',
		'request_id'           => $model['request']['id'],
		'artist_term_id'       => $model['artist']['artist_term_id'],
		'interest_id'          => $interest['id'],
		'expected_version'     => $interest['version'],
		'idempotency_key'      => wp_generate_uuid4(),
	) as $name => $value ) {
		printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( $value ) );
	}
}

/**
 * Render the revoke-contact form.
 *
 * @param array $model Authorized workspace model.
 * @param bool  $granted Whether contact consent is granted.
 */
function extrachill_events_local_support_consent_form( array $model, bool $granted ): void {
	?>
	<form method="post" class="ec-local-support__form"><?php extrachill_events_local_support_consent_fields( $model, $granted ); ?><button class="button-2" type="submit" data-loading-label="Revoking access..."><?php esc_html_e( 'Revoke contact access', 'extrachill-events' ); ?></button></form>
	<?php
}

/**
 * Render a compact optimistic-locking mutation form.
 *
 * @param string $action UI action.
 * @param int    $request_id Request ID.
 * @param int    $interest_id Interest ID.
 * @param int    $version Expected record version.
 * @param string $status Target status.
 * @param string $label Button label.
 * @param int    $artist_term_id Acting artist term ID.
 */
function extrachill_events_local_support_action_form( string $action, int $request_id, int $interest_id, int $version, string $status, string $label, int $artist_term_id = 0 ): void {
	?>
	<form method="post" class="ec-local-support__inline-form"><?php wp_nonce_field( 'extrachill_events_local_support' ); ?>
	<?php
	foreach ( array(
		'local_support_action' => $action,
		'request_id'           => $request_id,
		'interest_id'          => $interest_id,
		'artist_term_id'       => $artist_term_id,
		'expected_version'     => $version,
		'to_status'            => $status,
		'idempotency_key'      => wp_generate_uuid4(),
	) as $name => $value ) {
		printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( $value ) ); }
	?>
	<button class="button-2" type="submit" data-loading-label="Updating..."><?php echo esc_html( $label ); ?></button></form>
	<?php
}

/**
 * Render a bounded result notice, including recoverable stale-version state.
 *
 * @param string $notice Notice key.
 */
function extrachill_events_local_support_notice( string $notice ): void {
	$messages = array(
		'updated'  => array( 'success', __( 'Local support request updated.', 'extrachill-events' ) ),
		'conflict' => array( 'warning', __( 'Someone updated this request first. The latest version is shown; review it and try again.', 'extrachill-events' ) ),
		'error'    => array( 'error', __( 'That action could not be completed. Access and current state were rechecked.', 'extrachill-events' ) ),
	);
	if ( isset( $messages[ $notice ] ) ) {
		printf( '<div class="ec-local-support__notice ec-local-support__notice--%s" role="%s">%s</div>', esc_attr( $messages[ $notice ][0] ), 'error' === $messages[ $notice ][0] ? 'alert' : 'status', esc_html( $messages[ $notice ][1] ) );
	}
}
