<?php
/**
 * ExtraChill Artist Platform Assets Class
 * 
 * Handles CSS and JavaScript asset loading for artist platform functionality.
 * Manages context-aware loading and proper dependencies.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
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
     * Constructor
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
                'extrachill-artist-platform', 
                $plugin_url . 'assets/css/artist-platform.css', 
                array(), 
                $this->get_asset_version( 'assets/css/artist-platform.css' )
            );
        }

        // Artist directory assets
        if ( $this->is_artist_directory_page() || $this->is_artist_directory_forum() ) {
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
     */
    private function enqueue_link_page_management_assets() {
        $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;

        // Management interface styles
        wp_enqueue_style( 
            'extrachill-manage-link-page', 
            $plugin_url . 'assets/css/manage-link-page.css', 
            array(), 
            $this->get_asset_version( 'assets/css/manage-link-page.css' )
        );

        // Core management script
        wp_enqueue_script( 
            'extrachill-manage-link-page', 
            $plugin_url . 'assets/js/manage-link-page/manage-link-page.js', 
            array( 'jquery' ), 
            $this->get_asset_version( 'assets/js/manage-link-page/manage-link-page.js' ), 
            true 
        );

        // Individual management modules
        $management_scripts = array(
            'core', 'colors', 'fonts', 'links', 'analytics', 
            'background', 'customization', 'featured-link', 
            'info', 'qrcode', 'save', 'sizing', 'socials', 
            'subscribe', 'ui-utils', 'preview-updater', 
            'content-renderer', 'advanced'
        );

        foreach ( $management_scripts as $script ) {
            wp_enqueue_script( 
                "extrachill-manage-link-page-{$script}", 
                $plugin_url . "assets/js/manage-link-page/manage-link-page-{$script}.js", 
                array( 'jquery', 'extrachill-manage-link-page' ), 
                $this->get_asset_version( "assets/js/manage-link-page/manage-link-page-{$script}.js" ), 
                true 
            );
        }
    }

    /**
     * Add custom styles for link page CSS variables
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
               ( get_page_template_slug() === 'manage-artist-profile.php' || 
                 strpos( get_page_template_slug(), 'manage-artist-profile' ) !== false );
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
     * Check if current page is artist platform home page
     */
    private function is_artist_profile_page() {
        return is_singular( 'artist_profile' );
    }

    private function is_artist_platform_home_page() {
        return is_page() && 
               ( get_page_template_slug() === 'artist-platform-home.php' || 
                 strpos( get_page_template_slug(), 'artist-platform-home' ) !== false );
    }

    /**
     * Check if current page is the artist directory forum (Forum 5432)
     */
    private function is_artist_directory_forum() {
        return function_exists( 'bbp_is_single_forum' ) && bbp_is_single_forum( 5432 );
    }

    /**
     * Check if current admin page is artist platform related
     */
    private function is_artist_platform_admin_page( $hook ) {
        global $post_type;
        
        return in_array( $post_type, array( 'artist_profile', 'artist_link_page' ) ) ||
               in_array( $hook, array( 'edit.php', 'post.php', 'post-new.php' ) );
    }

    /**
     * Localize artist profile management JavaScript data
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
     */
    private function get_asset_version( $asset_path ) {
        $full_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . $asset_path;
        
        if ( file_exists( $full_path ) ) {
            return filemtime( $full_path );
        }
        
        return EXTRACHILL_ARTIST_PLATFORM_VERSION;
    }
}