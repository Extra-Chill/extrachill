<?php
/**
 * Owner-neutral social links primitive.
 *
 * One type catalog, one sanitizer and one renderer shared by every surface
 * that shows social links: Link Pages own theirs, and any other consumer
 * (community profiles, owner profiles) keeps its own storage but validates
 * and renders through these functions. Nothing here knows about a specific
 * owner or site.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

/** Return the shared catalog of known social link types. */
function ec_social_link_types() {
	static $types = null;
	if ( null === $types ) {
		$types = array(
			'apple_music' => array(
				'label' => 'Apple Music',
				'icon'  => 'fab fa-apple',
			),
			'bandcamp'    => array(
				'label' => 'Bandcamp',
				'icon'  => 'fab fa-bandcamp',
			),
			'bluesky'     => array(
				'label' => 'Bluesky',
				'icon'  => 'fa-brands fa-bluesky',
			),
			'custom'      => array(
				'label'            => 'Custom Link',
				'icon'             => 'fas fa-link',
				'has_custom_label' => true,
			),
			'facebook'    => array(
				'label' => 'Facebook',
				'icon'  => 'fab fa-facebook-f',
			),
			'github'      => array(
				'label' => 'GitHub',
				'icon'  => 'fab fa-github',
			),
			'instagram'   => array(
				'label' => 'Instagram',
				'icon'  => 'fab fa-instagram',
			),
			'patreon'     => array(
				'label' => 'Patreon',
				'icon'  => 'fab fa-patreon',
			),
			'pinterest'   => array(
				'label' => 'Pinterest',
				'icon'  => 'fab fa-pinterest',
			),
			'soundcloud'  => array(
				'label' => 'SoundCloud',
				'icon'  => 'fab fa-soundcloud',
			),
			'spotify'     => array(
				'label' => 'Spotify',
				'icon'  => 'fab fa-spotify',
			),
			'substack'    => array(
				'label' => 'Substack',
				'icon'  => 'fab fa-substack',
			),
			'tiktok'      => array(
				'label' => 'TikTok',
				'icon'  => 'fab fa-tiktok',
			),
			'twitch'      => array(
				'label' => 'Twitch',
				'icon'  => 'fab fa-twitch',
			),
			'twitter_x'   => array(
				'label' => 'Twitter / X',
				'icon'  => 'fab fa-x-twitter',
			),
			'venmo'       => array(
				'label' => 'Venmo',
				'icon'  => 'fab fa-venmo',
			),
			'website'     => array(
				'label' => 'Website',
				'icon'  => 'fas fa-globe',
			),
			'youtube'     => array(
				'label' => 'YouTube',
				'icon'  => 'fab fa-youtube',
			),
		);
		/**
		 * Filter the shared social link type catalog.
		 *
		 * @param array<string,array{label:string,icon:string,has_custom_label?:bool}> $types Types keyed by slug.
		 */
		$types = apply_filters( 'ec_social_link_types', $types );
		// Historical filter name, kept for existing integrations.
		$types = apply_filters( 'ec_link_page_social_types', $types );
	}
	return $types;
}

/** Back-compat alias: the Link Page catalog is the shared catalog. */
function ec_link_page_social_types() {
	return ec_social_link_types();
}

/**
 * Validate and sanitize a social links array for any consumer.
 *
 * Returns the sanitized list or a WP_Error naming the first invalid entry.
 * Entries keep `id`, `type`, `url` and, for types that allow it,
 * `custom_label`. A URL without a scheme is treated as https.
 *
 * @param mixed $social_links Candidate links.
 * @param mixed $args         Optional array. `max` (int, default 20) and
 *                            `error_prefix` (string, default 'social'; error
 *                            codes are invalid_{prefix}_links, too_many_{prefix}_links,
 *                            invalid_{prefix}_type and invalid_{prefix}_url).
 * @return array|WP_Error
 */
