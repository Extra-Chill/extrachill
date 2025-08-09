<?php
/**
 * Seamless Comments Integration for ExtraChill Community
 * 
 * Serves comment forms and manages cross-domain comment functionality.
 * Enables users on extrachill.com to comment using their community accounts.
 */

add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/comments/form', array(
        'methods' => 'GET',
        'callback' => 'serve_comment_form',
        'permission_callback' => '__return_true',
    ));
});

/**
 * Serve comment form via REST API
 * 
 * Provides HTML comment form for cross-domain embedding on extrachill.com.
 * Includes nonce security and proper form structure for AJAX submission.
 * 
 * @param WP_REST_Request $request REST API request object
 */
function serve_comment_form(WP_REST_Request $request) {
    header('Content-Type: text/html; charset=utf-8');

    // Generate nonce for comment submission security
    $comment_nonce = wp_create_nonce('submit_community_comment');

    // Directly outputting the HTML content
    echo '
    <div id="community-comment-form" data-post-id="" data-username="" data-email="" data-user-id="">
        <h2>Leave a Comment</h2>
        <form action="https://extrachill.com/wp-json/extrachill/v1/community-comment" method="post">
            <p>Hello, <span id="user-name">Guest</span>!</p>
            <p>
                <label for="comment">Comment:</label>
                <textarea id="comment" name="comment" rows="4" required></textarea>
            </p>
            <input type="hidden" name="comment_parent" id="comment_parent" value="0" />
            <input type="hidden" name="comment_nonce" id="comment_nonce" value="' . esc_attr($comment_nonce) . '" />
            <p><input type="submit" value="Post Comment" /></p>
        </form>
        <div class="comment-message"></div>
    </div>';

    exit; // Ensure no further processing or output occurs after this point
}



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
