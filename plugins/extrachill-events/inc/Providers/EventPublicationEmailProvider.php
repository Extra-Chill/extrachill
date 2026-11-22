<?php
/**
 * Branded email for published public event submissions.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Customizes Data Machine Events' pending-submission publication email. */
final class EventPublicationEmailProvider {

	/** Register the sibling plugin's notification filters once. */
	public static function register(): void {
		add_filter( 'data_machine_events_submission_notification_template', array( self::class, 'template' ), 10, 2 );
		add_filter( 'data_machine_events_submission_notification_context', array( self::class, 'context' ), 10, 4 );
		add_action( 'transition_post_status', array( self::class, 'on_direct_publish' ), 10, 3 );
	}

	/**
	 * Send the publish email when a trusted submission is created already published.
	 *
	 * Data Machine Events only emails on pending -> publish. Trusted submitters
	 * (#953) skip the queue, so their event goes straight to publish and that hook
	 * never fires. Pending -> publish is left to Data Machine Events so the email
	 * is never sent twice; publish -> publish updates are ignored.
	 */
	public static function on_direct_publish( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || in_array( $old_status, array( 'publish', 'pending' ), true ) ) {
			return;
		}
		$post_type = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
		if ( $post_type !== $post->post_type || ! self::has_submitter( $post ) ) {
			return;
		}
		if ( ! class_exists( '\\DataMachineEvents\\Core\\SubmissionNotification' ) ) {
			return;
		}
		\DataMachineEvents\Core\SubmissionNotification::on_status_transition( 'publish', 'pending', $post );
	}

	/** Select the network-branded mail template for events with submitter data. */
	public static function template( string $template_id, \WP_Post $post ): string {
		return self::has_submitter( $post ) ? 'extrachill/branded' : $template_id;
	}

	/** Build the branded event-live email context without touching unrelated posts. */
	public static function context( array $context, \WP_Post $post, string $submitter_email, string $template_id ): array {
		unset( $template_id );
		if ( ! self::has_submitter( $post ) ) {
			return $context;
		}

		$title     = get_the_title( $post );
		$event_url = get_permalink( $post );
		$name      = (string) get_post_meta( $post->ID, '_datamachine_submitter_name', true );
		$user_id   = (int) get_post_meta( $post->ID, '_datamachine_submitted_by', true );
		$subject   = sprintf(
			/* translators: %s: event title. */
			__( 'Your event is live on Extra Chill: %s', 'extrachill-events' ),
			$title
		);
		$preheader = sprintf(
			/* translators: %s: event title. */
			__( 'Your event is live. Share it with your audience: %s', 'extrachill-events' ),
			$title
		);

		$body  = '<p>' . esc_html__( 'Your event is live!', 'extrachill-events' ) . '</p>';
		$body .= '<p><a href="' . esc_url( $event_url ) . '">' . esc_html( $title ) . '</a></p>';
		$body .= '<p>' . esc_html__( 'Share your event with your fans and audience so they can make plans to be there.', 'extrachill-events' ) . '</p>';
		$body .= '<p>' . esc_html__( 'What you can do next:', 'extrachill-events' ) . '</p><ul>';
		$body .= self::list_link( self::site_url( 'artist' ), __( 'Create or claim your artist profile', 'extrachill-events' ) );
		$body .= self::list_link( self::site_url( 'events' ), __( 'Connect your venue’s public calendar to import future shows automatically', 'extrachill-events' ) );
		$body .= self::list_link( self::site_url( 'events' ), __( 'Submit your next show', 'extrachill-events' ) );
		$body .= self::ticket_item( $post->ID, $user_id );
		$body .= self::list_link( self::site_url( 'community' ), __( 'Join the Extra Chill community', 'extrachill-events' ) );
		$body .= '</ul>';

		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( $user && '1' === (string) get_user_meta( $user_id, 'ec_unclaimed', true ) ) {
			$claim_url = self::build_claim_url( $user );
			if ( $claim_url ) {
				$body .= '<hr><p>' . esc_html__( 'Your submission is credited to your Extra Chill account, so you get the credit when it goes live.', 'extrachill-events' ) . '</p>';
				$body .= '<p>' . sprintf(
					/* translators: %s: password setup link. */
					esc_html__( 'Set or reset your password to log in and manage your events: %s', 'extrachill-events' ),
					'<a href="' . esc_url( $claim_url ) . '">' . esc_html__( 'Set my password', 'extrachill-events' ) . '</a>'
				) . '</p>';
			}
		}

		return array_merge(
			$context,
			array(
				'subject_html'   => esc_html( $subject ),
				'preheader'      => $preheader,
				'recipient_name' => $name,
				'body_html'      => $body,
			)
		);
	}

	/** Offer ticketing only when it does not replace an existing paid destination. */
	private static function ticket_item( int $event_id, int $user_id ): string {
		$offer      = get_post_meta( $event_id, '_extrachill_ticket_offer', true );
		$ticket_url = (string) get_post_meta( $event_id, '_datamachine_ticket_url', true );
		if ( ( is_array( $offer ) && ! empty( $offer['tickets_enabled'] ) )
			|| ( function_exists( 'data_machine_events_is_gated_ticket_url' ) && data_machine_events_is_gated_ticket_url( $ticket_url ) ) ) {
			return '';
		}

		$url = rtrim( self::site_url( 'docs' ), '/' ) . '/selling-event-tickets/';
		if ( $user_id > 0 && function_exists( 'extrachill_events_user_can_manage_event' )
			&& function_exists( 'extrachill_events_guest_list_host_tools_url' )
			&& extrachill_events_user_can_manage_event( $user_id, $event_id ) ) {
			$url = extrachill_events_guest_list_host_tools_url( $event_id, 'ec-guest-list-management' );
		}
		$url = add_query_arg(
			array(
				'utm_source'   => 'extrachill_events',
				'utm_medium'   => 'email',
				'utm_campaign' => 'submission_email_tickets',
				'placement'    => 'submission_email_tickets',
			),
			$url
		);

		$item = self::list_link( $url, __( 'Sell tickets for this show on Extra Chill', 'extrachill-events' ) );
		$fee  = self::ticket_fee_copy( $event_id );
		return '' === $fee ? $item : str_replace( '</li>', ' — ' . esc_html( $fee ) . '</li>', $item );
	}

	/** Only describe the simple per-ticket schedule when the settings support it. */
	private static function ticket_fee_copy( int $event_id ): string {
		if ( ! function_exists( 'ec_commerce_fee_settings' ) ) {
			return '';
		}
		$fees = ec_commerce_fee_settings( array( 'kind' => 'extrachill_ticket', 'event_id' => $event_id ) );
		if ( ! isset( $fees['ticket_percent'], $fees['ticket_buyer_fee_per_ticket_minor'], $fees['ticket_buyer_fee_flat_minor'], $fees['ticket_buyer_fee_percent'] )
			|| 0.0 !== (float) $fees['ticket_percent'] || 0.0 !== (float) $fees['ticket_buyer_fee_flat_minor']
			|| 0.0 !== (float) $fees['ticket_buyer_fee_percent'] ) {
			return '';
		}
		return sprintf(
			/* translators: %s: service fee in US dollars per ticket. */
			__( 'No platform fee for you, buyers pay a $%s service fee per ticket', 'extrachill-events' ),
			rtrim( rtrim( number_format( (float) $fees['ticket_buyer_fee_per_ticket_minor'] / 100, 2, '.', '' ), '0' ), '.' )
		);
	}

	private static function has_submitter( \WP_Post $post ): bool {
		return (int) get_post_meta( $post->ID, '_datamachine_submitted_by', true ) > 0
			|| '' !== (string) get_post_meta( $post->ID, '_datamachine_submitter_email', true );
	}

	private static function site_url( string $site ): string {
		return (string) ec_get_site_url( $site );
	}

	private static function list_link( string $url, string $label ): string {
		return '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
	}

	/** Account claim link, owned by extrachill-users (extrachill-users#451). */
	private static function build_claim_url( \WP_User $user ): string {
		return function_exists( 'ec_users_build_account_claim_url' ) ? (string) ec_users_build_account_claim_url( $user ) : '';
	}
}
