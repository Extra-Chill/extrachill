<?php
/** Phone-friendly review of persisted public event submissions. */

namespace ExtraChillEvents\Abilities;

use DataMachine\Engine\AI\Actions\PendingActionHelper;
use DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt;
use DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility;

defined( 'ABSPATH' ) || exit;

final class EventSubmissionApproval {
	public const KIND               = 'extrachill_event_submission';
	private const ACTION_META       = '_extrachill_submission_action_id';
	private const NOTIFIED_META     = '_extrachill_submission_review_notified';
	private const LOCK_META         = '_extrachill_submission_review_lock';
	private const LIFETIME          = 604800;
	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'datamachine_pending_action_handlers', array( self::class, 'handlers' ) );
		add_action( 'datamachine_pending_action_resolved', array( self::class, 'onResolved' ), 10, 2 );
		// Unlike rejection, expiry deliberately leaves the event pending for wp-admin review.
		add_action( 'datamachine_events_after_event_upsert_persistence', array( self::class, 'afterUpsert' ), 10, 3 );
	}

	public static function handlers( $handlers ): array {
		$handlers               = is_array( $handlers ) ? $handlers : array();
		$handlers[ self::KIND ] = array(
			'apply'       => array( self::class, 'approve' ),
			'can_resolve' => array( self::class, 'canResolve' ),
		);
		return $handlers;
	}

	public static function canResolve( $payload, $decision, $user_id, $context = array() ) {
		if ( 'signed_url' === ( $context['resolution_transport'] ?? '' ) ) {
			return true;
		}
		$id   = (int) ( $payload['apply_input']['post_id'] ?? 0 );
		$type = get_post_type_object( 'data_machine_events' );
		if ( $user_id > 0 && $type && user_can( $user_id, 'edit_post', $id ) && user_can( $user_id, $type->cap->publish_posts ) ) {
			return true;
		}
		return new \WP_Error( 'event_review_forbidden', 'Only an event reviewer or a signed review link can resolve this submission.', array( 'status' => 403 ) );
	}

	public static function approve( array $input, array $payload, array $receipt ) {
		$authorized = PendingActionAuthorizationReceipt::consume(
			$receipt,
			self::KIND,
			self::KIND,
			$input,
			$input,
			(string) ( $payload['agent'] ?? $payload['creator'] ?? '' ),
			is_array( $payload['workspace'] ?? null ) ? $payload['workspace'] : array()
		);
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
		return self::resolvePost( (int) ( $input['post_id'] ?? 0 ), 'publish' );
	}

	/** Rejection is an observer, not apply: Data Machine only invokes apply on acceptance. */
	public static function onResolved( $action, $decision ): void {
		if ( ! is_object( $action ) || ! method_exists( $action, 'get_kind' ) || ! method_exists( $action, 'get_apply_input' ) || self::KIND !== $action->get_kind()
			|| ! is_object( $decision ) || ! method_exists( $decision, 'is_rejected' ) || ! $decision->is_rejected() ) {
			return;
		}
		$input  = (array) $action->get_apply_input();
		$result = self::resolvePost( (int) ( $input['post_id'] ?? 0 ), 'trash' );
		if ( is_wp_error( $result ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Event submission rejection failed.',
				array(
					'post_id' => $input['post_id'] ?? 0,
					'error'   => $result->get_error_message(),
				)
			);
		}
	}

	private static function resolvePost( int $id, string $status ) {
		$post = get_post( $id );
		if ( ! $post || 'data_machine_events' !== $post->post_type ) {
			return new \WP_Error( 'event_submission_missing', 'The submitted event no longer exists.' );
		}
		if ( 'pending' !== $post->post_status ) {
			return array(
				'success' => true,
				'noop'    => true,
				'message' => 'Event is no longer pending; no changes were made.',
			);
		}
		// Normal WP transitions preserve data-machine-events submitter publication emails.
		if ( 'trash' === $status && defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
			// wp_trash_post() permanently deletes when trash is disabled. Never do that from email.
			return new \WP_Error( 'event_trash_disabled', 'Trash is disabled; the event was left pending for manual review.' );
		}
		$result = 'trash' === $status ? wp_trash_post( $id ) : wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'event_review_failed', 'The event could not be updated.' );
		}
		return array(
			'success' => true,
			'post_id' => $id,
			'status'  => $status,
		);
	}

	/** Runs after metadata, dates and venue terms are persisted, not at initial post insert. */
	public static function afterUpsert( $context, $post_id, $result ): void {
		$id   = (int) $post_id;
		$post = get_post( $id );
		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['success'] )
			|| ! $post || 'data_machine_events' !== $post->post_type || 'pending' !== $post->post_status
			|| ! get_post_meta( $id, '_datamachine_submitter_email', true )
			|| get_post_meta( $id, self::NOTIFIED_META, true ) ) {
			return;
		}
		$to = apply_filters( 'extrachill_pending_event_submissions_recipient', get_option( 'admin_email' ) );
		if ( ! is_email( $to ) || ! add_post_meta( $id, self::LOCK_META, time(), true ) ) {
			return;
		}
		try {
			$links   = self::approvalLinks( $id );
			$date    = class_exists( '\DataMachineEvents\Core\EventDatesTable' ) ? \DataMachineEvents\Core\EventDatesTable::get( $id ) : null;
			$venues  = wp_get_post_terms( $id, 'venue', array( 'fields' => 'names' ) );
			$subject = sprintf( '[%s] Review event submission: %s', get_bloginfo( 'name' ), $post->post_title );
			$body    = '<p>A public event submission is ready for review.</p><ul>';
			foreach ( array(
				'Event'     => $post->post_title,
				'Date'      => $date->start_datetime ?? 'Not specified',
				'Venue'     => ! is_wp_error( $venues ) && $venues ? implode( ', ', $venues ) : 'Not specified',
				'Submitter' => get_post_meta( $id, '_datamachine_submitter_name', true ) . ' (' . get_post_meta( $id, '_datamachine_submitter_email', true ) . ')',
			) as $label => $value ) {
				$body .= '<li>' . esc_html( $label . ': ' . $value ) . '</li>';
			}
			// get_edit_post_link() is capability-sensitive; this email is often built as an anonymous user.
			$edit  = admin_url( 'post.php?post=' . $id . '&action=edit' );
			$body .= '</ul><p><a href="' . esc_url( (string) get_permalink( $id ) ) . '">View event</a> | <a href="' . esc_url( $edit ) . '">Edit event</a></p>';
			if ( $links ) {
				$body .= '<p><a href="' . esc_url( $links['approve_url'] ) . '">Approve</a> | <a href="' . esc_url( $links['reject_url'] ) . '">Reject</a></p><p>Links expire ' . esc_html( $links['expires_at'] ) . '. Each opens a confirmation page; no login required. Reject moves the event to trash.</p>';
			} else {
				$body .= '<p>Signed review links are unavailable. Please review in wp-admin.</p>';
			}
			EventSubmissionAbilities::dispatchEmail(
				array(
					'to'       => $to,
					'subject'  => $subject,
					'template' => 'extrachill/minimal',
					'context'  => array(
						'subject_html' => esc_html( $subject ),
						'preheader'    => 'Event submission ready for review.',
						'body_html'    => $body,
						'cta_url'      => self::pendingUrl(),
						'cta_label'    => 'Review pending events',
					),
				),
				'admin'
			);
			update_post_meta( $id, self::NOTIFIED_META, time() );
		} catch ( \Throwable $error ) {
			// Review notifications must never turn a persisted submission into a failed job.
			do_action(
				'datamachine_log',
				'warning',
				'Event submission review notification failed.',
				array(
					'post_id' => $id,
					'error'   => $error->getMessage(),
				)
			);
		} finally {
			delete_post_meta( $id, self::LOCK_META );
		}
	}

	public static function pendingUrl(): string {
		return admin_url( 'edit.php?post_status=pending&post_type=data_machine_events' );
	}

	public static function approvalLinks( int $id ): array {
		if ( ! class_exists( PendingActionHelper::class ) || ! class_exists( SignPendingActionResolutionAbility::class ) ) {
			return array();
		}
		$action_id = (string) get_post_meta( $id, self::ACTION_META, true );
		if ( '' === $action_id ) {
			$staged = PendingActionHelper::stage(
				array(
					'kind'            => self::KIND,
					'summary'         => 'Publish submitted event: ' . get_the_title( $id ),
					'apply_input'     => array( 'post_id' => $id ),
					'ttl'             => self::LIFETIME,
					'user_id'         => 0,
					'agent_id'        => 0,
					'resolver_grants' => array(),
				)
			);
			if ( empty( $staged['staged'] ) || empty( $staged['action_id'] ) ) {
				return array();
			}
			$action_id = $staged['action_id'];
			update_post_meta( $id, self::ACTION_META, $action_id );
		}
		// Trusted internal signing, without requiring an anonymous submission worker to have chat permission.
		$signed = SignPendingActionResolutionAbility::execute(
			array(
				'action_id' => $action_id,
				'lifetime'  => self::LIFETIME,
			)
		);
		return ! empty( $signed['success'] ) ? $signed : array();
	}
}
