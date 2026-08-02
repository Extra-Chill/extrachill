<?php
/**
 * Hidden event vendor request abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\VendorRequestService;
use function add_action;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers public-admission and private-management domain contracts. */
class VendorRequestAbilities {

	/** @var VendorRequestService */
	private $service;

	public function __construct( ?VendorRequestService $service = null ) {
		$this->service = $service ? $service : new VendorRequestService();
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		$this->register_ability( 'open-vendor-request', __( 'Open Vendor Request', 'extrachill-events' ), $this->open_schema(), array( $this, 'open_request' ), true );
		$this->register_ability( 'set-vendor-request-open', __( 'Set Vendor Request Open', 'extrachill-events' ), $this->request_status_schema(), array( $this, 'set_request_open' ), true );
		$this->register_ability( 'get-public-vendor-request', __( 'Get Public Vendor Request', 'extrachill-events' ), $this->object_schema( array( 'event_id' => $this->integer() ), array( 'event_id' ) ), array( $this, 'get_public_request' ), false, true );
		$this->register_ability( 'apply-to-vendor-request', __( 'Apply to Vendor Request', 'extrachill-events' ), $this->application_schema(), array( $this, 'apply' ), false );
		$this->register_ability( 'list-vendor-applications', __( 'List Vendor Applications', 'extrachill-events' ), $this->object_schema( array( 'request_id' => $this->integer() ), array( 'request_id' ) ), array( $this, 'list_applications' ), true );
		$this->register_ability( 'review-vendor-application', __( 'Review Vendor Application', 'extrachill-events' ), $this->review_schema(), array( $this, 'review_application' ), true );
		$this->register_ability( 'contact-vendor-applicant', __( 'Contact Vendor Applicant', 'extrachill-events' ), $this->contact_schema(), array( $this, 'contact_applicant' ), true );
		$this->register_ability( 'withdraw-vendor-application', __( 'Withdraw Vendor Application', 'extrachill-events' ), $this->withdraw_schema(), array( $this, 'withdraw' ), false );
	}

	public function open_request( array $input ) {
		return $this->service->open_request( (int) $input['event_id'], (array) ( $input['policy'] ?? array() ), (string) $input['idempotency_key'], get_current_user_id() );
	}

	public function set_request_open( array $input ) {
		return $this->service->set_request_open( (int) $input['request_id'], (bool) $input['open'], (int) $input['expected_version'], (string) $input['idempotency_key'], get_current_user_id() );
	}

	public function get_public_request( array $input ) {
		return $this->service->public_request_for_event( (int) $input['event_id'] );
	}

	public function apply( array $input ) {
		return $this->service->apply( $input, get_current_user_id() );
	}

	public function list_applications( array $input ) {
		return $this->service->list_applications( (int) $input['request_id'], get_current_user_id() );
	}

	public function review_application( array $input ) {
		return $this->service->review_application( (int) $input['application_id'], (string) $input['status'], (string) ( $input['notes'] ?? '' ), (int) $input['expected_version'], (string) $input['idempotency_key'], get_current_user_id() );
	}

	public function contact_applicant( array $input ) {
		return $this->service->contact_applicant( (int) $input['application_id'], (string) $input['subject'], (string) $input['message'], (string) $input['idempotency_key'], get_current_user_id() );
	}

	public function withdraw( array $input ) {
		return $this->service->withdraw( (string) $input['public_id'], (string) $input['access_token'], (int) $input['expected_version'], (string) $input['idempotency_key'] );
	}

	public function authenticated(): bool {
		return get_current_user_id() > 0;
	}

	private function register_ability( string $slug, string $label, array $input, callable $callback, bool $authenticated, bool $read_only = false ): void {
		wp_register_ability(
			'extrachill-events/' . $slug,
			array(
				'label'               => $label,
				'description'         => __( 'Event-scoped vendor request operation.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $input,
				'output_schema'       => array(
					'type'                 => array( 'object', 'null' ),
					'additionalProperties' => true,
				),
				'execute_callback'    => $callback,
				'permission_callback' => $authenticated ? array( $this, 'authenticated' ) : '__return_true',
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => $read_only,
						'idempotent'  => true,
						'destructive' => ! $read_only,
					),
				),
			)
		);
	}

	private function open_schema(): array {
		return $this->object_schema(
			array(
				'event_id'        => $this->integer(),
				'policy'          => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'idempotency_key' => $this->key_schema(),
			),
			array( 'event_id', 'idempotency_key' )
		);
	}

	private function request_status_schema(): array {
		return $this->object_schema(
			array(
				'request_id'       => $this->integer(),
				'open'             => array( 'type' => 'boolean' ),
				'expected_version' => $this->integer(),
				'idempotency_key'  => $this->key_schema(),
			),
			array( 'request_id', 'open', 'expected_version', 'idempotency_key' )
		);
	}

	private function application_schema(): array {
		$nullable = array(
			'type'      => array( 'string', 'null' ),
			'maxLength' => 2000,
		);
		return $this->object_schema(
			array(
				'event_id'        => $this->integer(),
				'idempotency_key' => $this->key_schema(),
				'business_name'   => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'contact_name'    => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'contact_email'   => array(
					'type'      => 'string',
					'format'    => 'email',
					'maxLength' => 255,
				),
				'contact_phone'   => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 64,
				),
				'category'        => array(
					'type'      => 'string',
					'maxLength' => 191,
				),
				'website_url'     => array(
					'type'      => array( 'string', 'null' ),
					'format'    => 'uri',
					'maxLength' => 2000,
				),
				'footprint'       => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'power_needs'     => $nullable,
				'insurance_notes' => $nullable,
				'message'         => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				'contact_consent' => array( 'type' => 'boolean' ),
			),
			array( 'event_id', 'idempotency_key', 'business_name', 'contact_name', 'contact_email', 'category', 'footprint', 'message', 'contact_consent' )
		);
	}

	private function review_schema(): array {
		return $this->object_schema(
			array(
				'application_id'   => $this->integer(),
				'status'           => array(
					'type' => 'string',
					'enum' => VendorRequestService::APPLICATION_STATUSES,
				),
				'notes'            => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				'expected_version' => $this->integer(),
				'idempotency_key'  => $this->key_schema(),
			),
			array( 'application_id', 'status', 'expected_version', 'idempotency_key' )
		);
	}

	private function contact_schema(): array {
		return $this->object_schema(
			array(
				'application_id'  => $this->integer(),
				'subject'         => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'message'         => array(
					'type'      => 'string',
					'maxLength' => 10000,
				),
				'idempotency_key' => $this->key_schema(),
			),
			array( 'application_id', 'subject', 'message', 'idempotency_key' )
		);
	}

	private function withdraw_schema(): array {
		return $this->object_schema(
			array(
				'public_id'        => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'access_token'     => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
				'expected_version' => $this->integer(),
				'idempotency_key'  => $this->key_schema(),
			),
			array( 'public_id', 'access_token', 'expected_version', 'idempotency_key' )
		);
	}

	private function object_schema( array $properties, array $required ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	private function integer(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 1,
		);
	}

	private function key_schema(): array {
		return array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 191,
		);
	}
}
