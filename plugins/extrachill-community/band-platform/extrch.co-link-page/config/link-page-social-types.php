<?php
/**
 * Returns an array of supported social/external link types for band profiles and link pages.
 *
 * Each type has a key, a translatable label, a Font Awesome icon class,
 * and an optional 'has_custom_label' boolean.
 *
 * @return array Array of link type definitions.
 */
if (!function_exists('bp_get_supported_social_link_types')) {
    function bp_get_supported_social_link_types() {
        return array(
            'apple_music' => array( 
                'label' => __( 'Apple Music', 'generatepress_child' ), 
                'icon' => 'fab fa-apple' 
            ),
            'bandcamp' => array( 
                'label' => __( 'Bandcamp', 'generatepress_child' ), 
                'icon' => 'fab fa-bandcamp' 
            ),
            'bluesky' => array( 
                'label' => __( 'Bluesky', 'generatepress_child' ), 
                'icon' => 'fa-brands fa-bluesky' // Corrected Font Awesome class
            ),
            'custom'  => array( 
                'label' => __( 'Custom Link', 'generatepress_child' ), 
                'icon' => 'fas fa-link', 
                'has_custom_label' => true 
            ),
            'facebook' => array( 
                'label' => __( 'Facebook', 'generatepress_child' ), 
                'icon' => 'fab fa-facebook-f' 
            ),
            'instagram' => array( 
                'label' => __( 'Instagram', 'generatepress_child' ), 
                'icon' => 'fab fa-instagram' 
            ),
            'patreon' => array( 
                'label' => __( 'Patreon', 'generatepress_child' ), 
                'icon' => 'fab fa-patreon' 
            ),
            'pinterest' => array(
                'label' => __( 'Pinterest', 'generatepress_child' ), 
                'icon' => 'fab fa-pinterest' 
            ),
            'soundcloud' => array( 
                'label' => __( 'SoundCloud', 'generatepress_child' ), 
                'icon' => 'fab fa-soundcloud' 
            ),
            'spotify' => array( 
                'label' => __( 'Spotify', 'generatepress_child' ), 
                'icon' => 'fab fa-spotify' 
            ),
            'tiktok' => array( 
                'label' => __( 'TikTok', 'generatepress_child' ), 
                'icon' => 'fab fa-tiktok' 
            ),
            'twitch' => array( 
                'label' => __( 'Twitch', 'generatepress_child' ), 
                'icon' => 'fab fa-twitch' 
            ),
            'twitter_x' => array( 
                'label' => __( 'Twitter / X', 'generatepress_child' ), 
                'icon' => 'fab fa-x-twitter' 
            ),
            'website' => array( 
                'label' => __( 'Website', 'generatepress_child' ), 
                'icon' => 'fas fa-globe' 
            ),
            'youtube' => array( 
                'label' => __( 'YouTube', 'generatepress_child' ), 
                'icon' => 'fab fa-youtube' 
            ),
        );
    }
} 