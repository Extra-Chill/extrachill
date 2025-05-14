<?php
/**
 * Custom rewrites and template loader for extrch.co public link pages.
 * Handles default landing page logic for extrachill.link root slug and admin-only editing.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;


add_filter( 'template_include', function( $template ) {
    $current_host = strtolower( $_SERVER['HTTP_HOST'] ?? '' );
    $request_uri = trim( $_SERVER['REQUEST_URI'] ?? '', '/' );
    $path_parts = explode( '/', $request_uri );
    $potential_slug = $path_parts[0] ?? '';

    $is_link_page_domain = ( stripos( $current_host, 'extrachill.link' ) !== false || stripos( $current_host, 'extrch.co' ) !== false );

    if ( ! $is_link_page_domain ) {
        return $template; // Not our domains, do nothing.
    }

    // Ensure the centralized creation/retrieval function is available
    if ( ! function_exists( 'extrch_get_or_create_default_admin_link_page' ) ) {
        $create_page_path = dirname( __FILE__ ) . '/create-link-page.php';
        if ( file_exists( $create_page_path ) ) {
            require_once $create_page_path;
        } else {
            // Major issue if this file is missing, but proceed cautiously.
            error_log('Extrch Link Page: create-link-page.php not found.');
            return $template;
        }
    }
    
    // Attempt to retrieve default admin items WITHOUT creating them
    $default_admin_items = extrch_get_or_create_default_admin_link_page( false );
    $default_admin_band_id = $default_admin_items && isset($default_admin_items['band_id']) ? $default_admin_items['band_id'] : null;
    $default_admin_link_page_id = $default_admin_items && isset($default_admin_items['link_page_id']) ? $default_admin_items['link_page_id'] : null;

    // 1. Handle root domain requests for both extrachill.link and extrch.co
    if ( empty( $potential_slug ) ) { // Root request
        if ( $default_admin_link_page_id ) {
            global $post;
            $post = get_post( $default_admin_link_page_id );
            if ( $post ) {
                setup_postdata( $post );
                return locate_template( 'single-band_link_page.php' );
            }
        }
        // If default link page doesn't exist, it will fall through to the 404 handling at the end.
    }

    // 2. Handle /manage-link-page/ for the default admin link page
    if ( $potential_slug === 'manage-link-page' && empty( $_GET['band_id'] ) && current_user_can( 'manage_options' ) ) {
        if ( $default_admin_band_id && $default_admin_link_page_id ) {
            // Default band profile and link page exist, redirect to manage it.
            // Ensure default link exists (idempotent check) - this part can remain as it's for existing pages.
            $existing_links = get_post_meta($default_admin_link_page_id, '_link_page_links', true);
            if (empty($existing_links) && $default_admin_band_id) { // Check band_id too
                $band_name = get_the_title($default_admin_items['band_id']);
                $band_profile_post = get_post($default_admin_items['band_id']);
                if ($band_profile_post) {
                    $band_profile_url = site_url('/band/' . $band_profile_post->post_name); // Use main site URL
                    $default_link_section = array( /* ... as before ... */ ); // Keep your existing default link structure
                     $default_link_section = array(
                        array(
                            'section_title' => '',
                            'links' => array(
                                array(
                                    'link_url' => $band_profile_url,
                                    'link_text' => $band_name . ' Forum Profile',
                                    'link_is_active' => true
                                )
                            )
                        )
                    );
                    update_post_meta($default_admin_items['link_page_id'], '_link_page_links', $default_link_section);
                }
            }
            wp_safe_redirect( add_query_arg( 'band_id', $default_admin_band_id, site_url('/manage-link-page/') ) );
            exit;
        } else {
            // Default band profile or link page doesn't exist.
            // Do not create here. Redirect to dashboard or show a message.
            // For simplicity, let it fall through to 404 or redirect to dashboard.
            // Or, provide a specific message:
            $message = __('The default site link page does not exist. Please create it via the dashboard widget.', 'generatepress_child');
            // Optional: Redirect to dashboard
            // wp_safe_redirect(add_query_arg('extrch_default_missing', '1', admin_url('index.php')));
            // exit;
            // For now, let it fall to general 404 handling or die.
            wp_die( $message . ' <a href="' . esc_url(admin_url('index.php')) . '">Go to Dashboard</a>' );
        }
    }
    
    // 3. Handle /band-slug requests for both domains (excluding 'manage-link-page')
    if ( ! empty( $potential_slug ) && $potential_slug !== 'manage-link-page' && $potential_slug !== 'wp-admin' && $potential_slug !== 'wp-login.php' ) {
        $band_profile = get_page_by_path( $potential_slug, OBJECT, 'band_profile' );
        if ( $band_profile ) {
            $link_page_id = get_post_meta( $band_profile->ID, '_extrch_link_page_id', true );
            if ( $link_page_id && get_post_type( $link_page_id ) === 'band_link_page' ) {
                global $post;
                $post = get_post( $link_page_id );
                if ( $post ) {
                    // --- Check for Temporary Redirect ---
                    $redirect_enabled = get_post_meta( $link_page_id, '_link_page_redirect_enabled', true ) === '1';
                    $redirect_target_url = get_post_meta( $link_page_id, '_link_page_redirect_target_url', true );

                    if ( $redirect_enabled && ! empty( $redirect_target_url ) ) {
                        // Perform the redirect (using 307 Temporary Redirect)
                        if ( ! headers_sent() ) {
                            wp_safe_redirect( esc_url_raw( $redirect_target_url ), 307 ); // Use 307 for temporary redirect
                            exit;
                        } else {
                            // Log an error if headers were already sent, redirect won't work.
                            error_log( 'Extrch Link Page Redirect Error: Headers already sent for link page ID ' . $link_page_id );
                            // Fall through to render the page normally as a fallback, or show an error.
                            // For now, let it fall through.
                        }
                    }
                    // --- End Check for Temporary Redirect ---

                    setup_postdata( $post );
                    // Prevent WordPress from trying to find a template for 'band_profile' CPT via its own slug
                    // by setting query vars as if it's a singular 'band_link_page'.
                    global $wp_query;
                    $wp_query->is_singular = true;
                    $wp_query->is_page = false; // Or true, depending on how you want it treated
                    $wp_query->is_single = true;
                    $wp_query->set('post_type', 'band_link_page');
                    $wp_query->set('name', $post->post_name); // Set the queried object name to the link page's slug
                    $wp_query->queried_object = $post;
                    $wp_query->queried_object_id = $post->ID;

                    return locate_template( 'single-band_link_page.php' );
                }
            }
        }
        
        // 4. If no band_profile or link_page found for the slug, handle as 404 for these domains
        // Redirect to main site's front page with a message, or a dedicated 404 page for the link service.
        if ( ! headers_sent() ) {
            // Example: Redirect to main site homepage with a query var
            // You might want a more user-friendly 404 page specific to the link service.
            wp_safe_redirect( add_query_arg( 'link_page_404', '1', home_url() ) );
            exit;
        } else {
            // Fallback if headers already sent (less ideal)
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            // Optionally include a simple 404 template part here or let WordPress handle it.
            // For a cleaner approach, ensure this redirect happens before any output.
            return get_404_template();
        }
    }

    return $template;
});

