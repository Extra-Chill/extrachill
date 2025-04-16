<?php
/**
 * AJAX Handlers for Festival Wire functionality.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handler for loading more Festival Wire posts.
 */
function festival_wire_load_more_handler() {
    // Verify nonce
    check_ajax_referer( 'festival_wire_load_more_nonce', 'nonce' );

    // Get query variables from AJAX request
    // Use sanitize_text_field for security on passed parameters
    // $query_vars = isset( $_POST['query_vars'] ) ? json_decode( stripslashes( sanitize_text_field( $_POST['query_vars'] ) ), true ) : array(); // REMOVED sanitize_text_field
    $query_vars = isset( $_POST['query_vars'] ) ? json_decode( stripslashes( $_POST['query_vars'] ), true ) : array(); // Decode directly
    $current_page = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1; // Get current page number to load

    // Basic validation for query vars
    if ( ! is_array( $query_vars ) ) {
        $query_vars = array();
    }

    $query_vars['paged'] = $current_page;
    $query_vars['post_status'] = 'publish';
    $query_vars['post_type'] = 'festival_wire'; // Ensure post type is explicitly set

    // Prevent potential conflicts with pre_get_posts filters on AJAX requests
    // Remove keys that might cause issues in direct WP_Query or are handled by pre_get_posts
    unset($query_vars['error']);
    unset($query_vars['m']);
    unset($query_vars['p']);
    unset($query_vars['post_parent']);
    unset($query_vars['subpost']);
    unset($query_vars['subpost_id']);
    unset($query_vars['attachment']);
    unset($query_vars['attachment_id']);
    unset($query_vars['name']);
    unset($query_vars['pagename']);
    unset($query_vars['page_id']);
    unset($query_vars['second']);
    unset($query_vars['minute']);
    unset($query_vars['hour']);
    unset($query_vars['day']);
    unset($query_vars['monthnum']);
    unset($query_vars['year']);
    unset($query_vars['w']);
    // unset($query_vars['category_name']); // Keep category/tag/taxonomy queries if they were part of the original query - RE-ENABLED
    // unset($query_vars['tag']); // Keep category/tag/taxonomy queries if they were part of the original query - RE-ENABLED
    unset($query_vars['author_name']);
    unset($query_vars['feed']);
    unset($query_vars['tb']);
    unset($query_vars['pb']);
    unset($query_vars['meta_key']);
    unset($query_vars['meta_value']);
    unset($query_vars['preview']);
    unset($query_vars['s']);
    unset($query_vars['sentence']);
    unset($query_vars['title']);
    unset($query_vars['fields']);
    unset($query_vars['menu_order']);
    unset($query_vars['embed']);
    unset($query_vars['ignore_sticky_posts']); // Let WP_Query handle this
    unset($query_vars['lazy_load_term_meta']); // Let WP_Query handle this

    // Query posts
    $posts_query = new WP_Query( $query_vars );

    if ( $posts_query->have_posts() ) :
        // Start output buffering
        ob_start();

        while ( $posts_query->have_posts() ) : $posts_query->the_post();
            // Replicate the HTML structure from archive-festival_wire.php loop
            ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class('festival-wire-card'); ?>>
                <?php if (has_post_thumbnail()): ?>
                <div class="festival-wire-card-image">
                    <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>">
                        <?php the_post_thumbnail('medium'); // Or match the size used in archive template if different ?>
                    </a>
                </div>
                <?php endif; ?>
                
                <div class="festival-wire-card-content">
                    <?php
                    // Start badges container
                    echo '<div class="festival-badges">';

                    // Get post categories (mimicking archive template structure)
                    $categories = get_the_category();
                    if (!empty($categories)) {
                        echo '<div class="festival-tags">'; // Changed span to div container
                        foreach ($categories as $category) {
                            // Make categories links like in archive template
                            echo '<a href="' . esc_url(get_category_link($category->term_id)) . '" class="festival-tag category-tag">' . esc_html($category->name) . '</a>';
                        }
                        echo '</div>';
                    }

                    // Display tags if available (matching archive template)
                    $tags = get_the_tags();
                    if ($tags) {
                        echo '<div class="post-tags">'; // Use the same class as archive
                        foreach ($tags as $tag) {
                            // Add festival-specific class to the anchor tag
                            $tag_link_classes = 'festival-tag tag-tag festival-' . esc_attr($tag->slug);
                            echo '<a href="' . esc_url(get_tag_link($tag->term_id)) . '" class="' . $tag_link_classes . '">' . esc_html($tag->name) . '</a>';
                        }
                        echo '</div>';
                    }

                    // Display Location Terms here
                    $locations = get_the_terms( get_the_ID(), 'location' );
                    if ( $locations && ! is_wp_error( $locations ) ) :
                        echo '<div class="location-badges">'; // Container for locations
                        foreach ( $locations as $location ) :
                            $location_link = get_term_link( $location );
                            if ( ! is_wp_error( $location_link ) ) :
                                // Wrap the link in a span with the location slug class and use festival-tag class
                                echo '<span class="location-' . esc_attr( $location->slug ) . '"><a href="' . esc_url( $location_link ) . '" class="festival-tag location-link" rel="tag">' . esc_html( $location->name ) . '</a></span>';
                            endif;
                        endforeach;
                        echo '</div>'; // Close .location-badges
                    endif;
                    
                    // Close badges container
                    echo '</div>'; // .festival-badges
                    ?>
                    
                    <header class="entry-header">
                        <?php the_title( sprintf( '<h2 class="entry-title"><a href="%s" class="card-link-target" rel="bookmark">', esc_url( get_permalink() ) ), '</a></h2>' ); ?>
                    </header><!-- .entry-header -->

                    <div class="entry-meta">
                        <span class="posted-on"><?php echo esc_html( get_the_date() ); ?></span>
                        <?php
                        // REMOVE Location Terms display from here
                        /*
                        $locations = get_the_terms( get_the_ID(), 'location' );
                        if ( $locations && ! is_wp_error( $locations ) ) :
                            echo '<span class="meta-sep"> | </span><span class="location-meta">'; // Separator and container
                            $location_links = array();
                            foreach ( $locations as $location ) :
                                $location_link = get_term_link( $location );
                                if ( ! is_wp_error( $location_link ) ) :
                                    // Wrap the link in a span with the location slug class for CSS targeting
                                    $location_links[] = '<span class="location-' . esc_attr( $location->slug ) . '"><a href="' . esc_url( $location_link ) . '" class="location-link" rel="tag">' . esc_html( $location->name ) . '</a></span>';
                                endif;
                            endforeach;
                            echo implode( ', ', $location_links ); // Comma-separate if multiple locations
                            echo '</span>';
                        endif;
                        */
                        ?>
                    </div><!-- .entry-meta -->

                    <div class="entry-summary">
                        <?php echo wp_trim_words( get_the_excerpt(), 30, '...' ); ?>
                    </div><!-- .entry-summary -->
                </div>
            </article><!-- #post-<?php the_ID(); ?> -->
            <?php
        endwhile;

        // Get the buffered content
        $output = ob_get_clean();

        // Reset post data
        wp_reset_postdata();

        // Send the HTML output
        echo $output;

    else:
        // No posts found for this page - send nothing or an indicator if needed by JS
        // wp_send_json_error( 'No more posts' ); // Example
    endif;

    wp_die(); // this is required to terminate immediately and return a proper response
}
add_action( 'wp_ajax_load_more_festival_wire', 'festival_wire_load_more_handler' ); // For logged-in users
add_action( 'wp_ajax_nopriv_load_more_festival_wire', 'festival_wire_load_more_handler' ); // For non-logged-in users


