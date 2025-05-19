<?php
/**
 * Custom rewrites and template loader for extrch.co public link pages.
 * Handles default landing page logic for extrachill.link root slug and admin-only editing.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

// Register custom query variables
add_filter( 'query_vars', 'extrch_register_custom_query_vars' );
function extrch_register_custom_query_vars( $vars ) {
    $vars[] = 'dev_view_link_page';
    return $vars;
}

add_filter( 'template_include', function( $template ) {
    // If EXTRCH_LINKPAGE_DEV is true, do not apply any custom template logic for extrachill.link.
    if ( defined( 'EXTRCH_LINKPAGE_DEV' ) && EXTRCH_LINKPAGE_DEV ) {
        error_log('[DEBUG] template_include: EXTRCH_LINKPAGE_DEV is true. Skipping all extrachill.link template logic.');
        return $template;
    }

    error_log('[DEBUG] template_include filter fired. Request URI: ' . ($_SERVER['REQUEST_URI'] ?? '') . ' Host: ' . ($_SERVER['HTTP_HOST'] ?? '')); // DEBUG
    $current_host = strtolower( $_SERVER['HTTP_HOST'] ?? '' );

    // --- START extrch.co redirect logic ---
    // if ( stripos( $current_host, 'extrch.co' ) !== false ) {
    //     // ... (extrch.co redirect code) ...
    //     return $template; // Or exit
    // }
    // --- END extrch.co redirect logic ---

    $is_link_page_domain = ( stripos( $current_host, 'extrachill.link' ) !== false );

    if ( ! $is_link_page_domain ) {
        error_log('[DEBUG] template_include: Not extrachill.link domain, returning original template for host: ' . $current_host);
        return $template; // Not extrachill.link, do nothing more here.
    }

    // The rest of this function is for extrachill.link domain handling.
    // It should not execute if the host is community-stage.local.
    // To be absolutely sure for testing, we can return early if not extrachill.link.

    /* --- COMMENTING OUT extrachill.link SPECIFIC LOGIC FOR LOCAL DEBUGGING ---
    // ... (all the logic for $potential_slug, default admin items, root domain, /manage-link-page, /band-slug for extrachill.link) ...
    */ // --- END OF COMMENTING OUT ---

    error_log('[DEBUG] template_include: Reached end of filter for extrachill.link, returning original template (should not happen for community-stage.local).');
    return $template;
});


// --- START: Redirect direct CPT access to extrachill.link domain ---
add_action( 'template_redirect', 'extrch_redirect_band_link_page_cpt_to_custom_domain' );

/**
 * Redirects direct access to 'band_link_page' CPT posts (via their WordPress permalinks)
 * to their canonical URL on the extrachill.link domain.
 */
function extrch_redirect_band_link_page_cpt_to_custom_domain() {
    // If EXTRCH_LINKPAGE_DEV is true, do not redirect CPT permalinks *to the custom domain*,
    // but still allow temporary redirect logic to run for it.
    $is_dev_mode = (defined('EXTRCH_LINKPAGE_DEV') && EXTRCH_LINKPAGE_DEV);
    $is_extrachill_link_host = (strpos(strtolower($_SERVER['HTTP_HOST'] ?? ''), 'extrachill.link') !== false);

    // Only proceed if we're on a singular band_link_page
    if (is_singular('band_link_page')) {
        $current_link_page_post = get_queried_object();
        if ($current_link_page_post && $current_link_page_post->ID) {
            $link_page_id = $current_link_page_post->ID;

            // --- Temporary Redirect Logic --- 
            $temp_redirect_enabled = get_post_meta($link_page_id, '_link_page_redirect_enabled', true);
            if ($temp_redirect_enabled === '1') {
                $target_redirect_url = get_post_meta($link_page_id, '_link_page_redirect_target_url', true);
                if (!empty($target_redirect_url) && filter_var($target_redirect_url, FILTER_VALIDATE_URL)) {
                    if (!headers_sent()) {
                        wp_redirect(esc_url_raw($target_redirect_url), 302); // 302 for temporary
                        exit;
                    } else {
                        error_log('Extrch Link Page Temporary Redirect Error: Headers already sent for link page ID ' . $link_page_id);
                        // If headers sent, redirect cannot happen, page will load as normal.
                    }
                }
            }
            // --- End Temporary Redirect Logic ---

            // --- Existing CPT to Custom Domain Redirect Logic ---
            // This part should only run if temporary redirect did NOT happen AND we are NOT in dev mode AND NOT on extrachill.link host.
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
            // If in dev mode or on extrachill.link host, and no temporary redirect, template will load normally.
        }
    }
    error_log('[DEBUG] template_redirect action (extrch_redirect_band_link_page_cpt_to_custom_domain) processed. Host: ' . ($_SERVER['HTTP_HOST'] ?? '') . ' is_singular(band_link_page): ' . (is_singular('band_link_page') ? 'true' : 'false'));
}
// --- END: Redirect direct CPT access to extrachill.link domain ---

