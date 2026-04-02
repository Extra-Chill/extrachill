<?php
/**
 * Resolve Instagram Media Ability
 *
 * Takes an Instagram post URL, shortcode, or numeric media ID and returns
 * the resolved numeric media ID suitable for Graph API calls.
 *
 * The shortcode-to-ID conversion uses Instagram's base64 alphabet — pure
 * math, zero API calls.
 *
 * @package    ExtraChillStudio
 * @subpackage Abilities
 * @since      0.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Instagram shortcode alphabet (custom base64).
 */
const EC_STUDIO_IG_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

/**
 * Decode an Instagram shortcode to a numeric media ID.
 *
 * @param string $shortcode The shortcode from the URL.
 * @return string Numeric media ID, or empty string on failure.
 */
function ec_studio_shortcode_to_media_id( string $shortcode ): string {
	if ( '' === $shortcode ) {
		return '';
	}

	// Use BCMath for arbitrary precision (media IDs exceed PHP_INT_MAX on 32-bit).
	if ( function_exists( 'bcmul' ) ) {
		$id = '0';
		for ( $i = 0, $len = strlen( $shortcode ); $i < $len; $i++ ) {
			$index = strpos( EC_STUDIO_IG_ALPHABET, $shortcode[ $i ] );
			if ( false === $index ) {
				return '';
			}
			$id = bcadd( bcmul( $id, '64' ), (string) $index );
		}
		return $id;
	}

	// Fallback: plain int math (works on 64-bit PHP for typical IDs).
	$id = 0;
	for ( $i = 0, $len = strlen( $shortcode ); $i < $len; $i++ ) {
		$index = strpos( EC_STUDIO_IG_ALPHABET, $shortcode[ $i ] );
		if ( false === $index ) {
			return '';
		}
		$id = $id * 64 + $index;
	}

	return (string) $id;
}

/**
 * Extract a shortcode from an Instagram URL.
 *
 * Supports /p/SHORTCODE/, /reel/SHORTCODE/, /tv/SHORTCODE/.
 *
 * @param string $url Instagram URL.
 * @return string|null Shortcode or null.
 */
function ec_studio_extract_instagram_shortcode( string $url ): ?string {
	if ( preg_match( '#instagram\.com/(?:p|reel|tv)/([A-Za-z0-9_-]+)#', $url, $matches ) ) {
		return $matches[1];
	}
	return null;
}

/**
 * Parse user input into a resolved media ID.
 *
 * Accepts:
 *   - A numeric media ID (e.g. 17891234567890123)
 *   - An Instagram URL (e.g. https://www.instagram.com/p/CxYz1234abc/)
 *   - A bare shortcode (e.g. CxYz1234abc)
 *
 * @param string $input User input.
 * @return array{platform: string, media_id: string}|null Resolved result or null.
 */
function ec_studio_parse_instagram_input( string $input ): ?array {
	$trimmed = trim( $input );

	if ( '' === $trimmed ) {
		return null;
	}

	// Already a numeric media ID.
	if ( preg_match( '/^\d{10,}$/', $trimmed ) ) {
		return array(
			'platform' => 'instagram',
			'media_id' => $trimmed,
		);
	}

	// Instagram URL — extract shortcode and decode.
	if ( false !== strpos( $trimmed, 'instagram.com' ) ) {
		$shortcode = ec_studio_extract_instagram_shortcode( $trimmed );
		if ( $shortcode ) {
			$media_id = ec_studio_shortcode_to_media_id( $shortcode );
			if ( '' !== $media_id ) {
				return array(
					'platform' => 'instagram',
					'media_id' => $media_id,
				);
			}
		}
		return null;
	}

	// Bare shortcode attempt.
	if ( preg_match( '/^[A-Za-z0-9_-]{6,}$/', $trimmed ) ) {
		$media_id = ec_studio_shortcode_to_media_id( $trimmed );
		if ( '' !== $media_id ) {
			return array(
				'platform' => 'instagram',
				'media_id' => $media_id,
			);
		}
	}

	return null;
}

/**
 * Register the resolve-instagram-media ability.
 */
function ec_studio_register_resolve_instagram_media_ability(): void {
	if ( ! class_exists( 'WP_Ability' ) ) {
		return;
	}

	$register = function () {
		wp_register_ability(
			'extrachill/resolve-instagram-media',
			array(
				'label'               => __( 'Resolve Instagram Media', 'extrachill-studio' ),
				'description'         => __( 'Resolve an Instagram URL, shortcode, or numeric ID to a Graph API media ID.', 'extrachill-studio' ),
				'category'            => 'extrachill',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'input' => array(
							'type'        => 'string',
							'description' => __( 'Instagram post URL, shortcode, or numeric media ID.', 'extrachill-studio' ),
						),
					),
					'required'   => array( 'input' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'platform' => array( 'type' => 'string' ),
						'media_id' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => 'ec_studio_execute_resolve_instagram_media',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	};

	if ( doing_action( 'wp_abilities_api_init' ) ) {
		$register();
	} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
		add_action( 'wp_abilities_api_init', $register );
	}
}

/**
 * Execute callback for resolve-instagram-media.
 *
 * @param array $input Input parameters.
 * @return array|\WP_Error Result.
 */
function ec_studio_execute_resolve_instagram_media( array $input ): array|\WP_Error {
	$raw = $input['input'] ?? '';

	if ( '' === trim( $raw ) ) {
		return new \WP_Error( 'missing_param', __( 'input is required.', 'extrachill-studio' ), array( 'status' => 400 ) );
	}

	$result = ec_studio_parse_instagram_input( $raw );

	if ( ! $result || empty( $result['media_id'] ) ) {
		return new \WP_Error(
			'resolve_failed',
			__( 'Could not resolve input to an Instagram media ID. Provide a post URL, shortcode, or numeric ID.', 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	return $result;
}

ec_studio_register_resolve_instagram_media_ability();