function ec_sanitize_social_links( $social_links, $args = array() ) {
	$args   = array_merge(
		array(
			'max'          => 20,
			'error_prefix' => 'social',
		),
		is_array( $args ) ? $args : array()
	);
	$prefix = sanitize_key( (string) $args['error_prefix'] );
	if ( ! is_array( $social_links ) ) {
		return new WP_Error( 'invalid_' . $prefix . '_links', 'Social links must be an array.' );
	}
	if ( count( $social_links ) > (int) $args['max'] ) {
		return new WP_Error( 'too_many_' . $prefix . '_links', 'Too many social links were supplied.' );
	}
	$known     = ec_social_link_types();
	$sanitized = array();
	$seen_ids  = array();
	$sequence  = 0;
	foreach ( $social_links as $social ) {
		if ( ! is_array( $social ) ) {
			return new WP_Error( 'invalid_' . $prefix . '_links', 'A social link is malformed.' );
		}
		++$sequence;
		$type = isset( $social['type'] ) ? sanitize_key( (string) $social['type'] ) : '';
		if ( ! isset( $known[ $type ] ) ) {
			return new WP_Error( 'invalid_' . $prefix . '_type', 'A social link type is not supported.', array( 'type' => $type ) );
		}
		$raw_url = isset( $social['url'] ) ? trim( wp_unslash( (string) $social['url'] ) ) : '';
		if ( '' !== $raw_url && ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $raw_url ) ) {
			$raw_url = 'https://' . ltrim( $raw_url, '/' );
		}
		$url = '' === $raw_url ? '' : esc_url_raw( $raw_url, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return new WP_Error( 'invalid_' . $prefix . '_url', 'A social link URL is invalid.' );
		}
		$id = isset( $social['id'] ) ? sanitize_text_field( (string) $social['id'] ) : '';
		if ( '' === $id || isset( $seen_ids[ $id ] ) ) {
			$id = $type . '-' . $sequence;
		}
		$seen_ids[ $id ] = true;
		$clean           = array(
			'id'   => $id,
			'type' => $type,
			'url'  => $url,
		);
		$label           = isset( $social['custom_label'] ) ? sanitize_text_field( (string) $social['custom_label'] ) : '';
		if ( '' !== $label && ! empty( $known[ $type ]['has_custom_label'] ) ) {
			$clean['custom_label'] = $label;
		}
		$sanitized[] = $clean;
	}
	return $sanitized;
}

/** Validate and sanitize a page-owned social links array. */
function ec_sanitize_link_page_social_links( $social_links ) {
	return ec_sanitize_social_links( $social_links, array( 'error_prefix' => 'link_page_social' ) );
}

/** Return the Font Awesome class for one social link. */
function ec_social_link_icon_class( $social ) {
	$type  = is_array( $social ) && isset( $social['type'] ) ? sanitize_key( (string) $social['type'] ) : '';
	$types = ec_social_link_types();
	$icon  = isset( $types[ $type ]['icon'] ) ? (string) $types[ $type ]['icon'] : 'fas fa-globe';
	$clean = array();
	$parts = preg_split( '/\s+/', trim( $icon ) );
	foreach ( is_array( $parts ) ? $parts : array() as $class ) {
		if ( preg_match( '/^(fab|fas|far|fa-brands|fa-solid|fa-regular|fa-[a-z0-9-]+)$/', $class ) ) {
			$clean[] = $class;
		}
	}
	return $clean ? implode( ' ', $clean ) : 'fas fa-globe';
}

/** Return the human label for one social link. */
function ec_social_link_label( $social ) {
	if ( ! is_array( $social ) ) {
		return 'Social Link';
	}
	if ( ! empty( $social['custom_label'] ) ) {
		return sanitize_text_field( (string) $social['custom_label'] );
	}
	$type  = isset( $social['type'] ) ? sanitize_key( (string) $social['type'] ) : '';
	$types = ec_social_link_types();
	return isset( $types[ $type ]['label'] ) ? (string) $types[ $type ]['label'] : ucfirst( str_replace( '_', ' ', $type ) );
}

/**
 * Render a list of social links as icon links.
 *
 * Markup matches the historical Link Page icons so existing styles apply.
 * Consumers can change the container class or add one.
 *
 * @param mixed $social_links Sanitized social links.
 * @param mixed $args         Optional array. `class` (container class, default
 *                            'extrch-link-page-socials'), `extra_class`,
 *                            `link_class` (default 'extrch-social-icon'),
 *                            `rel` (default 'ugc noopener noreferrer').
 * @return string HTML, or '' when there is nothing to render.
 */
function ec_render_social_links( $social_links, $args = array() ) {
	$args  = array_merge(
		array(
			'class'       => 'extrch-link-page-socials',
			'extra_class' => '',
			'link_class'  => 'extrch-social-icon',
			'rel'         => 'ugc noopener noreferrer',
		),
		is_array( $args ) ? $args : array()
	);
	$items = '';
	foreach ( is_array( $social_links ) ? $social_links : array() as $social ) {
		if ( ! is_array( $social ) || empty( $social['url'] ) || empty( $social['type'] ) ) {
			continue;
		}
		$label  = ec_social_link_label( $social );
		$items .= '<a href="' . esc_url( (string) $social['url'] ) . '" class="' . esc_attr( $args['link_class'] ) . '" target="_blank" rel="' . esc_attr( $args['rel'] ) . '" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '"><i class="' . esc_attr( ec_social_link_icon_class( $social ) ) . '" aria-hidden="true"></i></a>';
	}
	if ( '' === $items ) {
		return '';
	}
	$class = trim( $args['class'] . ' ' . $args['extra_class'] );
	return '<div class="' . esc_attr( $class ) . '">' . $items . '</div>';
}

/** Render page-owned social links for one Link Page icon position. */
function ec_render_link_page_social_links( $social_links, $position = 'above' ) {
	return ec_render_social_links( $social_links, array( 'extra_class' => 'below' === $position ? 'extrch-socials-below' : '' ) );
}
