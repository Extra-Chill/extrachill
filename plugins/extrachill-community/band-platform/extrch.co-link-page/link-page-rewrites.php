<?php
/**
 * Custom rewrites and template loader for extrch.co public link pages.
 * Handles default landing page logic for extrachill.link root slug and admin-only editing.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'redirect_canonical', 'extrch_prevent_canonical_redirect_for_link_domain', 10, 2 );
/**
 * Prevents canonical redirection if the request is for the extrachill.link domain.
 * This allows our custom template_include logic to handle routing for this domain.
 *
 * @param string $redirect_url The URL WordPress intends to redirect to.
 * @param string $requested_url The originally requested URL.
 * @return string|false The redirect URL, or false to prevent redirection.
 */
function extrch_prevent_canonical_redirect_for_link_domain( $redirect_url, $requested_url ) {
    $current_host = strtolower( $_SERVER['SERVER_NAME'] ?? '' );
    if ( $current_host === 'extrachill.link' ) {
        return false; 
    }
    return $redirect_url;
}

// Register custom query variables
add_filter( 'query_vars', 'extrch_register_custom_query_vars' );
function extrch_register_custom_query_vars( $vars ) {
    $vars[] = 'dev_view_link_page';
    return $vars;
}

add_filter( 'template_include', function( $template ) {
    if ( defined( 'EXTRCH_LINKPAGE_DEV' ) && EXTRCH_LINKPAGE_DEV ) {
        return $template;
    }

    $current_host = strtolower( $_SERVER['HTTP_HOST'] ?? '' );


    $is_link_page_domain = ( stripos( $current_host, 'extrachill.link' ) !== false );

    if ( ! $is_link_page_domain ) {
        return $template; // Not extrachill.link, do nothing more here.
    }

    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $request_path = trim( parse_url( $request_uri, PHP_URL_PATH ), '/' );

    if ( $request_path === 'join' ) {
        $redirect_url = 'https://community.extrachill.com/login/?from_join=true';
        if (!headers_sent()) {
            wp_redirect(esc_url_raw($redirect_url), 301); // 301 for permanent redirect
            exit(); // Exit to prevent further WordPress loading
        } else {
            error_log('[ERROR] template_include: Headers already sent, cannot redirect /join.');
            return $template;
        }
        $handled = true; // Mark as handled
    }

    $template_to_load = null;
    $handled = false; // Reset handled flag for subsequent logic if redirect failed

    $is_extra_chill_request = ( empty( $request_path ) || $request_path === 'extra-chill' );

    if ( $request_path === 'manage-link-page' ) {
        $manage_page = get_page_by_path('manage-link-page');
        if ($manage_page) {
            global $wp_query;
            $wp_query->posts = array( $manage_page );
            $wp_query->post_count = 1;
            $wp_query->found_posts = 1;
            $wp_query->max_num_pages = 1;
            $wp_query->is_page = true;
            $wp_query->is_singular = true;
            $wp_query->is_404 = false;
            $wp_query->query_vars['pagename'] = 'manage-link-page';
            $wp_query->queried_object_id = $manage_page->ID;
            $wp_query->queried_object = $manage_page;

            $template_to_load = locate_template('page-templates/manage-link-page.php');
            $handled = true;

        } else {
            status_header(404);
            $template_to_load = get_404_template();
            $handled = true;
        }

    } elseif ( $is_extra_chill_request ) {
         if ( $request_path === 'extra-chill' ) {
            $redirect_url = 'https://extrachill.link/';
            if (!headers_sent()) {
                wp_redirect(esc_url_raw($redirect_url), 301); // 301 for permanent redirect
                exit(); // Exit to prevent further WordPress loading
            } else {
                error_log('[ERROR] template_include: Headers already sent, cannot redirect /extra-chill/ to root.');
            }
         }

         $default_slug = 'extra-chill';
         $args = array(
             'name'           => $default_slug,
             'post_type'      => 'band_link_page',
             'post_status'    => 'publish',
             'numberposts'    => 1,
             'fields'         => 'ids',
         );
         $default_link_page_id = get_posts( $args );

         if ( $default_link_page_id ) {
             $default_link_page_id = $default_link_page_id[0];

             global $wp_query;
             $wp_query->posts = array( get_post( $default_link_page_id ) );
             $wp_query->post_count = 1;
             $wp_query->found_posts = 1;
             $wp_query->max_num_pages = 1;
             $wp_query->is_single = true;
             $wp_query->is_singular = true;
             $wp_query->is_404 = false;
             $wp_query->query_vars['name'] = $default_slug;
             $wp_query->query_vars['post_type'] = 'band_link_page';
             $wp_query->queried_object_id = $default_link_page_id;
             $wp_query->queried_object = get_post( $default_link_page_id );

             $template_to_load = locate_template('single-band_link_page.php');
             $handled = true;

         } else {
             status_header(404);
             $template_to_load = get_404_template();
             $handled = true;
         }

    } else {
        $args = array(
            'name'           => $request_path,
            'post_type'      => 'band_link_page',
            'post_status'    => 'publish',
            'numberposts'    => 1,
            'fields'         => 'ids',
        );
        $link_page_id = get_posts( $args );

        if ( $link_page_id ) {
            $link_page_id = $link_page_id[0];

            global $wp_query;
            $wp_query->posts = array( get_post( $link_page_id ) );
            $wp_query->post_count = 1;
            $wp_query->found_posts = 1;
            $wp_query->max_num_pages = 1;
            $wp_query->is_single = true;
            $wp_query->is_singular = true;
            $wp_query->is_404 = false;
            $wp_query->query_vars['name'] = $request_path;
            $wp_query->query_vars['post_type'] = 'band_link_page';
            $wp_query->queried_object_id = $link_page_id;
            $wp_query->queried_object = get_post( $link_page_id );

            $template_to_load = locate_template('single-band_link_page.php');
            $handled = true;

        } else {
            $redirect_url = 'https://extrachill.link/';
            if (!headers_sent()) {
                wp_redirect(esc_url_raw($redirect_url), 301); // 301 for permanent redirect
                exit(); // Exit to prevent further WordPress loading
            } else {
                error_log('[ERROR] template_include: Headers already sent, cannot redirect 404 to root.');
                return $template; 
            }
            $handled = true; // Mark as handled even if redirect failed
        }
    }

    if ( $handled && $template_to_load && file_exists( $template_to_load ) ) {
        return $template_to_load;
    } else if ($handled) {
        return $template;
    }

    return $template;
});

