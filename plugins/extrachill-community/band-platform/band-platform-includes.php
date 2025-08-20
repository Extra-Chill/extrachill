<?php
/**
 * Band Platform Feature Includes
 * 
 * Centralized loading for band platform functionality including CPTs,
 * forum integration, user linking, and link page system.
 *
 * @package ExtraChillCommunity
 * @since 1.0.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// --- Load Core Band Platform PHP Files ---
$bp_dir = dirname( __FILE__ ); // Gets the directory of the current file

require_once( $bp_dir . '/cpt-band-profile.php' );
require_once( $bp_dir . '/user-linking.php' );
require_once( $bp_dir . '/band-forums.php' );
require_once( $bp_dir . '/band-permissions.php' );
require_once( $bp_dir . '/frontend-forms.php' );
require_once( $bp_dir . '/band-directory.php' );
require_once( $bp_dir . '/extrch.co-link-page/link-page-includes.php' );
require_once( $bp_dir . '/band-forum-section-overrides.php' ); // Include forum section overrides
// require_once( $bp_dir . '/cpt-band-link-page.php' );

// Data Synchronization
require_once( $bp_dir . '/data-sync.php' );

// Roster specific files
require_once( $bp_dir . '/roster/manage-roster-ui.php' ); 
require_once( $bp_dir . '/roster/roster-ajax-handlers.php' );

// Following feature (moved to social directory)
require_once( get_stylesheet_directory() . '/forum-features/social/following/band-following.php' );

// Add other band platform PHP files here as they are created

// New file
require_once( $bp_dir . '/default-band-page-link-profiles.php' );

// Database setup for subscribers
require_once( $bp_dir . '/subscribe/subscriber-db.php' );
// Use after_switch_theme for table creation, as register_activation_hook does not work for themes in this context
add_action('after_switch_theme', 'extrch_create_subscribers_table');

// Include subscriber AJAX handler and data functions
// require_once( $bp_dir . '/subscribe/subscribe-handler.php' );
require_once( $bp_dir . '/subscribe/subscribe-data-functions.php' );

// Register activation hook to create the subscribers table
// register_activation_hook( get_stylesheet_directory() . '/functions.php', 'extrch_create_subscribers_table' );

// Data Synchronization

// --- Asset Enqueueing --- 

/**
 * Enqueues scripts and styles for single band profiles and the manage band profile page.
 */
function bp_enqueue_band_platform_assets() {
    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();

    // --- Styles/Scripts for Single Band Profile View --- 
    if ( is_singular( 'band_profile' ) ) {
        
        // Enqueue topics loop styles (used for the forum section)
        $topics_loop_css = $theme_dir . '/css/topics-loop.css';
        if ( file_exists( $topics_loop_css ) ) {
             wp_enqueue_style( 
                'topics-loop', 
                $theme_uri . '/css/topics-loop.css', 
                array('extra-chill-community-style'), // Dependency
                filemtime( $topics_loop_css ) // Version
            );
        }

        // Enqueue specific band profile styles
        $band_profile_css = $theme_dir . '/css/band-profile.css';
        if ( file_exists( $band_profile_css ) ) {
            wp_enqueue_style( 
                'band-profile', 
                $theme_uri . '/css/band-profile.css', 
                array('extra-chill-community-style'), // Dependency
                filemtime( $band_profile_css ) // Version
            );
        }

        // Enqueue follow button script only on single band profile
        if ( is_singular('band_profile') ) {
        $follow_js_path = '/forum-features/social/js/extrachill-follow.js'; // Updated path for reorganized social features
        if ( file_exists( $theme_dir . $follow_js_path ) ) {
             wp_enqueue_script(
                'bp-band-following', // New handle for clarity
                $theme_uri . $follow_js_path,
                array( 'jquery' ), // Dependencies
                filemtime( $theme_dir . $follow_js_path ),
                true // Load in footer
            );
            // Localize nonce for the follow script
            $current_user = wp_get_current_user();
            $user_email = $current_user->user_email ? $current_user->user_email : '';

            wp_localize_script( 'bp-band-following', 'bpFollowData', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'bp_follow_nonce' ),
                'currentUserEmail' => $user_email,
                'i18n' => array(
                    'confirmFollow' => __( 'Confirm Follow', 'extra-chill-community' ),
                    'cancel' => __( 'Cancel', 'extra-chill-community' ),
                    'processing' => __( 'Processing...', 'extra-chill-community' ),
                    'following' => __( 'Following', 'extra-chill-community' ),
                    'follow' => __( 'Follow', 'extra-chill-community' ),
                    'errorMessage' => __( 'Could not update follow status. Please try again.', 'extra-chill-community' ),
                    'ajaxRequestFailed' => __( 'AJAX request failed', 'extra-chill-community' ),
                )
            ));
            }
        }
    }

    // --- Scripts for Manage Band Profile Page ---
    if ( is_page_template( 'page-templates/manage-band-profile.php' ) ) {
        $subs_js_path = '/band-platform/js/manage-band-subscribers.js';
        if ( file_exists( $theme_dir . $subs_js_path ) ) {
            wp_enqueue_script(
                'bp-manage-band-subscribers',
                $theme_uri . $subs_js_path,
                array('jquery'),
                filemtime( $theme_dir . $subs_js_path ),
                true
            );
            // Localize ajaxurl for non-admin
            wp_localize_script( 'bp-manage-band-subscribers', 'bpManageSubscribersData', array('ajaxurl' => admin_url( 'admin-ajax.php' )) );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'bp_enqueue_band_platform_assets' ); 


// --- End Script/Style Enqueue ---
