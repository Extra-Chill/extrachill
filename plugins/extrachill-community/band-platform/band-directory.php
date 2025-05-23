<?php
/**
 * Functions related to the Band Directory Forum view (Forum ID 5432).
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Get the timestamp of the last activity related to a Band Profile.
 * Considers the profile's last modified date and the latest post (topic/reply) in its associated forum.
 *
 * @param int $band_profile_id The ID of the band_profile CPT.
 * @return int|false Unix timestamp (UTC/GMT) of the latest activity, or false on error.
 */
function bp_get_band_profile_last_activity_timestamp( $band_profile_id ) {
    $band_profile_id = absint( $band_profile_id );
    if ( ! $band_profile_id || get_post_type( $band_profile_id ) !== 'band_profile' ) {
        return false;
    }

    // Get profile's last modified timestamp (GMT)
    $profile_modified_gmt = get_post_modified_time( 'U', true, $band_profile_id );
    if ( ! $profile_modified_gmt ) {
        $profile_modified_gmt = get_post_time( 'U', true, $band_profile_id ); // Fallback to creation time
    }
    
    $latest_activity_timestamp = $profile_modified_gmt ?: 0; // Initialize with profile time

    // Get the associated forum ID
    $forum_id = get_post_meta( $band_profile_id, '_band_forum_id', true );
    $forum_id = absint( $forum_id );

    if ( $forum_id > 0 ) {
        // Query for the latest topic or reply in this specific forum
        $args = array(
            'post_type'      => array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ),
            'post_parent'    => $forum_id, // Only posts within this forum
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids', // Only need the ID to get the date
            'post_status'    => 'publish', // Only consider published items
            'meta_query'     => array(
                // Ensure the posts are directly associated with the forum if needed (e.g., for replies)
                // This might need adjustment based on how bbPress structures replies.
                // Typically, post_parent for replies is the topic, not the forum.
                // Let's query based on forum ID association if possible, or simply latest within the forum scope.
                // Using bbp_get_forum_topic_count / bbp_get_forum_reply_count relies on meta potentially.
                // A simpler approach: query latest topic/reply by date linked to the forum.
                 array(
                    'key' => '_bbp_forum_id', // Meta key bbPress uses
                    'value' => $forum_id,
                    'compare' => '='
                 )
            ),
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        );
        
        // Refined query: Get latest topic OR reply within the forum
         $latest_post_args = array(
            'post_type'      => array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ),
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'post_status'    => array( 'publish', 'closed' ), // Use standard public statuses directly
            'meta_query'     => array(
                array(
                    'key'     => '_bbp_forum_id',
                    'value'   => $forum_id,
                    'compare' => '=',
                ),
            ),
             'no_found_rows' => true,
             'update_post_term_cache' => false,
             'update_post_meta_cache' => false,
        );


        $latest_posts = get_posts( $latest_post_args );

        if ( ! empty( $latest_posts ) ) {
            $latest_post_id = $latest_posts[0];
            $latest_post_gmt = get_post_time( 'U', true, $latest_post_id );
            if ( $latest_post_gmt && $latest_post_gmt > $latest_activity_timestamp ) {
                $latest_activity_timestamp = $latest_post_gmt;
            }
        }
    }

    return $latest_activity_timestamp > 0 ? $latest_activity_timestamp : false;
}


/**
 * Filters the bbPress template location to use a custom loop for the Band Directory forum.
 *
 * @param string $located       The path to the template file bbPress has located.
 * @param array  $template_names The list of template names bbPress was searching for.
 * @param array  $args          Arguments passed to the template function.
 * @param bool   $load          Whether the template file will be loaded.
 * @return string The potentially modified path to the template file.
 */
function bp_custom_band_directory_loop_template( $located, $template_names, $args, $load ) {
    // Target Forum ID
    $band_directory_forum_id = 5432; 

    // Check if we are on the target forum page and if bbPress is looking for a standard loop template.
    // We want to replace the loop part inside the single forum view.
    // Common loop templates: loop-forums.php, loop-topics.php. Which one does single forum use? 
    // Let's target loop-topics.php as that's what usually lists items *within* a forum.
    if ( function_exists('bbp_is_single_forum') && bbp_is_single_forum( $band_directory_forum_id ) ) {
        
        // Check if 'loop-topics.php' is one of the templates being requested
        $is_loop_topics_request = false;
        // Add check to ensure $template_names is iterable
        if ( is_array( $template_names ) ) { 
            foreach ( $template_names as $template_name ) { 
                if ( $template_name === 'loop-topics.php' ) {
                    $is_loop_topics_request = true;
                    break;
                }
            }
        } else {
            // Handle case where $template_names is not an array (e.g., string)
            // If it's a string, check if it directly matches
            if ( is_string( $template_names ) && $template_names === 'loop-topics.php' ) {
                 $is_loop_topics_request = true;
            }
            // Optional: Log if it's neither array nor string
            // else { error_log('Unexpected type for $template_names in bp_custom_band_directory_loop_template'); }
        }

        if ( $is_loop_topics_request ) {
            // Construct the path to our custom template within the child theme
            $custom_template_path = get_stylesheet_directory() . '/bbpress/loop-band-profiles.php';

            // If our custom template exists, use it instead of the located one.
            if ( file_exists( $custom_template_path ) ) {
                // Use the located variable as it might already point to a child theme override.
                // We want to override even theme-level loop-topics.php.
                return $custom_template_path; 
            }
        }
    }

    // Return the original located path if conditions aren't met.
    return $located;
}
// Hook into bbp_locate_template to filter template paths. Priority 10 is default.
add_filter( 'bbp_locate_template', 'bp_custom_band_directory_loop_template', 10, 4 ); // Pass 4 arguments 