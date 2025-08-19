<?php
/**
 * Returns an array of supported social/external link types for band profiles and link pages in ABC order.
 *
 * Each type has a key, a translatable label, a Font Awesome icon class,
 * and an optional 'has_custom_label' boolean.
 * 
 * Currently based on FontAwesome icons. May transition to custom icons for greater flexibility and less dependency in the future.
 *
 * @return array Array of link type definitions.
 */
if (!function_exists('bp_get_supported_social_link_types')) {
    function bp_get_supported_social_link_types() {
        return array(
            'apple_music' => array( 
                'label' => __( 'Apple Music', 'extra-chill-community' ), 
                'icon' => 'fab fa-apple' 
            ),
            'bandcamp' => array( 
                'label' => __( 'Bandcamp', 'extra-chill-community' ), 
                'icon' => 'fab fa-bandcamp' 
            ),
            'bluesky' => array( 
                'label' => __( 'Bluesky', 'extra-chill-community' ), 
                'icon' => 'fa-brands fa-bluesky' // Corrected Font Awesome class
            ),
            'custom'  => array( 
                'label' => __( 'Custom Link', 'extra-chill-community' ), 
                'icon' => 'fas fa-link', 
                'has_custom_label' => true 
            ),
            'facebook' => array( 
                'label' => __( 'Facebook', 'extra-chill-community' ), 
                'icon' => 'fab fa-facebook-f' 
            ),
            'instagram' => array( 
                'label' => __( 'Instagram', 'extra-chill-community' ), 
                'icon' => 'fab fa-instagram' 
            ),
            'patreon' => array( 
                'label' => __( 'Patreon', 'extra-chill-community' ), 
                'icon' => 'fab fa-patreon' 
            ),
            'pinterest' => array(
                'label' => __( 'Pinterest', 'extra-chill-community' ), 
                'icon' => 'fab fa-pinterest' 
            ),
            'soundcloud' => array( 
                'label' => __( 'SoundCloud', 'extra-chill-community' ), 
                'icon' => 'fab fa-soundcloud' 
            ),
            'spotify' => array( 
                'label' => __( 'Spotify', 'extra-chill-community' ), 
                'icon' => 'fab fa-spotify' 
            ),
            'tiktok' => array( 
                'label' => __( 'TikTok', 'extra-chill-community' ), 
                'icon' => 'fab fa-tiktok' 
            ),
            'twitch' => array( 
                'label' => __( 'Twitch', 'extra-chill-community' ), 
                'icon' => 'fab fa-twitch' 
            ),
            'twitter_x' => array( 
                'label' => __( 'Twitter / X', 'extra-chill-community' ), 
                'icon' => 'fab fa-x-twitter' 
            ),
            'website' => array( 
                'label' => __( 'Website', 'extra-chill-community' ), 
                'icon' => 'fas fa-globe' 
            ),
            'youtube' => array( 
                'label' => __( 'YouTube', 'extra-chill-community' ), 
                'icon' => 'fab fa-youtube' 
            ),
        );
    }
} 