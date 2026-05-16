<?php
/**
 * Minimal WordPress block parser for unit tests.
 *
 * Parses block comment delimiters of the form:
 *   <!-- wp:namespace/name {"json":"attrs"} -->
 *     ...innerHTML and/or nested blocks...
 *   <!-- /wp:namespace/name -->
 *
 * Self-closing form is also supported:
 *   <!-- wp:namespace/name {"json":"attrs"} /-->
 *
 * Output mirrors WordPress core's `parse_blocks()` return shape sufficiently
 * for the helpers in inc/schema/schema-event.php to consume:
 *   [
 *     'blockName'   => string|null,
 *     'attrs'       => array,
 *     'innerBlocks' => array,
 *     'innerHTML'   => string,
 *   ]
 *
 * This is intentionally narrower than the real parser. It is only used by
 * unit tests against hand-authored fixtures, not against arbitrary content.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Support;

final class MiniBlockParser {

	/**
	 * Parse a content string into a block tree.
	 *
	 * @param string $content Raw post content.
	 * @return array<int, array<string, mixed>>
	 */
	public static function parse( string $content ): array {
		$pos = 0;
		return self::parse_tokens( $content, $pos, null );
	}

	/**
	 * Recursive token parser.
	 *
	 * @param string      $content        Source content.
	 * @param int         $pos            Current cursor position (by-ref).
	 * @param string|null $closing_block  Block name we are looking for the closing tag of, or null at top level.
	 * @return array<int, array<string, mixed>>
	 */
	private static function parse_tokens( string $content, int &$pos, ?string $closing_block ): array {
		$blocks  = array();
		$buffer  = '';
		$length  = strlen( $content );

		while ( $pos < $length ) {
			$next = strpos( $content, '<!--', $pos );
			if ( false === $next ) {
				$buffer .= substr( $content, $pos );
				$pos     = $length;
				break;
			}

			$buffer .= substr( $content, $pos, $next - $pos );
			$pos     = $next;

			$end_comment = strpos( $content, '-->', $pos );
			if ( false === $end_comment ) {
				$buffer .= substr( $content, $pos );
				$pos     = $length;
				break;
			}

			$comment_inner = trim( substr( $content, $pos + 4, $end_comment - ( $pos + 4 ) ) );
			$pos           = $end_comment + 3;

			// Closing block?
			if ( preg_match( '#^/wp:([a-z0-9-]+(?:/[a-z0-9-]+)?)\s*$#i', $comment_inner, $m ) ) {
				if ( null !== $closing_block && $m[1] === $closing_block ) {
					// Defer freeform append to caller.
					if ( '' !== trim( $buffer ) ) {
						$blocks[] = self::freeform_block( $buffer );
					}
					return $blocks;
				}
				// Mismatched closer; treat as freeform text and continue.
				$buffer .= "<!-- $comment_inner -->";
				continue;
			}

			// Opening or self-closing block.
			if ( preg_match( '#^wp:([a-z0-9-]+(?:/[a-z0-9-]+)?)\s*(\{.*?\})?\s*(/)?$#is', $comment_inner, $m ) ) {
				$block_name  = $m[1];
				$attrs_json  = isset( $m[2] ) && '' !== $m[2] ? $m[2] : '{}';
				$self_closed = ! empty( $m[3] );

				$attrs = json_decode( $attrs_json, true );
				if ( ! is_array( $attrs ) ) {
					$attrs = array();
				}

				// Flush any accumulated freeform text first.
				if ( '' !== trim( $buffer ) ) {
					$blocks[] = self::freeform_block( $buffer );
					$buffer   = '';
				}

				if ( $self_closed ) {
					$blocks[] = array(
						'blockName'   => $block_name,
						'attrs'       => $attrs,
						'innerBlocks' => array(),
						'innerHTML'   => '',
					);
					continue;
				}

				// Recurse to collect inner blocks until matching closer.
				$inner = self::parse_tokens( $content, $pos, $block_name );

				// Separate freeform innerHTML from inner blocks for shape parity.
				$inner_blocks = array();
				$inner_html   = '';
				foreach ( $inner as $child ) {
					if ( null === $child['blockName'] ) {
						$inner_html .= $child['innerHTML'];
					} else {
						$inner_blocks[] = $child;
					}
				}

				$blocks[] = array(
					'blockName'   => $block_name,
					'attrs'       => $attrs,
					'innerBlocks' => $inner_blocks,
					'innerHTML'   => $inner_html,
				);
				continue;
			}

			// Plain HTML comment — keep it in the buffer.
			$buffer .= "<!-- $comment_inner -->";
		}

		if ( '' !== trim( $buffer ) ) {
			$blocks[] = self::freeform_block( $buffer );
		}

		return $blocks;
	}

	/**
	 * Wrap freeform content in a null-block envelope.
	 *
	 * @param string $html Freeform HTML.
	 * @return array<string, mixed>
	 */
	private static function freeform_block( string $html ): array {
		return array(
			'blockName'   => null,
			'attrs'       => array(),
			'innerBlocks' => array(),
			'innerHTML'   => $html,
		);
	}
}
