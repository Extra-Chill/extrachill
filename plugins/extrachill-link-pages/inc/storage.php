<?php
/**
 * Generic Link Page persistence and creation.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

/** Return the generic Link Page defaults. */
function ec_link_page_defaults() {
	$defaults = array(
		'styles'   => array(
			'--link-page-background-color'              => '#121212',
			'--link-page-card-bg-color'                 => 'rgba(0, 0, 0, 0.4)',
			'--link-page-text-color'                    => '#e5e5e5',
			'--link-page-link-text-color'               => '#ffffff',
			'--link-page-button-bg-color'               => '#0b5394',
			'--link-page-button-border-color'           => '#0b5394',
			'--link-page-button-hover-bg-color'         => '#53940b',
			'--link-page-button-hover-text-color'       => '#ffffff',
			'--link-page-muted-text-color'              => '#aaa',
			'--link-page-overlay-color'                 => 'rgba(0, 0, 0, 0.5)',
			'--link-page-input-bg'                      => '#181818',
			'--link-page-accent'                        => '#888',
			'--link-page-accent-hover'                  => '#222',
			'--link-page-background-type'               => 'color',
			'--link-page-background-gradient-start'     => '#0b5394',
			'--link-page-background-gradient-end'       => '#53940b',
			'--link-page-background-gradient-direction' => 'to right',
			'--link-page-background-image-url'          => '',
			'--link-page-image-size'                    => 'cover',
			'--link-page-image-position'                => 'center center',
			'--link-page-image-repeat'                  => 'no-repeat',
			'overlay'                                   => '1',
			'--link-page-title-font-family'             => 'Loft Sans',
			'--link-page-title-font-size'               => '2.1em',
			'--link-page-body-font-family'              => 'Helvetica',
			'--link-page-button-radius'                 => '8px',
			'--link-page-button-border-width'           => '0px',
			'--link-page-profile-img-size'              => '30%',
			'_link_page_profile_img_shape'              => 'circle',
		),
		'settings' => array(
			'link_expiration_enabled' => false,
			'redirect_enabled'        => false,
			'redirect_target_url'     => '',
			'youtube_embed_enabled'   => true,
			'meta_pixel_id'           => '',
			'google_tag_id'           => '',
			'google_tag_manager_id'   => '',
			'social_icons_position'   => 'above',
			'profile_image_shape'     => 'circle',
			'overlay_enabled'         => true,
			'background_image_id'     => '',
		),
	);

	return apply_filters( 'ec_link_page_defaults', $defaults );
}

/** Return one defaults category. */
function ec_link_page_defaults_for( $category ) {
	$defaults = ec_link_page_defaults();
	return isset( $defaults[ $category ] ) && is_array( $defaults[ $category ] ) ? $defaults[ $category ] : array();
}

/** Return one default value. */
function ec_link_page_default( $category, $key, $fallback = null ) {
	$defaults = ec_link_page_defaults_for( $category );
	return array_key_exists( $key, $defaults ) ? $defaults[ $key ] : $fallback;
}

/** Return the persistent ID counter meta keys. */
function ec_link_page_id_meta_keys() {
	return array(
		'section' => '_ec_link_page_next_section_id',
		'link'    => '_ec_link_page_next_link_id',
	);
}

/** Whether an element needs a persistent ID. */
function ec_link_page_needs_id_assignment( $id ) {
	return ! is_string( $id ) || '' === trim( $id ) || 1 === preg_match( '/^(?:new|temp|tmp)(?:[-_]|$)/i', $id );
}

/** Execute a complete mutation while holding the exact per-page advisory lock. */
function ec_with_link_page_lock_scope( $link_page_id, $callback, $scope_type = 'generic' ) {
	global $wpdb;
	$link_page_id = absint( $link_page_id );
	if ( ! $link_page_id || ! is_callable( $callback ) ) {
		return new WP_Error( 'invalid_link_page_lock_scope', 'The Link Page lock scope is invalid.' );
	}
	$blog_id    = get_current_blog_id();
	$scope_type = in_array( $scope_type, array( 'generic', 'combined', 'separate' ), true ) ? $scope_type : 'generic';
	$scope      = $GLOBALS['ec_link_page_lock_scope'] ?? null;
	if ( is_array( $scope ) ) {
		if ( $scope['blog_id'] !== $blog_id || $scope['link_page_id'] !== $link_page_id ) {
			return new WP_Error( 'link_page_lock_scope_conflict', 'A different Link Page lock scope is already active.' );
		}
		if ( ( 'separate' === $scope['type'] ) !== ( 'separate' === $scope_type ) ) {
			return new WP_Error( 'link_page_lock_scope_conflict', 'A separate Link Page mutation cannot compose with the active save.' );
		}
		++$GLOBALS['ec_link_page_lock_scope']['depth'];
		try {
			return call_user_func( $callback );
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'link_page_lock_scope_callback_failed', 'The Link Page lock scope callback failed.' );
		} finally {
			--$GLOBALS['ec_link_page_lock_scope']['depth'];
		}
	}
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
		return new WP_Error( 'link_page_id_lock_unsupported', 'Persistent Link Page IDs require advisory lock support.' );
	}
	$lock_name = 'ec_link_page_ids:' . $blog_id . ':' . $link_page_id;
	$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory locks are the serialization primitive.
	if ( '1' !== (string) $acquired ) {
		return new WP_Error( 'link_page_id_lock_failed', 'The Link Page ID allocation lock could not be acquired.' );
	}
	$GLOBALS['ec_link_page_lock_scope'] = array(
		'blog_id'      => $blog_id,
		'link_page_id' => $link_page_id,
		'depth'        => 1,
		'type'         => $scope_type,
	);
	$result                             = null;
	try {
		$result = call_user_func( $callback );
	} catch ( Throwable $throwable ) {
		$result = new WP_Error( 'link_page_lock_scope_callback_failed', 'The Link Page lock scope callback failed.' );
	} finally {
		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory locks must be explicitly released.
		unset( $GLOBALS['ec_link_page_lock_scope'] );
	}
	return '1' === (string) $released ? $result : new WP_Error( 'link_page_id_lock_release_failed', 'The Link Page advisory lock could not be released.' );
}

