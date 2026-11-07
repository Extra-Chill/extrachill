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

/**
 * Sample an image on the link storage site, caching successes and failures.
 *
 * @param array $styles   Resolved link page CSS variables.
 * @param array $settings Link page settings.
 * @return array|null RGBA average color, or null when unreadable.
 */
function extrachill_artist_platform_preview_image_average( $styles, $settings ) {
	$site     = function_exists( 'ec_get_link_page_storage_blog_id' ) ? ec_get_link_page_storage_blog_id() : 0;
	$switched = $site && get_current_blog_id() !== $site;
	if ( $switched ) {
		switch_to_blog( $site );
	}
	try {
		$id  = (int) ( $settings['background_image_id'] ?? 0 );
		$url = trim( $styles['--link-page-background-image-url'] ?? '' );
		if ( preg_match( '/^url\([\'\"]?(.*?)[\'\"]?\)$/', $url, $match ) ) {
			$url = $match[1];
		}
		if ( ! $id && ! $url ) {
			return null;
		}
		$file   = $id ? get_attached_file( $id ) : false;
		$key    = 'ec_preview_average_' . md5( $id . '|' . $url . '|' . ( $file && is_file( $file ) ? filemtime( $file ) : '' ) );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached['color'];
		}
		$bytes = false;
		if ( $file && is_readable( $file ) ) {
			$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local attachment file, not a URL.
		} elseif ( $url ) {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 5,
					'limit_response_size' => 10 * 1024 * 1024,
				)
			);
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$bytes = wp_remote_retrieve_body( $response );
			}
		}
		$color = null;
		if ( $bytes && function_exists( 'imagecreatefromstring' ) ) {
			$image = getimagesizefromstring( $bytes ) ? imagecreatefromstring( $bytes ) : false;
			if ( $image ) {
				$sample = imagecreatetruecolor( 1, 1 );
				imagecopyresampled( $sample, $image, 0, 0, 0, 0, 1, 1, imagesx( $image ), imagesy( $image ) );
				$rgb   = imagecolorat( $sample, 0, 0 );
				$color = array( ( $rgb >> 16 ) & 255, ( $rgb >> 8 ) & 255, $rgb & 255, 1.0 );
				imagedestroy( $sample );
				imagedestroy( $image );
			}
		} elseif ( $bytes && class_exists( 'Imagick' ) ) {
			$image = new Imagick();
			try {
				$image->readImageBlob( $bytes );
				$image->setIteratorIndex( 0 );
				$image->scaleImage( 1, 1 );
				$rgb   = $image->getImagePixelColor( 0, 0 )->getColor();
				$color = array( $rgb['r'], $rgb['g'], $rgb['b'], 1.0 );
			} catch ( Exception $exception ) {
				$color = null;
			} finally {
				$image->clear();
			}
		}
		set_transient( $key, array( 'color' => $color ), $color ? 86400 : 300 );
		return $color;
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Estimate each surface; gradient reports retain the worst stop.
 *
 * @param array $styles   Resolved link page CSS variables.
 * @param array $settings Link page settings.
 * @return array Contrast report keyed by pair name.
 */
function extrachill_artist_platform_preview_contrast( $styles, $settings ) {
	$get = static function ( $key ) use ( $styles ) {
		return extrachill_artist_platform_preview_color( $styles[ '--link-page-' . $key ] ?? '' );
	};
	$type = $styles['--link-page-background-type'] ?? 'color';
	if ( 'gradient' === $type ) {
		$reports = array();
		foreach ( array( 'start', 'middle', 'end' ) as $stop ) {
			$color = $styles[ '--link-page-background-gradient-' . $stop ] ?? '';
			if ( 'middle' === $stop && '' === trim( $color ) ) {
				continue;
			}
			$solid                                 = $styles;
			$solid['--link-page-background-type']  = 'color';
			$solid['--link-page-background-color'] = $color;
			foreach ( extrachill_artist_platform_preview_contrast( $solid, $settings ) as $name => $entry ) {
				$reports[ $name ][] = array(
					'color' => $color,
					'entry' => $entry,
				);
			}
		}
		$result = array();
		foreach ( $reports as $name => $stops ) {
			$worst = $stops[0]['entry'];
			foreach ( $stops as $stop ) {
				if ( null === $stop['entry']['ratio'] || ( null !== $worst['ratio'] && $stop['entry']['ratio'] < $worst['ratio'] ) ) {
					$worst = $stop['entry'];
				}
			}
			$worst['stops']  = array_map(
				static function ( $stop ) {
					return array(
						'color' => $stop['color'],
						'ratio' => $stop['entry']['ratio'],
					);
				},
				$stops
			);
			$result[ $name ] = $worst;
		}
		return $result;
	}
	$page = 'color' === $type ? $get( 'background-color' ) : null;
	if ( 'image' === $type ) {
		$page = extrachill_artist_platform_preview_image_average( $styles, $settings );
		if ( $page && ! empty( $settings['overlay_enabled'] ) ) {
			$overlay = $get( 'overlay-color' );
			$page    = extrachill_artist_platform_preview_composite( $overlay ? $overlay : $get( 'card-bg-color' ), $page );
		}
	}
	if ( $page && $page[3] < 1 ) {
		$page = null;
	}
	$card = empty( $settings['overlay_enabled'] ) ? $page : extrachill_artist_platform_preview_composite( $get( 'card-bg-color' ), $page );
	$button = extrachill_artist_platform_preview_composite( $get( 'button-bg-color' ), $card );
	$icon   = $get( 'icon-color' );
	$icon   = $icon ? $icon : $get( 'text-color' );
	$pairs  = array(
		'button_text_on_button'   => array( $get( 'link-text-color' ), $button ),
		'page_text_on_background' => array( $get( 'text-color' ), $page ),
		'page_text_on_card'       => array( $get( 'text-color' ), $card ),
		'icons_on_background'     => array( $icon, $page ),
		'icons_on_card'           => array( $icon, $card ),
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
		if ( null === $ratio && 'image' === $type ) {
			$report[ $name ]['reason'] = $page ? 'Unsupported foreground or surface color.' : 'Background image average could not be read or its overlay is unsupported.';
		}
	}
	return $report;
}
