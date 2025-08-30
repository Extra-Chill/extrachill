<?php
/**
 * ExtraChill Artist Platform Social Links Manager
 * 
 * Centralized management for artist social links functionality.
 * Single source for social types, CRUD operations, validation, and rendering.
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

class ExtraChillArtistPlatform_SocialLinks {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Meta key for storing artist social links
     */
    const META_KEY = '_artist_profile_social_links';

    /**
     * Supported social link types
     */
    private $supported_types = null;

    /**
     * Get single instance
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     * 
     * @since 1.1.0
     */
    private function __construct() {
        add_action( 'init', array( $this, 'init_hooks' ) );
    }

    /**
     * Initialize WordPress hooks
     * 
     * @since 1.1.0
     */
    public function init_hooks() {
        // Hook for when social links are updated
        add_action( 'updated_post_meta', array( $this, 'on_social_links_updated' ), 10, 4 );
    }

    /**
     * Get supported social link types
     * 
     * @since 1.1.0
     * @return array Array of supported social link types
     */
    public function get_supported_types() {
        if ( null === $this->supported_types ) {
            $this->supported_types = array(
                'apple_music' => array( 
                    'label' => __( 'Apple Music', 'extrachill-artist-platform' ), 
                    'icon' => 'fab fa-apple',
                    'base_url' => 'music.apple.com'
                ),
                'bandcamp' => array( 
                    'label' => __( 'Bandcamp', 'extrachill-artist-platform' ), 
                    'icon' => 'fab fa-bandcamp',
                    'base_url' => 'bandcamp.com'
                ),
                'bluesky' => array( 
                    'label' => __( 'Bluesky', 'extrachill-artist-platform' ), 
                    'icon' => 'fa-brands fa-bluesky',
                    'base_url' => 'bsky.app'
                ),
                'custom'  => array( 
                    'label' => __( 'Custom Link', 'extrachill-artist-platform' ), 
                    'icon' => 'fas fa-link', 
                    'has_custom_label' => true 
                ),
                'facebook' => array( 
                    'label' => __( 'Facebook', 'extrachill-artist-platform' ), 
                    'icon' => 'fab fa-facebook-f',
                    'base_url' => 'facebook.com'
                ),
                'instagram' => array( 
                    'label' => __( 'Instagram', 'extrachill-artist-platform' ), 
                    'icon' => 'fab fa-instagram',
                    'base_url' => 'instagram.com'
                ),
                'patreon' => array( 
                    'label' => __( 'Patreon', 'extrachill-artist-platform' ), 
                    'icon' => 'fab fa-patreon',
                    'base_url' => 'patreon.com'
                ),
                'pinterest' => array(
                    'label' => __( 'Pinterest', 'extrachill-artist-platform' ), 
                    'icon' => 'fab fa-pinterest',
                    'base_url' => 'pinterest.com'
                ),
                'soundcloud' => array( 
                    'label' => __( 'SoundCloud', 'extrachill-artist-platform' ), 
                    'icon' => 'fab fa-soundcloud',
                    'base_url' => 'soundcloud.com'
                ),
                'spotify' => array( 
                    'label' => __( 'Spotify', 'extrachill-artist-platform' ), 
                    'icon' => 'fab fa-spotify',
                    'base_url' => 'spotify.com'
                ),
                'tiktok' => array( 
                    'label' => __( 'TikTok', 'extrachill-artist-platform' ), 
                    'icon' => 'fab fa-tiktok',
                    'base_url' => 'tiktok.com'
                ),
                'twitch' => array( 
                    'label' => __( 'Twitch', 'extrachill-artist-platform' ), 
                    'icon' => 'fab fa-twitch',
                    'base_url' => 'twitch.tv'
                ),
                'twitter_x' => array( 
                    'label' => __( 'Twitter / X', 'extrachill-artist-platform' ), 
                    'icon' => 'fab fa-x-twitter',
                    'base_url' => 'x.com'
                ),
                'website' => array( 
                    'label' => __( 'Website', 'extrachill-artist-platform' ), 
                    'icon' => 'fas fa-globe' 
                ),
                'youtube' => array( 
                    'label' => __( 'YouTube', 'extrachill-artist-platform' ), 
                    'icon' => 'fab fa-youtube',
                    'base_url' => 'youtube.com'
                ),
            );

            /**
             * Filter supported social link types
             * 
             * @since 1.1.0
             * @param array $types Supported social link types
             */
            $this->supported_types = apply_filters( 'ec_social_types', $this->supported_types );
        }

        return $this->supported_types;
    }

    /**
     * Get social links for an artist
     * 
     * @since 1.1.0
     * @param int $artist_id Artist profile post ID
     * @return array Array of social link objects
     */
    public function get( $artist_id ) {
        if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
            return array();
        }

        $social_links = get_post_meta( $artist_id, self::META_KEY, true );
        
        if ( ! is_array( $social_links ) ) {
            return array();
        }

        // Validate and normalize each social link
        $normalized_links = array();
        foreach ( $social_links as $link ) {
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
     * Save social links for an artist
     * 
     * @since 1.1.0
     * @param int $artist_id Artist profile post ID
     * @param array $social_links Array of social link objects
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function save( $artist_id, $social_links ) {
        if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
            return new WP_Error( 'invalid_artist', __( 'Invalid artist profile ID.', 'extrachill-artist-platform' ) );
        }

        // Check user permissions
        if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
            return new WP_Error( 'permission_denied', __( 'Permission denied: You do not have access to manage this artist.', 'extrachill-artist-platform' ) );
        }

        // Validate and sanitize all links
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

        $result = update_post_meta( $artist_id, self::META_KEY, $sanitized_links );
        
        if ( false === $result ) {
            return new WP_Error( 'save_failed', __( 'Failed to save social links.', 'extrachill-artist-platform' ) );
        }

        /**
         * Action fired after social links are successfully saved
         * 
         * @since 1.1.0
         * @param array $sanitized_links Array of saved social links
         * @param int $artist_id Artist profile post ID
         */
        do_action( 'extrachill_artist_platform_social_links_saved', $sanitized_links, $artist_id );

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
        if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
            return false;
        }

        // Check user permissions
        if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
            return false;
        }

        $result = delete_post_meta( $artist_id, self::META_KEY );
        
        if ( $result ) {
            /**
             * Action fired after social links are successfully deleted
             * 
             * @since 1.1.0
             * @param int $artist_id Artist profile post ID
             */
            do_action( 'extrachill_artist_platform_social_links_deleted', $artist_id );
        }

        return $result;
    }

    /**
     * Validate and normalize a single social link
     * 
     * @since 1.1.0
     * @param array $link Social link data
     * @return array|false Normalized link or false if invalid
     */
    private function validate_and_normalize_link( $link ) {
        if ( ! is_array( $link ) || empty( $link['type'] ) || empty( $link['url'] ) ) {
            return false;
        }

        $supported_types = $this->get_supported_types();
        
        // Validate type
        if ( ! array_key_exists( $link['type'], $supported_types ) ) {
            return false;
        }

        // Validate URL
        $url = $this->validate_url( $link['url'] );
        if ( ! $url ) {
            return false;
        }

        $normalized = array(
            'type' => sanitize_key( $link['type'] ),
            'url' => $url
        );

        // Handle custom labels
        if ( ! empty( $supported_types[ $link['type'] ]['has_custom_label'] ) && ! empty( $link['custom_label'] ) ) {
            $normalized['custom_label'] = sanitize_text_field( $link['custom_label'] );
        }

        return $normalized;
    }

    /**
     * Sanitize an array of social links
     * 
     * @since 1.1.0
     * @param array $social_links Array of social link objects
     * @return array|WP_Error Sanitized links array or WP_Error on failure
     */
    public function sanitize_links( $social_links ) {
        if ( ! is_array( $social_links ) ) {
            return new WP_Error( 'invalid_data', __( 'Social links must be an array.', 'extrachill-artist-platform' ) );
        }

        $sanitized_links = array();
        $errors = array();

        foreach ( $social_links as $index => $link ) {
            $sanitized_link = $this->validate_and_normalize_link( $link );
            
            if ( false === $sanitized_link ) {
                $errors[] = sprintf( 
                    /* translators: %d: Link index number */
                    __( 'Invalid social link at position %d.', 'extrachill-artist-platform' ), 
                    $index + 1 
                );
                continue;
            }

            $sanitized_links[] = $sanitized_link;
        }

        if ( ! empty( $errors ) ) {
            return new WP_Error( 'validation_failed', implode( ' ', $errors ) );
        }

        return $sanitized_links;
    }

    /**
     * Validate a URL
     * 
     * @since 1.1.0
     * @param string $url URL to validate
     * @return string|false Sanitized URL or false if invalid
     */
    private function validate_url( $url ) {
        $url = trim( $url );
        
        if ( empty( $url ) ) {
            return false;
        }

        // Add https:// if no protocol specified
        if ( ! preg_match( '/^https?:\/\//', $url ) ) {
            $url = 'https://' . $url;
        }

        $sanitized_url = esc_url_raw( $url );
        
        if ( ! $sanitized_url || ! filter_var( $sanitized_url, FILTER_VALIDATE_URL ) ) {
            return false;
        }

        return $sanitized_url;
    }

    /**
     * Sanitize Font Awesome icon classes while preserving spaces
     * 
     * @since 1.1.0
     * @param string $icon_class Font Awesome icon class string
     * @return string Sanitized icon class string
     */
    private function sanitize_icon_class( $icon_class ) {
        if ( empty( $icon_class ) ) {
            return 'fas fa-globe';
        }

        // Split the class string into individual classes
        $classes = explode( ' ', trim( $icon_class ) );
        $sanitized_classes = array();

        foreach ( $classes as $class ) {
            $class = trim( $class );
            
            // Skip empty classes
            if ( empty( $class ) ) {
                continue;
            }

            // Allow Font Awesome prefixes and icon classes
            if ( preg_match( '/^(fab|fas|far|fal|fat|fad|fa-brands|fa-solid|fa-regular|fa-light|fa-thin|fa-duotone)$/i', $class ) ) {
                $sanitized_classes[] = strtolower( $class );
            }
            // Allow Font Awesome icon names (fa-*)
            elseif ( preg_match( '/^fa-[a-z0-9-]+$/i', $class ) ) {
                $sanitized_classes[] = strtolower( $class );
            }
        }

        // If no valid classes found, return fallback
        if ( empty( $sanitized_classes ) ) {
            return 'fas fa-globe';
        }

        // Ensure we have at least a prefix and icon
        if ( count( $sanitized_classes ) === 1 ) {
            // If only one class and it's an icon, add default prefix
            if ( preg_match( '/^fa-/', $sanitized_classes[0] ) ) {
                array_unshift( $sanitized_classes, 'fas' );
            }
            // If only prefix, add default icon
            elseif ( in_array( $sanitized_classes[0], array( 'fab', 'fas', 'far', 'fal', 'fat', 'fad' ) ) ) {
                $sanitized_classes[] = 'fa-globe';
            }
        }

        return implode( ' ', $sanitized_classes );
    }

    /**
     * Get icon class for a social link type
     * 
     * @since 1.1.0
     * @param string $type Social link type
     * @param array $link_data Optional. Complete link data for context
     * @return string Icon class string
     */
    public function get_icon_class( $type, $link_data = array() ) {
        $supported_types = $this->get_supported_types();
        
        // First priority: Check if icon is specified in link data
        if ( ! empty( $link_data['icon'] ) ) {
            return $this->sanitize_icon_class( $link_data['icon'] );
        }

        // Second priority: Get from supported types config
        if ( array_key_exists( $type, $supported_types ) && ! empty( $supported_types[ $type ]['icon'] ) ) {
            return $this->sanitize_icon_class( $supported_types[ $type ]['icon'] );
        }

        // Third priority: Generate FontAwesome class from type
        if ( $type !== 'custom' && $type !== 'website' ) {
            $icon_name = sanitize_html_class( str_replace( '_', '-', $type ) );
            return $this->sanitize_icon_class( 'fab fa-' . $icon_name );
        }

        // Final fallback
        return 'fas fa-globe';
    }

    /**
     * Get label for a social link
     * 
     * @since 1.1.0
     * @param array $link Social link data
     * @return string Link label
     */
    public function get_link_label( $link ) {
        if ( ! is_array( $link ) || empty( $link['type'] ) ) {
            return __( 'Social Link', 'extrachill-artist-platform' );
        }

        // Use custom label if available
        if ( ! empty( $link['custom_label'] ) ) {
            return sanitize_text_field( $link['custom_label'] );
        }

        $supported_types = $this->get_supported_types();
        
        if ( array_key_exists( $link['type'], $supported_types ) ) {
            return $supported_types[ $link['type'] ]['label'];
        }

        return ucfirst( str_replace( '_', ' ', $link['type'] ) );
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
            'icon_class' => 'extrch-social-icon',
            'show_labels' => false,
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
            'before' => '',
            'after' => ''
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

        $output = $options['before'];
        $output .= '<div class="' . esc_attr( $options['container_class'] ) . '">';

        foreach ( $social_links as $link ) {
            $icon_class = $this->get_icon_class( $link['type'], $link );
            $label = $this->get_link_label( $link );
            
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
     * Handle social links updated action
     * 
     * @since 1.1.0
     * @param int $meta_id Meta ID
     * @param int $object_id Post ID
     * @param string $meta_key Meta key
     * @param mixed $meta_value Meta value
     */
    public function on_social_links_updated( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( $meta_key === self::META_KEY && get_post_type( $object_id ) === 'artist_profile' ) {
            /**
             * Action fired when artist social links are updated via update_post_meta
             * 
             * @since 1.1.0
             * @param int $artist_id Artist profile post ID
             * @param array $meta_value Updated social links
             */
            do_action( 'extrachill_artist_platform_social_links_updated', $object_id, $meta_value );
        }
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
function extrachill_artist_platform_social_links() {
    return ExtraChillArtistPlatform_SocialLinks::instance();
}