/** Compatibility alias for callers that allocate persistent element IDs. */
function ec_with_link_page_id_lock( $link_page_id, $callback ) {
	return ec_with_link_page_lock_scope( $link_page_id, $callback );
}

/** Allocate the next persistent element ID. */
function ec_link_page_next_element_id( $link_page_id, $type ) {
	$keys = ec_link_page_id_meta_keys();
	if ( ! isset( $keys[ $type ] ) ) {
		return new WP_Error( 'invalid_link_page_element_type', 'The Link Page element type is invalid.' );
	}
	$next   = max( 1, (int) get_post_meta( $link_page_id, $keys[ $type ], true ) + 1 );
	$stored = update_post_meta( $link_page_id, $keys[ $type ], $next );
	if ( false === $stored && (int) get_post_meta( $link_page_id, $keys[ $type ], true ) !== $next ) {
		return new WP_Error( 'link_page_id_counter_failed', 'The persistent Link Page ID counter could not be updated.' );
	}
	return (int) $link_page_id . '-' . $type . '-' . $next;
}

/** Advance a counter when a persisted ID is supplied. */
function ec_link_page_sync_element_counter( $link_page_id, $type, $id ) {
	$keys = ec_link_page_id_meta_keys();
	if ( ! isset( $keys[ $type ] ) || 1 !== preg_match( '/^' . preg_quote( (string) (int) $link_page_id, '/' ) . '-' . preg_quote( $type, '/' ) . '-(\d+)$/', (string) $id, $matches ) ) {
		return new WP_Error( 'invalid_link_page_element_id', 'The persistent Link Page element ID is invalid.' );
	}
	if ( (int) $matches[1] > (int) get_post_meta( $link_page_id, $keys[ $type ], true ) ) {
		$updated = update_post_meta( $link_page_id, $keys[ $type ], (int) $matches[1] );
		if ( false === $updated && (int) get_post_meta( $link_page_id, $keys[ $type ], true ) !== (int) $matches[1] ) {
			return new WP_Error( 'link_page_id_counter_failed', 'The persistent Link Page ID counter could not be synchronized.' );
		}
	}
	return true;
}

/** Sanitize sections and links while retaining stable IDs. */
function ec_sanitize_link_page_links( $links, $link_page_id = 0 ) {
	if ( ! is_array( $links ) ) {
		return new WP_Error( 'invalid_link_page_links', 'Link Page links must be an array.' );
	}
	if ( $link_page_id ) {
		return ec_with_link_page_lock_scope(
			$link_page_id,
			static function () use ( $links, $link_page_id ) {
				$stored = get_post_meta( $link_page_id, '_link_page_links', true );
				return ec_sanitize_link_page_links_locked( $links, $link_page_id, ec_collect_link_page_element_ids( is_array( $stored ) ? $stored : array() ) );
			}
		);
	}
	return ec_sanitize_link_page_links_locked( $links, 0 );
}

/** Collect existing element IDs and their exact persisted types. */
function ec_collect_link_page_element_ids( $links ) {
	$ids  = array();
	$flat = ! empty( $links ) && isset( $links[0]['link_text'] ) && ! isset( $links[0]['links'] );
	if ( $flat ) {
		foreach ( $links as $link ) {
			if ( ! empty( $link['id'] ) && is_string( $link['id'] ) ) {
				$ids[ $link['id'] ] = 'link';
			}
		}
		return $ids;
	}
	foreach ( $links as $section ) {
		if ( ! is_array( $section ) ) {
			continue;
		}
		if ( ! empty( $section['id'] ) && is_string( $section['id'] ) ) {
			$ids[ $section['id'] ] = 'section';
		}
		foreach ( $section['links'] ?? array() as $link ) {
			if ( ! empty( $link['id'] ) && is_string( $link['id'] ) ) {
				$ids[ $link['id'] ] = 'link';
			}
		}
	}
	return $ids;
}

/** Validate and allocate persistent IDs while the page lock is held. */
function ec_sanitize_link_page_links_locked( $links, $link_page_id, $existing_ids = array() ) {
	$legacy_flat = ! empty( $links ) && isset( $links[0]['link_text'] ) && ! isset( $links[0]['links'] );
	if ( $legacy_flat ) {
		$links = array(
			array(
				'id'            => $link_page_id ? $link_page_id . '-section-1' : '',
				'section_title' => '',
				'links'         => $links,
			),
		);
	}
	$sanitized = array();
	$seen      = array();
	foreach ( $links as $section ) {
		if ( ! is_array( $section ) ) {
			return new WP_Error( 'invalid_link_page_links', 'A Link Page section is malformed.' );
		}
		$section_id = isset( $section['id'] ) ? sanitize_text_field( (string) $section['id'] ) : '';
		if ( $link_page_id && ec_link_page_needs_id_assignment( $section_id ) ) {
			$section_id = ec_link_page_next_element_id( $link_page_id, 'section' );
			if ( is_wp_error( $section_id ) ) {
				return $section_id;
			}
		} elseif ( $link_page_id && 1 === preg_match( '/^' . preg_quote( (string) $link_page_id, '/' ) . '-section-[1-9]\d*$/', $section_id ) ) {
			$synced = ec_link_page_sync_element_counter( $link_page_id, 'section', $section_id );
			if ( is_wp_error( $synced ) ) {
				return $synced;
			}
		} elseif ( $link_page_id && ( ! isset( $existing_ids[ $section_id ] ) || 'section' !== $existing_ids[ $section_id ] ) ) {
			return new WP_Error( 'invalid_link_page_element_id', 'A Link Page section ID does not belong to this page.' );
		}
		if ( '' !== $section_id && isset( $seen[ $section_id ] ) ) {
			return new WP_Error( 'duplicate_link_page_element_id', 'Link Page element IDs must be unique.' );
		}
		$seen[ $section_id ] = true;
		$clean               = array(
			'id'            => $section_id,
			'section_title' => isset( $section['section_title'] ) ? sanitize_text_field( wp_unslash( (string) $section['section_title'] ) ) : '',
			'links'         => array(),
		);
		foreach ( isset( $section['links'] ) && is_array( $section['links'] ) ? $section['links'] : array() as $link ) {
			if ( ! is_array( $link ) ) {
				return new WP_Error( 'invalid_link_page_links', 'A Link Page link is malformed.' );
			}
			$link_id = isset( $link['id'] ) ? sanitize_text_field( (string) $link['id'] ) : '';
			if ( $link_page_id && ec_link_page_needs_id_assignment( $link_id ) ) {
				$link_id = ec_link_page_next_element_id( $link_page_id, 'link' );
				if ( is_wp_error( $link_id ) ) {
					return $link_id;
				}
			} elseif ( $link_page_id && 1 === preg_match( '/^' . preg_quote( (string) $link_page_id, '/' ) . '-link-[1-9]\d*$/', $link_id ) ) {
				$synced = ec_link_page_sync_element_counter( $link_page_id, 'link', $link_id );
				if ( is_wp_error( $synced ) ) {
					return $synced;
				}
			} elseif ( $link_page_id && ( ! isset( $existing_ids[ $link_id ] ) || 'link' !== $existing_ids[ $link_id ] ) ) {
				return new WP_Error( 'invalid_link_page_element_id', 'A Link Page link ID does not belong to this page.' );
			}
			if ( '' !== $link_id && isset( $seen[ $link_id ] ) ) {
				return new WP_Error( 'duplicate_link_page_element_id', 'Link Page element IDs must be unique.' );
			}
			$seen[ $link_id ] = true;
			$item             = array(
				'id'        => $link_id,
				'link_text' => isset( $link['link_text'] ) ? sanitize_text_field( wp_unslash( (string) $link['link_text'] ) ) : '',
				'link_url'  => isset( $link['link_url'] ) ? esc_url_raw( wp_unslash( (string) $link['link_url'] ) ) : '',
			);
			if ( ! empty( $link['expires_at'] ) ) {
				$item['expires_at'] = sanitize_text_field( wp_unslash( (string) $link['expires_at'] ) );
			}
			$clean['links'][] = $item;
		}
		$sanitized[] = $clean;
	}
	return $legacy_flat ? $sanitized[0]['links'] : $sanitized;
}

