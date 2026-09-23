<?php
/**
 * Extra Chill Artist Platform Social Links Manager
 *
 * Artist-facing API for social links. Socials are owned by the artist's
 * Link Page; types, validation and markup come from the shared Link Pages
 * social links primitive. This class keeps its historical public methods so
 * abilities, REST routes and templates keep working unchanged.
 */

defined( 'ABSPATH' ) || exit;

class ExtraChillArtistPlatform_SocialLinks {

	private static $instance = null;

	/** Legacy profile copy; read only until the Link Page owns socials. */
	const META_KEY = '_artist_profile_social_links';

	/** Page-owned socials (written through the Link Pages storage API). */
	const PAGE_META_KEY = '_link_page_social_links';

	private $supported_types = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'init_hooks' ) );
	}

	public function init_hooks() {
		// Socials are written through save(), which fires the historical
		// updated action itself; no profile meta hook is needed.
	}

	/**
	 * Get supported social link types.
	 *
	 * The catalog is the shared Link Pages social links primitive; this
	 * plugin no longer keeps its own copy. Entries carry `label`, `icon`
	 * and optionally `has_custom_label`.
	 *
	 * @since 1.1.0
	 * @return array Array of supported social link types
	 */
	public function get_supported_types() {
		if ( null === $this->supported_types ) {
			if ( function_exists( 'ec_social_link_types' ) ) {
				$this->supported_types = ec_social_link_types();
			} elseif ( function_exists( 'ec_link_page_social_types' ) ) {
				// Link Pages runtimes before the shared primitive expose the
				// same catalog under its historical name.
				$this->supported_types = ec_link_page_social_types();
			} else {
				$this->supported_types = array();
			}
		}
		return $this->supported_types;
	}

	/**
	 * Run a callback on the artist site, restoring the caller's site.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	private function on_artist_blog( $callback ) {
		$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
		if ( ! $artist_blog_id || ! is_multisite() || get_current_blog_id() === $artist_blog_id ) {
			return $callback();
		}
		switch_to_blog( $artist_blog_id );
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Whether the Link Pages runtime (page storage + shared primitive) is loaded.
	 *
	 * Without it the plugin runs its rolling fallback runtime, where socials
	 * stay on the artist profile as before.
	 *
	 * @return bool
	 */
	private function page_storage_available() {
		return function_exists( 'ec_sanitize_social_links' ) && function_exists( 'ec_save_link_page_persistence' ) && function_exists( 'ec_with_link_page_storage_blog' );
	}

	/**
	 * Minimal normalization for the fallback runtime (no shared catalog).
	 *
	 * @param mixed $link Candidate link.
	 * @return array|false
	 */
	private function fallback_normalize_link( $link ) {
		if ( ! is_array( $link ) || empty( $link['type'] ) || empty( $link['url'] ) ) {
			return false;
		}
		$url = esc_url_raw( trim( (string) $link['url'] ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			return false;
		}
		$normalized = array(
			'type' => sanitize_key( (string) $link['type'] ),
			'url'  => $url,
		);
		if ( ! empty( $link['id'] ) ) {
			$normalized['id'] = sanitize_text_field( (string) $link['id'] );
		}
		if ( ! empty( $link['custom_label'] ) ) {
			$normalized['custom_label'] = sanitize_text_field( (string) $link['custom_label'] );
		}
		return $normalized;
	}

	/**
	 * Resolve the Link Page that owns an artist's socials.
	 *
	 * @param int $artist_id Artist profile ID.
	 * @return int Link Page ID, or 0.
	 */
	private function link_page_id( $artist_id ) {
		return (int) $this->on_artist_blog(
			static function () use ( $artist_id ) {
				return function_exists( 'ec_get_link_page_for_artist' ) ? ec_get_link_page_for_artist( $artist_id ) : 0;
			}
		);
	}

	/**
	 * Whether the Link Page already owns a socials value.
	 *
	 * @param int $link_page_id Link Page ID.
	 * @return bool
	 */
	private function page_owns_socials( $link_page_id ) {
		if ( ! function_exists( 'ec_with_link_page_storage_blog' ) ) {
			return false;
		}
		$owns = ec_with_link_page_storage_blog(
			static function () use ( $link_page_id ) {
				return metadata_exists( 'post', $link_page_id, self::PAGE_META_KEY );
			}
		);
		return true === $owns;
	}

	/**
	 * Read the pre-migration profile copy of an artist's socials.
	 *
	 * Only consulted until the Link Page owns its socials (see
	 * ec_artist_backfill_link_page_owned_content()).
	 *
	 * @param int $artist_id Artist profile ID.
	 * @return array
	 */
	private function legacy_profile_socials( $artist_id ) {
		$raw = $this->on_artist_blog(
			static function () use ( $artist_id ) {
				return get_post_meta( $artist_id, self::META_KEY, true );
			}
		);
		$raw = is_array( $raw ) ? $raw : maybe_unserialize( $raw );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Get social links for an artist.
	 *
	 * Socials are owned by the artist's Link Page. Until a page owns them,
	 * the legacy profile copy is returned so nothing disappears between
	 * deploy and backfill.
	 *
	 * @since 1.1.0
	 * @param int $artist_id Artist profile post ID
	 * @return array Array of social link objects
	 */
	public function get( $artist_id ) {
		$artist_id = absint( $artist_id );
		$is_artist = $artist_id && 'artist_profile' === $this->on_artist_blog(
			static function () use ( $artist_id ) {
				return get_post_type( $artist_id );
			}
		);
		if ( ! $is_artist ) {
			return array();
		}

		$link_page_id = $this->page_storage_available() ? $this->link_page_id( $artist_id ) : 0;
		if ( $link_page_id && $this->page_owns_socials( $link_page_id ) && function_exists( 'ec_read_link_page_persistence' ) ) {
			$data  = ec_read_link_page_persistence( $link_page_id );
			$links = is_wp_error( $data ) ? array() : $data['social_links'];
		} else {
			$links = $this->legacy_profile_socials( $artist_id );
		}

		$normalized_links = array();
		foreach ( $links as $link ) {
			$normalized_link = $this->validate_and_normalize_link( $link );
			if ( $normalized_link ) {
				$normalized_links[] = $normalized_link;
			}
		}

		/**
		 * Filter artist social links after retrieval
		 *
		 * @since 1.1.0
		 * @param array $normalized_links Array of normalized social links
		 * @param int $artist_id Artist profile post ID
		 */
		return apply_filters( 'extrachill_artist_platform_get_social_links', $normalized_links, $artist_id );
	}

	/**
	 * Save social links for an artist onto its Link Page.
	 *
	 * @since 1.1.0
	 * @param int $artist_id Artist profile post ID
	 * @param array $social_links Array of social link objects
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function save( $artist_id, $social_links ) {
		$artist_id = absint( $artist_id );
		$is_artist = $artist_id && 'artist_profile' === $this->on_artist_blog(
			static function () use ( $artist_id ) {
				return get_post_type( $artist_id );
			}
		);
		if ( ! $is_artist ) {
			return new WP_Error( 'invalid_artist', __( 'Invalid artist profile ID.', 'extrachill-artist-platform' ) );
		}

		// Check user permissions
		if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
			return new WP_Error( 'permission_denied', __( 'Permission denied: You do not have access to manage this artist.', 'extrachill-artist-platform' ) );
		}

		$sanitized_links = $this->sanitize_links( $social_links );
		if ( is_wp_error( $sanitized_links ) ) {
			return $sanitized_links;
		}

		/**
		 * Filter artist social links before saving
		 *
		 * @since 1.1.0
		 * @param array $sanitized_links Array of sanitized social links
		 * @param int $artist_id Artist profile post ID
		 * @param array $social_links Original social links array
		 */
		$sanitized_links = apply_filters( 'extrachill_artist_platform_save_social_links', $sanitized_links, $artist_id, $social_links );

		if ( $this->page_storage_available() ) {
			$result = $this->write_page_socials( $artist_id, $sanitized_links );
		} else {
			$result = $this->on_artist_blog(
				static function () use ( $artist_id, $sanitized_links ) {
					return false !== update_post_meta( $artist_id, self::META_KEY, $sanitized_links ) || get_post_meta( $artist_id, self::META_KEY, true ) === $sanitized_links;
				}
			) ? true : new WP_Error( 'save_failed', __( 'Failed to save social links.', 'extrachill-artist-platform' ) );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Action fired after social links are successfully saved
		 *
		 * @since 1.1.0
		 * @param array $sanitized_links Array of saved social links
		 * @param int $artist_id Artist profile post ID
		 */
		do_action( 'extrachill_artist_platform_social_links_saved', $sanitized_links, $artist_id );
		do_action( 'extrachill_artist_platform_social_links_updated', $artist_id, $sanitized_links );

		return true;
	}

	/**
	 * Persist socials on the Link Page, which becomes their only copy.
	 *
	 * No permission check: callers authorize. Used by save() and by the
	 * owned-content backfill.
	 *
	 * @param int   $artist_id       Artist profile ID.
	 * @param array $sanitized_links Sanitized links.
	 * @return true|WP_Error
	 */
	public function write_page_socials( $artist_id, $sanitized_links ) {
		$link_page_id = $this->link_page_id( $artist_id );
		if ( ! $link_page_id || ! function_exists( 'ec_artist_save_link_page_fields' ) ) {
			return new WP_Error( 'link_page_not_found', __( 'This artist has no link page to hold social links.', 'extrachill-artist-platform' ) );
		}
		$saved = ec_artist_save_link_page_fields( $link_page_id, array( 'social_links' => $sanitized_links ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		// The page is now the only copy. Dropping the legacy profile copy
		// keeps an emptied page (which deletes its meta) from falling back
		// to stale profile socials.
		$this->on_artist_blog(
			static function () use ( $artist_id ) {
				delete_post_meta( $artist_id, self::META_KEY );
			}
		);
		return true;
	}

	/**
	 * Delete all social links for an artist
	 *
	 * @since 1.1.0
	 * @param int $artist_id Artist profile post ID
	 * @return bool True on success, false on failure
	 */
	public function delete( $artist_id ) {
		$result = $this->save( $artist_id, array() );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		/**
		 * Action fired after social links are successfully deleted
		 *
		 * @since 1.1.0
		 * @param int $artist_id Artist profile post ID
		 */
		do_action( 'extrachill_artist_platform_social_links_deleted', $artist_id );

		return true;
	}

	/**
	 * Validate and normalize a single social link
	 *
	 * @since 1.1.0
	 * @param mixed $link Social link data
	 * @return array|false Normalized link or false if invalid
	 */
	private function validate_and_normalize_link( $link ) {
		if ( ! $this->page_storage_available() ) {
			return $this->fallback_normalize_link( $link );
		}
		if ( ! is_array( $link ) ) {
			return false;
		}
		// @phpstan-ignore phpstan.function.notFound (provided by the extrachill-link-pages runtime; guarded by page_storage_available().)
		$clean = ec_sanitize_social_links( array( $link ) );
		if ( is_wp_error( $clean ) || empty( $clean[0] ) ) {
			return false;
		}
		// Keep the caller's ID untouched when it had none (the shared
		// sanitizer assigns a positional one for single-item input).
		if ( empty( $link['id'] ) ) {
			unset( $clean[0]['id'] );
		}
		return $clean[0];
	}

	/**
	 * Sanitize an array of social links
	 *
	 * @since 1.1.0
	 * @param mixed $social_links Array of social link objects
	 * @return array|WP_Error Sanitized links array or WP_Error on failure
	 */
	public function sanitize_links( $social_links ) {
		if ( ! is_array( $social_links ) ) {
			return new WP_Error( 'invalid_data', __( 'Social links must be an array.', 'extrachill-artist-platform' ) );
		}
		if ( ! $this->page_storage_available() ) {
			$clean = array();
			foreach ( array_values( $social_links ) as $index => $link ) {
				$normalized = $this->fallback_normalize_link( $link );
				if ( false === $normalized ) {
					/* translators: %d: Link index number */
					return new WP_Error( 'validation_failed', sprintf( __( 'Invalid social link at position %d.', 'extrachill-artist-platform' ), $index + 1 ) );
				}
				$clean[] = $normalized;
			}
			return $clean;
		}
		// @phpstan-ignore phpstan.function.notFound (provided by the extrachill-link-pages runtime; guarded by page_storage_available().)
		$clean = ec_sanitize_social_links( array_values( $social_links ) );
		if ( is_wp_error( $clean ) ) {
			return new WP_Error( 'validation_failed', $clean->get_error_message(), array( 'cause' => $clean->get_error_code() ) + (array) $clean->get_error_data() );
		}
		return $clean;
	}

	/**
	 * Get icon class for a social link type
	 *
	 * @since 1.1.0
	 * @param string $type Social link type
	 * @param mixed $link_data Optional. Complete link data for context
	 * @return string Icon class string
	 */
	public function get_icon_class( $type, $link_data = array() ) {
		$link_data         = is_array( $link_data ) ? $link_data : array();
		$link_data['type'] = $type;
		if ( function_exists( 'ec_social_link_icon_class' ) ) {
			return ec_social_link_icon_class( $link_data );
		}
		$types = $this->get_supported_types();
		return isset( $types[ $type ]['icon'] ) && preg_match( '/^[a-z0-9 -]+$/', (string) $types[ $type ]['icon'] ) ? (string) $types[ $type ]['icon'] : 'fas fa-globe';
	}

	/**
	 * Get label for a social link
	 *
	 * @since 1.1.0
	 * @param array $link Social link data
	 * @return string Link label
	 */
	public function get_link_label( $link ) {
		if ( function_exists( 'ec_social_link_label' ) ) {
			return ec_social_link_label( $link );
		}
		if ( ! is_array( $link ) || empty( $link['type'] ) ) {
			return __( 'Social Link', 'extrachill-artist-platform' );
		}
		if ( ! empty( $link['custom_label'] ) ) {
			return sanitize_text_field( (string) $link['custom_label'] );
		}
		$types = $this->get_supported_types();
		return isset( $types[ $link['type'] ]['label'] ) ? (string) $types[ $link['type'] ]['label'] : ucfirst( str_replace( '_', ' ', (string) $link['type'] ) );
	}

	/**
	 * Render social icons for an artist
	 *
	 * @since 1.1.0
	 * @param int $artist_id Artist profile post ID
	 * @param array $options Rendering options
	 * @return string HTML output
	 */
	public function render_social_icons( $artist_id, $options = array() ) {
		$social_links = $this->get( $artist_id );

		if ( empty( $social_links ) ) {
			return '';
		}

		$defaults = array(
			'container_class' => 'extrch-social-icons',
			'icon_class'      => 'extrch-social-icon',
			'show_labels'     => false,
			'target'          => '_blank',
			'rel'             => 'ugc noopener noreferrer',
			'before'          => '',
			'after'           => '',
		);

		$options = wp_parse_args( $options, $defaults );

		/**
		 * Filter social icons rendering options
		 *
		 * @since 1.1.0
		 * @param array $options Rendering options
		 * @param int $artist_id Artist profile post ID
		 * @param array $social_links Social links data
		 */
		$options = apply_filters( 'extrachill_artist_platform_render_social_icons_options', $options, $artist_id, $social_links );

		$output  = $options['before'];
		$output .= '<div class="' . esc_attr( $options['container_class'] ) . '">';

		foreach ( $social_links as $link ) {
			$icon_class = $this->get_icon_class( $link['type'], $link );
			$label      = $this->get_link_label( $link );

			$output .= sprintf(
				'<a href="%s" class="%s" target="%s" rel="%s" title="%s" aria-label="%s">',
				esc_url( $link['url'] ),
				esc_attr( $options['icon_class'] ),
				esc_attr( $options['target'] ),
				esc_attr( $options['rel'] ),
				esc_attr( $label ),
				esc_attr( $label )
			);

			$output .= '<i class="' . esc_attr( $icon_class ) . '" aria-hidden="true"></i>';

			if ( $options['show_labels'] ) {
				$output .= '<span class="social-label">' . esc_html( $label ) . '</span>';
			}

			$output .= '</a>';
		}

		$output .= '</div>';
		$output .= $options['after'];

		/**
		 * Filter social icons HTML output
		 *
		 * @since 1.1.0
		 * @param string $output HTML output
		 * @param int $artist_id Artist profile post ID
		 * @param array $social_links Social links data
		 * @param array $options Rendering options
		 */
		return apply_filters( 'extrachill_artist_platform_render_social_icons_html', $output, $artist_id, $social_links, $options );
	}

	/**
	 * Get social links in JSON format for JavaScript
	 *
	 * @since 1.1.0
	 * @param int $artist_id Artist profile post ID
	 * @return string JSON-encoded social links
	 */
	public function get_json( $artist_id ) {
		$social_links = $this->get( $artist_id );
		return wp_json_encode( $social_links );
	}

	/**
	 * Save social links from JSON data
	 *
	 * @since 1.1.0
	 * @param int $artist_id Artist profile post ID
	 * @param string $json_data JSON-encoded social links
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function save_from_json( $artist_id, $json_data ) {
		$social_links = json_decode( $json_data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', __( 'Invalid JSON data provided.', 'extrachill-artist-platform' ) );
		}

		return $this->save( $artist_id, $social_links );
	}
}

// Initialize the social links manager
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- WordPress plugin idiom: class plus its global accessor function.
function extrachill_artist_platform_social_links() {
	return ExtraChillArtistPlatform_SocialLinks::instance();
}