/**
 * Process the festival wire tip form submission.
 * This function will be called via AJAX.
 */
function process_festival_wire_tip_submission() {
	// Check nonce for security
	if ( ! check_ajax_referer( 'festival_wire_tip_nonce', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed.' ) );
	}
	
	// Validate inputs
	$content = isset( $_POST['content'] ) ? sanitize_textarea_field( $_POST['content'] ) : '';
	$turnstile_response = isset( $_POST['cf-turnstile-response'] ) ? $_POST['cf-turnstile-response'] : '';

	if ( empty( $content ) ) {
		wp_send_json_error( array( 'message' => 'Please enter your tip.' ) );
	}

	// Verify Turnstile if enabled
	$turnstile_secret_key = get_option( 'ec_turnstile_secret_key' );
	if ( ! empty( $turnstile_secret_key ) ) {
		$verify_result = verify_turnstile_response( $turnstile_response, $turnstile_secret_key );

		if ( ! $verify_result['success'] ) {
			wp_send_json_error( array( 'message' => 'Turnstile verification failed. Please try again.' ) );
		}
	}

	// Send email to admin
	$to = get_option( 'admin_email' );
	$subject = 'New Festival Wire Tip Submission';

	$message = "A new festival tip has been submitted:\n\n";
	$message .= "Tip: " . $content . "\n\n";
	$message .= "Submitted on: " . current_time( 'mysql' ) . "\n";
	
	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	
	$email_sent = wp_mail( $to, $subject, $message, $headers );
	
	if ( $email_sent ) {
		wp_send_json_success( array( 'message' => 'Thank you for your tip! We will review it soon.' ) );
	} else {
		wp_send_json_error( array( 'message' => 'There was an error sending your tip. Please try again later.' ) );
	}
}
add_action( 'wp_ajax_festival_wire_tip_submission', 'process_festival_wire_tip_submission' );
add_action( 'wp_ajax_nopriv_festival_wire_tip_submission', 'process_festival_wire_tip_submission' );

/**
 * Verify Cloudflare Turnstile response.
 *
 * @param string $turnstile_response The turnstile response token.
 * @param string $secret_key The secret key for Turnstile.
 * @return array The response data.
 */
function verify_turnstile_response( $turnstile_response, $secret_key ) {
	$verify_url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	$args = array(
		'body' => array(
			'secret' => $secret_key,
			'response' => $turnstile_response,
			'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', // Ensure remote IP is set
		),
        'timeout' => 15, // Add timeout for external request
	);

	$response = wp_remote_post( $verify_url, $args );

	if ( is_wp_error( $response ) ) {
        // Log the error for debugging
        error_log('Turnstile Verification Error: ' . $response->get_error_message());
		return array( 'success' => false, 'error' => 'Connection error: ' . $response->get_error_message() );
	}

	$response_code = wp_remote_retrieve_response_code( $response );
    if ( $response_code !== 200 ) {
        // Log the error
        error_log('Turnstile Verification HTTP Error: Code ' . $response_code . ' Body: ' . wp_remote_retrieve_body($response));
        return array( 'success' => false, 'error' => 'HTTP error: ' . $response_code );
    }

	$response_body = wp_remote_retrieve_body( $response );
	$result = json_decode( $response_body, true );

    if ( $result === null ) {
        // Log the error
        error_log('Turnstile Verification JSON Decode Error: Body - ' . $response_body);
        return array( 'success' => false, 'error' => 'Invalid response format' );
    }

    // Cloudflare might return error codes in the response body
    if ( isset( $result['success'] ) && ! $result['success'] && isset( $result['error-codes'] ) ) {
         error_log('Turnstile Verification Failed: ' . implode(', ', $result['error-codes']));
    } elseif ( ! isset( $result['success'] ) ) {
         // Unexpected response format
         error_log('Turnstile Verification Unexpected Response: ' . $response_body);
         return array( 'success' => false, 'error' => 'Unexpected response format' );
    }


	return $result;
} 