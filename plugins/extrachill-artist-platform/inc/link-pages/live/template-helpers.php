<?php
/**
 * Template display helpers for the live Link Page.
 *
 * Pure output helpers shared by the link-page PHP templates (public page and
 * editor preview iframe). These return pre-escaped HTML — callers must echo
 * the return value directly without re-escaping.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bound the leading emoji of a Link Page title so it can no longer balloon.
 *
 * Emoji glyphs render at the full font-size and fill the entire em-box. The
 * link page <h1> title uses --link-page-title-font-size (default 2.1em), so a
 * leading emoji visually dwarfs the surrounding text. When the title begins
 * with an emoji, this helper wraps that single leading emoji (including ZWJ
 * sequences and skin-tone / variation selectors) in a span tagged with the
 * `.extrch-link-page-title-emoji` class, which CSS already bounds to 0.75em.
 *
 * The remainder of the title is escaped with esc_html(). If the title has no
 * leading emoji, the whole string is returned as esc_html() output unchanged.
 *
 * @param string $title Raw profile display title, optionally prefixed with an emoji.
 * @return string Pre-escaped HTML suitable for direct echo inside an <h1>.
 *                 Includes an emoji span when a leading emoji is detected.
 */
function ec_link_page_wrap_title_emoji( $title ) {
	if ( ! is_string( $title ) || '' === $title ) {
		return '';
	}

	/*
	 * Match a single leading emoji at the start of the title:
	 *   \p{Extended_Pictographic}              the base pictographic glyph
	 *   (?:\x{200D}\p{Extended_Pictographic})* zero or more ZWJ-joined glyphs
	 *   [\x{FE0F}\x{1F3FB}-\x{1F3FF}]?         optional variation selector or skin tone
	 *
	 * Requires PCRE2 10.30+ (PHP 7.3+). Mirrors the LEADING_EMOJI_RE used by
	 * src/blocks/link-page-editor/components/Preview.js::renderTitle() so the
	 * server output and the React preview stay in sync.
	 */
	$pattern = '/^(\p{Extended_Pictographic}(?:\x{200D}\p{Extended_Pictographic})*[\x{FE0F}\x{1F3FB}-\x{1F3FF}]?)/u';

	if ( preg_match( $pattern, $title, $matches ) ) {
		$emoji = $matches[1];
		$rest  = substr( $title, strlen( $emoji ) );

		return '<span class="extrch-link-page-title-emoji">' . esc_html( $emoji ) . '</span>' . esc_html( $rest );
	}

	return esc_html( $title );
}
