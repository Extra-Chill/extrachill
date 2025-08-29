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

        // Artist platform home page assets
        if ( $this->is_artist_platform_home_page() ) {
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

        // Localize script for AJAX
        wp_localize_script( 'extrachill-artist-platform', 'extraChillArtistPlatform', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'extrachill_artist_platform_nonce' ),
            'linkPageAnalytics' => array(
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
            $plugin_url . 'assets/js/link-page-public-tracking.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'assets/js/link-page-public-tracking.js' ), 
            true 
        );

        // Share modal script
        wp_enqueue_script( 
            'extrachill-share-modal', 
            $plugin_url . 'assets/js/extrch-share-modal.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'assets/js/extrch-share-modal.js' ), 
            true 
        );

        // Subscribe functionality
        wp_enqueue_script( 
            'extrachill-subscribe', 
            $plugin_url . 'assets/js/link-page-subscribe.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'assets/js/link-page-subscribe.js' ), 
            true 
        );

        // YouTube embed functionality
        wp_enqueue_script( 
            'extrachill-youtube-embed', 
            $plugin_url . 'assets/js/link-page-youtube-embed.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'assets/js/link-page-youtube-embed.js' ), 
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

        // Enqueue manage-artist-profiles JS (main)
        wp_enqueue_script( 
            'extrachill-manage-artist-profiles', 
            $plugin_url . 'assets/js/manage-artist-profiles.js', 
            array( 'jquery', 'extrachill-shared-tabs-js' ), 
            $this->get_asset_version( 'assets/js/manage-artist-profiles.js' ), 
            true 
        );

        // Localize script data for artist profile management
        $this->localize_artist_profile_data();

        wp_enqueue_script( 
            'extrachill-artist-subscribers', 
            $plugin_url . 'assets/js/manage-artist-subscribers.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'assets/js/manage-artist-subscribers.js' ), 
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

        // Live preview CSS - public link page styles
        wp_enqueue_style( 
            'extrachill-link-page-public', 
            $plugin_url . 'inc/link-pages/management/live-preview/assets/css/preview.css', 
            array( 'extrachill-manage-link-page' ), 
            $this->get_asset_version( 'inc/link-pages/management/live-preview/assets/css/preview.css' )
        );

        // Share modal CSS - needed for preview parity
        wp_enqueue_style( 
            'extrachill-share-modal', 
            $plugin_url . 'assets/css/extrch-share-modal.css', 
            array( 'extrachill-link-page-public' ), 
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


        // Get current artist and link page IDs for JavaScript configuration
        $current_artist_id = isset( $_GET['artist_id'] ) ? absint( $_GET['artist_id'] ) : 0;
        $link_page_id = 0;

        if ( $current_artist_id > 0 ) {
            $link_page_id = get_post_meta( $current_artist_id, '_extrch_link_page_id', true );
        }

        // Localize JavaScript configuration for live preview functionality
        wp_localize_script( 
            'extrachill-manage-link-page-shared-utils', 
            'extrchLinkPageConfig', 
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'extrch_link_page_ajax_nonce' ),
                'fetch_link_title_nonce' => wp_create_nonce( 'fetch_link_meta_title_nonce' ),
                'link_page_id' => $link_page_id,
                'artist_id' => $current_artist_id,
                'supportedLinkTypes' => extrachill_artist_platform_social_links()->get_supported_types(),
            )
        );

        // Individual management modules (self-contained, no orchestrator needed)
        $management_scripts = array(
            'shared-utils', 'css-variables', 'colors', 'fonts', 'links', 'analytics', 
            'background', 'featured-link', 'info', 'qrcode', 'save', 'sizing', 
            'socials', 'subscribe', 'ui-utils', 'advanced'
        );

        foreach ( $management_scripts as $script ) {
            wp_enqueue_script( 
                "extrachill-manage-link-page-{$script}", 
                $plugin_url . "inc/link-pages/management/assets/js/{$script}.js", 
                array( 'jquery', 'sortable-js', 'extrachill-shared-tabs-js' ), 
                $this->get_asset_version( "inc/link-pages/management/assets/js/{$script}.js" ), 
                true 
            );
        }

        // Load preview modules separately
        $preview_scripts = array(
            'links-preview', 'info-preview', 'socials-preview', 
            'background-preview', 'fonts-preview',
            'sizing-preview', 'overlay-preview', 'featured-link-preview'
        );

        foreach ( $preview_scripts as $script ) {
            wp_enqueue_script( 
                "extrachill-link-page-{$script}", 
                $plugin_url . "inc/link-pages/management/live-preview/assets/js/{$script}.js", 
                array( 'jquery', 'extrachill-manage-link-page-shared-utils' ), 
                $this->get_asset_version( "inc/link-pages/management/live-preview/assets/js/{$script}.js" ), 
                true 
            );
        }
    }

    /**
     * Add custom styles for link page CSS variables
     * 
     * Outputs custom CSS variables stored in link page meta.
     */
    public function add_custom_styles() {
        if ( is_singular( 'artist_link_page' ) || $this->is_link_page_context() ) {
            global $post;
            
            if ( ! $post ) {
                return;
            }

            $custom_css_vars = get_post_meta( $post->ID, '_link_page_custom_css_vars', true );
            
            if ( ! empty( $custom_css_vars ) ) {
                echo '<style id="link-page-custom-vars">' . wp_kses_post( $custom_css_vars ) . '</style>';
            }
        }
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
     * Check if current page is artist platform home page
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
        $artist_id = isset( $_GET['artist_id'] ) ? absint( $_GET['artist_id'] ) : 0;
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