/** Sanitize supported CSS custom properties. */
function ec_sanitize_link_page_css_vars( $vars, $existing_vars = array() ) {
	if ( ! is_array( $vars ) ) {
		return new WP_Error( 'invalid_link_page_css_vars', 'Link Page styles must be an array.' );
	}
	$colors    = array( '--link-page-background-color', '--link-page-card-bg-color', '--link-page-text-color', '--link-page-link-text-color', '--link-page-button-bg-color', '--link-page-button-border-color', '--link-page-button-hover-bg-color', '--link-page-button-hover-text-color', '--link-page-muted-text-color', '--link-page-overlay-color', '--link-page-input-bg', '--link-page-accent', '--link-page-accent-hover', '--link-page-background-gradient-start', '--link-page-background-gradient-end' );
	$enums     = array(
		'--link-page-background-type'               => array( 'color', 'gradient', 'image' ),
		'--link-page-background-gradient-direction' => array( 'to right', 'to bottom', '135deg' ),
		'--link-page-image-size'                    => array( 'cover', 'contain', 'auto' ),
		'--link-page-image-repeat'                  => array( 'no-repeat', 'repeat', 'repeat-x', 'repeat-y' ),
		'_link_page_profile_img_shape'              => array( 'circle', 'square', 'rectangle' ),
		'--link-page-profile-img-shape'             => array( 'circle', 'square', 'rectangle' ),
		'overlay'                                   => array( '0', '1' ),
	);
	$sanitized = array();
	foreach ( $vars as $key => $value ) {
		if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
			return new WP_Error( 'invalid_link_page_css_var', 'A Link Page style is malformed.' );
		}
		$value = trim( (string) $value );
		if ( array_key_exists( $key, $existing_vars ) && (string) $existing_vars[ $key ] === (string) $value ) {
			$sanitized[ $key ] = $existing_vars[ $key ];
			continue;
		}
		if ( preg_match( '/[;{}\x00-\x1F]|\/\*|\*\/|url\s*\(|@import/i', $value ) ) {
			return new WP_Error( 'invalid_link_page_css_value', 'A Link Page style contains unsafe CSS.' );
		}
		if ( in_array( $key, $colors, true ) ) {
			$hex = sanitize_hex_color( $value );
			if ( $hex ) {
				$sanitized[ $key ] = $hex;
			} elseif ( 1 === preg_match( '/^rgba?\(\s*(?:\d{1,3}\s*,\s*){2}\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/', $value ) ) {
				$sanitized[ $key ] = $value;
			} else {
				return new WP_Error( 'invalid_link_page_css_value', 'A Link Page color is invalid.' );
			}
		} elseif ( isset( $enums[ $key ] ) && in_array( $value, $enums[ $key ], true ) ) {
			$sanitized[ $key ] = $value;
		} elseif ( in_array( $key, array( '--link-page-title-font-size', '--link-page-button-radius', '--link-page-button-border-width' ), true ) && preg_match( '/^(?:0|\d+(?:\.\d+)?)(?:px|em|rem)$/', $value ) ) {
			$sanitized[ $key ] = $value;
		} elseif ( '--link-page-profile-img-size' === $key && preg_match( '/^(?:100|[1-9]?\d)%$/', $value ) ) {
			$sanitized[ $key ] = $value;
		} elseif ( in_array( $key, array( '--link-page-title-font-family', '--link-page-body-font-family' ), true ) && preg_match( '/^[a-z0-9 ,\'"-]+$/i', $value ) ) {
			$sanitized[ $key ] = $value;
		} elseif ( '--link-page-image-position' === $key && preg_match( '/^(?:left|center|right)(?:\s+(?:top|center|bottom))?$/', $value ) ) {
			$sanitized[ $key ] = $value;
		} elseif ( '--link-page-background-image-url' === $key && '' === $value ) {
			$sanitized[ $key ] = '';
		} else {
			return new WP_Error( 'unsupported_link_page_css_var', 'A Link Page style property or value is unsupported.' );
		}
	}
	return $sanitized;
}

