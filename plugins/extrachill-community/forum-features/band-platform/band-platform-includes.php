<?php
/**
 * Main include file for the Band Platform feature.
 * This file loads all necessary PHP files for the feature and enqueues assets.
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
// require_once( $bp_dir . '/cpt-band-link-page.php' );

// Data Synchronization
require_once( $bp_dir . '/data-sync.php' );

// Roster specific files
require_once( $bp_dir . '/roster/manage-roster-ui.php' ); 
require_once( $bp_dir . '/roster/roster-ajax-handlers.php' );

// Following feature
require_once( $bp_dir . '/band-following.php' );

// Add other band platform PHP files here as they are created

// New file
require_once( $bp_dir . '/default-band-page-link-profiles.php' );

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
                array('generatepress-child-style'), // Dependency
                filemtime( $topics_loop_css ) // Version
            );
        }

        // Enqueue specific band profile styles
        $band_profile_css = $theme_dir . '/css/band-profile.css';
        if ( file_exists( $band_profile_css ) ) {
            wp_enqueue_style( 
                'band-profile', 
                $theme_uri . '/css/band-profile.css', 
                array('generatepress-child-style'), // Dependency
                filemtime( $band_profile_css ) // Version
            );
        }

        // Enqueue follow button script only on single band profile
        if ( is_singular('band_profile') ) {
        $follow_js_path = '/js/extrachill-follow.js'; // Still using old name for now
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
                    'confirmFollow' => __( 'Confirm Follow', 'generatepress_child' ),
                    'cancel' => __( 'Cancel', 'generatepress_child' ),
                    'processing' => __( 'Processing...', 'generatepress_child' ),
                    'following' => __( 'Following', 'generatepress_child' ),
                    'follow' => __( 'Follow', 'generatepress_child' ),
                    'errorMessage' => __( 'Could not update follow status. Please try again.', 'generatepress_child' ),
                    'ajaxRequestFailed' => __( 'AJAX request failed', 'generatepress_child' ),
                )
            ));
            }
        }
    }

    // --- Styles/Scripts for Manage Band Profile Page Template --- 
    /* This block is being removed because extrachill_enqueue_manage_band_profile_assets in functions.php
       already handles enqueuing css/manage-band-profile.css (singular) and js/manage-band-profiles.js
       for the page-templates/manage-band-profile.php template.
    if ( is_page_template( 'page-templates/manage-band-profile.php' ) ) {
        
        // Enqueue CSS for manage page
        $manage_css_path = '/css/manage-band-profiles.css'; // This was pointing to the plural version
        if ( file_exists( $theme_dir . $manage_css_path ) ) {
            wp_enqueue_style(
                'bp-manage-band-profiles', 
                $theme_uri . $manage_css_path,
                array('generatepress-child-style'), // Dependency
                filemtime( $theme_dir . $manage_css_path )
            );
        }

        // Enqueue JS for manage page
        $manage_js_path = '/js/manage-band-profiles.js';
        if ( file_exists( $theme_dir . $manage_js_path ) ) {
            wp_enqueue_script(
                'bp-manage-band-profiles',
                $theme_uri . $manage_js_path,
                array( 'jquery' ), // Dependencies
                filemtime( $theme_dir . $manage_js_path ),
                true // Load in footer
            );

            // Localize script to pass data
            $band_id = isset( $_GET['band_id'] ) ? absint( $_GET['band_id'] ) : 0;
            $data_to_pass = array(
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'searchNonce'   => wp_create_nonce( 'bp_member_search_nonce' ), // Kept for now, though main search is removed. Might be reused for invite modal later or removed.
                'bandProfileId' => $band_id, 
                'noMembersText' => __( 'No members listed yet.', 'generatepress_child' ), // Updated text
                'ajaxAddNonce'  => wp_create_nonce( 'bp_ajax_add_roster_member_nonce' ),
                'ajaxRemovePlaintextNonce' => wp_create_nonce( 'bp_ajax_remove_plaintext_member_nonce' ),
                'ajaxInviteMemberByEmailNonce' => wp_create_nonce( 'bp_ajax_invite_member_by_email_nonce' ),
            );

            // Add data for dynamic links UI
            if ( function_exists('bp_get_supported_social_link_types') ) {
                $data_to_pass['linkTypes'] = bp_get_supported_social_link_types();
            }
            $existing_links = array();
            if ( $band_id > 0 ) { // Only fetch if we have a band ID
                $existing_links = get_post_meta( $band_id, '_band_profile_dynamic_links', true );
                if ( ! is_array( $existing_links ) ) {
                    $existing_links = array();
                }
            }
            $data_to_pass['existingLinks'] = $existing_links;
            $data_to_pass['linkNonce'] = wp_create_nonce( 'bp_dynamic_link_nonce' ); // Can reuse nonce from container if needed
            $data_to_pass['text'] = [ // Translatable text for JS
                'removeLink' => __( 'Remove Link', 'generatepress_child' ),
                'customLinkLabel' => __( 'Custom Link Label', 'generatepress_child' )
            ];

            wp_localize_script( 'bp-manage-band-profiles', 'bpManageMembersData', $data_to_pass );
        }
    }
    
    // --- Styles for Band Directory View (Forum 5432) --- 
    if ( function_exists('bbp_is_single_forum') && bbp_is_single_forum( 5432 ) ) {
        $cards_css_path = '/css/band-profile-cards.css';
        if ( file_exists( $theme_dir . $cards_css_path ) ) {
            wp_enqueue_style(
                'bp-band-profile-cards',
                $theme_uri . $cards_css_path,
                array('generatepress-child-style'), // Dependency
                filemtime( $theme_dir . $cards_css_path )
            );
        }
    }
    
    /* --- Social Links JS for the band profile management page ONLY (REMOVED - Legacy) ---
    if ( is_page_template( 'page-templates/manage-band-profile.php' ) ) {
        $social_js_path = '/js/band-social-links.js';
        if ( file_exists( $theme_dir . $social_js_path ) ) {
            wp_enqueue_script(
                'band-social-links',
                $theme_uri . $social_js_path,
                array('jquery'),
                filemtime( $theme_dir . $social_js_path ),
                true
            );
        }
    }
    */
}
add_action( 'wp_enqueue_scripts', 'bp_enqueue_band_platform_assets' ); 


