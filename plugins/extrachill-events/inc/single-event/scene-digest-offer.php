<?php
/**
 * Single-event Local Scene digest offer (extrachill-events#962).
 *
 * Organic search lands on single event pages, but the weekly Local Scene
 * digest was only offered on city archives to logged-in members whose city
 * already matched. This module puts one compact, consent-based offer on every
 * event page whose city is resolvable:
 *
 *   "Get this week's shows in {city} by email"
 *
 * State machine (first match wins):
 *   - no selectable city on the event            → nothing.
 *   - logged out                                 → sign-in link carrying the
 *     existing signed archive continuation, returning to THIS event page.
 *   - signed subscribe continuation on return    → explicit one-click confirm
 *     (autofocus). Subscribing someone to email straight from a link would
 *     let a crafted URL opt in any signed-in visitor, so the archive's
 *     "nothing changes until you confirm" rule applies here too.
 *   - just subscribed (flash)                    → success notice.
 *   - already subscribed                         → nothing.
 *   - Local Scene set to a different city        → nothing. The digest only
 *     delivers to members whose scene matches the city, so subscribing here
 *     would silently never send. The archive's "Make this my city" owns that.
 *   - no Local Scene                             → one click sets it to this
 *     city and subscribes.
 *   - Local Scene is this city                   → one click subscribes.
 *
 * The extrachill-users attendance button dispatches `ec:attendance-changed`
 * after a mark; assets/js/event-digest-offer.js reframes this same offer at
 * that moment (extrachill-users#450). Users never learns about the digest.
 *
 * Analytics:
 *   - view:       single-event page_view already counts every page this
 *                 renders on (the offer renders whenever a city resolves).
 *   - click:      `cta_click` through the data-ec-track ids below.
 *   - completion: `local_scene_digest_subscribed`, emitted only when
 *                 extrachill-users reports a newly created subscription row.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_EVENTS_EVENT_DIGEST_SURFACES = array( 'event_page', 'post_mark' );

/**
 * Resolve the selectable city for an event.
 *
 * Events carry one canonical `location` term. Only city-depth terms (two or
 * more ancestors) are digest entities, matching the digest runner and the
 * archive prompt.
 *
 * @param int $post_id Event post ID.
 * @return WP_Term|null City term.
 */
function extrachill_events_get_event_scene_term( int $post_id ): ?WP_Term {
	$terms = $post_id > 0 ? get_the_terms( $post_id, 'location' ) : false;
	if ( ! is_array( $terms ) ) {
		return null;
	}

	foreach ( $terms as $term ) {
		if ( count( get_ancestors( $term->term_id, 'location', 'taxonomy' ) ) >= 2 ) {
			return $term;
		}
	}

	return null;
}

/**
 * Request-scoped analytics context for a digest subscription about to be made.
 *
 * Callers set the surface before executing `extrachill/entity-subscribe`; the
 * creation listener reads it. Unset means the progressive archive toggle,
 * which subscribes through the Abilities REST runner.
 *
 * @param array|null $context New context to set, or null to read.
 * @return array{surface:string,event_id:int,via_continuation:bool}
 */
function extrachill_events_digest_subscription_context( ?array $context = null ): array {
	static $current = null;

	if ( null !== $context ) {
		$current = array(
			'surface'          => sanitize_key( (string) ( $context['surface'] ?? 'inline_toggle' ) ),
			'event_id'         => absint( $context['event_id'] ?? 0 ),
			'via_continuation' => ! empty( $context['via_continuation'] ),
		);
	}

	return $current ?? array(
		'surface'          => 'inline_toggle',
		'event_id'         => 0,
		'via_continuation' => false,
	);
}

/**
 * Emit `local_scene_digest_subscribed` for a newly created digest subscription.
 *
 * Listens to extrachill-users' generic creation action, which fires only when
 * a row is actually inserted, so re-subscribing never double counts. The
 * payload never carries the city slug or name.
 *
 * @hook extrachill_users_entity_subscription_created
 * @param int   $user_id Subscriber.
 * @param mixed $entity  Normalized entity (entity_type, taxonomy, slug).
 * @return bool Whether an event was emitted.
 */
