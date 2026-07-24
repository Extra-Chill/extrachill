<?php
/**
 * Authorized booking attachment abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\BookingAttachmentPolicy;
use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingAttachmentService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\VenueAuthorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Exposes no public admission route and re-resolves booking venue scope on every call. */
class BookingAttachmentAbilities {

	/** Attachment repository.
	 *
	 * @var BookingAttachmentRepository
	 */
	private $attachments;
	/** Booking repository.
	 *
	 * @var BookingRepository
	 */
	private $bookings;
	/** Attachment service.
	 *
	 * @var BookingAttachmentService
	 */
	private $service;
	/** Venue authorization policy.
	 *
	 * @var VenueAuthorization
	 */
	private $authorization;

	/**
	 * Build and hook the private attachment ability surface.
	 *
	 * @param BookingAttachmentRepository|null $attachments   Attachment repository.
	 * @param BookingRepository|null           $bookings      Booking repository.
	 * @param BookingAttachmentService|null    $service       Attachment service.
	 * @param VenueAuthorization|null          $authorization Venue policy.
	 */
	public function __construct( ?BookingAttachmentRepository $attachments = null, ?BookingRepository $bookings = null, ?BookingAttachmentService $service = null, ?VenueAuthorization $authorization = null ) {
		$this->attachments   = $attachments ? $attachments : new BookingAttachmentRepository();
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->service       = $service ? $service : new BookingAttachmentService( $this->attachments, $this->bookings, null, null, null, $this->authorization );
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/** Register authorized attachment contracts. */
	public function register(): void {
		$this->register_ability( 'list-booking-attachments', true, true, array( $this, 'list_attachments' ), $this->booking_input() );
		$this->register_ability( 'attach-booking-file', false, true, array( $this, 'attach' ), $this->attach_input() );
		$this->register_ability( 'replace-booking-attachment', false, false, array( $this, 'replace' ), $this->attach_input( true ) );
		$this->register_ability( 'delete-booking-attachment', false, true, array( $this, 'delete' ), $this->attachment_input() );
		$this->register_ability( 'download-booking-attachment', false, false, array( $this, 'download' ), $this->attachment_input() );
	}

	/**
	 * Register one attachment ability.
	 *
	 * @param string   $slug         Ability slug.
	 * @param bool     $show_in_rest REST exposure flag.
	 * @param bool     $idempotent   Idempotency annotation.
	 * @param callable $callback     Execute callback.
	 * @param array    $input        Input schema.
	 */
	private function register_ability( string $slug, bool $show_in_rest, bool $idempotent, callable $callback, array $input ): void {
		wp_register_ability(
			'extrachill/' . $slug,
			array(
				'label'               => ucwords( str_replace( '-', ' ', $slug ) ),
				'description'         => __( 'Manage one private booking attachment within an authorized venue scope.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $input,
				'output_schema'       => 'list-booking-attachments' === $slug
					? array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					)
					: array( 'type' => 'object' ),
				'execute_callback'    => $callback,
				'permission_callback' => array( $this, 'can_access_booking' ),
				'meta'                => array(
					'show_in_rest' => $show_in_rest,
					'annotations'  => array(
						'readonly'    => 'list-booking-attachments' === $slug,
						'idempotent'  => $idempotent,
						'destructive' => in_array( $slug, array( 'replace-booking-attachment', 'delete-booking-attachment' ), true ),
					),
				),
			)
		);
	}

	/**
	 * List sanitized attachment metadata for a booking.
	 *
	 * @param array $input Ability input.
	 */
	public function list_attachments( array $input ) {
		$result = $this->attachments->list_for_booking( (int) $input['booking_id'] );
		return is_array( $result ) ? array_map( array( $this, 'present' ), $result ) : $result;
	}

	/**
	 * Attach an object as the authenticated operator.
	 *
	 * @param array $input Ability input.
	 */
	public function attach( array $input ) {
		$input['uploader_type']    = 'user';
		$input['uploader_user_id'] = get_current_user_id();
		unset( $input['uploader_reference'] );
		$result = $this->service->attach( $input );
		return is_array( $result ) ? $this->present( $result ) : $result;
	}

	/**
	 * Replace an object as the authenticated operator.
	 *
	 * @param array $input Ability input.
	 */
	public function replace( array $input ) {
		$input['uploader_type']    = 'user';
		$input['uploader_user_id'] = get_current_user_id();
		unset( $input['uploader_reference'] );
		$result = $this->service->replace( $input );
		return is_array( $result ) ? $this->present( $result ) : $result;
	}

	/**
	 * Logically delete an attachment.
	 *
	 * @param array $input Ability input.
	 */
	public function delete( array $input ) {
		$result = $this->service->delete( (int) $input['booking_id'], (int) $input['attachment_id'], get_current_user_id() );
		return is_array( $result ) ? $this->present( $result ) : $result;
	}

	/**
	 * Issue a secure stream handoff.
	 *
	 * @param array $input Ability input.
	 */
	public function download( array $input ) {
		return $this->service->download_descriptor( (int) $input['booking_id'], (int) $input['attachment_id'], get_current_user_id() );
	}

	/**
	 * Resolve the booking internally, then authorize its stored venue.
	 *
	 * @param array $input Ability input.
	 */
	public function can_access_booking( array $input ) {
		$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
		if ( ! is_array( $booking ) ) {
			return new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		return $this->authorization->authorize( get_current_user_id(), $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
	}

	/**
	 * Remove opaque references, hashes, idempotency material, and inbound message IDs.
	 *
	 * @param array $attachment Stored attachment.
	 */
	public function present( array $attachment ): array {
		unset( $attachment['storage_reference'], $attachment['content_hash'], $attachment['idempotency_key'], $attachment['request_hash'], $attachment['uploader_reference'] );
		return $attachment;
	}

	/** Return booking-only input. */
	private function booking_input(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'booking_id' ),
			'additionalProperties' => false,
		);
	}

	/** Return booking and attachment input. */
	private function attachment_input(): array {
		$schema                                = $this->booking_input();
		$schema['properties']['attachment_id'] = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$schema['required'][]                  = 'attachment_id';
		return $schema;
	}

	/**
	 * Return private object attachment input.
	 *
	 * @param bool $replacement Whether attachment ID is required.
	 */
	private function attach_input( bool $replacement = false ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'storage_reference' => array(
					'type'      => 'string',
					'minLength' => 24,
					'maxLength' => 191,
				),
				'idempotency_key'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 191,
				),
				'purpose'           => array(
					'type' => 'string',
					'enum' => BookingAttachmentPolicy::PURPOSES,
				),
				'artist_term_id'    => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 1,
				),
				'artist_profile_id' => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 1,
				),
			),
			'required'             => array( 'booking_id', 'storage_reference', 'idempotency_key', 'purpose' ),
			'additionalProperties' => false,
		);
		if ( $replacement ) {
			$schema['properties']['attachment_id'] = array(
				'type'    => 'integer',
				'minimum' => 1,
			);
			$schema['required'][]                  = 'attachment_id';
		}
		return $schema;
	}
}
