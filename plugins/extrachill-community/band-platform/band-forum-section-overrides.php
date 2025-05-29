<?php
/**
 * Forum Section Overrides for Band Profile
 *
 * Provides functions to fetch override values for the forum section title and bio on the band profile page.
 *
 * @package generatepress_child
 */

if ( ! function_exists( 'bp_get_forum_section_title_and_bio' ) ) {
    /**
     * Get the forum section title and bio for a band profile, using overrides if set.
     *
     * @param int $band_profile_id The band_profile post ID.
     * @return array [ 'title' => string, 'bio' => string ]
     */
    function bp_get_forum_section_title_and_bio( $band_profile_id ) {
        // Get override meta fields
        $title_override = get_post_meta( $band_profile_id, '_forum_section_title_override', true );
        $bio_override   = get_post_meta( $band_profile_id, '_forum_section_bio_override', true );

        // Fallbacks
        $default_title = sprintf( __( 'About %s', 'generatepress_child' ), get_the_title( $band_profile_id ) );
        $default_bio   = get_post_field( 'post_content', $band_profile_id );

        // Use override if set, else fallback
        $title = ! empty( $title_override ) ? $title_override : $default_title;
        $bio   = ! empty( $bio_override )   ? $bio_override   : $default_bio;

        return [
            'title' => $title,
            'bio'   => $bio,
        ];
    }
} 