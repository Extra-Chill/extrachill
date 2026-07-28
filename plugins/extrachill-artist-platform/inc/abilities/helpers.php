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
 * Delegated principals are bounded to their acting user and capability ceiling.
 * Direct WP-CLI and Action Scheduler execution retain the existing trusted path.
 *
 * @return bool
 */
function extrachill_artist_platform_ability_admin_permission() {
	$actor = extrachill_artist_platform_ability_actor( 'manage_network_options' );

	return $actor['trusted_system'] || ( $actor['user_id'] && user_can( $actor['user_id'], 'manage_network_options' ) );
}

/**
 * Resolve the trusted actor for an ability execution.
 *
 * A resolved Agents API principal takes precedence over the ambient WordPress
 * session. This prevents a delegated runtime from inheriting the capabilities
 * of a more privileged logged-in user.
 *
 * @param string $capability Capability required by a delegated principal ceiling.
 * @return array{user_id:int,trusted_system:bool}
 */
function extrachill_artist_platform_ability_actor( $capability ) {
	$principal_class = '\\AgentsAPI\\AI\\WP_Agent_Execution_Principal';
	$principal       = null;

	if ( class_exists( $principal_class ) ) {
		$request_context = ( defined( 'WP_CLI' ) && WP_CLI ) ? 'cli' : ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ? 'cron' : 'rest' );
		try {
			$principal = $principal_class::resolve( array( 'request_context' => $request_context ) );
		} catch ( Throwable $throwable ) {
			return array(
				'user_id'        => 0,
				'trusted_system' => false,
			);
		}
	}

	if ( $principal ) {
		$trusted_system = 0 === (int) $principal->acting_user_id
			&& 'system' === (string) $principal->auth_source
			&& in_array( (string) $principal->request_context, array( 'cli', 'cron' ), true );

		if ( $trusted_system ) {
			return array(
				'user_id'        => 0,
				'trusted_system' => true,
			);
		}

		$user_id = (int) $principal->acting_user_id;
		$ceiling = $principal->capability_ceiling;
		if ( $user_id <= 0 ) {
			return array(
				'user_id'        => 0,
				'trusted_system' => false,
			);
		}

		if ( $ceiling instanceof WP_Agent_Capability_Ceiling ) {
			if ( (int) $ceiling->user_id !== $user_id || ! $ceiling->allows_capability( $capability ) ) {
				return array(
					'user_id'        => 0,
					'trusted_system' => false,
				);
			}
		}

		return array(
			'user_id'        => $user_id,
			'trusted_system' => false,
		);
	}

	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( class_exists( 'ActionScheduler' ) && did_action( 'action_scheduler_before_execute' ) ) ) {
		return array(
			'user_id'        => get_current_user_id(),
			'trusted_system' => true,
		);
	}

	return array(
		'user_id'        => get_current_user_id(),
		'trusted_system' => false,
	);
}

/**
 * Return the trusted acting user rather than an ambient delegated session.
 *
 * @return int
 */
function extrachill_artist_platform_ability_acting_user_id() {
	$actor = extrachill_artist_platform_ability_actor( 'manage_artist' );

	return (int) $actor['user_id'];
}

/**
 * Permission callback for abilities that operate on an owned artist.
 *
 * @param array $input Ability input containing artist_id or id.
 * @return bool
 */
function extrachill_artist_platform_ability_artist_permission( $input ) {
	$artist_id = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : absint( $input['id'] ?? 0 );
	$actor     = extrachill_artist_platform_ability_actor( 'manage_artist' );

	if ( isset( $input['user_id'] ) && absint( $input['user_id'] ) !== (int) $actor['user_id'] && ! $actor['trusted_system'] ) {
		return false;
	}

	if ( $actor['trusted_system'] ) {
		return (bool) $artist_id;
	}

	return $artist_id
		&& function_exists( 'ec_user_can' )
		&& ec_user_can(
			'manage_artist',
			array(
				'artist_id' => $artist_id,
				'user_id'   => (int) $actor['user_id'],
			)
		);
}

/**
 * Permission callback for creating an artist for a user.
 *
 * @param array $input Ability input containing an optional user_id.
 * @return bool
 */
function extrachill_artist_platform_ability_create_permission( $input ) {
	$actor          = extrachill_artist_platform_ability_actor( 'create_artist_profile' );
	$actor_user_id  = (int) $actor['user_id'];
	$target_user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : $actor_user_id;

	if ( $actor['trusted_system'] ) {
		return $target_user_id > 0;
	}

	if ( $actor_user_id <= 0 || $target_user_id <= 0 ) {
		return false;
	}

	if ( $actor_user_id !== $target_user_id ) {
		return user_can( $actor_user_id, 'manage_options' ) || user_can( $actor_user_id, 'manage_network_options' );
	}

	return function_exists( 'ec_user_can' )
		&& ec_user_can( 'create_artist_profile', array( 'user_id' => $actor_user_id ) );
}

/**
 * Confirm an explicitly supplied link page belongs to the authorized artist.
 *
 * @param int $artist_id    Artist profile post ID.
 * @param int $link_page_id Link page post ID.
 * @return bool
 */
function extrachill_artist_platform_ability_link_page_belongs_to_artist( $artist_id, $link_page_id ) {
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : 0;
	$did_switch     = $artist_blog_id && get_current_blog_id() !== (int) $artist_blog_id;

	if ( $did_switch ) {
		switch_to_blog( $artist_blog_id );
	}

	try {
		return 'artist_link_page' === get_post_type( $link_page_id )
			&& (int) get_post_meta( $link_page_id, '_associated_artist_profile_id', true ) === (int) $artist_id;
	} finally {
		if ( $did_switch ) {
			restore_current_blog();
		}
	}
}

/**
 * Permission callback for the private local-support producer query.
 *
 * @param array $input Ability input.
 * @return bool
 */
function extrachill_artist_platform_ability_local_support_producer_permission( $input ) {
	if ( extrachill_artist_platform_ability_admin_permission() ) {
		return true;
	}

	$producer = sanitize_key( $input['producer'] ?? '' );
	$scene    = extrachill_artist_platform_resolve_local_support_scene( $input['scene_slug'] ?? '' );

	return '' !== $producer
		&& ! is_wp_error( $scene )
		&& (bool) apply_filters( 'extrachill_artist_platform_local_support_producer_authorized', false, $producer, $scene );
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
			if ( ! is_string( $value ) ) {
				continue;
			}

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
		'bio',
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
