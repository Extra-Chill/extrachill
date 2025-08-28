<?php
/**
 * Artist Platform Feature Includes
 * 
 * Centralized loading for artist platform functionality including CPTs,
 * forum integration, user linking, and link page system.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// --- Load Core Artist Platform PHP Files ---
$bp_dir = dirname( __FILE__ );

require_once( $bp_dir . '/cpt-artist-profile.php' );
require_once( $bp_dir . '/user-linking.php' );
require_once( $bp_dir . '/artist-forums.php' );
require_once( $bp_dir . '/artist-permissions.php' );
require_once( $bp_dir . '/frontend-forms.php' );
require_once( $bp_dir . '/artist-directory.php' );
require_once( $bp_dir . '/extrch.co-link-page/link-page-includes.php' );
require_once( $bp_dir . '/artist-forum-section-overrides.php' ); // Include forum section overrides
// require_once( $bp_dir . '/cpt-artist-link-page.php' );

// Data Synchronization
require_once( $bp_dir . '/data-sync.php' );

// Roster specific files
require_once( $bp_dir . '/roster/manage-roster-ui.php' ); 
require_once( $bp_dir . '/roster/roster-ajax-handlers.php' );

// Following feature (moved to social directory)
require_once( get_stylesheet_directory() . '/forum-features/social/artist-following.php' );

// Add other artist platform PHP files here as they are created

// New file
require_once( $bp_dir . '/default-artist-page-link-profiles.php' );

// Database setup for subscribers
require_once( $bp_dir . '/subscribe/subscriber-db.php' );
add_action('after_switch_theme', 'extrch_create_subscribers_table');

require_once( $bp_dir . '/subscribe/subscribe-data-functions.php' );

// --- Asset Enqueueing --- 

/**
 * Enqueues scripts and styles for single artist profiles and the manage artist profile page.
 * 
 * Handles CSS and JS loading for artist profile views and management pages.
 */
function bp_enqueue_artist_platform_assets() {
    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();

    // --- Styles/Scripts for Single Artist Profile View --- 
    if ( is_singular( 'artist_profile' ) ) {
        
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

        // Enqueue specific artist profile styles
        $artist_profile_css = $theme_dir . '/css/artist-profile.css';
        if ( file_exists( $artist_profile_css ) ) {
            wp_enqueue_style( 
                'artist-profile', 
$theme_uri . '/css/artist-profile.css', 
                array('extra-chill-community-style'), // Dependency
filemtime( $artist_profile_css ) // Version
            );
        }

        // Enqueue follow button script only on single artist profile
        if ( is_singular('artist_profile') ) {
        $follow_js_path = '/forum-features/social/js/extrachill-follow.js';
        if ( file_exists( $theme_dir . $follow_js_path ) ) {
             wp_enqueue_script(
                'bp-artist-following',
                $theme_uri . $follow_js_path,
                array( 'jquery' ), // Dependencies
                filemtime( $theme_dir . $follow_js_path ),
                true // Load in footer
            );
            // Localize data for the follow script
            $current_user = wp_get_current_user();
            $user_email = $current_user->user_email ? $current_user->user_email : '';

            wp_localize_script( 'bp-artist-following', 'bpFollowData', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'bp_follow_nonce' ),
                'currentUserEmail' => $user_email,
                'i18n' => array(
                    'confirmFollow' => __( 'Confirm Follow', 'extrachill-artist-platform' ),
                    'cancel' => __( 'Cancel', 'extrachill-artist-platform' ),
                    'processing' => __( 'Processing...', 'extrachill-artist-platform' ),
                    'following' => __( 'Following', 'extrachill-artist-platform' ),
                    'follow' => __( 'Follow', 'extrachill-artist-platform' ),
                    'errorMessage' => __( 'Could not update follow status. Please try again.', 'extrachill-artist-platform' ),
                    'ajaxRequestFailed' => __( 'AJAX request failed', 'extrachill-artist-platform' ),
                )
            ));
            }
        }
    }

    // --- Scripts for Manage Artist Profile Page ---
    if ( is_page_template( 'page-templates/manage-artist-profile.php' ) ) {
        $plugin_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;
        $plugin_uri = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
        $subs_js_path = '/assets/js/manage-artist-subscribers.js';
        if ( file_exists( $plugin_dir . $subs_js_path ) ) {
            wp_enqueue_script(
                'bp-manage-artist-subscribers',
                $plugin_uri . $subs_js_path,
                array('jquery'),
                filemtime( $plugin_dir . $subs_js_path ),
                true
            );
            // Localize ajaxurl for non-admin
            wp_localize_script( 'bp-manage-artist-subscribers', 'bpManageSubscribersData', array('ajaxurl' => admin_url( 'admin-ajax.php' )) );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'bp_enqueue_artist_platform_assets' ); 


// --- End Script/Style Enqueue ---
