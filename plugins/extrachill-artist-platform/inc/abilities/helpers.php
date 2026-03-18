<?php
/**
 * Shared helpers for artist platform abilities.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Permission callback for artist platform abilities that require management access.
 *
 * Allows WP-CLI, Action Scheduler, and network admins unconditionally.
 * For other contexts the caller must supply artist_id via the input array
 * so that ec_can_manage_artist() can check membership.
 *
 * @return bool
 */
function extrachill_artist_platform_ability_admin_permission() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}

	if ( class_exists( 'ActionScheduler' ) && did_action( 'action_scheduler_before_execute' ) ) {
		return true;
	}

	return current_user_can( 'manage_network_options' );
}

/**
 * Permission callback for read-only artist platform abilities.
 *
 * Read abilities are available to WP-CLI, Action Scheduler, network admins,
 * and any logged-in user who can manage the specified artist.
 *
 * @return bool
 */
function extrachill_artist_platform_ability_read_permission() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}

	if ( class_exists( 'ActionScheduler' ) && did_action( 'action_scheduler_before_execute' ) ) {
		return true;
	}

	return current_user_can( 'manage_network_options' ) || is_user_logged_in();
}

/**
 * ID meta key map for link page entities.
 *
 * Maps entity type to the post meta counter key stored on the link page.
 *
 * @return array<string, string>
 */
function extrachill_artist_platform_id_meta_key_map() {
	return array(
		'section' => '_ec_section_id_counter',
		'link'    => '_ec_link_id_counter',
		'social'  => '_ec_social_id_counter',
	);
}

/**
 * Get the next available ID for a given entity type on a link page.
 *
 * @param int    $link_page_id Link page post ID.
 * @param string $type         Entity type: section|link|social.
 * @return string Generated ID in format "{link_page_id}-{type}-{counter}".
 */
function extrachill_artist_platform_get_next_id( $link_page_id, $type ) {
	$map = extrachill_artist_platform_id_meta_key_map();
	if ( ! isset( $map[ $type ] ) ) {
		return '';
	}

	$meta_key   = $map[ $type ];
	$next_index = (int) get_post_meta( $link_page_id, $meta_key, true );
	$next_index++;
	update_post_meta( $link_page_id, $meta_key, $next_index );

	return sprintf( '%d-%s-%d', $link_page_id, $type, $next_index );
}

/**
 * Check if an ID needs assignment (empty or temp placeholder).
 *
 * @param string $id Input ID.
 * @return bool True if the ID needs a persistent assignment.
 */
function extrachill_artist_platform_needs_id_assignment( $id ) {
	return empty( $id ) || str_starts_with( $id, 'temp-' );
}

/**
 * Sync counter from an existing ID to prevent collisions.
 *
 * @param int    $link_page_id Link page post ID.
 * @param string $type         Entity type.
 * @param string $id           Existing ID to sync from.
 */
function extrachill_artist_platform_sync_counter_from_id( $link_page_id, $type, $id ) {
	$map = extrachill_artist_platform_id_meta_key_map();
	if ( ! isset( $map[ $type ] ) ) {
		return;
	}

	$pattern = sprintf( '/^(%d)\-%s\-(\d+)$/', (int) $link_page_id, preg_quote( $type, '/' ) );
	if ( 1 !== preg_match( $pattern, $id, $matches ) ) {
		return;
	}

	$current  = (int) $matches[2];
	$meta_key = $map[ $type ];
	$stored   = (int) get_post_meta( $link_page_id, $meta_key, true );

	if ( $current > $stored ) {
		update_post_meta( $link_page_id, $meta_key, $current );
	}
}

/**
 * Sanitize links array with ID assignment.
 *
 * @param array $links        Raw links data (array of sections with nested links).
 * @param int   $link_page_id Link page post ID for counter-based ID generation.
 * @return array Sanitized links array.
 */
