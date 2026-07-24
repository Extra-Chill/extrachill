<?php
/**
 * Bounded venue invitation delivery worker.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Composes bearer credentials only inside a fixed Action Scheduler callback. */
class VenueInvitationDeliveryWorker {

	public const HOOK  = 'extrachill_events_deliver_venue_invitation';
	public const GROUP = 'extrachill-events-venue-invitations';

	/**
	 * Venue onboarding persistence.
	 *
	 * @var VenueOnboardingRepository
	 */
	private $repository;

	public function __construct( ?VenueOnboardingRepository $repository = null ) {
		$this->repository = $repository ? $repository : new VenueOnboardingRepository();
	}

	/** Register the fixed worker callback. */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ), 10, 1 );
	}

	/**
	 * Action Scheduler callback receiving only an opaque stored delivery ID.
	 *
	 * @throws \RuntimeException When delivery fails and Action Scheduler should retry.
	 */
	public static function run( string $delivery_id ): void {
		$result = ( new self() )->deliver( $delivery_id );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'Venue invitation delivery failed.' );
		}
	}

	/** Mint runtime credentials, synchronously deliver, and persist the outcome. */
	public function deliver( string $delivery_id ) {
		$invitation = $this->repository->prepare_delivery( $delivery_id, VenueOnboardingService::INVITATION_TTL );
		if ( is_wp_error( $invitation ) ) {
			return $invitation;
		}

		$user  = get_userdata( $invitation['user_id'] );
		$venue = get_term( $invitation['venue_term_id'], 'venue' );
		$email = $user instanceof \WP_User ? strtolower( sanitize_email( $user->user_email ) ) : '';
		if ( ! $user || ! $venue || is_wp_error( $venue ) || '' === $email || ! hash_equals( $invitation['_email_hash'], hash_hmac( 'sha256', $email, wp_salt( 'auth' ) ) ) ) {
			$this->repository->finish_delivery( $delivery_id, false );
			return new \WP_Error( 'venue_invitation_delivery_identity_changed', __( 'The invitation delivery identity changed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		$claim_url = '';
		if ( '1' === (string) get_user_meta( $user->ID, 'ec_unclaimed', true ) ) {
			$key = get_password_reset_key( $user );
			if ( is_wp_error( $key ) ) {
				$this->repository->finish_delivery( $delivery_id, false );
				return new \WP_Error( 'venue_invitation_reset_key_failed', __( 'The invited account handoff could not be created.', 'extrachill-events' ), array( 'status' => 500 ) );
			}
			$base      = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : network_site_url();
			$claim_url = trailingslashit( $base ) . 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login );
		}

		$accept_url = add_query_arg(
			array(
				'action'        => 'ec_accept_venue_invitation',
				'invitation_id' => $invitation['public_id'],
				'token'         => $invitation['_delivery_token'],
				'venue_term_id' => $invitation['venue_term_id'],
				'is_owner'      => $invitation['is_owner'] ? 1 : 0,
				'version'       => $invitation['version'],
			),
			admin_url( 'admin-post.php' )
		);
		$cta_url    = $claim_url ? $claim_url : $accept_url;
		/* translators: %s: Venue name. */
		$body = '<p>' . esc_html( sprintf( __( 'You have been invited to help manage %s on Extra Chill.', 'extrachill-events' ), $venue->name ) ) . '</p>';
		if ( $claim_url ) {
			$body .= '<p>' . esc_html__( 'Set your account password first, then return to the invitation link below to accept.', 'extrachill-events' ) . '</p>';
			$body .= '<p><a href="' . esc_url( $accept_url ) . '">' . esc_html__( 'Accept venue invitation', 'extrachill-events' ) . '</a></p>';
		}
		$body .= '<p>' . esc_html__( 'This message was sent by Extra Chill Bot acting on Chris Huber\'s behalf.', 'extrachill-events' ) . '</p>';

		if ( ! function_exists( 'ec_send_email' ) ) {
			$this->repository->finish_delivery( $delivery_id, false );
			return new \WP_Error( 'venue_invitation_delivery_unavailable', __( 'Synchronous invitation delivery is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$result = ec_send_email(
			array(
				'to'        => $user->user_email,
				'cc'        => 'chubes@extrachill.com',
				/* translators: %s: Venue name. */
				'subject'   => sprintf( __( 'Invitation to manage %s', 'extrachill-events' ), $venue->name ),
				'from_name' => 'Extra Chill Bot',
				'template'  => 'extrachill/branded',
				'context'   => array(
					'recipient_name' => $user->display_name,
					'body_html'      => $body,
					'cta_url'        => $cta_url,
					'cta_label'      => $claim_url ? __( 'Set Your Password', 'extrachill-events' ) : __( 'Accept Invitation', 'extrachill-events' ),
				),
			)
		);
		$sent   = is_array( $result ) && ! empty( $result['success'] );
		$saved  = $this->repository->finish_delivery( $delivery_id, $sent );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return $sent ? $this->repository->public_invitation( $saved ) : new \WP_Error( 'venue_invitation_delivery_failed', __( 'The invitation email could not be sent.', 'extrachill-events' ), array( 'status' => 503 ) );
	}
}