function extrachill_events_record_digest_subscription( $user_id, $entity ): bool {
	if ( ! is_array( $entity ) || ( $entity['entity_type'] ?? '' ) !== 'local_scene_digest' || ! defined( 'EC_ANALYTICS_EVENT_LOCAL_SCENE_DIGEST_SUBSCRIBED' ) || ! function_exists( 'wp_get_ability' ) ) {
		return false;
	}

	$ability = wp_get_ability( 'extrachill/track-analytics-event' );
	if ( ! $ability ) {
		return false;
	}

	$context    = extrachill_events_digest_subscription_context();
	$event_data = array(
		'user_id'          => absint( $user_id ),
		'surface'          => $context['surface'],
		'via_continuation' => $context['via_continuation'],
	);
	if ( $context['event_id'] > 0 ) {
		$event_data['event_id'] = $context['event_id'];
	}

	$ability->execute(
		array(
			'event_type' => constant( 'EC_ANALYTICS_EVENT_LOCAL_SCENE_DIGEST_SUBSCRIBED' ),
			'event_data' => $event_data,
		)
	);

	return true;
}
add_action( 'extrachill_users_entity_subscription_created', 'extrachill_events_record_digest_subscription', 10, 2 );

/**
 * Whether the current user already receives this city's digest.
 *
 * @param WP_Term $term City term.
 * @return bool|null True/false, or null when status can't be read.
 */
function extrachill_events_event_digest_is_subscribed( WP_Term $term ): ?bool {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/entity-subscription-status' ) : null;
	if ( ! $ability ) {
		return null;
	}

	$result = $ability->execute(
		array(
			'entity_type' => 'local_scene_digest',
			'taxonomy'    => 'location',
			'slug'        => $term->slug,
		)
	);

	return ! is_wp_error( $result ) && is_array( $result ) ? ! empty( $result['subscribed'] ) : null;
}

/**
 * Resolve what the offer should render for the current visitor.
 *
 * @param int $post_id Event post ID.
 * @return array{mode:string,term:WP_Term,scene:array|null,retry?:bool}|null Null hides the offer.
 */
function extrachill_events_event_digest_offer_state( int $post_id ): ?array {
	$term = extrachill_events_get_event_scene_term( $post_id );
	if ( null === $term ) {
		return null;
	}

	if ( ! is_user_logged_in() ) {
		return array(
			'mode'  => 'anonymous',
			'term'  => $term,
			'scene' => null,
		);
	}

	$scene  = extrachill_events_get_account_market();
	$intent = extrachill_events_get_archive_auth_intent( $term );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash set by this module's nonce-protected redirect.
	$status = isset( $_GET['digest_status'] ) && is_scalar( $_GET['digest_status'] ) ? sanitize_key( (string) wp_unslash( $_GET['digest_status'] ) ) : '';

	if ( 'subscribe_digest' === $intent ) {
		return array(
			'mode'  => 'confirm',
			'term'  => $term,
			'scene' => $scene,
		);
	}

	if ( 'subscribed' === $status ) {
		return array(
			'mode'  => 'subscribed',
			'term'  => $term,
			'scene' => $scene,
		);
	}

	if ( $scene && (int) $scene['term_id'] !== (int) $term->term_id ) {
		return null;
	}

	if ( false !== extrachill_events_event_digest_is_subscribed( $term ) ) {
		// Subscribed, or status unreadable: never ask someone who may already be in.
		return null;
	}

	return array(
		'mode'  => $scene ? 'subscribe' : 'set_scene',
		'term'  => $term,
		'scene' => $scene,
		'retry' => 'failed' === $status,
	);
}

/**
 * Render the offer in the Event Details action composition.
 *
 * Priority 27: after the "add a show" link (25), before the network bridge
 * (30), so it reads as the next step after the ticket/attendance actions.
 *
 * @hook data_machine_events_action_buttons
 * @param int    $post_id    Event post ID.
 * @param string $ticket_url Ticket URL (unused).
 * @param string $timing     Event timing (unused; the digest is forward-looking for any event).
 */