// --- End Script/Style Enqueue ---

/**
 * Returns an array of supported social/external link types for band profiles.
 *
 * Each type has a key, a translatable label, a Font Awesome icon class,
 * and an optional 'has_custom_label' boolean.
 *
 * @return array Array of link type definitions.
 */
function bp_get_supported_social_link_types() {
    return array(
        'website' => array( 
            'label' => __( 'Website', 'generatepress_child' ), 
            'icon' => 'fas fa-globe' 
        ),
        'spotify' => array( 
            'label' => __( 'Spotify', 'generatepress_child' ), 
            'icon' => 'fab fa-spotify' 
        ),
        'apple_music' => array( 
            'label' => __( 'Apple Music', 'generatepress_child' ), 
            'icon' => 'fab fa-apple' 
        ),
        'bandcamp' => array( 
            'label' => __( 'Bandcamp', 'generatepress_child' ), 
            'icon' => 'fab fa-bandcamp' 
        ),
        'youtube' => array( 
            'label' => __( 'YouTube', 'generatepress_child' ), 
            'icon' => 'fab fa-youtube' 
        ),
        'soundcloud' => array( 
            'label' => __( 'SoundCloud', 'generatepress_child' ), 
            'icon' => 'fab fa-soundcloud' 
        ),
        'facebook' => array( 
            'label' => __( 'Facebook', 'generatepress_child' ), 
            'icon' => 'fab fa-facebook-f' 
        ),
        'instagram' => array( 
            'label' => __( 'Instagram', 'generatepress_child' ), 
            'icon' => 'fab fa-instagram' 
        ),
        'twitter_x' => array( 
            'label' => __( 'Twitter / X', 'generatepress_child' ), 
            'icon' => 'fab fa-x-twitter' 
        ),
        'tiktok' => array( 
            'label' => __( 'TikTok', 'generatepress_child' ), 
            'icon' => 'fab fa-tiktok' 
        ),
        'bluesky' => array( 
            'label' => __( 'Bluesky', 'generatepress_child' ), 
            'icon' => 'fas fa-comment' // Generic social icon as placeholder
        ),
        'patreon' => array( 
            'label' => __( 'Patreon', 'generatepress_child' ), 
            'icon' => 'fab fa-patreon' 
        ),
        'twitch' => array( 
            'label' => __( 'Twitch', 'generatepress_child' ), 
            'icon' => 'fab fa-twitch' 
        ),
        'custom'  => array( 
            'label' => __( 'Custom Link', 'generatepress_child' ), 
            'icon' => 'fas fa-link', 
            'has_custom_label' => true 
        )
    );
}

// Ensure all other includes are loaded after this function if they might use it.
// Or, if this file is primarily for function definitions, its position is fine. 