/** Sanitize generic settings into save keys. */
function ec_sanitize_link_page_settings( $settings ) {
	if ( ! is_array( $settings ) ) {
		return new WP_Error( 'invalid_link_page_settings', 'Link Page settings must be an array.' );
	}
	$sanitized = array();
	foreach ( array( 'link_expiration_enabled', 'redirect_enabled', 'youtube_embed_enabled' ) as $field ) {
		if ( array_key_exists( $field, $settings ) ) {
			$sanitized[ $field ] = $settings[ $field ] ? '1' : '0';
		}
	}
	foreach ( array( 'redirect_target_url', 'meta_pixel_id', 'google_tag_id', 'google_tag_manager_id', 'social_icons_position', 'profile_image_shape' ) as $field ) {
		if ( array_key_exists( $field, $settings ) ) {
			$value = wp_unslash( (string) $settings[ $field ] );
			if ( 'redirect_target_url' === $field ) {
				$url = esc_url_raw( $value, array( 'http', 'https' ) );
				if ( '' !== $value && ( ! $url || ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) ) {
					return new WP_Error( 'invalid_link_page_redirect_url', 'The Link Page redirect target must use HTTP or HTTPS.' );
				}
				$sanitized[ $field ] = $url;
			} else {
				$sanitized[ $field ] = sanitize_text_field( $value );
			}
		}
	}
	return $sanitized;
}

/** Read owner-neutral persisted data without writing defaults. */
function ec_read_link_page_persistence( $link_page_id, $overrides = array() ) {
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $overrides ) {
				return ec_read_link_page_persistence( $link_page_id, $overrides );
			}
		);
	}
	$link_page_id = absint( $link_page_id );
	if ( ! $link_page_id || EC_LINK_PAGE_POST_TYPE !== get_post_type( $link_page_id ) ) {
		return new WP_Error( 'invalid_link_page', 'Invalid Link Page ID.' );
	}
	$styles                              = get_post_meta( $link_page_id, '_link_page_custom_css_vars', true );
	$links                               = get_post_meta( $link_page_id, '_link_page_links', true );
	$defaults                            = ec_link_page_defaults_for( 'styles' );
	$styles                              = array_merge( $defaults, is_array( $styles ) ? $styles : array() );
	$styles['--link-page-card-bg-color'] = $defaults['--link-page-card-bg-color'];
	$styles['--link-page-button-hover-text-color'] = $styles['--link-page-link-text-color'];
	$settings                                      = ec_link_page_defaults_for( 'settings' );
	$map = array(
		'link_expiration_enabled' => '_link_expiration_enabled',
		'redirect_enabled'        => '_link_page_redirect_enabled',
		'redirect_target_url'     => '_link_page_redirect_target_url',
		'youtube_embed_enabled'   => '_enable_youtube_inline_embed',
		'meta_pixel_id'           => '_link_page_meta_pixel_id',
		'google_tag_id'           => '_link_page_google_tag_id',
		'google_tag_manager_id'   => '_link_page_google_tag_manager_id',
		'social_icons_position'   => '_link_page_social_icons_position',
		'profile_image_shape'     => '_link_page_profile_img_shape',
		'background_image_id'     => '_link_page_background_image_id',
	);
	foreach ( $map as $key => $meta_key ) {
		if ( metadata_exists( 'post', $link_page_id, $meta_key ) ) {
			$value            = get_post_meta( $link_page_id, $meta_key, true );
			$settings[ $key ] = in_array( $key, array( 'link_expiration_enabled', 'redirect_enabled' ), true ) ? '1' === $value : $value;
			if ( 'youtube_embed_enabled' === $key ) {
				$settings[ $key ] = '0' !== $value;
			}
		}
	}
	$settings['overlay_enabled'] = '1' === (string) ( $styles['overlay'] ?? '1' );
	$data                        = array(
		'link_page_id'         => $link_page_id,
		'css_vars'             => $styles,
		'links'                => is_array( $links ) ? $links : array(),
		'link_sections'        => isset( $links[0]['links'] ) || empty( $links ) ? ( is_array( $links ) ? $links : array() ) : array(
			array(
				'section_title' => '',
				'links'         => $links,
			),
		),
		'bio'                  => (string) get_post_meta( $link_page_id, '_link_page_bio_text', true ),
		'settings'             => $settings,
		'background_image_id'  => absint( $settings['background_image_id'] ),
		'background_image_url' => ! empty( $settings['background_image_id'] ) ? (string) wp_get_attachment_url( absint( $settings['background_image_id'] ) ) : '',
	);
	return array_replace_recursive( $data, is_array( $overrides ) ? $overrides : array() );
}

/** Snapshot one metadata key for compensation. */
function ec_snapshot_link_page_meta( $link_page_id, $meta_key ) {
	return array(
		'exists' => metadata_exists( 'post', $link_page_id, $meta_key ),
		'value'  => get_post_meta( $link_page_id, $meta_key, true ),
	);
}

/** Write or delete one metadata key and verify its final state. */
function ec_write_link_page_meta( $link_page_id, $meta_key, $value, $delete = false ) {
	if ( $delete ) {
		if ( ! metadata_exists( 'post', $link_page_id, $meta_key ) ) {
			return true;
		}
		$result = delete_post_meta( $link_page_id, $meta_key );
		return false !== $result && ! metadata_exists( 'post', $link_page_id, $meta_key );
	}
	$current_exists = metadata_exists( 'post', $link_page_id, $meta_key );
	$current        = $current_exists ? get_post_meta( $link_page_id, $meta_key, true ) : null;
	if ( $current_exists && $current === $value ) {
		return true;
	}
	$result = update_post_meta( $link_page_id, $meta_key, $value );
	return false !== $result && metadata_exists( 'post', $link_page_id, $meta_key ) && get_post_meta( $link_page_id, $meta_key, true ) === $value;
}

/** Restore and verify metadata snapshots after a failed mutation. */
function ec_restore_link_page_meta_snapshots( $link_page_id, $snapshots ) {
	$restored = true;
	foreach ( $snapshots as $meta_key => $snapshot ) {
		$success  = ec_write_link_page_meta( $link_page_id, $meta_key, $snapshot['value'], ! $snapshot['exists'] );
		$restored = $success && $restored;
	}
	return $restored;
}