function extrachill_events_render_event_digest_offer( $post_id, $ticket_url = '', $timing = '' ): void {
	unset( $ticket_url, $timing );

	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}

	$state = extrachill_events_event_digest_offer_state( $post_id );
	if ( null === $state ) {
		return;
	}

	$term      = $state['term'];
	$mode      = $state['mode'];
	$event_url = (string) get_permalink( $post_id );

	if ( 'anonymous' !== $mode ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	wp_enqueue_script( 'extrachill-events-event-digest-offer', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/event-digest-offer.js', array(), EXTRACHILL_EVENTS_VERSION, true );

	/* translators: %s: City name. */
	$heading = sprintf( __( "Get this week's shows in %s by email", 'extrachill-events' ), $term->name );
	/* translators: %s: City name. */
	$post_mark_heading = sprintf( __( "You're in. Get the rest of this week's shows in %s by email", 'extrachill-events' ), $term->name );
	$notice_class      = 'subscribed' === $mode ? 'notice-success' : 'notice-info';
	?>
	<aside class="notice <?php echo esc_attr( $notice_class ); ?> event-digest-offer" id="ec-event-digest" data-event-digest-offer data-event-digest-mode="<?php echo esc_attr( $mode ); ?>" data-event-digest-city="<?php echo esc_attr( $term->slug ); ?>" data-post-mark-heading="<?php echo esc_attr( $post_mark_heading ); ?>" data-ec-track-placement="event_digest_offer" aria-labelledby="ec-event-digest-heading">
		<p class="event-digest-offer__heading" id="ec-event-digest-heading" data-event-digest-heading><strong><?php echo esc_html( 'subscribed' === $mode ? sprintf( /* translators: %s: City name. */ __( "You're getting this week's shows in %s", 'extrachill-events' ), $term->name ) : $heading ); ?></strong></p>
		<?php if ( 'anonymous' === $mode ) : ?>
			<p><?php echo esc_html( sprintf( /* translators: %s: City name. */ __( 'One email a week with upcoming shows in %s. Free account, unsubscribe any time.', 'extrachill-events' ), $term->name ) ); ?></p>
			<p class="event-digest-offer__actions">
				<a class="button-1 button-small" data-ec-track="event-digest-signin" href="<?php echo esc_url( extrachill_events_archive_intent_login_url( $term, 'subscribe_digest', $event_url ) ); ?>"><?php esc_html_e( 'Sign in to get the email', 'extrachill-events' ); ?></a>
			</p>
		<?php elseif ( 'subscribed' === $mode ) : ?>
			<p><?php esc_html_e( 'Your first weekly email is on its way. You can turn it off from any email.', 'extrachill-events' ); ?></p>
		<?php else : ?>
			<?php if ( 'confirm' === $mode && $state['scene'] && (int) $state['scene']['term_id'] !== (int) $term->term_id ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: 1: Current city, 2: New city. */ __( 'This switches your city from %1$s to %2$s and turns on its weekly email. Nothing changes until you confirm.', 'extrachill-events' ), $state['scene']['name'], $term->name ) ); ?></p>
			<?php elseif ( 'confirm' === $mode ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: City name. */ __( 'You asked for the %s weekly email before signing in. Nothing changes until you confirm.', 'extrachill-events' ), $term->name ) ); ?></p>
			<?php elseif ( ! empty( $state['retry'] ) ) : ?>
				<p><?php esc_html_e( 'We could not turn that on. Nothing was changed. Please try again.', 'extrachill-events' ); ?></p>
			<?php elseif ( 'set_scene' === $mode ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: City name. */ __( 'This also saves %s as your city, so the calendar focuses on it.', 'extrachill-events' ), $term->name ) ); ?></p>
			<?php endif; ?>
			<form class="event-digest-offer__actions" method="post" action="<?php echo esc_url( extrachill_events_archive_intent_clean_url( $term, $event_url ) ); ?>#ec-event-digest">
				<input type="hidden" name="extrachill_events_digest_action" value="subscribe">
				<input type="hidden" name="extrachill_events_digest_nonce" value="<?php echo esc_attr( wp_create_nonce( 'extrachill_events_event_digest_' . $post_id ) ); ?>">
				<input type="hidden" name="extrachill_events_digest_surface" value="event_page" data-event-digest-surface>
				<input type="hidden" name="extrachill_events_digest_continuation" value="<?php echo 'confirm' === $mode ? '1' : '0'; ?>">
				<button class="button-1 button-small" type="submit" data-ec-track="<?php echo esc_attr( 'confirm' === $mode ? 'event-digest-confirm' : 'event-digest-subscribe' ); ?>"<?php echo 'confirm' === $mode ? ' autofocus' : ''; ?>><?php echo 'confirm' === $mode ? esc_html__( 'Confirm: send me the weekly email', 'extrachill-events' ) : esc_html__( 'Email me the weekly shows', 'extrachill-events' ); ?></button>
				<?php if ( 'confirm' !== $mode ) : ?>
					<button class="button-3 button-small" type="button" data-event-digest-dismiss data-ec-track="event-digest-dismiss" hidden><?php esc_html_e( 'Not now', 'extrachill-events' ); ?></button>
				<?php endif; ?>
			</form>
		<?php endif; ?>
	</aside>
	<?php
}
add_action( 'data_machine_events_action_buttons', 'extrachill_events_render_event_digest_offer', 27, 3 );

