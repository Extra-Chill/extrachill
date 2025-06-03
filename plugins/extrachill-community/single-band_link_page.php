<?php
/**
 * Public Link Page Template for extrch.co
 * Blank slate, mobile-first, Linktree-style.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;
require_once( __DIR__ . '/band-platform/extrch.co-link-page/link-page-font-config.php' );
global $extrch_link_page_fonts;

global $wp_query; // Make sure $wp_query is available

// Use the current post as the link page
$link_page = $wp_query->get_queried_object(); // Get the post object from the main query

if ( !$link_page || !isset($link_page->ID) || $link_page->post_type !== 'band_link_page' ) {
    // If the queried object isn't what we expect, then it's a genuine issue.
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not Found</title></head><body><h1>Link Page Not Found (Invalid Query)</h1></body></html>';
    exit;
}

$link_page_id = $link_page->ID;

$band_id = get_post_meta($link_page_id, '_associated_band_profile_id', true);

$band_profile = $band_id ? get_post($band_id) : null;

if ( !$band_profile ) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not Found</title></head><body><h1>Link Page Not Found</h1></body></html>';
    exit;
}

// Ensure LinkPageDataProvider class is available.
if ( ! class_exists( 'LinkPageDataProvider' ) ) {
    $data_provider_path = dirname( __FILE__ ) . '/band-platform/extrch.co-link-page/data/LinkPageDataProvider.php';
    if ( file_exists( $data_provider_path ) ) {
        require_once $data_provider_path;
    }
}

if ( class_exists( 'LinkPageDataProvider' ) ) {
    $data = LinkPageDataProvider::get_data( $link_page_id, $band_id, array() ); // No overrides for public page
    $data['original_link_page_id'] = $link_page_id; // Add the actual link page ID to $data
} else {
    // Fallback if LinkPageDataProvider somehow isn't loaded
    $data = array(
        'display_title' => get_the_title($band_id) ?: 'Link Page',
        'bio' => '',
        'profile_img_url' => '',
        'social_links' => array(),
        'link_sections' => array(),
        'powered_by' => true,
        'css_vars' => array(),
        'background_type' => 'color',
        'background_color' => '#1a1a1a',
        // Add other necessary defaults to prevent errors in the template
    );
    $data['original_link_page_id'] = $link_page_id; // Add the actual link page ID to $data
}

// Also ensure it's added if LinkPageDataProvider provides the data
if (isset($data) && is_array($data)) {
    $data['original_link_page_id'] = $link_page_id; 
}

$body_bg_style = '';
$background_type = isset($data['background_type']) ? $data['background_type'] : 'color'; // Default to color
$background_image_url = isset($data['background_image_url']) ? $data['background_image_url'] : '';
$background_color = isset($data['background_color']) ? $data['background_color'] : '#1a1a1a'; // Default page background color
$background_gradient_start = isset($data['background_gradient_start']) ? $data['background_gradient_start'] : '#0b5394';
$background_gradient_end = isset($data['background_gradient_end']) ? $data['background_gradient_end'] : '#53940b';
$background_gradient_direction = isset($data['background_gradient_direction']) ? $data['background_gradient_direction'] : 'to right';

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
        extrch_link_page_custom_head( $band_id, $link_page_id );
    } else {
        // Fallback basic meta if the function isn't loaded, though it should be.
        echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $band_title_fallback = $band_id ? get_the_title( $band_id ) : 'Link Page';
        echo '<title>' . esc_html( $band_title_fallback ) . ' | extrachill.link</title>';
    }
    ?>
</head>
<body class="extrch-link-page"<?php if ($body_bg_style) echo ' style="' . esc_attr( $body_bg_style ) . '"'; ?>>
<?php
// Google Tag Manager (noscript)
?>
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-NXKDLFD"
            height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<?php
// Display the edit button only if the user can manage the band.
// This logic is now handled by JavaScript after an AJAX check.
// if ( $can_manage_band ) {
    $manage_url = 'https://community.extrachill.com/manage-link-page/?band_id=' . $band_id; // Link directly to the main site for session recognition
    ?>
    <a href="<?php echo esc_url($manage_url); ?>" class="extrch-link-page-edit-btn">
        <i class="fas fa-pencil-alt"></i>
    </a>
    <?php
// }
?>
    <?php
    // Pass $data explicitly to the template so overlay and all settings are available
    $extrch_link_page_template_data = $data;
    // Add the link_page_id to the $extrch_link_page_template_data array as well for good measure
    // though the template now tries to get it from $data['original_link_page_id'] first.
    $extrch_link_page_template_data['original_link_page_id'] = $link_page_id;

    require locate_template('band-platform/extrch.co-link-page/extrch-link-page-template.php');
    ?>
    <?php wp_print_footer_scripts(); // Output scripts enqueued for footer ?>
</body>
</html> 