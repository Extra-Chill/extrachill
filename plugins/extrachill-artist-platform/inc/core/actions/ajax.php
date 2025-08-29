<?php
/**
 * Centralized AJAX handler system for ExtraChill Artist Platform
 * 
 * Single source of truth for all AJAX operations with standardized
 * nonce validation, permission checks, and response formatting.
 */

/**
 * Centralized AJAX action registry
 * Stores all registered AJAX actions with their callbacks and permission requirements
 */
class EC_Ajax_Registry {
    private static $actions = array();
    
    /**
     * Register an AJAX action with centralized handling
     * 
     * @param string $action AJAX action name (without wp_ajax_ prefix)
     * @param callable $callback Function to handle the action
     * @param array $args Configuration array:
     *   - 'permission_check' => callable Function to check permissions (receives $_POST data)
     *   - 'nonce_action' => string Nonce action name (defaults to 'ec_ajax_nonce')
     *   - 'require_login' => bool Whether login is required (default true)
     *   - 'allow_public' => bool Whether non-logged-in users can access (default false)
     */
    public static function register( $action, $callback, $args = array() ) {
        $defaults = array(
            'permission_check' => null,
            'nonce_action' => 'ec_ajax_nonce',
            'require_login' => true,
            'allow_public' => false
        );
        
        $args = wp_parse_args( $args, $defaults );
        
        self::$actions[ $action ] = array(
            'callback' => $callback,
            'args' => $args
        );
        
        // Register WordPress AJAX hooks
        add_action( 'wp_ajax_' . $action, array( __CLASS__, 'handle_ajax' ) );
        
        if ( $args['allow_public'] ) {
            add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, 'handle_ajax' ) );
        }
    }
    
    /**
     * Centralized AJAX handler that processes all registered actions
     */
    public static function handle_ajax() {
        $action = str_replace( 'wp_ajax_', '', current_action() );
        $action = str_replace( 'wp_ajax_nopriv_', '', $action );
        
        if ( ! isset( self::$actions[ $action ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid AJAX action.', 'extrachill-artist-platform' ) ) );
        }
        
        $config = self::$actions[ $action ];
        $args = $config['args'];
        
        // Check login requirement
        if ( $args['require_login'] && ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Authentication required.', 'extrachill-artist-platform' ) ) );
        }
        
        // Verify nonce
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! wp_verify_nonce( $nonce, $args['nonce_action'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'extrachill-artist-platform' ) ) );
        }
        
        // Check permissions
        if ( is_callable( $args['permission_check'] ) ) {
            $permission_result = call_user_func( $args['permission_check'], $_POST );
            if ( ! $permission_result ) {
                wp_send_json_error( array( 'message' => __( 'Permission denied.', 'extrachill-artist-platform' ) ) );
            }
        }
        
        // Execute the callback
        try {
            call_user_func( $config['callback'] );
        } catch ( Exception $e ) {
            error_log( 'EC AJAX Error in ' . $action . ': ' . $e->getMessage() );
            wp_send_json_error( array( 'message' => __( 'An error occurred processing your request.', 'extrachill-artist-platform' ) ) );
        }
    }
}

/**
 * Standard nonce names for different contexts
 */
define( 'EC_AJAX_NONCE', 'ec_ajax_nonce' );
define( 'EC_ADMIN_NONCE', 'ec_admin_nonce' );
define( 'EC_PUBLIC_NONCE', 'ec_public_nonce' );

/**
 * Common permission check functions
 */

/**
 * Permission check: Can manage artist based on POST data
 */
function ec_ajax_can_manage_artist( $post_data ) {
    $artist_id = isset( $post_data['artist_id'] ) ? (int) $post_data['artist_id'] : 0;
    if ( ! $artist_id ) {
        return false;
    }
    
    return ec_can_manage_artist( get_current_user_id(), $artist_id );
}

/**
 * Permission check: Can manage link page based on POST data
 */
function ec_ajax_can_manage_link_page( $post_data ) {
    $link_page_id = isset( $post_data['link_page_id'] ) ? (int) $post_data['link_page_id'] : 0;
    if ( ! $link_page_id ) {
        return false;
    }
    
    $artist_id = ec_get_artist_for_link_page( $link_page_id );
    if ( ! $artist_id ) {
        return false;
    }
    
    return ec_can_manage_artist( get_current_user_id(), $artist_id );
}

/**
 * Permission check: Admin capabilities
 */
function ec_ajax_is_admin( $post_data ) {
    return current_user_can( 'manage_options' );
}

/**
 * Permission check: Can create artist profiles
 */
function ec_ajax_can_create_artists( $post_data ) {
    return ec_can_create_artist_profiles( get_current_user_id() );
}

/**
 * Convenience function to register AJAX actions
 * 
 * @param string $action AJAX action name
 * @param callable $callback Function to handle the action
 * @param array $args Configuration array
 */
function ec_register_ajax_action( $action, $callback, $args = array() ) {
    EC_Ajax_Registry::register( $action, $callback, $args );
}

