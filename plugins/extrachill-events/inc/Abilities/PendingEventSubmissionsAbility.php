<?php
/** Pending public event submissions and reviewer digest. */

namespace ExtraChillEvents\Abilities;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/EventSubmissionAbilities.php';

class PendingEventSubmissionsAbility {
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/pending-event-submissions',
			array(
				'label'               => __( 'Pending Event Submissions', 'extrachill-events' ),
				'description'         => __( 'List public event submissions awaiting review.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'execute_callback'    => array( $this, 'getPendingSubmissions' ),
				// Rows carry submitter names and emails: reviewers only.
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_others_posts' );
				},
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => true,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function getPendingSubmissions(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'data_machine_events',
				'post_status'    => 'pending',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_datamachine_submitter_email',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$rows  = array();
		foreach ( $posts as $post ) {
			$submitter_email = (string) get_post_meta( $post->ID, '_datamachine_submitter_email', true );
			if ( '' === $submitter_email ) {
				continue;
			}
			$event        = class_exists( '\\DataMachineEvents\\Core\\EventDatesTable' )
				? \DataMachineEvents\Core\EventDatesTable::get( (int) $post->ID )
				: null;
			$event_date   = $event && ! empty( $event->start_datetime )
				? new \DateTimeImmutable( substr( (string) $event->start_datetime, 0, 10 ), wp_timezone() )
				: null;
			$days_until   = $event_date ? (int) $today->diff( $event_date )->format( '%r%a' ) : null;
			$days_waiting = max( 0, (int) ( new \DateTimeImmutable( substr( (string) get_post_time( 'Y-m-d', true, $post ), 0, 10 ), wp_timezone() ) )->diff( $today )->format( '%r%a' ) );
			$rows[]       = array(
				'id'               => (int) $post->ID,
				'title'            => get_the_title( $post ),
				'submitter_name'   => (string) get_post_meta( $post->ID, '_datamachine_submitter_name', true ),
				'submitter_email'  => $submitter_email,
				'days_waiting'     => $days_waiting,
				'days_until_event' => $days_until,
				'flagged'          => null !== $days_until && $days_until < 7,
				'edit_link'        => get_edit_post_link( $post->ID, 'raw' ),
			);
		}
		usort( $rows, static function ( array $a, array $b ): int {
			$a_days   = null === $a['days_until_event'] ? PHP_INT_MAX : $a['days_until_event'];
			$b_days   = null === $b['days_until_event'] ? PHP_INT_MAX : $b['days_until_event'];
			$by_event = $a_days <=> $b_days;
			return 0 !== $by_event ? $by_event : ( $b['days_waiting'] <=> $a['days_waiting'] );
		} );
		return $rows;
	}

	/** Send one daily digest, and remain silent for an empty queue. */
	public function sendDigest(): array {
		$rows = $this->getPendingSubmissions();
		if ( ! $rows ) {
			return array(
				'sent'  => false,
				'count' => 0,
			);
		}
		$recipient = apply_filters( 'extrachill_pending_event_submissions_recipient', get_option( 'admin_email' ) );
		if ( ! is_email( $recipient ) ) {
			return array(
				'sent'  => false,
				'count' => count( $rows ),
			);
		}
		$body = '<p>' . esc_html__( 'These public event submissions are awaiting review:', 'extrachill-events' ) . '</p><ul>';
		foreach ( $rows as $row ) {
			$until = null === $row['days_until_event'] ? __( 'unknown', 'extrachill-events' ) : ( $row['days_until_event'] < 0 ? __( 'past', 'extrachill-events' ) : sprintf(
				/* translators: %d: number of days until the event. */
				_n( '%d day', '%d days', $row['days_until_event'], 'extrachill-events' ),
				$row['days_until_event']
			) );
			$label = sprintf( '%s — %s; waiting %d days; event %s', $row['title'], $row['submitter_name'], $row['days_waiting'], $until );
			if ( $row['flagged'] ) {
				$label = '[URGENT] ' . $label;
			}
			$body .= '<li><a href="' . esc_url( $row['edit_link'] ) . '">' . esc_html( $label ) . '</a></li>';
		}
		$body   .= '</ul>';
		$subject = sprintf(
			/* translators: 1: site name, 2: number of pending submissions. */
			__( '[%1$s] Pending event submissions (%2$d)', 'extrachill-events' ),
			get_bloginfo( 'name' ),
			count( $rows )
		);
		$args = array(
			'to'       => $recipient,
			'subject'  => $subject,
			'template' => 'extrachill/minimal',
			'context'  => array(
				'subject_html' => esc_html( $subject ),
				'preheader'    => __( 'Daily pending event submission review digest.', 'extrachill-events' ),
				'body_html'    => $body,
			),
		);
		EventSubmissionAbilities::dispatchEmail( $args, 'admin' );
		return array(
			'sent'      => true,
			'count'     => count( $rows ),
			'recipient' => $recipient,
		);
	}
}
