<?php
/**
 * Venue claim and invitation abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueOnboardingRepository;
use ExtraChillEvents\Core\VenueOnboardingService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the venue onboarding command surface. */
class VenueOnboardingAbilities {

	/** Whether registration has already been hooked. */
	private static bool $registered = false;

	/** Venue onboarding policy service. */
	private $service;

	/** Venue authorization service. */
	private $authorization;

	/** Build and hook the onboarding abilities. */
	public function __construct( ?VenueOnboardingService $service = null, ?VenueAuthorization $authorization = null ) {
		$this->service       = $service ? $service : new VenueOnboardingService();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register claim and invitation abilities. */
	public function register(): void {
		$id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$this->register_ability( 'extrachill/submit-venue-claim', __( 'Submit Venue Claim', 'extrachill-events' ), array( 'venue_term_id' => $id ), array( 'venue_term_id' ), array( $this, 'submit_claim' ), array( $this, 'is_authenticated' ), false, true );
		$this->register_ability(
			'extrachill/review-venue-claim',
			__( 'Review Venue Claim', 'extrachill-events' ),
			array(
				'claim_id'         => $id,
				'decision'         => array(
					'type' => 'string',
					'enum' => array( VenueOnboardingRepository::CLAIM_APPROVED, VenueOnboardingRepository::CLAIM_REJECTED ),
				),
				'expected_version' => $id,
			),
			array( 'claim_id', 'decision', 'expected_version' ),
			array( $this, 'review_claim' ),
			array( $this, 'is_administrator' ),
			true,
			true
		);
		$this->register_ability(
			'extrachill/cancel-venue-claim',
			__( 'Cancel Venue Claim', 'extrachill-events' ),
			array(
				'claim_id'         => $id,
				'expected_version' => $id,
			),
			array( 'claim_id', 'expected_version' ),
			array( $this, 'cancel_claim' ),
			array( $this, 'is_authenticated' ),
			true,
			true
		);
		$this->register_ability(
			'extrachill/list-venue-claims',
			__( 'List Venue Claims', 'extrachill-events' ),
			array(
				'status' => array(
					'type' => 'string',
					'enum' => VenueOnboardingRepository::claim_statuses(),
				),
			),
			array(),
			array( $this, 'list_claims' ),
			array( $this, 'is_administrator' ),
			false,
			false,
			true
		);

		$invite_properties = array(
			'venue_term_id' => $id,
			'email'         => array(
				'type'   => 'string',
				'format' => 'email',
			),
			'is_owner'      => array( 'type' => 'boolean' ),
		);
		$this->register_ability( 'extrachill/create-venue-invitation', __( 'Create Venue Invitation', 'extrachill-events' ), $invite_properties, array( 'venue_term_id', 'email', 'is_owner' ), array( $this, 'create_invitation' ), array( $this, 'can_manage_venue' ), false, true );
		$this->register_ability(
			'extrachill/resend-venue-invitation',
			__( 'Resend Venue Invitation', 'extrachill-events' ),
			array(
				'venue_term_id'    => $id,
				'invitation_id'    => $id,
				'expected_version' => $id,
			),
			array( 'venue_term_id', 'invitation_id', 'expected_version' ),
			array( $this, 'resend_invitation' ),
			array( $this, 'can_manage_venue' ),
			false,
			true
		);
		$this->register_ability(
			'extrachill/cancel-venue-invitation',
			__( 'Cancel Venue Invitation', 'extrachill-events' ),
			array(
				'venue_term_id'    => $id,
				'invitation_id'    => $id,
				'expected_version' => $id,
			),
			array( 'venue_term_id', 'invitation_id', 'expected_version' ),
			array( $this, 'cancel_invitation' ),
			array( $this, 'can_manage_venue' ),
			true,
			true
		);
		$this->register_ability( 'extrachill/list-venue-invitations', __( 'List Venue Invitations', 'extrachill-events' ), array( 'venue_term_id' => $id ), array( 'venue_term_id' ), array( $this, 'list_invitations' ), array( $this, 'can_manage_venue' ), false, false, true );
		$this->register_ability(
			'extrachill/respond-to-venue-invitation',
			__( 'Respond To Venue Invitation', 'extrachill-events' ),
			array(
				'invitation_id'    => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'token'            => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
				'venue_term_id'    => $id,
				'is_owner'         => array( 'type' => 'boolean' ),
				'expected_version' => $id,
				'decision'         => array(
					'type' => 'string',
					'enum' => array( VenueOnboardingRepository::INVITE_ACCEPTED, VenueOnboardingRepository::INVITE_REJECTED ),
				),
			),
			array( 'invitation_id', 'token', 'venue_term_id', 'is_owner', 'expected_version', 'decision' ),
			array( $this, 'respond_to_invitation' ),
			array( $this, 'is_authenticated' ),
			true,
			true
		);
	}

	/** Require an authenticated network user. */
	public function is_authenticated() {
		return get_current_user_id() > 0 ? true : new \WP_Error( 'venue_action_forbidden', __( 'Authentication is required.', 'extrachill-events' ), array( 'status' => 401 ) );
	}

	/** Require a network administrator. */
	public function is_administrator() {
		return user_can( get_current_user_id(), 'manage_options' ) ? true : new \WP_Error( 'venue_action_forbidden', __( 'Administrator access is required.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/** Authorize current venue membership management. */
	public function can_manage_venue( array $input ) {
		return $this->authorization->authorize( get_current_user_id(), absint( $input['venue_term_id'] ?? 0 ), VenueAuthorization::ACTION_MANAGE_MEMBERS );
	}

	/** Execute claim submission. */
	public function submit_claim( array $input ) {
		return $this->service->submit_claim( get_current_user_id(), absint( $input['venue_term_id'] ?? 0 ) );
	}

	/** Execute operator claim review. */
	public function review_claim( array $input ) {
		return $this->service->review_claim( get_current_user_id(), absint( $input['claim_id'] ?? 0 ), sanitize_key( $input['decision'] ?? '' ), absint( $input['expected_version'] ?? 0 ) );
	}

	/** Execute claim cancellation. */
	public function cancel_claim( array $input ) {
		return $this->service->cancel_claim( get_current_user_id(), absint( $input['claim_id'] ?? 0 ), absint( $input['expected_version'] ?? 0 ) );
	}

	/** Execute operator claim listing. */
	public function list_claims( array $input ) {
		return $this->service->list_claims( get_current_user_id(), sanitize_key( $input['status'] ?? '' ) );
	}

	/** Execute invitation creation. */
	public function create_invitation( array $input ) {
		return $this->service->invite( get_current_user_id(), absint( $input['venue_term_id'] ?? 0 ), (string) ( $input['email'] ?? '' ), (bool) ( $input['is_owner'] ?? false ) );
	}

	/** Execute invitation resend. */
	public function resend_invitation( array $input ) {
		return $this->service->resend( get_current_user_id(), absint( $input['invitation_id'] ?? 0 ), absint( $input['expected_version'] ?? 0 ) );
	}

	/** Execute invitation cancellation. */
	public function cancel_invitation( array $input ) {
		return $this->service->cancel_invitation( get_current_user_id(), absint( $input['invitation_id'] ?? 0 ), absint( $input['expected_version'] ?? 0 ) );
	}

	/** Execute invitation listing. */
	public function list_invitations( array $input ) {
		return $this->service->list_invitations( get_current_user_id(), absint( $input['venue_term_id'] ?? 0 ) );
	}

	/** Execute an invitation response. */
	public function respond_to_invitation( array $input ) {
		return $this->service->respond( get_current_user_id(), sanitize_text_field( $input['invitation_id'] ?? '' ), sanitize_text_field( $input['token'] ?? '' ), absint( $input['venue_term_id'] ?? 0 ), (bool) ( $input['is_owner'] ?? false ), absint( $input['expected_version'] ?? 0 ), sanitize_key( $input['decision'] ?? '' ) );
	}

	/** Register one onboarding ability contract. */
	private function register_ability( string $name, string $label, array $properties, array $required, callable $execute, callable $permission, bool $destructive, bool $idempotent, bool $is_readonly = false ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $label,
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => array( 'object', 'array' ) ),
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => $is_readonly,
						'idempotent'  => $idempotent,
						'destructive' => $destructive,
					),
				),
			)
		);
	}
}
