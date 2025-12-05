<?php
/**
 * ExtraChill Artist Platform Assets Class
 * 
 * Handles CSS and JavaScript asset loading with context-aware loading,
 * proper dependency management, and file existence checks. Provides
 * conditional asset loading based on page template context.
 */

defined( 'ABSPATH' ) || exit;

class ExtraChillArtistPlatform_Assets {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_join_flow_assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_head', array( $this, 'add_custom_styles' ) );
    }

    /**
     * Conditionally loads CSS and JavaScript assets based on page context
     * (link pages, management interfaces, artist profiles) with cache busting via filemtime()
     */
    public function enqueue_frontend_assets() {
        $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
        $plugin_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;

        if ( $this->is_link_page_context() ) {
            $this->enqueue_link_page_assets();
        }

        if ( $this->is_manage_artist_profile_page() ) {
            $this->enqueue_artist_profile_management_assets();
        }

        if ( $this->is_manage_link_page_page() ) {
            $this->enqueue_link_page_management_assets();
        }

        if ( $this->is_artist_profile_page() ) {
            wp_enqueue_style(
                'extrachill-artist-profile',
                $plugin_url . 'assets/css/artist-profile.css',
                array(),
                $this->get_asset_version( 'assets/css/artist-profile.css' )
            );

            if ( defined( 'EXTRACHILL_COMMUNITY_PLUGIN_URL' ) && defined( 'EXTRACHILL_COMMUNITY_PLUGIN_DIR' ) ) {
                wp_enqueue_style(
                    'extrachill-bbpress',
                    EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/bbpress.css',
                    array(),
                    filemtime( EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/bbpress.css' )
                );

                wp_enqueue_style(
                    'topics-loop',
                    EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/topics-loop.css',
                    array( 'extrachill-bbpress' ),
                    filemtime( EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/topics-loop.css' )
                );

                wp_enqueue_style(
                    'replies-loop',
                    EXTRACHILL_COMMUNITY_PLUGIN_URL . '/inc/assets/css/replies-loop.css',
                    array( 'extrachill-bbpress' ),
                    filemtime( EXTRACHILL_COMMUNITY_PLUGIN_DIR . '/inc/assets/css/replies-loop.css' )
                );
            }
        }

        if ( $this->is_artist_directory_page() ) {
            wp_enqueue_style(
                'extrachill-artist-card',
                $plugin_url . 'assets/css/artist-card.css',
                array(),
                $this->get_asset_version( 'assets/css/artist-card.css' )
            );

            wp_enqueue_style(
                'extrachill-artist-platform-home',
                $plugin_url . 'assets/css/artist-platform-home.css',
                array( 'extrachill-artist-card' ),
                $this->get_asset_version( 'assets/css/artist-platform-home.css' )
            );
        }

        if ( $this->should_load_hero_card_styles() ) {
            wp_enqueue_style(
                'extrachill-artist-card',
                $plugin_url . 'assets/css/artist-card.css',
                array(),
                $this->get_asset_version( 'assets/css/artist-card.css' )
            );

            wp_enqueue_style(
                'extrachill-artist-platform-home',
                $plugin_url . 'assets/css/artist-platform-home.css',
                array( 'extrachill-artist-platform', 'extrachill-artist-card' ),
                $this->get_asset_version( 'assets/css/artist-platform-home.css' )
            );

            wp_enqueue_script(
                'extrachill-artist-platform-home',
                $plugin_url . 'assets/js/artist-platform-home.js',
                array(),
                $this->get_asset_version( 'assets/js/artist-platform-home.js' ),
                true
            );

            wp_enqueue_script(
                'extrachill-artist-grid-pagination',
                $plugin_url . 'assets/js/artist-grid-pagination.js',
                array( 'extrachill-artist-platform' ),
                $this->get_asset_version( 'assets/js/artist-grid-pagination.js' ),
                true
            );
        }

        if ( $this->is_bbpress_user_profile() ) {
            wp_enqueue_style(
                'extrachill-artist-card',
                $plugin_url . 'assets/css/artist-card.css',
                array(),
                $this->get_asset_version( 'assets/css/artist-card.css' )
            );

            wp_enqueue_style(
                'extrachill-artist-platform-home',
                $plugin_url . 'assets/css/artist-platform-home.css',
                array( 'extrachill-artist-card' ),
                $this->get_asset_version( 'assets/css/artist-platform-home.css' )
            );
        }

        wp_enqueue_style( 
            'extrachill-artist-platform', 
            $plugin_url . 'assets/css/artist-platform.css', 
            array(), 
            $this->get_asset_version( 'assets/css/artist-platform.css' )
        );

        wp_enqueue_script( 
            'extrachill-artist-platform', 
            $plugin_url . 'assets/js/artist-platform.js', 
            array(), 
            $this->get_asset_version( 'assets/js/artist-platform.js' ), 
            true
        );

        $current_artist_id = apply_filters('ec_get_artist_id', $_GET);
        $link_page_data = $current_artist_id > 0 ? ec_get_link_page_data( $current_artist_id ) : array();

        $artist_slug = '';
        if ( $current_artist_id > 0 ) {
            $artist_post = get_post( $current_artist_id );
            if ( $artist_post ) {
                $artist_slug = $artist_post->post_name;
            }
        }

        $fonts_data = array();
        if ( class_exists( 'ExtraChillArtistPlatform_Fonts' ) ) {
            $font_manager = ExtraChillArtistPlatform_Fonts::instance();
            $fonts_data = $font_manager->get_supported_fonts();
        }

        wp_localize_script( 'extrachill-artist-platform', 'extraChillArtistPlatform', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'restUrl' => rest_url( 'extrachill/v1' ),
            'artistSlug' => $artist_slug,
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

    public function enqueue_admin_assets( $hook ) {
        $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;

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

    private function enqueue_link_page_assets() {
        $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;

        wp_enqueue_style(
            'extrachill-link-page',
            $plugin_url . 'assets/css/extrch-links.css',
            array(),
            $this->get_asset_version( 'assets/css/extrch-links.css' )
        );

        wp_enqueue_style(
            'extrachill-custom-social-icons',
            $plugin_url . 'assets/css/custom-social-icons.css',
            array( 'extrachill-link-page' ),
            $this->get_asset_version( 'assets/css/custom-social-icons.css' )
        );

        wp_enqueue_style(
            'extrachill-share-modal',
            $plugin_url . 'assets/css/extrch-share-modal.css',
            array(),
            $this->get_asset_version( 'assets/css/extrch-share-modal.css' )
        );

        wp_enqueue_script(
            'extrachill-link-tracking',
            $plugin_url . 'inc/link-pages/live/assets/js/link-page-public-tracking.js',
            array(),
            $this->get_asset_version( 'inc/link-pages/live/assets/js/link-page-public-tracking.js' ),
            true
        );

        wp_enqueue_script(
            'extrachill-share-modal',
            $plugin_url . 'inc/link-pages/live/assets/js/extrch-share-modal.js',
            array(),
            $this->get_asset_version( 'inc/link-pages/live/assets/js/extrch-share-modal.js' ),
            true
        );

        wp_enqueue_script(
            'extrachill-subscribe',
            $plugin_url . 'inc/link-pages/live/assets/js/link-page-subscribe.js',
            array(),
            $this->get_asset_version( 'inc/link-pages/live/assets/js/link-page-subscribe.js' ),
            true
        );

        wp_enqueue_script(
            'extrachill-youtube-embed',
            $plugin_url . 'inc/link-pages/live/assets/js/link-page-youtube-embed.js',
            array(),
            $this->get_asset_version( 'inc/link-pages/live/assets/js/link-page-youtube-embed.js' ),
            true
        );

        wp_enqueue_script(
            'extrachill-link-page-edit-permission',
            $plugin_url . 'inc/link-pages/live/assets/js/link-page-edit-permission.js',
            array(),
            $this->get_asset_version( 'inc/link-pages/live/assets/js/link-page-edit-permission.js' ),
            true
        );

        global $artist_id;
        if ( isset( $artist_id ) && $artist_id ) {
            wp_localize_script(
                'extrachill-link-page-edit-permission',
                'extrchEditPermission',
                array( 'artistId' => $artist_id )
            );
        }
    }

    private function enqueue_artist_profile_management_assets() {
        $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;

        // Font Awesome for artist platform management interfaces (isolated from theme)
        wp_enqueue_style( 'font-awesome', '//cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css' );

        wp_enqueue_style( 'extrachill-shared-tabs', get_template_directory_uri() . '/assets/css/shared-tabs.css', array(), filemtime( get_template_directory() . '/assets/css/shared-tabs.css' ) );
        wp_enqueue_script( 'extrachill-shared-tabs', get_template_directory_uri() . '/assets/js/shared-tabs.js', array(), filemtime( get_template_directory() . '/assets/js/shared-tabs.js' ), true );

        wp_enqueue_style(
            'extrachill-artist-switcher',
            $plugin_url . 'assets/css/components/artist-switcher.css',
            array(),
            $this->get_asset_version( 'assets/css/components/artist-switcher.css' )
        );

        wp_enqueue_style(
            'extrachill-manage-artist-profile',
            $plugin_url . 'assets/css/manage-artist-profile.css',
            array(),
            $this->get_asset_version( 'assets/css/manage-artist-profile.css' )
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
            array(),
            $this->get_asset_version( 'inc/artist-profiles/assets/js/manage-artist-profiles.js' ),
            true
        );

        // Localize script data for artist profile management
        $this->localize_artist_profile_data();

        wp_enqueue_script( 
            'extrachill-artist-subscribers', 
            $plugin_url . 'inc/artist-profiles/assets/js/manage-artist-subscribers.js', 
            array(), 
            $this->get_asset_version( 'inc/artist-profiles/assets/js/manage-artist-subscribers.js' ), 
            true 
        );

        // Provide REST API settings for subscribers script
        wp_localize_script(
            'extrachill-artist-subscribers',
            'wpApiSettings',
            array( 'nonce' => wp_create_nonce( 'wp_rest' ) )
        );
    }

    private function enqueue_link_page_management_assets() {
        $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;

        // Font Awesome for artist platform management interfaces (isolated from theme)
        wp_enqueue_style( 'font-awesome', '//cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css' );

        // Enqueue shared tabs for management interface
        wp_enqueue_style( 'extrachill-shared-tabs', get_template_directory_uri() . '/assets/css/shared-tabs.css', array(), filemtime( get_template_directory() . '/assets/css/shared-tabs.css' ) );
        wp_enqueue_script( 'extrachill-shared-tabs', get_template_directory_uri() . '/assets/js/shared-tabs.js', array(), filemtime( get_template_directory() . '/assets/js/shared-tabs.js' ), true );

        // Core assets are handled by individual enqueue methods below

        // Enqueue artist-switcher CSS (dependency)
        wp_enqueue_style(
            'extrachill-artist-switcher',
            $plugin_url . 'assets/css/components/artist-switcher.css',
            array(),
            $this->get_asset_version( 'assets/css/components/artist-switcher.css' )
        );

        // Management interface styles
        wp_enqueue_style(
            'extrachill-manage-link-page',
            $plugin_url . 'inc/link-pages/management/assets/css/management.css',
            array(),
            $this->get_asset_version( 'inc/link-pages/management/assets/css/management.css' )
        );

        // Custom social icons styles for management interface
        wp_enqueue_style( 
            'extrachill-custom-social-icons', 
            $plugin_url . 'assets/css/custom-social-icons.css', 
            array( 'extrachill-manage-link-page' ), 
            $this->get_asset_version( 'assets/css/custom-social-icons.css' )
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

        // Enqueue Chart.js for analytics visualization (UMD build for global use)
        wp_enqueue_script( 
            'chart-js', 
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js', 
            array(), 
            '4.4.0', 
            true 
        );

        // Enqueue SortableJS for drag and drop functionality
        wp_enqueue_script(
            'sortable-js',
            'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js',
            array(),
            '1.15.2',
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
        
        // Get comprehensive link page data using centralized data provider function
        $link_page_data = $current_artist_id > 0 ? ec_get_link_page_data( $current_artist_id ) : array();
        
        // Prepare JavaScript configuration with comprehensive data
        $js_config = array(
            // AJAX configuration
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'ec_ajax_nonce' ),
            'fetch_link_title_nonce' => wp_create_nonce( 'fetch_link_meta_title_nonce' ),
            
            // Supported types from existing filter
            'supportedLinkTypes' => extrachill_artist_platform_social_links()->get_supported_types(),
            
            // Comprehensive link page data from centralized data provider
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
            array( 'sortable-js' ), 
            $this->get_asset_version( 'inc/link-pages/management/assets/js/sortable.js' ), 
            true 
        );

        // Individual management modules (self-contained, no orchestrator needed)
        $management_scripts = array(
            'colors', 'fonts', 'links', 'analytics', 
            'background', 'info', 'profile-image', 'qrcode', 'sizing', 
            'socials', 'subscribe', 'ui-utils', 'advanced'
        );

        foreach ( $management_scripts as $script ) {
            // Analytics script needs Chart.js dependency
            $dependencies = array( 'sortable-js', 'extrachill-sortable-system' );
            if ( $script === 'analytics' ) {
                $dependencies[] = 'chart-js';
            }
            
            wp_enqueue_script( 
                "extrachill-manage-link-page-{$script}", 
                $plugin_url . "inc/link-pages/management/assets/js/{$script}.js", 
                $dependencies, 
                $this->get_asset_version( "inc/link-pages/management/assets/js/{$script}.js" ), 
                true 
            );
        }

        // Load preview modules separately
        $preview_scripts = array(
            'links-preview', 'info-preview', 'profile-image-preview', 'socials-preview', 'subscribe-preview',
            'background-preview', 'colors-preview', 'fonts-preview',
            'sizing-preview', 'overlay-preview', 'sorting-preview'
        );

        foreach ( $preview_scripts as $script ) {
            wp_enqueue_script( 
                "extrachill-link-page-{$script}", 
                $plugin_url . "inc/link-pages/management/live-preview/assets/js/{$script}.js", 
                array(), 
                $this->get_asset_version( "inc/link-pages/management/live-preview/assets/js/{$script}.js" ), 
                true 
            );
        }
        
        // Enqueue Google Fonts for current link page
        $this->enqueue_link_page_google_fonts();
    }

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
        
        // Get link page data with font settings using centralized data provider
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
     * CSS variables now generated by link-page-head.php and preview.php only
     * via centralized ec_get_link_page_data() function
     */
    public function add_custom_styles() {
        // CSS variables are now generated by link-page-head.php and preview.php only
        // This removes duplication and maintains centralized control
    }

    private function is_link_page_context() {
        return is_singular( 'artist_link_page' ) || 
               ( isset( $_GET['artist_link_page'] ) && ! empty( $_GET['artist_link_page'] ) );
    }

    private function is_manage_artist_profile_page() {
        return is_page() && 
               ( get_page_template_slug() === 'manage-artist-profiles.php' || 
                 strpos( get_page_template_slug(), 'manage-artist-profiles' ) !== false );
    }

    private function is_manage_link_page_page() {
        return is_page() && 
               ( get_page_template_slug() === 'manage-link-page.php' || 
                 strpos( get_page_template_slug(), 'manage-link-page' ) !== false );
    }

    private function is_artist_directory_page() {
        return is_page() && 
               ( get_page_template_slug() === 'artist-directory.php' || 
                 strpos( get_page_template_slug(), 'artist-directory' ) !== false );
    }

    private function is_artist_profile_page() {
        return is_singular( 'artist_profile' );
    }

    private function should_load_hero_card_styles() {
        // Artist platform homepage on artist.extrachill.com (site #4)
        $is_home_page = false;
        if ( is_front_page() || is_home() ) {
            $is_home_page = get_current_blog_id() === 4;
        }

        // Artist profiles archive page (/artists)
        $is_artist_archive = is_post_type_archive( 'artist_profile' );

        return $is_home_page || $is_artist_archive;
    }



    private function is_bbpress_user_profile() {
        if ( ! function_exists( 'bbp_is_single_user' ) ) {
            return false;
        }
        
        return bbp_is_single_user() || bbp_is_user_home();
    }

    private function is_artist_platform_admin_page( $hook ) {
        global $post_type;
        
        return in_array( $post_type, array( 'artist_profile', 'artist_link_page' ) ) ||
               in_array( $hook, array( 'edit.php', 'post.php', 'post-new.php' ) );
    }

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
            'restUrl'         => rest_url( 'extrachill/v1' ),
            'nonce'           => wp_create_nonce( 'wp_rest' ),
            'artistProfileId' => $final_artist_id,
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
     * Enqueues join flow assets on login/register page
     *
     * Loads modal styling and JavaScript for the join flow system
     * with dependencies on community plugin's login/register interface.
     */
    public function enqueue_join_flow_assets() {
        if ( ! isset( $_GET['from_join'] ) || $_GET['from_join'] !== 'true' ) {
            return;
        }

        $css_path = 'inc/join/assets/css/join-flow.css';
        $js_path = 'inc/join/assets/js/join-flow-ui.js';
        $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;

        if ( file_exists( EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . $css_path ) ) {
            wp_enqueue_style(
                'ec-join-flow',
                $plugin_url . $css_path,
                array( 'extrachill-shared-tabs' ),
                $this->get_asset_version( $css_path )
            );
        }

        if ( file_exists( EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . $js_path ) ) {
            wp_enqueue_script(
                'ec-join-flow-ui',
                $plugin_url . $js_path,
                array( 'extrachill-shared-tabs' ),
                $this->get_asset_version( $js_path ),
                true
            );
        }
    }

    private function get_asset_version( $asset_path ) {
        $full_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . $asset_path;

        if ( file_exists( $full_path ) ) {
            return filemtime( $full_path );
        }

        return EXTRACHILL_ARTIST_PLATFORM_VERSION;
    }
}