/** Return a primary save error unless compensation itself failed. */
function ec_compensate_link_page_save_error( $link_page_id, $snapshots, $error ) {
	if ( ec_restore_link_page_meta_snapshots( $link_page_id, $snapshots ) ) {
		return $error;
	}
	return new WP_Error( 'link_page_save_compensation_failed', 'The failed Link Page save could not be compensated.', array( 'cause' => $error->get_error_code() ) );
}

/** Request the trusted Extra Chill Cache internal post purge contract once. */
function ec_purge_link_page_after_mutation( $link_page_id ) {
	do_action( 'extrachill_cache_purge_post', absint( $link_page_id ) );
}

/** Persist only generic Link Page fields. */
function ec_save_link_page_persistence( $link_page_id, $save_data ) {
	return ec_save_link_page_persistence_composed( $link_page_id, $save_data, '__return_true' );
}

/** Persist generic fields and finalize owner state under one exact page lock. */
function ec_save_link_page_persistence_composed( $link_page_id, $save_data, $finalizer ) {
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id, $save_data, $finalizer ) {
				return ec_save_link_page_persistence_composed( $link_page_id, $save_data, $finalizer );
			}
		);
	}
	$link_page_id = absint( $link_page_id );
	if ( ! $link_page_id || EC_LINK_PAGE_POST_TYPE !== get_post_type( $link_page_id ) || ! is_array( $save_data ) ) {
		return new WP_Error( 'invalid_link_page', 'Invalid Link Page save request.' );
	}
	if ( ! is_callable( $finalizer ) ) {
		return new WP_Error( 'invalid_link_page_mutation_finalizer', 'The Link Page mutation finalizer is invalid.' );
	}
	return ec_with_link_page_lock_scope(
		$link_page_id,
		static function () use ( $link_page_id, $save_data, $finalizer ) {
			return ec_save_link_page_persistence_composed_locked( $link_page_id, $save_data, $finalizer );
		}
	);
}

/** Persist generic fields while the exact per-page advisory lock is held. */
function ec_save_link_page_persistence_locked( $link_page_id, $save_data ) {
	return ec_save_link_page_persistence_composed_locked( $link_page_id, $save_data, '__return_true' );
}

