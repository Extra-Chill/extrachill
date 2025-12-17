<?php
/**
 * Public Link Page Template for extrch.co
 * Blank slate, mobile-first, Linktree-style.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;
// Font configuration now handled by centralized font manager

global $wp_query; // Make sure $wp_query is available


// Use the current post as the link page
$link_page = $wp_query->get_queried_object(); // Get the post object from the main query

if ( !$link_page || !isset($link_page->ID) || $link_page->post_type !== 'artist_link_page' ) {
    
    // If the queried object isn't what we expect, then it's a genuine issue.
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not Found</title></head><body><h1>Link Page Not Found (Invalid Query)</h1></body></html>';
    exit;
}

$link_page_id = $link_page->ID;

$artist_id = apply_filters('ec_get_artist_id', $link_page_id);

$artist_profile = $artist_id ? get_post($artist_id) : null;

if ( !$artist_profile ) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not Found</title></head><body><h1>Link Page Not Found</h1></body></html>';
    exit;
}

// Ensure ec_get_link_page_data filter function is available.
if ( ! function_exists( 'ec_get_link_page_data' ) ) {
    $data_filter_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/filters/data.php';
    if ( file_exists( $data_filter_path ) ) {
        require_once $data_filter_path;
    }
}

$data = ec_get_link_page_data( $artist_id, $link_page_id );
$data['original_link_page_id'] = $link_page_id;

$body_bg_style = '';
$background_type = isset($data['background_type']) ? $data['background_type'] : ec_get_link_page_default( 'settings', 'background_type', 'color' );
$background_image_url = isset($data['background_image_url']) ? $data['background_image_url'] : '';
$background_color = isset($data['background_color']) ? $data['background_color'] : ec_get_link_page_default( 'settings', 'background_color', '#1a1a1a' );
$background_gradient_start = isset($data['background_gradient_start']) ? $data['background_gradient_start'] : ec_get_link_page_default( 'styles', '--link-page-background-gradient-start', '#0b5394' );
$background_gradient_end = isset($data['background_gradient_end']) ? $data['background_gradient_end'] : ec_get_link_page_default( 'styles', '--link-page-background-gradient-end', '#53940b' );
$background_gradient_direction = isset($data['background_gradient_direction']) ? $data['background_gradient_direction'] : ec_get_link_page_default( 'styles', '--link-page-background-gradient-direction', 'to right' );

if ($background_type === 'image' && !empty($background_image_url)) {
    $body_bg_style = 'background-image:url(' . esc_url($background_image_url) . ');background-size:cover;background-position:center;background-repeat:no-repeat;background-attachment:fixed;';
} elseif ($background_type === 'gradient') {
    $body_bg_style = 'background:linear-gradient(' . esc_attr($background_gradient_direction) . ', ' . esc_attr($background_gradient_start) . ', ' . esc_attr($background_gradient_end) . ');background-attachment:fixed;';
} else { // 'color' or default
    $body_bg_style = 'background-color:' . esc_attr($background_color) . ';';
}
// Ensure body takes full height
$body_bg_style .= 'min-height:100vh;';

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <?php
    // Call the custom head function to output minimal, necessary head elements
    if (function_exists('extrch_link_page_custom_head')) {
        extrch_link_page_custom_head( $artist_id, $link_page_id );
    } else {
        // Fallback basic meta if the function isn't loaded, though it should be.
        echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $artist_title_fallback = $artist_id ? get_the_title( $artist_id ) : 'Link Page';
        echo '<title>' . esc_html( $artist_title_fallback ) . ' | extrachill.link</title>';
    }
    ?>
</head>
<?php
$permissions_api_url = rest_url( 'extrachill/v1/artists/' . $artist_id . '/permissions' );
$subscribe_api_url = rest_url( 'extrachill/v1/artists/' . $artist_id . '/subscribe' );
$tracking_click_url = rest_url( 'extrachill/v1/analytics/link-click' );
$tracking_view_url = rest_url( 'extrachill/v1/analytics/view' );
?>
<body class="extrch-link-page"<?php if ($body_bg_style) echo ' style="' . esc_attr( $body_bg_style ) . '"'; ?> data-extrch-artist-id="<?php echo esc_attr( (string) absint( $artist_id ) ); ?>" data-extrch-link-page-id="<?php echo esc_attr( (string) absint( $link_page_id ) ); ?>" data-extrch-permissions-api-url="<?php echo esc_url( $permissions_api_url ); ?>" data-extrch-subscribe-api-url="<?php echo esc_url( $subscribe_api_url ); ?>" data-extrch-tracking-click-url="<?php echo esc_url( $tracking_click_url ); ?>" data-extrch-tracking-view-url="<?php echo esc_url( $tracking_view_url ); ?>">
<?php
// Google Tag Manager (noscript)
?>
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-NXKDLFD"
            height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<?php
/**
 * Edit button security model: Client-side only rendering with zero server-side HTML.
 * JavaScript performs a credentialed CORS permission check (extrachill.link → artist.extrachill.com)
 * and renders the button only if authorized.
 *
 * Related:
 * - JS: inc/link-pages/live/assets/js/link-page-edit-button.js
 * - REST endpoint: extrachill-api/inc/routes/artist/permissions.php
 */

    // Pass $data explicitly to the template so overlay and all settings are available
    $extrch_link_page_template_data = $data;
    // Add the link_page_id to the $extrch_link_page_template_data array as well for good measure
    // though the template now tries to get it from $data['original_link_page_id'] first.
    $extrch_link_page_template_data['original_link_page_id'] = $link_page_id;

    require EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/live/templates/extrch-link-page-template.php';
    ?>
    <?php wp_print_footer_scripts(); // Output scripts enqueued for footer ?>
</body>
</html> 