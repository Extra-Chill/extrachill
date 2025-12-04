<?php
/**
 * Festival Wire AJAX Handlers
 *
 * Handles AJAX requests for Festival Wire load more pagination.
 *
 * @package ExtraChillNewsWire
 * @since 0.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handler for load more Festival Wire posts
 *
 * Processes AJAX pagination requests on Festival Wire archive pages.
 * Validates nonce, sanitizes query parameters, and returns HTML content.
 *
 * @since 0.1.0
 */
function festival_wire_load_more_handler() {
    // Security verification
    check_ajax_referer( 'festival_wire_load_more_nonce', 'nonce' );

    // Extract and validate query parameters
    $query_vars = isset( $_POST['query_vars'] ) ? json_decode( stripslashes( $_POST['query_vars'] ), true ) : array();
    $current_page = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;

    // Validate and prepare query parameters
    if ( ! is_array( $query_vars ) ) {
        $query_vars = array();
    }

    $query_vars['paged'] = $current_page;
    $query_vars['post_status'] = 'publish';
    $query_vars['post_type'] = 'festival_wire';

    // Clean query variables to prevent conflicts with WP_Query
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
    unset($query_vars['ignore_sticky_posts']);
    unset($query_vars['lazy_load_term_meta']);

    // Execute query and generate output
    $posts_query = new WP_Query( $query_vars );

    if ( $posts_query->have_posts() ) :
        ob_start();

        while ( $posts_query->have_posts() ) : $posts_query->the_post();
            require FESTIVAL_WIRE_TEMPLATE_DIR . 'content-card.php';
        endwhile;

        $output = ob_get_clean();
        wp_reset_postdata();
        echo $output;

    else:
        // No posts found - JavaScript handles empty response
    endif;

    wp_die();
}
add_action( 'wp_ajax_load_more_festival_wire', 'festival_wire_load_more_handler' );
add_action( 'wp_ajax_nopriv_load_more_festival_wire', 'festival_wire_load_more_handler' );

 