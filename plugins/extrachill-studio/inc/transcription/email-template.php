<?php
/**
 * Transcription Completion Email — inner-body renderer.
 *
 * Renders ONLY the transcription-specific inner body (filename, stats,
 * preview blockquote) that drops into the `extrachill/branded` template
 * registered by extrachill-multisite. The branded shell owns the
 * `<html>`/`<body>` chrome, greeting (`Hey {recipient_name},`), CTA
 * button, "Much love, Extra Chill" sign-off, link grid, and footer —
 * this file does not render any of that.
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
		/* translators: 1: hours, 2: minutes */
		return sprintf( __( '%1$dh %2$dm', 'extrachill-studio' ), $h, $m );
	}
	return sprintf( '%d:%02d', $m, $s );
}

/**
 * Render the inner body HTML for the completion email.
 *
 * Returns ONLY the transcription-specific content (filename line, stats
 * line, preview blockquote, sign-off note). The surrounding chrome —
 * `<html>`/`<body>`, greeting, "Open draft in editor" CTA, link grid,
 * footer — is provided by the `extrachill/branded` template registered
 * by extrachill-multisite. The greeting and CTA are passed to that
 * template via `recipient_name`, `cta_url`, and `cta_label` in the
 * context array.
 *
 * @since 0.13.0
 * @since X.Y.Z Reduced to a pure inner-body renderer; greeting, CTA, and
 *              HTML chrome moved to the `extrachill/branded` shell.
 *
 * @param array $args {
 *     @type string $filename     Original recording filename.
 *     @type float  $duration_sec Audio duration in seconds.
 *     @type int    $segments     Whisper segment count.
 *     @type bool   $has_speakers Whether diarization ran.
 *     @type string $preview      First ~400 chars of the transcript.
 * }
 * @return string Inner-body HTML (no document chrome).
 */
function ec_studio_transcription_render_completion_email( array $args ): string {
	$defaults = array(
		'filename'     => '',
		'duration_sec' => 0,
		'segments'     => 0,
		'has_speakers' => false,
		'preview'      => '',
	);
	$args     = wp_parse_args( $args, $defaults );

	$duration_label = ec_studio_transcription_format_duration( (float) $args['duration_sec'] );

	$speakers_clause = $args['has_speakers']
		? __( 'with speaker labels', 'extrachill-studio' )
		: __( 'without speaker labels', 'extrachill-studio' );

	$preview_html = $args['preview']
		? '<blockquote style="margin:0 0 16px 0;padding:12px 16px;border-left:3px solid #53940b;background:#f8f8f8;color:#444;font-style:italic;">' . esc_html( $args['preview'] ) . '</blockquote>'
		: '';

	$body = '<p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;">' . sprintf(
		/* translators: %s: source recording filename */
		esc_html__( 'Your transcription of %s is ready.', 'extrachill-studio' ),
		'<strong>' . esc_html( $args['filename'] ) . '</strong>'
	) . '</p>';
	$body .= '<p style="margin:0 0 16px 0;color:#666;font-size:14px;">' . sprintf(
		/* translators: 1: human duration (e.g. "5:29"), 2: segment count, 3: speakers clause */
		esc_html__( '%1$s · %2$d segments · %3$s', 'extrachill-studio' ),
		esc_html( $duration_label ),
		(int) $args['segments'],
		esc_html( $speakers_clause )
	) . '</p>';
	$body .= $preview_html;
	$body .= '<p style="margin:24px 0 0 0;color:#666;font-size:14px;line-height:1.6;">' . esc_html__( 'The draft is on extrachill.com — open it in the editor to review, polish, and publish.', 'extrachill-studio' ) . '</p>';

	return $body;
}
