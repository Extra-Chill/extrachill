<?php
/**
 * Read-only Link Page preview and conservative WCAG contrast estimates.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/** Return the same defaults and derived variables used by public persistence. */
function extrachill_artist_platform_ability_preview_link_page( $input ) {
	$viewport = $input['viewport'] ?? array( 'width' => 390, 'height' => 844 );
	if ( ! is_array( $viewport ) || ! isset( $viewport['width'], $viewport['height'] ) ) {
		return new WP_Error( 'invalid_viewport', 'viewport requires width and height.' );
	}
	foreach ( array( 'width', 'height' ) as $dimension ) {
		if ( ! is_int( $viewport[ $dimension ] ) || $viewport[ $dimension ] < 1 || $viewport[ $dimension ] > 10000 ) {
			return new WP_Error( 'invalid_viewport', 'Viewport dimensions must be integers between 1 and 10000.' );
		}
	}
	/* Do not forward an arbitrary link_page_id: preview is always artist-owned. */
	$data = extrachill_artist_platform_ability_get_link_page_data( array( 'artist_id' => $input['artist_id'] ?? 0 ) );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$styles = $data['css_vars'];
	if ( class_exists( 'ExtraChillArtistPlatform_Fonts' ) ) {
		$styles = ExtraChillArtistPlatform_Fonts::instance()->process_font_css_vars( $styles );
	}
	return array(
		'public_url'      => ec_get_link_page_public_url( $data['link_page_id'] ),
		'viewport'        => $viewport,
		'resolved_styles' => $styles,
		'contrast_report' => extrachill_artist_platform_preview_contrast( $styles, $data['settings'] ?? array() ),
		'screenshot'      => array(
			'status' => 'unavailable',
			'url'    => null,
			'reason' => 'WP Codebox viewport replay requires a Playground archive and route; it does not capture a live public URL.',
		),
	);
}

/** Parse supported CSS colors; unsupported values remain explicitly unknown. */
function extrachill_artist_platform_preview_color( $value ) {
	$value = strtolower( trim( (string) $value ) );
	if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value, $match ) ) {
		$hex = $match[1];
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ), 1.0 );
	}
	if ( preg_match( '/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*(0|1|0?\.\d+))?\s*\)$/', $value, $match ) ) {
		$color = array( (int) $match[1], (int) $match[2], (int) $match[3], isset( $match[4] ) ? (float) $match[4] : 1.0 );
		return max( array_slice( $color, 0, 3 ) ) <= 255 ? $color : null;
	}
	return null;
}

/** Alpha composite only when the underlying surface is known and opaque. */
function extrachill_artist_platform_preview_composite( $front, $back ) {
	if ( ! $front ) {
		return null;
	}
	if ( 1.0 === (float) $front[3] ) {
		return $front;
	}
	if ( ! $back ) {
		return null;
	}
	$result = array();
	for ( $i = 0; $i < 3; ++$i ) {
		$result[] = $front[$i] * $front[3] + $back[$i] * ( 1 - $front[3] );
	}
	$result[] = 1.0;
	return $result;
}

/** WCAG relative luminance in linear sRGB. */
function extrachill_artist_platform_preview_luminance( $color ) {
	$linear = array();
	for ( $i = 0; $i < 3; ++$i ) {
		$c = $color[$i] / 255;
		$linear[] = $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}
	return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
}

/** Report unknown rather than claiming image/gradient backgrounds pass. */
function extrachill_artist_platform_preview_contrast( $styles, $settings ) {
	$get = static function ( $key ) use ( $styles ) {
		return extrachill_artist_platform_preview_color( $styles[ '--link-page-' . $key ] ?? '' );
	};
	$page = 'color' === ( $styles['--link-page-background-type'] ?? 'color' ) ? $get( 'background-color' ) : null;
	if ( $page && $page[3] < 1 ) {
		$page = null;
	}
	$card = empty( $settings['overlay_enabled'] ) ? $page : extrachill_artist_platform_preview_composite( $get( 'card-bg-color' ), $page );
	$button = extrachill_artist_platform_preview_composite( $get( 'button-bg-color' ), $card );
	$pairs = array(
		'button_text_on_button' => array( $get( 'link-text-color' ), $button ),
		'page_text_on_background' => array( $get( 'text-color' ), $page ),
		'page_text_on_card' => array( $get( 'text-color' ), $card ),
		'icons_on_background' => array( $get( 'link-text-color' ), $page ),
		'icons_on_card' => array( $get( 'link-text-color' ), $card ),
	);
	$report = array();
	foreach ( $pairs as $name => $pair ) {
		$foreground = extrachill_artist_platform_preview_composite( $pair[0], $pair[1] );
		$ratio = null;
		if ( $foreground && $pair[1] ) {
			$a = extrachill_artist_platform_preview_luminance( $foreground );
			$b = extrachill_artist_platform_preview_luminance( $pair[1] );
			$ratio = ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
		}
		$report[ $name ] = array(
			'ratio' => null === $ratio ? null : round( $ratio, 4 ),
			'threshold' => 4.5,
			'below_threshold' => null === $ratio ? null : $ratio < 4.5,
			'status' => null === $ratio ? 'unknown' : ( $ratio < 4.5 ? 'fail' : 'pass' ),
		);
	}
	return $report;
}