function extrachill_artist_platform_sanitize_links( $links, $link_page_id = 0 ) {
	if ( ! is_array( $links ) ) {
		return array();
	}

	$sanitized = array();

	foreach ( $links as $section ) {
		if ( ! is_array( $section ) ) {
			continue;
		}

		$section_id = isset( $section['id'] ) ? sanitize_text_field( $section['id'] ) : '';
		if ( $link_page_id && extrachill_artist_platform_needs_id_assignment( $section_id ) ) {
			$section_id = extrachill_artist_platform_get_next_id( $link_page_id, 'section' );
		} elseif ( $link_page_id ) {
			extrachill_artist_platform_sync_counter_from_id( $link_page_id, 'section', $section_id );
		}

		$sanitized_section = array(
			'id'            => $section_id,
			'section_title' => isset( $section['section_title'] ) ? sanitize_text_field( wp_unslash( $section['section_title'] ) ) : '',
			'links'         => array(),
		);

		if ( isset( $section['links'] ) && is_array( $section['links'] ) ) {
			foreach ( $section['links'] as $link ) {
				if ( ! is_array( $link ) ) {
					continue;
				}

				$link_id = isset( $link['id'] ) ? sanitize_text_field( $link['id'] ) : '';
				if ( $link_page_id && extrachill_artist_platform_needs_id_assignment( $link_id ) ) {
					$link_id = extrachill_artist_platform_get_next_id( $link_page_id, 'link' );
				} elseif ( $link_page_id ) {
					extrachill_artist_platform_sync_counter_from_id( $link_page_id, 'link', $link_id );
				}

				$sanitized_link = array(
					'id'        => $link_id,
					'link_text' => isset( $link['link_text'] ) ? sanitize_text_field( wp_unslash( $link['link_text'] ) ) : '',
					'link_url'  => isset( $link['link_url'] ) ? esc_url_raw( wp_unslash( $link['link_url'] ) ) : '',
				);

				if ( isset( $link['expires_at'] ) && ! empty( $link['expires_at'] ) ) {
					$sanitized_link['expires_at'] = sanitize_text_field( wp_unslash( $link['expires_at'] ) );
				}

				$sanitized_section['links'][] = $sanitized_link;
			}
		}

		$sanitized[] = $sanitized_section;
	}

	return $sanitized;
}

/**
 * Sanitize CSS variables for link page styles.
 *
 * @param array $vars Raw CSS variables.
 * @return array Sanitized CSS variables.
 */
function extrachill_artist_platform_sanitize_css_vars( $vars ) {
	if ( ! is_array( $vars ) ) {
		return array();
	}

	$sanitized = array();

	foreach ( $vars as $key => $value ) {
		if ( strpos( $key, '--link-page-' ) !== 0 && $key !== 'overlay' ) {
			continue;
		}

		if ( strpos( $key, 'color' ) !== false || strpos( $key, '-bg' ) !== false ) {
			// Accept hex (#rgb, #rrggbb), rgb(), rgba(), hsl(), hsla() — not just hex.
			$hex = sanitize_hex_color( $value );
			if ( $hex ) {
				$sanitized[ $key ] = $hex;
			} elseif ( preg_match( '/^(rgba?|hsla?)\(\s*[\d.,\s%]+\)$/', $value ) ) {
				$sanitized[ $key ] = $value;
			} else {
				// Skip invalid color values silently.
				continue;
			}
		} else {
			$sanitized[ $key ] = sanitize_text_field( wp_unslash( $value ) );
		}
	}

	return $sanitized;
}

/**
 * Sanitize link page settings.
 *
 * @param array $settings Raw settings.
 * @return array Sanitized settings as flat keys for ec_handle_link_page_save().
 */
function extrachill_artist_platform_sanitize_link_settings( $settings ) {
	if ( ! is_array( $settings ) ) {
		return array();
	}

	$sanitized = array();

	$bool_fields = array(
		'link_expiration_enabled',
		'redirect_enabled',
		'youtube_embed_enabled',
	);

	foreach ( $bool_fields as $field ) {
		if ( isset( $settings[ $field ] ) ) {
			$sanitized[ $field ] = $settings[ $field ] ? '1' : '0';
		}
	}

	$string_fields = array(
		'redirect_target_url',
		'meta_pixel_id',
		'google_tag_id',
		'google_tag_manager_id',
		'subscribe_display_mode',
		'subscribe_description',
		'social_icons_position',
		'profile_image_shape',
	);

	foreach ( $string_fields as $field ) {
		if ( isset( $settings[ $field ] ) ) {
			$sanitized[ $field ] = sanitize_text_field( wp_unslash( $settings[ $field ] ) );
		}
	}

	return $sanitized;
}

/**
 * Sanitize social links array with ID assignment.
 *
 * @param array $socials       Raw social links data.
 * @param int   $link_page_id  Link page post ID for counter-based ID generation.
 * @return array Sanitized social links array.
 */
function extrachill_artist_platform_sanitize_socials( $socials, $link_page_id = 0 ) {
	if ( ! is_array( $socials ) ) {
		return array();
	}

	$sanitized = array();

	foreach ( $socials as $social ) {
		if ( ! is_array( $social ) ) {
			continue;
		}

		$social_id = isset( $social['id'] ) ? sanitize_text_field( $social['id'] ) : '';
		if ( $link_page_id && extrachill_artist_platform_needs_id_assignment( $social_id ) ) {
			$social_id = extrachill_artist_platform_get_next_id( $link_page_id, 'social' );
		} elseif ( $link_page_id ) {
			extrachill_artist_platform_sync_counter_from_id( $link_page_id, 'social', $social_id );
		}

		$type = isset( $social['type'] ) ? sanitize_text_field( wp_unslash( $social['type'] ) ) : '';
		$url  = isset( $social['url'] ) ? esc_url_raw( wp_unslash( $social['url'] ) ) : '';

		if ( empty( $type ) || empty( $url ) ) {
			continue;
		}

		$sanitized[] = array(
			'id'   => $social_id,
			'type' => $type,
			'url'  => $url,
		);
	}

	return $sanitized;
}
