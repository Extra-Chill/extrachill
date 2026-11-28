<?php
/**
 * Public ability for sending feedback about a docs article.
 *
 * @package ExtraChillDocs
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_docs_register_feedback_ability' );

/** Register the feedback form's REST-callable ability. */
function extrachill_docs_register_feedback_ability() {
	wp_register_ability(
		'extrachill/docs-feedback-submit',
		array(
			'label'               => __( 'Submit Docs Feedback', 'extrachill-docs' ),
			'description'         => __( 'Send feedback about a docs article to the docs maintainer.', 'extrachill-docs' ),
			'category'            => 'extrachill-docs',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'            => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'message'            => array(
						'type'      => 'string',
						'maxLength' => 2000,
					),
					'email'              => array(
						'type'      => 'string',
						'maxLength' => 254,
					),
					'turnstile_response' => array(
						'type'      => 'string',
						'maxLength' => 2048,
					),
				),
				'required'             => array( 'post_id', 'message', 'turnstile_response' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
				'required'   => array( 'success' ),
			),
			// Anyone may submit; validation, the rate limit and the single-use challenge run only on execution.
			'permission_callback' => '__return_true',
			'execute_callback'    => 'extrachill_docs_submit_feedback',
			'meta'                => array( 'show_in_rest' => true ),
		)
	);
}

/**
 * Reserve one of five attempts per hour for the account or guest IP.
 *
 * @param int $user_id Current user ID, or zero for a guest.
 * @return bool Whether another attempt is allowed.
 */
function extrachill_docs_feedback_allow_attempt( $user_id ) {
	// Use the server's remote address, not a caller-controlled forwarding header.
	$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : false;
	$ip      = $ip ? $ip : 'unknown';
	$visitor = $user_id ? 'user:' . $user_id : 'ip:' . $ip;
	$key     = 'ec_docs_feedback_' . hash_hmac( 'sha256', $visitor, wp_salt() );
	$now     = time();
	$window  = get_transient( $key );

	if ( ! is_array( $window ) || $window['expires'] <= $now ) {
		$window = array(
			'count'   => 0,
			'expires' => $now + HOUR_IN_SECONDS,
		);
	}
	if ( $window['count'] >= 5 ) {
		return false;
	}

	++$window['count'];
	set_transient( $key, $window, $window['expires'] - $now );
	return true;
}

/**
 * Validate and deliver an article feedback submission.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Success or a failure with an HTTP status.
 */
function extrachill_docs_submit_feedback( $input ) {
	$post_id = filter_var( $input['post_id'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! extrachill_docs_is_feedback_article( $post ) ) {
		return new WP_Error( 'docs_feedback_invalid_article', __( 'This docs article could not be found.', 'extrachill-docs' ), array( 'status' => 404 ) );
	}

	$raw_message = $input['message'] ?? '';
	$message     = is_string( $raw_message ) ? sanitize_textarea_field( $raw_message ) : '';
	if ( '' === $message || mb_strlen( $raw_message ) > 2000 ) {
		return new WP_Error( 'docs_feedback_invalid_message', __( 'Enter a message of up to 2,000 characters.', 'extrachill-docs' ), array( 'status' => 400 ) );
	}

	$user  = wp_get_current_user();
	$email = $user->ID ? $user->user_email : ( $input['email'] ?? '' );
	if ( ! $user->ID && '' !== $email && ( ! is_string( $email ) || ! is_email( $email ) ) ) {
		return new WP_Error( 'docs_feedback_invalid_email', __( 'Enter a valid email address or leave it blank.', 'extrachill-docs' ), array( 'status' => 400 ) );
	}

	if ( ! function_exists( 'ec_verify_turnstile_response' ) ) {
		return new WP_Error( 'docs_feedback_turnstile_unavailable', __( 'Security verification is unavailable. Please try again later.', 'extrachill-docs' ), array( 'status' => 503 ) );
	}

	if ( ! extrachill_docs_feedback_allow_attempt( $user->ID ) ) {
		return new WP_Error( 'docs_feedback_rate_limited', __( 'Too many feedback attempts. Please try again later.', 'extrachill-docs' ), array( 'status' => 429 ) );
	}

	$token = $input['turnstile_response'] ?? '';
	if ( ! is_string( $token ) || '' === trim( $token ) || ! ec_verify_turnstile_response( $token ) ) {
		return new WP_Error( 'docs_feedback_turnstile_failed', __( 'Security verification failed. Please try again.', 'extrachill-docs' ), array( 'status' => 403 ) );
	}

	$title   = sanitize_text_field( get_the_title( $post ) );
	$url     = esc_url_raw( (string) get_permalink( $post ) );
	$body    = "Page: {$title}\nURL: {$url}\n";
	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	if ( $user->ID ) {
		$name    = sanitize_text_field( $user->display_name );
		$profile = function_exists( 'extrachill_get_user_profile_url' ) ? extrachill_get_user_profile_url( $user->ID ) : get_author_posts_url( $user->ID );
		$body   .= "From: {$name}\nProfile: " . esc_url_raw( $profile ) . "\n";
	}
	if ( is_email( $email ) ) {
		$headers[] = 'Reply-To: ' . sanitize_email( $email );
	}
	$body .= "\n{$message}";

	// The article check above ensures we're reading the docs site's own option.
	$recipient = apply_filters( 'extrachill_docs_feedback_recipient', get_option( 'admin_email' ), $post_id );
	if ( ! wp_mail( $recipient, 'Docs feedback: ' . $title, $body, $headers ) ) {
		return new WP_Error( 'docs_feedback_mail_failed', __( 'Feedback could not be sent. Please try again.', 'extrachill-docs' ), array( 'status' => 500 ) );
	}

	return array( 'success' => true );
}
