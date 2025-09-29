<?php
/**
 * Topic Quick Reply Feature Assets
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Asset enqueue moved to inc/core/assets.php for centralized management 

/**
 * Output the mobile quick reply form container in the footer.
 * This prevents it from interfering with the replies loop rendering.
 */
function extrachill_output_mobile_quick_reply_form() {
    // Only output on single bbPress topics where the user can reply
    if ( is_bbpress() && bbp_is_single_topic() ) {
        $topic_id = bbp_get_topic_id();
        $can_reply = is_user_logged_in() && 
                     current_user_can( 'publish_replies', $topic_id ) && 
                     !bbp_is_topic_closed( $topic_id );

        if ( $can_reply ) {
            ?>
            <?php // Output the Mobile Button itself ?>
            <div class="quick-reply-container quick-reply-mobile quick-reply-mobile-button-only"> <?php // Added quick-reply-mobile for hiding ?>
                 <button id="quick-reply-button-mobile" 
                         class="button quick-reply-button-float" 
                         data-topic-id="<?php echo esc_attr( $topic_id ); ?>">
                     <i class="fa-solid fa-reply"></i>
                 </button>
            </div>

            <?php // Output the Mobile Flyout Form Container ?>
            <div id="quick-reply-form-mobile" class="quick-reply-flyout quick-reply-mobile">
                 <button class="close-flyout-button">×</button>
                  <div id="quick-reply-form-placeholder-mobile">
                     <?php 
                     // We need to ensure the correct topic context is set for the template part here
                     // Since this runs in wp_footer, the main query might not be the topic
                     $current_topic_id = bbp_get_topic_id(); // Get ID from the main query (should be correct on single topic)
                     if ($current_topic_id) {
                         // Temporarily set up globals for the template part
                         bbp_setup_current_user(); // Ensure user is set up
                         $topic_query = new WP_Query( array( 'post_type' => bbp_get_topic_post_type(), 'p' => $current_topic_id ) ); 
                         if ( $topic_query->have_posts() ) {
                             $topic_query->the_post();
                             if ( isset( bbpress()->current_topic_id ) ) {
                                 bbpress()->current_topic_id = $current_topic_id;
                             }
                             // Set flag for mobile editor ID
                             $GLOBALS['extrachill_is_mobile_quick_reply'] = true;

                             // Now load the template part with context set
                             bbp_get_template_part( 'form', 'reply-quick' ); 
                             
                             // Unset flag
                             unset($GLOBALS['extrachill_is_mobile_quick_reply']);

                             // IMPORTANT: Reset post data after custom query/the_post
                             wp_reset_postdata(); 
                         } else {
                             echo '<p class="error-quick-reply">Error: Could not load topic data for form.</p>';
                         }
                     } else {
                          echo '<p class="error-quick-reply">Error: Could not determine topic ID for form.</p>';
                     }
                     ?>
                  </div>
             </div>
            <?php
        }
    }
}
add_action( 'wp_footer', 'extrachill_output_mobile_quick_reply_form' ); 