/**
 * Subscribe the current user to an event's city digest.
 *
 * Sets the Local Scene first when the user has none, or when they explicitly
 * confirmed a signed continuation that names a different city (the confirm
 * copy says so). Otherwise a mismatched scene fails closed: the digest would
 * never deliver to it.
 *
 * @param int    $post_id          Event post ID.
 * @param string $surface          event_page|post_mark.
 * @param bool   $via_continuation Whether this confirms a signed pre-login intent.
 * @return string Outcome: subscribed|failed.
 */
function extrachill_events_subscribe_to_event_digest( int $post_id, string $surface, bool $via_continuation ): string {
	$term = extrachill_events_get_event_scene_term( $post_id );
	if ( null === $term || ! is_user_logged_in() || ! function_exists( 'wp_get_ability' ) ) {
		return 'failed';
	}

	$scene = extrachill_events_get_account_market();
	if ( ! $scene || ( $via_continuation && (int) $scene['term_id'] !== (int) $term->term_id ) ) {
		$settings = wp_get_ability( 'extrachill/update-user-settings' );
		$saved    = $settings ? $settings->execute( array( 'local_scene' => $term->slug ) ) : null;
		if ( ! $settings || is_wp_error( $saved ) ) {
			return 'failed';
		}
	} elseif ( (int) $scene['term_id'] !== (int) $term->term_id ) {
		return 'failed';
	}

	$subscribe = wp_get_ability( 'extrachill/entity-subscribe' );
	if ( ! $subscribe ) {
		return 'failed';
	}

	extrachill_events_digest_subscription_context(
		array(
			'surface'          => in_array( $surface, EXTRACHILL_EVENTS_EVENT_DIGEST_SURFACES, true ) ? $surface : 'event_page',
			'event_id'         => $post_id,
			'via_continuation' => $via_continuation,
		)
	);

	$result = $subscribe->execute(
		array(
			'entity_type' => 'local_scene_digest',
			'taxonomy'    => 'location',
			'slug'        => $term->slug,
		)
	);

	return ! is_wp_error( $result ) && is_array( $result ) && ! empty( $result['subscribed'] ) ? 'subscribed' : 'failed';
}

/**
 * Handle the offer's POST on single event pages, then redirect (PRG).
 *
 * @hook template_redirect
 */
function extrachill_events_handle_event_digest_offer(): void {
	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( 'POST' !== $request_method || ! isset( $_POST['extrachill_events_digest_action'], $_POST['extrachill_events_digest_nonce'] ) || ! is_singular( 'data_machine_events' ) ) {
		return;
	}

	$post_id = (int) get_queried_object_id();
	$nonce   = is_scalar( $_POST['extrachill_events_digest_nonce'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['extrachill_events_digest_nonce'] ) ) : '';
	$status  = 'failed';

	if ( $post_id > 0 && wp_verify_nonce( $nonce, 'extrachill_events_event_digest_' . $post_id ) ) {
		$surface      = isset( $_POST['extrachill_events_digest_surface'] ) && is_scalar( $_POST['extrachill_events_digest_surface'] ) ? sanitize_key( (string) wp_unslash( $_POST['extrachill_events_digest_surface'] ) ) : 'event_page';
		$continuation = isset( $_POST['extrachill_events_digest_continuation'] ) && '1' === $_POST['extrachill_events_digest_continuation'];
		$status       = extrachill_events_subscribe_to_event_digest( $post_id, $surface, $continuation );
	}

	wp_safe_redirect( add_query_arg( 'digest_status', $status, (string) get_permalink( $post_id ) ) . '#ec-event-digest' );
	exit;
}
add_action( 'template_redirect', 'extrachill_events_handle_event_digest_offer', 5 );
