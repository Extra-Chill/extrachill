<?php
/**
 * Scoped venue email-sharing consent.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_EVENTS_VENUE_EMAIL_SHARING_PRODUCER = 'extrachill-events-venue-email-sharing';

/** Register the feature-owned identity, archive control, and private producer. */
function extrachill_events_init_venue_email_sharing(): void {
	add_filter( 'extrachill_users_entity_subscription_entities', 'extrachill_events_register_venue_email_sharing_identity' );
	add_filter( 'extrachill_users_entity_subscription_producer_authorized', 'extrachill_events_authorize_venue_email_sharing_producer', 10, 4 );
	add_action( 'extrachill_archive_below_description', 'extrachill_events_render_venue_email_sharing_control', 6 );
	add_action( 'wp_enqueue_scripts', 'extrachill_events_venue_email_sharing_scripts' );
}

/**
 * Register the venue email-sharing purpose independently of venue updates.
 *
 * @param array $entities Entity identity definitions.
 * @return array
 */
function extrachill_events_register_venue_email_sharing_identity( array $entities ): array {
	$entities['venue-email-sharing'] = array(
		'taxonomy'                           => 'venue',
		'uses_notification_email_preference' => false,
	);

	return $entities;
}

/**
 * Authorize only the exact private venue email-sharing resolution contract.
 *
 * @param bool   $authorized Existing authorization decision.
 * @param string $producer   Requesting producer.
 * @param array  $entity     Normalized entity identity.
 * @param string $delivery   Delivery channel.
 * @return bool
 */
function extrachill_events_authorize_venue_email_sharing_producer( $authorized, $producer, $entity, $delivery ): bool {
	if ( EXTRACHILL_EVENTS_VENUE_EMAIL_SHARING_PRODUCER !== $producer ) {
		return (bool) $authorized;
	}

	return 'email' === $delivery
		&& is_array( $entity )
		&& 'venue-email-sharing' === ( $entity['entity_type'] ?? '' )
		&& 'venue' === ( $entity['taxonomy'] ?? '' );
}

/** Render a separate explicit email-sharing choice on canonical venue archives. */
function extrachill_events_render_venue_email_sharing_control(): void {
	$term = function_exists( 'extrachill_events_get_venue_archive_term' ) ? extrachill_events_get_venue_archive_term() : null;
	if ( null === $term ) {
		return;
	}

	$archive_url = get_term_link( $term );
	if ( ! is_user_logged_in() ) {
		echo '<aside class="events-market-context events-market-context--quiet"><span>' . esc_html__( 'Share your account email with this venue for its own email list.', 'extrachill-events' ) . '</span> <a href="' . esc_url( wp_login_url( $archive_url ) ) . '">' . esc_html__( 'Sign in to share your email', 'extrachill-events' ) . '</a></aside>';
		return;
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	?>
	<aside class="events-market-context" data-venue-email-sharing-control>
		<div class="events-market-context__copy">
			<strong><?php esc_html_e( 'Venue email list', 'extrachill-events' ); ?></strong>
			<span data-venue-email-sharing-status><?php esc_html_e( 'Checking your email-sharing choice...', 'extrachill-events' ); ?></span>
		</div>
		<button class="button-1 button-small" type="button" disabled aria-pressed="false" data-venue-email-sharing data-endpoint="<?php echo esc_url( rest_url( 'wp-abilities/v1/abilities/' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-slug="<?php echo esc_attr( $term->slug ); ?>"><?php esc_html_e( 'Share email with venue', 'extrachill-events' ); ?></button>
	</aside>
	<?php
}

/** Enqueue the progressive control only for authenticated venue archives. */
function extrachill_events_venue_email_sharing_scripts(): void {
	if ( ! function_exists( 'extrachill_events_get_venue_archive_term' ) || null === extrachill_events_get_venue_archive_term() || ! is_user_logged_in() ) {
		return;
	}

	wp_enqueue_script( 'extrachill-events-venue-email-sharing', EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/js/venue-email-sharing.js', array(), EXTRACHILL_EVENTS_VERSION, true );
}