/** Persist and finalize a composed save while the exact page lock is held. */
function ec_save_link_page_persistence_composed_locked( $link_page_id, $save_data, $finalizer ) {
	$meta_keys = array(
		'links'                   => '_link_page_links',
		'css_vars'                => '_link_page_custom_css_vars',
		'bio'                     => '_link_page_bio_text',
		'link_expiration_enabled' => '_link_expiration_enabled',
		'redirect_enabled'        => '_link_page_redirect_enabled',
		'redirect_target_url'     => '_link_page_redirect_target_url',
		'youtube_embed_enabled'   => '_enable_youtube_inline_embed',
		'meta_pixel_id'           => '_link_page_meta_pixel_id',
		'google_tag_id'           => '_link_page_google_tag_id',
		'google_tag_manager_id'   => '_link_page_google_tag_manager_id',
		'social_icons_position'   => '_link_page_social_icons_position',
		'profile_image_shape'     => '_link_page_profile_img_shape',
		'background_image_id'     => '_link_page_background_image_id',
	);
	$touched   = array_intersect_key( $meta_keys, $save_data );
	if ( array_key_exists( 'links', $save_data ) ) {
		$touched += ec_link_page_id_meta_keys();
	}
	$snapshots = array();
	foreach ( $touched as $meta_key ) {
		$snapshots[ $meta_key ] = ec_snapshot_link_page_meta( $link_page_id, $meta_key );
	}
	$writes = array();
	if ( array_key_exists( 'links', $save_data ) ) {
		$stored_links = get_post_meta( $link_page_id, '_link_page_links', true );
		$links        = ec_sanitize_link_page_links_locked( $save_data['links'], $link_page_id, ec_collect_link_page_element_ids( is_array( $stored_links ) ? $stored_links : array() ) );
		if ( is_wp_error( $links ) ) {
			return ec_compensate_link_page_save_error( $link_page_id, $snapshots, $links );
		}
		$writes['_link_page_links'] = array(
			'value'  => $links,
			'delete' => false,
		);
	}
	if ( array_key_exists( 'css_vars', $save_data ) ) {
		$existing_vars = $snapshots['_link_page_custom_css_vars']['exists'] && is_array( $snapshots['_link_page_custom_css_vars']['value'] ) ? $snapshots['_link_page_custom_css_vars']['value'] : array();
		$vars          = ec_sanitize_link_page_css_vars( array_merge( $existing_vars, $save_data['css_vars'] ), $existing_vars );
		if ( is_wp_error( $vars ) ) {
			return ec_compensate_link_page_save_error( $link_page_id, $snapshots, $vars );
		}
		unset( $vars['--link-page-card-bg-color'] );
		$writes['_link_page_custom_css_vars'] = array(
			'value'  => $vars,
			'delete' => false,
		);
	}
	if ( array_key_exists( 'bio', $save_data ) ) {
		$bio                           = sanitize_text_field( wp_unslash( (string) $save_data['bio'] ) );
		$writes['_link_page_bio_text'] = array(
			'value'  => $bio,
			'delete' => '' === $bio,
		);
	}
	$settings = ec_sanitize_link_page_settings( $save_data );
	if ( is_wp_error( $settings ) ) {
		return ec_compensate_link_page_save_error( $link_page_id, $snapshots, $settings );
	}
	$map = array(
		'link_expiration_enabled' => '_link_expiration_enabled',
		'redirect_enabled'        => '_link_page_redirect_enabled',
		'redirect_target_url'     => '_link_page_redirect_target_url',
		'youtube_embed_enabled'   => '_enable_youtube_inline_embed',
		'meta_pixel_id'           => '_link_page_meta_pixel_id',
		'google_tag_id'           => '_link_page_google_tag_id',
		'google_tag_manager_id'   => '_link_page_google_tag_manager_id',
		'social_icons_position'   => '_link_page_social_icons_position',
		'profile_image_shape'     => '_link_page_profile_img_shape',
	);
	foreach ( $map as $key => $meta_key ) {
		if ( array_key_exists( $key, $settings ) ) {
			$writes[ $meta_key ] = array(
				'value'  => $settings[ $key ],
				'delete' => '' === $settings[ $key ],
			);
		}
	}
	if ( array_key_exists( 'background_image_id', $save_data ) ) {
		$image_id                                 = absint( $save_data['background_image_id'] );
		$writes['_link_page_background_image_id'] = array(
			'value'  => $image_id,
			'delete' => ! $image_id,
		);
	}
	foreach ( $writes as $meta_key => $write ) {
		if ( ! ec_write_link_page_meta( $link_page_id, $meta_key, $write['value'], $write['delete'] ) ) {
			$primary = new WP_Error( 'link_page_save_failed', 'Link Page metadata could not be persisted.', array( 'meta_key' => $meta_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Error context, not a query.
			return ec_compensate_link_page_save_error( $link_page_id, $snapshots, $primary );
		}
	}
	$persistence = ec_read_link_page_persistence( $link_page_id );
	if ( is_wp_error( $persistence ) ) {
		return ec_compensate_link_page_save_error( $link_page_id, $snapshots, $persistence );
	}
	$finalized = ec_invoke_link_page_mutation_finalizer( $finalizer, array( $link_page_id, $persistence ) );
	if ( is_wp_error( $finalized ) ) {
		return ec_compensate_link_page_save_error( $link_page_id, $snapshots, $finalized );
	}
	do_action( 'ec_link_page_persistence_saved', $link_page_id, array_keys( $writes ) );
	return $persistence;
}

/** Invoke a composed mutation finalizer without allowing storage-context leakage. */
function ec_invoke_link_page_mutation_finalizer( $finalizer, $arguments ) {
	if ( ! is_callable( $finalizer ) || ! is_array( $arguments ) ) {
		return new WP_Error( 'invalid_link_page_mutation_finalizer', 'The Link Page mutation finalizer is invalid.' );
	}
	$blog_id  = get_current_blog_id();
	$stack    = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$switched = ! empty( $GLOBALS['switched'] );
	$result   = null;
	try {
		$result = call_user_func_array( $finalizer, $arguments );
	} catch ( Throwable $throwable ) {
		$result = new WP_Error( 'link_page_mutation_finalizer_exception', 'The Link Page mutation finalizer failed with an exception.' );
	}
	$leaked   = get_current_blog_id() !== $blog_id || ( $GLOBALS['_wp_switched_stack'] ?? array() ) !== $stack || ! empty( $GLOBALS['switched'] ) !== $switched;
	$restored = ec_restore_link_pages_site_context( $blog_id, $stack, $switched );
	if ( ! $restored ) {
		return new WP_Error( 'link_page_mutation_finalizer_restore_failed', 'The Link Page mutation finalizer context could not be restored.' );
	}
	if ( $leaked ) {
		return new WP_Error( 'link_page_mutation_finalizer_context_leak', 'The Link Page mutation finalizer leaked its site context.' );
	}
	if ( true !== $result && ! is_wp_error( $result ) ) {
		return new WP_Error( 'link_page_mutation_finalizer_invalid_result', 'The Link Page mutation finalizer returned an invalid result.' );
	}
	return $result;
}

/** Remove a newly inserted page after failed ownership assignment. */
function ec_compensate_created_link_page( $link_page_id ) {
	delete_post_meta( $link_page_id, EC_LINK_PAGE_OWNER_META_KEY );
	if ( ! empty( ec_get_stored_link_page_owner_references( $link_page_id ) ) ) {
		return new WP_Error( 'link_page_creation_compensation_failed', 'The failed Link Page owner assignment could not be removed.' );
	}
	return wp_delete_post( $link_page_id, true ) ? true : new WP_Error( 'link_page_creation_compensation_failed', 'The unowned Link Page could not be removed.' );
}

/** Restore and verify a force-replaced Link Page. */
function ec_restore_replaced_link_page( $link_page_id, $owner_reference, $slug ) {
	if ( ! $link_page_id ) {
		return true;
	}
	$assigned = ec_assign_link_page_owner( $link_page_id, $owner_reference );
	$updated  = wp_update_post(
		array(
			'ID'        => $link_page_id,
			'post_name' => $slug,
		),
		true
	);
	return ! is_wp_error( $assigned )
		&& ! is_wp_error( $updated )
		&& array( $owner_reference ) === ec_get_stored_link_page_owner_references( $link_page_id )
		&& get_post_field( 'post_name', $link_page_id ) === $slug;
}

/** Preserve a creation error unless replacement compensation failed. */
function ec_compensate_link_page_creation_error( $error, $existing, $owner_reference, $slug ) {
	if ( ! $existing || ec_restore_replaced_link_page( $existing, $owner_reference, $slug ) ) {
		return $error;
	}
	return new WP_Error( 'link_page_creation_compensation_failed', 'The failed Link Page creation could not restore the previous page.', array( 'cause' => $error->get_error_code() ) );
}

/** Provision under the canonical owner lock and report whether this call won. */
function ec_provision_owned_link_page( $owner_reference, $title, $slug, $force = false, $precondition = null ) {
	return ec_provision_owned_link_page_internal( $owner_reference, $title, $slug, null, $force, $precondition );
}

/** Provision and finalize owner state before reporting successful creation. */
function ec_provision_owned_link_page_composed( $owner_reference, $title, $slug, $finalizer, $force = false, $precondition = null ) {
	if ( ! is_callable( $finalizer ) ) {
		return new WP_Error( 'invalid_link_page_mutation_finalizer', 'The Link Page mutation finalizer is invalid.' );
	}
	return ec_provision_owned_link_page_internal( $owner_reference, $title, $slug, $finalizer, $force, $precondition );
}

/** Run direct or composed provisioning under the canonical owner lock. */
function ec_provision_owned_link_page_internal( $owner_reference, $title, $slug, $finalizer, $force, $precondition ) {
	global $wpdb;
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $owner_reference, $title, $slug, $finalizer, $force, $precondition ) {
				return ec_provision_owned_link_page_internal( $owner_reference, $title, $slug, $finalizer, $force, $precondition );
			}
		);
	}
	$owner_reference = ec_normalize_link_page_owner_reference( $owner_reference );
	if ( is_wp_error( $owner_reference ) ) {
		return $owner_reference;
	}
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
		return new WP_Error( 'link_page_owner_lock_unsupported', 'Link Page provisioning requires owner advisory lock support.' );
	}
	$lock_name = 'ec_link_page_owner:' . hash( 'sha256', $owner_reference );
	$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Owner lock serializes provisioning before a page exists.
	if ( '1' !== (string) $acquired ) {
		return new WP_Error( 'link_page_owner_lock_failed', 'The Link Page owner provisioning lock could not be acquired.' );
	}
	try {
		if ( null !== $precondition ) {
			if ( ! is_callable( $precondition ) ) {
				return new WP_Error( 'invalid_link_page_provision_precondition', 'The Link Page provisioning precondition is invalid.' );
			}
			$allowed = ec_invoke_link_page_provision_precondition( $precondition, $owner_reference );
			if ( true !== $allowed ) {
				return $allowed;
			}
		}
		$existing = ec_get_link_page_id_for_owner( $owner_reference );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( $existing && ! $force ) {
			if ( null !== $finalizer ) {
				$finalized = ec_with_link_page_lock_scope(
					$existing,
					static function () use ( $existing, $owner_reference, $finalizer ) {
						return ec_invoke_link_page_mutation_finalizer( $finalizer, array( (int) $existing, $owner_reference ) );
					},
					'combined'
				);
				if ( is_wp_error( $finalized ) ) {
					return $finalized;
				}
			}
			return array(
				'link_page_id' => (int) $existing,
				'created'      => false,
			);
		}
		$previous_slug = $existing && $force ? (string) get_post_field( 'post_name', $existing ) : '';
		$result        = ec_prepare_owned_link_page_creation( $owner_reference, $title, $slug, $force );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( null !== $finalizer ) {
			$finalized = ec_with_link_page_lock_scope(
				$result,
				static function () use ( $result, $owner_reference, $finalizer ) {
					return ec_invoke_link_page_mutation_finalizer( $finalizer, array( (int) $result, $owner_reference ) );
				},
				'combined'
			);
			if ( is_wp_error( $finalized ) ) {
				$compensated = ec_compensate_created_link_page( $result );
				if ( is_wp_error( $compensated ) ) {
					return new WP_Error( 'link_page_creation_compensation_failed', 'The failed Link Page creation could not be compensated.', array( 'cause' => $finalized->get_error_code() ) );
				}
				return ec_compensate_link_page_creation_error( $finalized, $existing, $owner_reference, $previous_slug );
			}
		}
		do_action( 'ec_owned_link_page_created', $result, $owner_reference, (bool) $force );
		return array(
			'link_page_id' => (int) $result,
			'created'      => true,
		);
	} finally {
		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Paired owner lock release.
		if ( '1' !== (string) $released ) {
			do_action( 'ec_link_page_owner_lock_release_failed', $owner_reference );
		}
	}
}

