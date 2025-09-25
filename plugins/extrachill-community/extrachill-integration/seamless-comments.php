<?php
/**
 * Seamless Comments Integration for Extra Chill Community
 * 
 * Serves comment forms and manages cross-domain comment functionality.
 * Enables users on extrachill.com to comment using their community accounts.
 */




/**
 * Enable REST API support for bbPress post types
 * 
 * Allows topics and replies to be accessible via WordPress REST API
 * for cross-platform integration and data access.
 */
function my_bbpress_rest_support() {
   // Make sure 'topic' and 'reply' are registered with 'show_in_rest' => true
   global $wp_post_types;

   if ( isset( $wp_post_types['topic'] ) ) {
       $wp_post_types['topic']->show_in_rest = true;
       $wp_post_types['topic']->rest_base    = 'topic'; // optional, but clarifies the rest base
   }

   if ( isset( $wp_post_types['reply'] ) ) {
       $wp_post_types['reply']->show_in_rest = true;
       $wp_post_types['reply']->rest_base    = 'reply'; // same note as above
   }
}
add_action( 'init', 'my_bbpress_rest_support', 25 );
