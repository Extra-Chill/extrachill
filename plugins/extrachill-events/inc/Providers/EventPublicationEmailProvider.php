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

	private static function has_submitter( \WP_Post $post ): bool {
		return (int) get_post_meta( $post->ID, '_datamachine_submitted_by', true ) > 0
			|| '' !== (string) get_post_meta( $post->ID, '_datamachine_submitter_email', true );
	}

	private static function site_url( string $site ): string {
		return function_exists( 'ec_get_site_url' ) ? (string) ec_get_site_url( $site ) : home_url( '/' );
	}

	private static function list_link( string $url, string $label ): string {
		return '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
	}

	/** Build the same reset-password handoff used by the submission receipt. */
	private static function build_claim_url( \WP_User $user ): string {
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return '';
		}

		$base = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : network_site_url();
		return trailingslashit( $base ) . 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login );
	}
}