/** Invoke an owner precondition without allowing storage-context leakage. */
function ec_invoke_link_page_provision_precondition( $precondition, $owner_reference ) {
	$blog_id  = get_current_blog_id();
	$stack    = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$switched = ! empty( $GLOBALS['switched'] );
	$result   = null;
	$error    = null;
	try {
		$result = call_user_func( $precondition, $owner_reference );
	} catch ( Throwable $throwable ) {
		$error = new WP_Error( 'link_page_provision_precondition_exception', 'The Link Page provisioning precondition failed with an exception.' );
	}
	$leaked   = get_current_blog_id() !== $blog_id || ( $GLOBALS['_wp_switched_stack'] ?? array() ) !== $stack || ! empty( $GLOBALS['switched'] ) !== $switched;
	$restored = ec_restore_link_pages_site_context( $blog_id, $stack, $switched );
	if ( ! $restored ) {
		return new WP_Error( 'link_page_provision_precondition_restore_failed', 'The Link Page provisioning precondition context could not be restored.' );
	}
	if ( $leaked ) {
		return new WP_Error( 'link_page_provision_precondition_context_leak', 'The Link Page provisioning precondition leaked its site context.' );
	}
	if ( $error ) {
		return $error;
	}
	if ( true !== $result && ! is_wp_error( $result ) ) {
		return new WP_Error( 'link_page_provision_precondition_invalid_result', 'The Link Page provisioning precondition returned an invalid result.' );
	}
	return $result;
}

/** Preserve the historical integer-return creation contract. */
function ec_create_owned_link_page( $owner_reference, $title, $slug, $force = false ) {
	$result = ec_provision_owned_link_page( $owner_reference, $title, $slug, $force );
	return is_wp_error( $result ) ? $result : (int) $result['link_page_id'];
}

/** Create and assign a page while the canonical owner lock is held. */
function ec_create_owned_link_page_unlocked( $owner_reference, $title, $slug, $force = false ) {
	$result = ec_prepare_owned_link_page_creation( $owner_reference, $title, $slug, $force );
	if ( ! is_wp_error( $result ) ) {
		do_action( 'ec_owned_link_page_created', $result, ec_normalize_link_page_owner_reference( $owner_reference ), (bool) $force );
	}
	return $result;
}

