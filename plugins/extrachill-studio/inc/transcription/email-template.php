<?php
/**
 * Transcription Completion Email Template
 *
 * Renders the HTML body of the "your transcription is ready" email sent
 * after a sweatpants callback creates the draft post on main extrachill.com.
 *
 * Intentionally simple inline-styled HTML — no theme dependency, no
 * external assets, no JS. The same body renders cleanly in webmail
 * clients (Gmail, Apple Mail) and the WP-CLI test pipeline.
 *
 * @package    ExtraChillStudio
 * @subpackage Transcription
 * @since      0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Format a duration in seconds as "M:SS" or "Hh Mm".
 *
 * @since 0.13.0
 *
 * @param float $seconds Duration in seconds.
 * @return string Human-readable duration.
 */
function ec_studio_transcription_format_duration( float $seconds ): string {
	if ( $seconds <= 0 ) {
		return __( 'unknown', 'extrachill-studio' );
	}
	$total = (int) round( $seconds );
	$h     = intdiv( $total, 3600 );
	$m     = intdiv( $total % 3600, 60 );
	$s     = $total % 60;
	if ( $h > 0 ) {
		return sprintf( __( '%dh %dm', 'extrachill-studio' ), $h, $m );
	}
	return sprintf( '%d:%02d', $m, $s );
}

/**
 * Render the completion email body.
 *
 * @since 0.13.0
 *
 * @param array $args {
 *     @type string $recipient_name Display name of the uploader.
 *     @type string $filename       Original recording filename.
 *     @type float  $duration_sec   Audio duration in seconds.
 *     @type int    $segments       Whisper segment count.
 *     @type bool   $has_speakers   Whether diarization ran.
 *     @type string $preview        First ~400 chars of the transcript.
 *     @type string $edit_url       Direct edit URL for the draft post.
 * }
 * @return string HTML body.
 */
function ec_studio_transcription_render_completion_email( array $args ): string {
	$defaults = array(
		'recipient_name' => '',
		'filename'       => '',
		'duration_sec'   => 0,
		'segments'       => 0,
		'has_speakers'   => false,
		'preview'        => '',
		'edit_url'       => '',
	);
	$args = wp_parse_args( $args, $defaults );

	$greeting = $args['recipient_name']
		? sprintf( __( 'Hey %s,', 'extrachill-studio' ), esc_html( $args['recipient_name'] ) )
		: __( 'Hey,', 'extrachill-studio' );

	$duration_label = ec_studio_transcription_format_duration( (float) $args['duration_sec'] );

	$speakers_clause = $args['has_speakers']
		? __( 'with speaker labels', 'extrachill-studio' )
		: __( 'without speaker labels', 'extrachill-studio' );

	// Sanitize everything that goes into the HTML body.
	$preview_html   = $args['preview']
		? '<blockquote style="margin:0 0 16px 0;padding:12px 16px;border-left:3px solid #53940b;background:#f8f8f8;color:#444;font-style:italic;">' . esc_html( $args['preview'] ) . '</blockquote>'
		: '';

	$edit_button = $args['edit_url']
		? sprintf(
			'<p style="margin:24px 0;"><a href="%s" style="display:inline-block;padding:12px 24px;background:#53940b;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">%s</a></p>',
			esc_url( $args['edit_url'] ),
			esc_html__( 'Open draft in editor', 'extrachill-studio' )
		)
		: '';

	$body = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . esc_html__( 'Your transcription is ready', 'extrachill-studio' ) . '</title></head><body style="font-family:Helvetica,Arial,sans-serif;line-height:1.5;color:#222;max-width:560px;margin:0 auto;padding:24px;">';
	$body .= '<p>' . $greeting . '</p>';
	$body .= '<p>' . sprintf(
		/* translators: %s: source recording filename */
		esc_html__( 'Your transcription of %s is ready.', 'extrachill-studio' ),
		'<strong>' . esc_html( $args['filename'] ) . '</strong>'
	) . '</p>';
	$body .= '<p style="color:#666;font-size:14px;">' . sprintf(
		/* translators: 1: human duration (e.g. "5:29"), 2: segment count, 3: speakers clause */
		esc_html__( '%1$s · %2$d segments · %3$s', 'extrachill-studio' ),
		esc_html( $duration_label ),
		(int) $args['segments'],
		esc_html( $speakers_clause )
	) . '</p>';
	$body .= $preview_html;
	$body .= $edit_button;
	$body .= '<p style="color:#888;font-size:13px;margin-top:32px;border-top:1px solid #eee;padding-top:16px;">' . esc_html__( 'Sent automatically by the Extra Chill Studio transcription pipeline. The draft is on extrachill.com — open it in the editor to review, polish, and publish.', 'extrachill-studio' ) . '</p>';
	$body .= '</body></html>';

	return $body;
}