/**
 * Initialize the centralized AJAX system
 * This replaces individual add_action calls throughout the codebase
 */
function ec_init_ajax_system() {
    // Migration: Register existing AJAX actions through centralized system
    
    // Link page subscription
    ec_register_ajax_action( 'extrch_link_page_subscribe', 'extrch_link_page_subscribe_ajax_handler', array(
        'nonce_action' => 'extrch_subscribe_nonce',
        'allow_public' => true,
        'require_login' => false
    ) );
    
    // Link analytics tracking (public)
    ec_register_ajax_action( 'extrch_record_link_event', 'extrch_record_link_event_ajax', array(
        'nonce_action' => 'extrch_link_page_tracking_nonce',
        'allow_public' => true,
        'require_login' => false
    ) );
    
    // Link click tracking (public)
    ec_register_ajax_action( 'link_page_click_tracking', 'handle_link_click_tracking', array(
        'allow_public' => true,
        'require_login' => false
    ) );
    
    // Fetch analytics (admin only)
    ec_register_ajax_action( 'extrch_fetch_link_page_analytics', 'extrch_fetch_link_page_analytics_ajax', array(
        'permission_check' => 'ec_ajax_can_manage_link_page'
    ) );
    
    // Artist subscribers management
    ec_register_ajax_action( 'extrch_fetch_artist_subscribers', 'extrch_fetch_artist_subscribers_ajax', array(
        'permission_check' => 'ec_ajax_can_manage_artist'
    ) );
    
    ec_register_ajax_action( 'extrch_export_subscribers_csv', 'extrch_export_artist_subscribers_csv', array(
        'permission_check' => 'ec_ajax_can_manage_artist',
        'allow_public' => true // Needed for download functionality
    ) );
    
    // Artist following
    ec_register_ajax_action( 'bp_toggle_follow_artist', 'bp_ajax_toggle_follow_artist_handler' );
    
    ec_register_ajax_action( 'update_user_artist_subscriptions', 'bp_ajax_update_user_artist_subscriptions_handler' );
    
    // Roster management
    ec_register_ajax_action( 'bp_ajax_invite_member_by_email', 'bp_ajax_invite_member_by_email', array(
        'permission_check' => 'ec_ajax_can_manage_artist'
    ) );
    
    // Link page management
    ec_register_ajax_action( 'extrch_upload_background_image_ajax', 'extrch_upload_background_image_ajax', array(
        'permission_check' => 'ec_ajax_can_manage_link_page',
        'allow_public' => true // Needed for upload functionality
    ) );
    
    ec_register_ajax_action( 'extrch_generate_qrcode', 'extrch_generate_qrcode_ajax', array(
        'permission_check' => 'ec_ajax_can_manage_link_page'
    ) );
    
    // Live preview handlers
    ec_register_ajax_action( 'extrch_update_preview', 'extrch_handle_preview_content_update', array(
        'permission_check' => 'ec_ajax_can_manage_link_page',
        'allow_public' => true // Needed for preview functionality
    ) );
    
    ec_register_ajax_action( 'extrch_validate_preview_data', 'extrch_handle_preview_validation', array(
        'permission_check' => 'ec_ajax_can_manage_link_page'
    ) );
    
    ec_register_ajax_action( 'extrch_reset_preview', 'extrch_handle_preview_reset', array(
        'permission_check' => 'ec_ajax_can_manage_link_page'
    ) );
    
    // Featured link handler
    ec_register_ajax_action( 'extrch_fetch_og_image_for_preview', 'extrch_ajax_fetch_og_image_for_preview', array(
        'permission_check' => 'ec_ajax_can_manage_link_page',
        'allow_public' => true // Needed for image fetching
    ) );
    
    // Link meta title fetcher
    ec_register_ajax_action( 'fetch_link_meta_title', 'extrch_fetch_link_meta_title_ajax_handler', array(
        'permission_check' => 'ec_ajax_can_manage_link_page'
    ) );
    
    // Migration handlers (admin only)
    ec_register_ajax_action( 'run_artist_migration', array( 'ExtraChillArtistPlatform_Migration', 'ajax_run_migration' ), array(
        'permission_check' => 'ec_ajax_is_admin'
    ) );
    
    ec_register_ajax_action( 'fix_corrupted_serialized_data', array( 'ExtraChillArtistPlatform_Migration', 'ajax_fix_corrupted_serialized_data' ), array(
        'permission_check' => 'ec_ajax_is_admin'
    ) );
    
    // Artist search (admin)
    ec_register_ajax_action( 'bp_search_artists', 'bp_ajax_search_artists', array(
        'permission_check' => 'ec_ajax_is_admin'
    ) );
    
    // Live preview class handler
    ec_register_ajax_action( 'update_live_preview', array( 'LivePreviewHandler', 'handle_preview_update' ), array(
        'permission_check' => 'ec_ajax_can_manage_link_page',
        'allow_public' => true // Needed for preview functionality
    ) );
}

// Initialize the system
add_action( 'init', 'ec_init_ajax_system' );