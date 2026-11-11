<?php
/**
 * Bounded team invitation delivery worker.
 *
 * @package ExtraChillUsers\Teams
 */

namespace ExtraChill\Users\Teams;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Composes bearer credentials only inside a fixed Action Scheduler callback. */
class TeamInvitationDeliveryWorker {

	public const HOOK  = 'extrachill_users_deliver_team_invitation';
	public const GROUP = 'extrachill-users-team-invitations';

	/** Onboarding persistence for one team type. */
	protected $repository;

	/** Team-type adapter. */
	protected $adapter;

	public function __construct( TeamOnboardingAdapter $adapter, ?TeamOnboardingRepository $repository = null ) {
		$this->adapter    = $adapter;
		$this->repository = $repository ? $repository : new TeamOnboardingRepository( $adapter );
	}

	/** Register the fixed worker callback. */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ), 10, 1 );
	}

	/**
	 * Action Scheduler callback receiving only an opaque stored delivery ID.
	 *
	 * Delivery IDs are scoped by team type, so each registered adapter is tried
	 * until one owns the delivery.
	 *
	 * @throws \RuntimeException When delivery fails and Action Scheduler should retry.
	 */
	public static function run( string $delivery_id ): void {
		$result = new \WP_Error( 'team_invitation_delivery_not_found', 'No team owns this invitation delivery.' );
		foreach ( TeamOnboardingAdapters::all() as $adapter ) {
			$result = ( new self( $adapter ) )->deliver( $delivery_id );
			if ( ! is_wp_error( $result ) || $adapter->error_code( 'team_invitation_delivery_not_found' ) !== $result->get_error_code() ) {
				break;
			}
		}
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'Team invitation delivery failed.' );
		}
	}

	/** Mint runtime credentials, synchronously deliver, and persist the outcome. */
	public function deliver( string $delivery_id ) {
		$invitation = $this->repository->prepare_delivery( $delivery_id, TeamOnboardingService::INVITATION_TTL );
		if ( is_wp_error( $invitation ) ) {
			return $invitation;
		}

		$user  = get_userdata( $invitation['user_id'] );
		$email = $user instanceof \WP_User ? strtolower( sanitize_email( $user->user_email ) ) : '';
		$copy  = $user ? $this->adapter->invitation_email( $invitation, (string) $invitation['_delivery_token'] ) : null;
		if ( ! $user || ! is_array( $copy ) || '' === $email || ! hash_equals( $invitation['_email_hash'], hash_hmac( 'sha256', $email, wp_salt( 'auth' ) ) ) ) {
			$this->repository->finish_delivery( $delivery_id, false );
			return new \WP_Error( $this->adapter->error_code( 'team_invitation_delivery_identity_changed' ), __( 'The invitation delivery identity changed.', 'extrachill-users' ), array( 'status' => 409 ) );
		}

		$claim_url = '';
		if ( '1' === (string) get_user_meta( $user->ID, 'ec_unclaimed', true ) ) {
			$claim_url = function_exists( 'ec_users_build_account_claim_url' ) ? (string) ec_users_build_account_claim_url( $user ) : '';
			if ( '' === $claim_url ) {
				$this->repository->finish_delivery( $delivery_id, false );
				return new \WP_Error( $this->adapter->error_code( 'team_invitation_reset_key_failed' ), __( 'The invited account handoff could not be created.', 'extrachill-users' ), array( 'status' => 500 ) );
			}
		}

		$accept_url = (string) $copy['accept_url'];
		$cta_url    = $claim_url ? $claim_url : $accept_url;
		$body       = '<p>' . esc_html( (string) $copy['intro'] ) . '</p>';
		if ( $claim_url ) {
			$body .= '<p>' . esc_html__( 'Set your account password first, then return to the invitation link below to accept.', 'extrachill-users' ) . '</p>';
			$body .= '<p><a href="' . esc_url( $accept_url ) . '">' . esc_html( (string) $copy['accept_label'] ) . '</a></p>';
		}
		$body .= '<p>' . esc_html__( 'Powered by Extra Chill.', 'extrachill-users' ) . '</p>';

		if ( ! function_exists( 'ec_send_email' ) ) {
			$this->repository->finish_delivery( $delivery_id, false );
			return new \WP_Error( $this->adapter->error_code( 'team_invitation_delivery_unavailable' ), __( 'Synchronous invitation delivery is unavailable.', 'extrachill-users' ), array( 'status' => 503 ) );
		}
		$result = ec_send_email(
			array(
				'to'        => $user->user_email,
				'cc'        => '',
				'subject'   => (string) $copy['subject'],
				'from_name' => (string) $copy['from_name'],
				'template'  => 'extrachill/branded',
				'context'   => array(
					'recipient_name' => $user->display_name,
					'body_html'      => $body,
					'cta_url'        => $cta_url,
					'cta_label'      => $claim_url ? __( 'Set Your Password', 'extrachill-users' ) : __( 'Accept Invitation', 'extrachill-users' ),
				),
			)
		);
		$sent   = ! empty( $result['success'] );
		$saved  = $this->repository->finish_delivery( $delivery_id, $sent );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return $sent ? $this->repository->public_invitation( $saved ) : new \WP_Error( $this->adapter->error_code( 'team_invitation_delivery_failed' ), __( 'The invitation email could not be sent.', 'extrachill-users' ), array( 'status' => 503 ) );
	}
}