// --- START: Redirect direct CPT access to extrachill.link domain ---
add_action( 'template_redirect', 'extrch_redirect_band_link_page_cpt_to_custom_domain' );

/**
 * Redirects direct access to 'band_link_page' CPT posts (via their WordPress permalinks)
 * to their canonical URL on the extrachill.link domain.
 * Includes logic for temporary redirects based on the '_link_page_redirect_enabled' and '_link_page_redirect_target_url' post meta.
 */
function extrch_redirect_band_link_page_cpt_to_custom_domain() {
    $is_dev_mode = (defined('EXTRCH_LINKPAGE_DEV') && EXTRCH_LINKPAGE_DEV);
    $is_extrachill_link_host = (strpos(strtolower($_SERVER['HTTP_HOST'] ?? ''), 'extrachill.link') !== false);

    if (is_singular('band_link_page')) {
        $current_link_page_post = get_queried_object();
        if ($current_link_page_post && $current_link_page_post->ID) {
            $link_page_id = $current_link_page_post->ID;

            $temp_redirect_enabled = get_post_meta($link_page_id, '_link_page_redirect_enabled', true);
            if ($temp_redirect_enabled === '1') {
                $target_redirect_url = get_post_meta($link_page_id, '_link_page_redirect_target_url', true);
                if (!empty($target_redirect_url) && filter_var($target_redirect_url, FILTER_VALIDATE_URL)) {
                    if (!headers_sent()) {
                        wp_redirect(esc_url_raw($target_redirect_url), 302); // 302 for temporary
                        exit;
                    } else {
                        error_log('Extrch Link Page Temporary Redirect Error: Headers already sent for link page ID ' . $link_page_id);
                    }
                }
            }
            if (!$is_dev_mode && !$is_extrachill_link_host) {
                $associated_band_profile_id = get_post_meta($link_page_id, '_associated_band_profile_id', true);
                if ($associated_band_profile_id) {
                    $band_profile_post = get_post($associated_band_profile_id);
                    if ($band_profile_post && !empty($band_profile_post->post_name)) {
                        $band_slug = $band_profile_post->post_name;
                        $target_url_path = '/' . $band_slug . '/';
                        $target_url = 'https://extrachill.link' . $target_url_path;
                        if (!empty($_SERVER['QUERY_STRING'])) {
                            $target_url .= '?' . $_SERVER['QUERY_STRING'];
                        }
                        if (!headers_sent()) {
                            wp_safe_redirect(esc_url_raw($target_url), 301);
                            exit;
                        } else {
                            error_log('Extrch Link Page CPT Redirect Error: Headers already sent for link page ID ' . $link_page_id);
                        }
                    } else {
                        error_log('Extrch Link Page CPT Redirect Error: Could not find band_profile post or post_name for band_profile ID ' . $associated_band_profile_id);
                    }
                } else {
                    error_log('Extrch Link Page CPT Redirect Error: _associated_band_profile_id not found for link page ID ' . $link_page_id);
                }
            }
        }
    }
    error_log('[DEBUG] template_redirect action (extrch_redirect_band_link_page_cpt_to_custom_domain) processed. Host: ' . ($_SERVER['HTTP_HOST'] ?? '') . ' is_singular(band_link_page): ' . (is_singular('band_link_page') ? 'true' : 'false'));
}
// --- END: Redirect direct CPT access to extrachill.link domain ---


