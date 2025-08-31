<?php
/**
 * ExtraChill Artist Platform Assets Class
 * 
 * Handles CSS and JavaScript asset loading with context-aware loading
 * and proper dependency management.
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

class ExtraChillArtistPlatform_Assets {

    /**
     * Single instance of the class
     */
    private static $instance = null;

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
     * Constructor - Initialize hooks
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_head', array( $this, 'add_custom_styles' ) );
    }

    /**
     * Enqueue frontend assets
     * 
     * Context-aware asset loading based on current page template.
     */
    public function enqueue_frontend_assets() {
        $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
        $plugin_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;

        // Link page specific assets
        if ( $this->is_link_page_context() ) {
            $this->enqueue_link_page_assets();
        }

        // Artist profile management assets
        if ( $this->is_manage_artist_profile_page() ) {
            $this->enqueue_artist_profile_management_assets();
        }

        // Link page management assets
        if ( $this->is_manage_link_page_page() ) {
            $this->enqueue_link_page_management_assets();
        }

        // Artist profile single page assets
        if ( $this->is_artist_profile_page() ) {
            wp_enqueue_style( 
                'extrachill-artist-profile', 
                $plugin_url . 'assets/css/artist-profile.css', 
                array(), 
                $this->get_asset_version( 'assets/css/artist-profile.css' )
            );
            
            // Enqueue topics loop CSS for forum integration
            wp_enqueue_style( 
                'extrachill-topics-loop', 
                get_template_directory_uri() . '/css/topics-loop.css', 
                array(), 
                wp_get_theme()->get('Version')
            );
        }

        // Artist directory assets
        if ( $this->is_artist_directory_page() ) {
            wp_enqueue_style( 
                'extrachill-artist-platform-home', 
                $plugin_url . 'assets/css/artist-platform-home.css', 
                array(), 
                $this->get_asset_version( 'assets/css/artist-platform-home.css' )
            );
        }

        // Hero card styles for artist platform home page and artist archive
        if ( $this->should_load_hero_card_styles() ) {
            wp_enqueue_style( 
                'extrachill-artist-platform-home', 
                $plugin_url . 'assets/css/artist-platform-home.css', 
                array( 'extrachill-artist-platform' ), 
                $this->get_asset_version( 'assets/css/artist-platform-home.css' )
            );

            wp_enqueue_script( 
                'extrachill-artist-platform-home', 
                $plugin_url . 'assets/js/artist-platform-home.js', 
                array( 'jquery' ), 
                $this->get_asset_version( 'assets/js/artist-platform-home.js' ), 
                true 
            );
        }

        // bbPress user profile pages with artist cards
        if ( $this->is_bbpress_user_profile() ) {
            wp_enqueue_style( 
                'extrachill-artist-platform-home', 
                $plugin_url . 'assets/css/artist-platform-home.css', 
                array(), 
                $this->get_asset_version( 'assets/css/artist-platform-home.css' )
            );
        }

        // Global artist platform assets
        wp_enqueue_style( 
            'extrachill-artist-platform', 
            $plugin_url . 'assets/css/artist-platform.css', 
            array(), 
            $this->get_asset_version( 'assets/css/artist-platform.css' )
        );

        wp_enqueue_script( 
            'extrachill-artist-platform', 
            $plugin_url . 'assets/js/artist-platform.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'assets/js/artist-platform.js' ), 
            true 
        );

        // Get current artist ID for complete data
        $current_artist_id = apply_filters('ec_get_artist_id', $_GET);
        $link_page_data = $current_artist_id > 0 ? ec_get_link_page_data( $current_artist_id ) : array();
        
        // Get fonts manager for font data
        $fonts_data = array();
        if ( class_exists( 'ExtraChillArtistPlatform_Fonts' ) ) {
            $font_manager = ExtraChillArtistPlatform_Fonts::instance();
            $fonts_data = $font_manager->get_supported_fonts();
        }
        
        // Localize script for AJAX with complete data structure
        wp_localize_script( 'extrachill-artist-platform', 'extraChillArtistPlatform', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'ec_ajax_nonce' ),
            'linkPageData' => $link_page_data,
            'fonts' => $fonts_data,
            'linkExpirationEnabled' => $link_page_data['settings']['link_expiration_enabled'] ?? false,
            'nonces' => array(),
            'analyticsConfig' => array(
                'trackingEnabled' => true,
                'trackingEndpoint' => admin_url( 'admin-ajax.php' )
            )
        ) );
    }

    /**
     * Enqueue admin assets
     * 
     * Only loads on artist platform related admin pages.
     */
    public function enqueue_admin_assets( $hook ) {
        $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;

        // Only load on artist platform related admin pages
        if ( $this->is_artist_platform_admin_page( $hook ) ) {
            wp_enqueue_style( 
                'extrachill-artist-platform-admin', 
                $plugin_url . 'assets/css/admin.css', 
                array(), 
                $this->get_asset_version( 'assets/css/admin.css' )
            );

            wp_enqueue_script( 
                'extrachill-artist-platform-admin', 
                $plugin_url . 'assets/js/admin.js', 
                array( 'jquery' ), 
                $this->get_asset_version( 'assets/js/admin.js' ), 
                true 
            );
        }
    }

    /**
     * Enqueue link page specific assets
     * 
     * Includes tracking, share modal, subscribe, and YouTube embed scripts.
     */
    private function enqueue_link_page_assets() {
        $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;

        // Core link page styles
        wp_enqueue_style( 
            'extrachill-link-page', 
            $plugin_url . 'assets/css/extrch-links.css', 
            array(), 
            $this->get_asset_version( 'assets/css/extrch-links.css' )
        );

        // Share modal styles
        wp_enqueue_style( 
            'extrachill-share-modal', 
            $plugin_url . 'assets/css/extrch-share-modal.css', 
            array(), 
            $this->get_asset_version( 'assets/css/extrch-share-modal.css' )
        );

        // Link page tracking script
        wp_enqueue_script( 
            'extrachill-link-tracking', 
            $plugin_url . 'inc/link-pages/live/assets/js/link-page-public-tracking.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'inc/link-pages/live/assets/js/link-page-public-tracking.js' ), 
            true 
        );

        // Share modal script
        wp_enqueue_script( 
            'extrachill-share-modal', 
            $plugin_url . 'inc/link-pages/live/assets/js/extrch-share-modal.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'inc/link-pages/live/assets/js/extrch-share-modal.js' ), 
            true 
        );

        // Subscribe functionality
        wp_enqueue_script( 
            'extrachill-subscribe', 
            $plugin_url . 'inc/link-pages/live/assets/js/link-page-subscribe.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'inc/link-pages/live/assets/js/link-page-subscribe.js' ), 
            true 
        );

        // YouTube embed functionality
        wp_enqueue_script( 
            'extrachill-youtube-embed', 
            $plugin_url . 'inc/link-pages/live/assets/js/link-page-youtube-embed.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'inc/link-pages/live/assets/js/link-page-youtube-embed.js' ), 
            true 
        );
    }

    /**
     * Enqueue artist profile management assets
     * 
     * Loads tabbed interface, artist switcher, and roster management.
     */
    private function enqueue_artist_profile_management_assets() {
        $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;

        // Enqueue shared-tabs CSS (dependency)
        wp_enqueue_style( 
            'extrachill-shared-tabs', 
            $plugin_url . 'assets/css/shared-tabs.css', 
            array(), 
            $this->get_asset_version( 'assets/css/shared-tabs.css' )
        );

        // Enqueue artist-switcher CSS (dependency)
        wp_enqueue_style( 
            'extrachill-artist-switcher', 
            $plugin_url . 'assets/css/components/artist-switcher.css', 
            array(), 
            $this->get_asset_version( 'assets/css/components/artist-switcher.css' )
        );

        // Enqueue manage-artist-profile CSS (main)
        wp_enqueue_style( 
            'extrachill-manage-artist-profile', 
            $plugin_url . 'assets/css/manage-artist-profile.css', 
            array( 'extrachill-shared-tabs' ), 
            $this->get_asset_version( 'assets/css/manage-artist-profile.css' )
        );

        // Enqueue shared-tabs JS (dependency)
        wp_enqueue_script( 
            'extrachill-shared-tabs-js', 
            $plugin_url . 'assets/js/shared-tabs.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'assets/js/shared-tabs.js' ), 
            true 
        );

        // Enqueue artist-switcher JS (shared component)
        wp_enqueue_script( 
            'extrachill-artist-switcher-js', 
            $plugin_url . 'assets/js/artist-switcher.js', 
            array(), 
            $this->get_asset_version( 'assets/js/artist-switcher.js' ), 
            true 
        );

        // Enqueue manage-artist-profiles JS (main)
        wp_enqueue_script( 
            'extrachill-manage-artist-profiles', 
            $plugin_url . 'inc/artist-profiles/assets/js/manage-artist-profiles.js', 
            array( 'jquery', 'extrachill-shared-tabs-js' ), 
            $this->get_asset_version( 'inc/artist-profiles/assets/js/manage-artist-profiles.js' ), 
            true 
        );

        // Localize script data for artist profile management
        $this->localize_artist_profile_data();

        wp_enqueue_script( 
            'extrachill-artist-subscribers', 
            $plugin_url . 'inc/artist-profiles/assets/js/manage-artist-subscribers.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'inc/artist-profiles/assets/js/manage-artist-subscribers.js' ), 
            true 
        );
    }

    /**
     * Enqueue link page management assets
     * 
     * Loads live preview system and all management modules.
     */
    private function enqueue_link_page_management_assets() {
        $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;

        // Core assets are handled by individual enqueue methods below

        // Enqueue shared-tabs CSS (dependency)
        wp_enqueue_style( 
            'extrachill-shared-tabs', 
            $plugin_url . 'assets/css/shared-tabs.css', 
            array(), 
            $this->get_asset_version( 'assets/css/shared-tabs.css' )
        );

        // Management interface styles
        wp_enqueue_style( 
            'extrachill-manage-link-page', 
            $plugin_url . 'inc/link-pages/management/assets/css/management.css', 
            array( 'extrachill-shared-tabs' ), 
            $this->get_asset_version( 'inc/link-pages/management/assets/css/management.css' )
        );

        // Live preview CSS removed - iframe loads styles independently
        // This prevents management page styles from interfering with preview iframe

        // Share modal CSS - dependency updated since we removed link-page-public enqueue
        wp_enqueue_style( 
            'extrachill-share-modal', 
            $plugin_url . 'assets/css/extrch-share-modal.css', 
            array( 'extrachill-manage-link-page' ), 
            $this->get_asset_version( 'assets/css/extrch-share-modal.css' )
        );

        // Enqueue SortableJS for drag and drop functionality
        wp_enqueue_script( 
            'sortable-js', 
            'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js', 
            array(), 
            '1.15.2', 
            true 
        );

        // Enqueue shared-tabs JS (dependency)
        wp_enqueue_script( 
            'extrachill-shared-tabs-js', 
            $plugin_url . 'assets/js/shared-tabs.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'assets/js/shared-tabs.js' ), 
            true 
        );

        // Enqueue artist-switcher JS (shared component)
        wp_enqueue_script( 
            'extrachill-artist-switcher-js', 
            $plugin_url . 'assets/js/artist-switcher.js', 
            array(), 
            $this->get_asset_version( 'assets/js/artist-switcher.js' ), 
            true 
        );


        // Get current artist ID for centralized data loading
        $current_artist_id = apply_filters('ec_get_artist_id', $_GET);
        
        // Get comprehensive link page data using centralized filter
        $link_page_data = $current_artist_id > 0 ? ec_get_link_page_data( $current_artist_id ) : array();
        
        // Prepare JavaScript configuration with comprehensive data
        $js_config = array(
            // AJAX configuration
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'ec_ajax_nonce' ),
            'fetch_link_title_nonce' => wp_create_nonce( 'fetch_link_meta_title_nonce' ),
            
            // Supported types from existing filter
            'supportedLinkTypes' => extrachill_artist_platform_social_links()->get_supported_types(),
            
            // Comprehensive link page data (single source of truth)
            'linkPageData' => $link_page_data
        );
        
        // Localize comprehensive data for JavaScript modules (use colors script as localization target)
        wp_localize_script( 
            'extrachill-manage-link-page-colors', 
            'extrchLinkPageConfig', 
            $js_config
        );

        // Enqueue centralized sortable system (required by management modules)
        wp_enqueue_script( 
            'extrachill-sortable-system', 
            $plugin_url . 'inc/link-pages/management/assets/js/sortable.js', 
            array( 'jquery', 'sortable-js' ), 
            $this->get_asset_version( 'inc/link-pages/management/assets/js/sortable.js' ), 
            true 
        );

        // Individual management modules (self-contained, no orchestrator needed)
        $management_scripts = array(
            'colors', 'fonts', 'links', 'analytics', 
            'background', 'info', 'qrcode', 'sizing', 
            'socials', 'subscribe', 'ui-utils', 'advanced'
        );

        foreach ( $management_scripts as $script ) {
            wp_enqueue_script( 
                "extrachill-manage-link-page-{$script}", 
                $plugin_url . "inc/link-pages/management/assets/js/{$script}.js", 
                array( 'jquery', 'sortable-js', 'extrachill-shared-tabs-js', 'extrachill-sortable-system' ), 
                $this->get_asset_version( "inc/link-pages/management/assets/js/{$script}.js" ), 
                true 
            );
        }

        // Load preview modules separately
        $preview_scripts = array(
            'links-preview', 'info-preview', 'socials-preview', 'subscribe-preview',
            'background-preview', 'colors-preview', 'fonts-preview',
            'sizing-preview', 'overlay-preview', 'sorting-preview'
        );

        foreach ( $preview_scripts as $script ) {
            wp_enqueue_script( 
                "extrachill-link-page-{$script}", 
                $plugin_url . "inc/link-pages/management/live-preview/assets/js/{$script}.js", 
                array( 'jquery' ), 
                $this->get_asset_version( "inc/link-pages/management/live-preview/assets/js/{$script}.js" ), 
                true 
            );
        }
        
        // Enqueue Google Fonts for current link page
        $this->enqueue_link_page_google_fonts();
    }

    /**
     * Enqueue Google Fonts for link page management
     * 
     * Loads fonts dynamically based on current link page settings.
     * Replaces inline font loading from template.
     */
    private function enqueue_link_page_google_fonts() {
        // Get current artist and link page IDs
        $artist_id = apply_filters('ec_get_artist_id', $_GET);
        if ( ! $artist_id ) {
            return;
        }
        
        $link_page_id = apply_filters('ec_get_link_page_id', $artist_id);
        if ( ! $link_page_id ) {
            return;
        }
        
        // Get link page data with font settings
        $link_page_data = ec_get_link_page_data( $artist_id, $link_page_id );
        $custom_vars = $link_page_data['css_vars'] ?? array();
        
        $fonts_manager = ExtraChillArtistPlatform_Fonts::instance();
        
        // Enqueue title font
        if ( ! empty( $custom_vars['--link-page-title-font-family'] ) ) {
            $title_font_stack = $custom_vars['--link-page-title-font-family'];
            $title_font_value = trim( explode( ',', trim( $title_font_stack ), 2 )[0], " '" );
            $google_font_param = $fonts_manager->get_google_font_param( $title_font_value );
            
            if ( $google_font_param ) {
                wp_enqueue_style( 
                    'extrachill-link-page-title-font', 
                    'https://fonts.googleapis.com/css2?family=' . $google_font_param . '&display=swap',
                    array(),
                    null // Google Fonts don't need versioning
                );
            }
        }
        
        // Enqueue body font
        if ( ! empty( $custom_vars['--link-page-body-font-family'] ) ) {
            $body_font_stack = $custom_vars['--link-page-body-font-family'];
            $body_font_value = trim( explode( ',', trim( $body_font_stack ), 2 )[0], " '" );
            $google_font_param = $fonts_manager->get_google_font_param( $body_font_value );
            
            if ( $google_font_param ) {
                wp_enqueue_style( 
                    'extrachill-link-page-body-font', 
                    'https://fonts.googleapis.com/css2?family=' . $google_font_param . '&display=swap',
                    array(),
                    null // Google Fonts don't need versioning
                );
            }
        }
    }

    /**
     * Add custom styles for link page CSS variables
     * 
     * CSS variables are now handled by inc/link-pages/live/link-page-head.php 
     * to maintain single source of truth from ec_get_link_page_data() filter.
     */
    public function add_custom_styles() {
        // CSS variables are now generated by link-page-head.php and preview.php only
        // This removes duplication and maintains centralized control
    }

    /**
     * Check if current context is a link page
     */
    private function is_link_page_context() {
        return is_singular( 'artist_link_page' ) || 
               ( isset( $_GET['artist_link_page'] ) && ! empty( $_GET['artist_link_page'] ) );
    }

    /**
     * Check if current page is manage artist profile
     */
    private function is_manage_artist_profile_page() {
        return is_page() && 
               ( get_page_template_slug() === 'manage-artist-profiles.php' || 
                 strpos( get_page_template_slug(), 'manage-artist-profiles' ) !== false );
    }

    /**
     * Check if current page is manage link page
     */
    private function is_manage_link_page_page() {
        return is_page() && 
               ( get_page_template_slug() === 'manage-link-page.php' || 
                 strpos( get_page_template_slug(), 'manage-link-page' ) !== false );
    }

    /**
     * Check if current page is artist directory
     */
    private function is_artist_directory_page() {
        return is_page() && 
               ( get_page_template_slug() === 'artist-directory.php' || 
                 strpos( get_page_template_slug(), 'artist-directory' ) !== false );
    }

    /**
     * Check if current page is artist profile
     */
    private function is_artist_profile_page() {
        return is_singular( 'artist_profile' );
    }

    /**
     * Check if current page should load hero card styles
     * Includes both artist platform home page and artist profiles archive
     */
    private function should_load_hero_card_styles() {
        // Artist platform home page
        $is_home_page = is_page() && 
                       ( get_page_template_slug() === 'artist-platform-home.php' || 
                         strpos( get_page_template_slug(), 'artist-platform-home' ) !== false );
        
        // Artist profiles archive page (/artists)
        $is_artist_archive = is_post_type_archive( 'artist_profile' );
        
        return $is_home_page || $is_artist_archive;
    }

    /**
     * Check if current page is artist platform home page
     * @deprecated Use should_load_hero_card_styles() instead
     */
    private function is_artist_platform_home_page() {
        return is_page() && 
               ( get_page_template_slug() === 'artist-platform-home.php' || 
                 strpos( get_page_template_slug(), 'artist-platform-home' ) !== false );
    }


    /**
     * Check if current page is a bbPress user profile
     */
    private function is_bbpress_user_profile() {
        // Check if bbPress is active and this is a user profile page
        if ( ! function_exists( 'bbp_is_single_user' ) ) {
            return false;
        }
        
        return bbp_is_single_user() || bbp_is_user_home();
    }

    /**
     * Check if current admin page is artist platform related
     * 
     * Checks post type and admin page hooks for artist platform content.
     */
    private function is_artist_platform_admin_page( $hook ) {
        global $post_type;
        
        return in_array( $post_type, array( 'artist_profile', 'artist_link_page' ) ) ||
               in_array( $hook, array( 'edit.php', 'post.php', 'post-new.php' ) );
    }

    /**
     * Localize artist profile management JavaScript data
     * 
     * Passes AJAX endpoints, nonces, and roster management data to frontend.
     */
    private function localize_artist_profile_data() {
        $current_user_id = get_current_user_id();
        $artist_id = apply_filters('ec_get_artist_id', $_GET);
        $artist_profile_id_from_user = 0;

        if ( ! $artist_id && $current_user_id ) {
            // Attempt to get the artist_id from user meta if not in URL
            $user_artist_profiles = get_user_meta( $current_user_id, 'artist_profile_ids', true );
            if ( ! empty( $user_artist_profiles ) && is_array( $user_artist_profiles ) ) {
                $artist_profile_id_from_user = reset( $user_artist_profiles );
            }
        }
        
        // Prioritize URL param, then user meta, then 0
        $final_artist_id = $artist_id ?: $artist_profile_id_from_user;

        $data_to_pass = array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'artistProfileId' => $final_artist_id,
            'ajaxAddNonce'  => wp_create_nonce( 'bp_ajax_add_roster_member_nonce' ),
            'ajaxRemovePlaintextNonce' => wp_create_nonce( 'bp_ajax_remove_plaintext_member_nonce' ),
            'ajaxInviteMemberByEmailNonce' => wp_create_nonce( 'bp_ajax_invite_member_by_email_nonce' ),
            'i18n' => array(
                'confirmRemoveMember' => __('Are you sure you want to remove "%s" from the roster listing?', 'extrachill-artist-platform'),
                'enterEmail' => __('Please enter an email address.', 'extrachill-artist-platform'),
                'sendingInvitation' => __('Sending...', 'extrachill-artist-platform'),
                'sendInvitation' => __('Send Invitation', 'extrachill-artist-platform'),
                'errorSendingInvitation' => __('Error: Could not send invitation.', 'extrachill-artist-platform'),
                'errorAjax' => __('An error occurred. Please try again.', 'extrachill-artist-platform'),
                'errorRemoveListing' => __('Error: Could not remove listing.', 'extrachill-artist-platform')
            )
        );

        wp_localize_script( 'extrachill-manage-artist-profiles', 'apManageMembersData', $data_to_pass );
    }

    /**
     * Get asset version using file modification time
     * 
     * Uses filemtime() for cache busting, falls back to plugin version.
     */
    private function get_asset_version( $asset_path ) {
        $full_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . $asset_path;
        
        if ( file_exists( $full_path ) ) {
            return filemtime( $full_path );
        }
        
        return EXTRACHILL_ARTIST_PLATFORM_VERSION;
    }
}