/** Create and assign a page without emitting its deferred success event. */
function ec_prepare_owned_link_page_creation( $owner_reference, $title, $slug, $force = false ) {
	$owner_reference = ec_normalize_link_page_owner_reference( $owner_reference );
	$title           = sanitize_text_field( (string) $title );
	$slug            = sanitize_title( (string) $slug );
	if ( is_wp_error( $owner_reference ) ) {
		return $owner_reference;
	}
	if ( '' === $title || '' === $slug ) {
		return new WP_Error( 'incomplete_link_page_data', 'A Link Page title and slug are required.' );
	}
	$existing = ec_get_link_page_id_for_owner( $owner_reference );
	if ( is_wp_error( $existing ) ) {
		return $existing;
	}
	if ( $existing && ! $force ) {
		return $existing;
	}
	$previous_slug = '';
	if ( $existing && $force ) {
		$previous_slug = (string) get_post_field( 'post_name', $existing );
		$renamed       = wp_update_post(
			array(
				'ID'        => $existing,
				'post_name' => $previous_slug . '-replaced-' . $existing,
			),
			true
		);
		if ( is_wp_error( $renamed ) ) {
			return $renamed;
		}
	}
	$slug_matches = get_posts(
		array(
			'name'           => $slug,
			'post_type'      => EC_LINK_PAGE_POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => 2,
			'fields'         => 'ids',
		)
	);
	if ( ! empty( $slug_matches ) ) {
		return ec_compensate_link_page_creation_error( new WP_Error( 'link_page_slug_conflict', 'The requested Link Page slug is already in use.' ), $existing, $owner_reference, $previous_slug );
	}
	$link_page_id = wp_insert_post(
		array(
			'post_type'   => EC_LINK_PAGE_POST_TYPE,
			'post_title'  => $title,
			'post_name'   => $slug,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $link_page_id ) || ! $link_page_id ) {
		$error = is_wp_error( $link_page_id ) ? $link_page_id : new WP_Error( 'link_page_creation_failed', 'The Link Page could not be created.' );
		return ec_compensate_link_page_creation_error( $error, $existing, $owner_reference, $previous_slug );
	}
	$created = get_post( $link_page_id );
	if ( ! $created || $slug !== $created->post_name ) {
		$compensated = ec_compensate_created_link_page( $link_page_id );
		$error       = is_wp_error( $compensated ) ? $compensated : new WP_Error( 'link_page_slug_conflict', 'WordPress changed the requested Link Page slug.' );
		return ec_compensate_link_page_creation_error( $error, $existing, $owner_reference, $previous_slug );
	}
	$assigned = ec_assign_link_page_owner( $link_page_id, $owner_reference, $force ? (int) $existing : 0 );
	if ( is_wp_error( $assigned ) ) {
		$compensated = ec_compensate_created_link_page( $link_page_id );
		$error       = is_wp_error( $compensated ) ? $compensated : new WP_Error( 'link_page_owner_assignment_failed', 'The Link Page owner could not be assigned.' );
		return ec_compensate_link_page_creation_error( $error, $existing, $owner_reference, $previous_slug );
	}
	if ( $existing && $force ) {
		delete_post_meta( $existing, EC_LINK_PAGE_OWNER_META_KEY, $owner_reference );
		if ( ! empty( ec_get_stored_link_page_owner_references( $existing ) ) ) {
			$compensated = ec_compensate_created_link_page( $link_page_id );
			$error       = is_wp_error( $compensated ) ? $compensated : new WP_Error( 'link_page_previous_owner_detach_failed', 'The previous Link Page owner could not be detached.' );
			return ec_compensate_link_page_creation_error( $error, $existing, $owner_reference, $previous_slug );
		}
	}
	if ( ! ec_write_link_page_meta( $link_page_id, '_link_page_custom_css_vars', ec_link_page_defaults_for( 'styles' ) ) ) {
		$compensated = ec_compensate_created_link_page( $link_page_id );
		$error       = is_wp_error( $compensated ) ? $compensated : new WP_Error( 'link_page_default_styles_failed', 'The Link Page default styles could not be persisted.' );
		return ec_compensate_link_page_creation_error( $error, $existing, $owner_reference, $previous_slug );
	}
	return (int) $link_page_id;
}

/** Remove expired links from all opted-in pages. */
function ec_cleanup_expired_link_page_links() {
	$storage_blog_id = ec_get_link_page_storage_blog_id();
	if ( ! $storage_blog_id ) {
		return new WP_Error( 'link_page_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
	}
	if ( get_current_blog_id() !== $storage_blog_id ) {
		return ec_with_link_page_storage_blog( 'ec_cleanup_expired_link_page_links' );
	}
	$ids = get_posts(
		array(
			'post_type'      => EC_LINK_PAGE_POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	$now = current_datetime()->getTimestamp();
	foreach ( $ids as $link_page_id ) {
		$data = ec_read_link_page_persistence( $link_page_id );
		if ( is_wp_error( $data ) || empty( $data['settings']['link_expiration_enabled'] ) ) {
			continue;
		}
		$changed  = false;
		$sections = isset( $data['links'][0]['links'] ) || empty( $data['links'] );
		$filter   = static function ( $link ) use ( $now, &$changed ) {
			if ( empty( $link['expires_at'] ) ) {
				return true;
			}
			$expires = strtotime( (string) $link['expires_at'] );
			$keep    = false !== $expires && $now < $expires;
			$changed = $changed || ! $keep;
			return $keep;
		};
		if ( $sections ) {
			foreach ( $data['links'] as &$section ) {
				$section['links'] = array_values( array_filter( $section['links'] ?? array(), $filter ) );
			}
			unset( $section );
			$data['links'] = array_values(
				array_filter(
					$data['links'],
					static function ( $section ) {
						return ! empty( $section['links'] );
					}
				)
			);
		} else {
			$data['links'] = array_values( array_filter( $data['links'], $filter ) );
		}
		if ( $changed ) {
			$result = ec_save_link_page_persistence( $link_page_id, array( 'links' => $data['links'] ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			ec_purge_link_page_after_mutation( $link_page_id );
		}
	}
	return true;
}

/** Purge the canonical public page before its post is deleted. */
function ec_purge_link_page_before_delete( $post_id ) {
	if ( EC_LINK_PAGE_POST_TYPE === get_post_type( $post_id ) ) {
		ec_purge_link_page_after_mutation( $post_id );
	}
}

/** Schedule generic expiration cleanup for the current site. */
function ec_schedule_link_page_expiration_cleanup() {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'ec_link_pages_cleanup_expired_links' ) ) {
		wp_schedule_event( time(), 'hourly', 'ec_link_pages_cleanup_expired_links' );
	}
}

/** Remove generic expiration cleanup from the current site. */
function ec_unschedule_link_page_expiration_cleanup() {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'ec_link_pages_cleanup_expired_links' );
	}
}

add_action( 'ec_link_pages_cleanup_expired_links', 'ec_cleanup_expired_link_page_links' );
add_action( 'before_delete_post', 'ec_purge_link_page_before_delete', 5 );
add_action( 'ec_link_page_save', 'ec_purge_link_page_after_mutation', 20 );
