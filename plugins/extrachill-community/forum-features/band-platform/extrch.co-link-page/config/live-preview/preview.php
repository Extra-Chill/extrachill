<?php
/**
 * Partial: preview.php (live preview partial)
 * Modular, isolated live preview for extrch.co Link Page customization.
 *
 * Expects query_vars:
 * - 'preview_template_data': Array containing all content data (title, bio, links, etc.)
 * - 'initial_container_style_for_php_preview': String for the main container's style attribute (primarily background).
 */

// Get data passed from the parent template (manage-link-page.php or AJAX handler)
$preview_template_data = get_query_var('preview_template_data', null);
$container_style_attr = get_query_var('initial_container_style_for_php_preview', '');

// Set a query var to indicate this is the preview iframe context
set_query_var('is_extrch_preview_iframe', true);

if (!isset($preview_template_data) || !is_array($preview_template_data)) {
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
    echo '<div class="bp-notice bp-notice-error">Preview data not provided correctly.</div>';
    echo '</body></html>';
    return;
}

// Extract variables from the main data array for clarity in the template
$display_title   = isset($preview_template_data['display_title']) ? $preview_template_data['display_title'] : '';
$bio             = isset($preview_template_data['bio']) ? $preview_template_data['bio'] : '';
$profile_img_url = isset($preview_template_data['profile_img_url']) ? $preview_template_data['profile_img_url'] : '';
$social_links    = isset($preview_template_data['social_links']) && is_array($preview_template_data['social_links']) ? $preview_template_data['social_links'] : array();
$link_sections   = isset($preview_template_data['link_sections']) && is_array($preview_template_data['link_sections']) ? $preview_template_data['link_sections'] : array();
$powered_by      = isset($preview_template_data['powered_by']) ? (bool)$preview_template_data['powered_by'] : true;

$overlay_enabled = true;
if (isset($preview_template_data['overlay'])) {
    $overlay_enabled = $preview_template_data['overlay'] === '1';
}
$wrapper_class = 'extrch-link-page-content-wrapper' . ($overlay_enabled ? '' : ' no-overlay');

// Profile Image Shape Class
$profile_img_shape = isset($preview_template_data['profile_img_shape']) ? $preview_template_data['profile_img_shape'] : 'circle'; // Default to circle
$profile_img_shape_class = ' shape-' . esc_attr($profile_img_shape);

// CSS variables (like text color, button color, font) are now applied via a <style> tag
// in the parent document targeting the iframe's :root. This partial no longer injects them directly.
// The $container_style_attr is for the background (color, image, or gradient).

$theme_uri_for_preview = get_stylesheet_directory_uri();
?>
<!DOCTYPE html>
<html style="height:100%;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Page Preview</title>
    <style id="extrch-preview-initial-styles-from-php">
    :root {
        <?php
        $css_vars_to_use = isset($preview_template_data['css_vars_for_preview_style_tag']) && is_array($preview_template_data['css_vars_for_preview_style_tag']) 
                            ? $preview_template_data['css_vars_for_preview_style_tag'] 
                            : (isset($preview_template_data['css_vars']) && is_array($preview_template_data['css_vars']) ? $preview_template_data['css_vars'] : array());
        
        $title_font_family_from_vars = ''; // Restored
        $body_font_family_from_vars = ''; // Restored

        if (!empty($css_vars_to_use)) {
            foreach ($css_vars_to_use as $key => $value) {
                if (strpos($key, '--') === 0 && $value !== '' && $value !== null) {
                    echo esc_attr($key) . ': ' . esc_attr($value) . ";\n";
                    if ($key === '--link-page-title-font-family') { // Restored
                        $title_font_family_from_vars = $value; // Restored
                    }
                    if ($key === '--link-page-body-font-family') { // Restored
                        $body_font_family_from_vars = $value; // Restored
                    }
                }
            }
        }
        ?>
    }
    </style>
    <?php
    // RESTORED: Dynamic Google Font loading logic
    global $extrch_link_page_fonts; 
    if ( empty( $extrch_link_page_fonts ) ) {
        $font_config_path = dirname( __FILE__, 2 ) . '/link-page-font-config.php'; 
        if ( file_exists( $font_config_path ) ) {
            require_once $font_config_path;
        }
    }
    if ( ! empty( $extrch_link_page_fonts ) && is_array( $extrch_link_page_fonts ) ) {
        $loaded_font_urls_preview = array(); 
        $check_title_font = trim(explode(',', $title_font_family_from_vars)[0], " \t\n\r\0\x0B'\"");
        $check_body_font = trim(explode(',', $body_font_family_from_vars)[0], " \t\n\r\0\x0B'\"");
        if ( ! empty( $check_title_font ) ) {
            foreach ( $extrch_link_page_fonts as $font ) {
                if ( strcasecmp($font['value'], $check_title_font) === 0 && ! empty( $font['google_font_url'] ) && ! isset( $loaded_font_urls_preview[ $font['google_font_url'] ] ) ) {
                    echo '<link rel="stylesheet" href="' . esc_url( $font['google_font_url'] ) . '">' . "\n";
                    $loaded_font_urls_preview[ $font['google_font_url'] ] = true;
                }
            }
        }
        if ( ! empty( $check_body_font ) ) {
            foreach ( $extrch_link_page_fonts as $font ) {
                if ( strcasecmp($font['value'], $check_body_font) === 0 && ! empty( $font['google_font_url'] ) && ! isset( $loaded_font_urls_preview[ $font['google_font_url'] ] ) ) {
                    echo '<link rel="stylesheet" href="' . esc_url( $font['google_font_url'] ) . '">' . "\n";
                    $loaded_font_urls_preview[ $font['google_font_url'] ] = true;
                }
            }
        }
    }

    $share_modal_css_path_for_preview = '/forum-features/band-platform/extrch.co-link-page/css/extrch-share-modal.css';
    if (file_exists(get_stylesheet_directory() . $share_modal_css_path_for_preview)) {
        echo '<link rel="stylesheet" href="' . esc_url($theme_uri_for_preview . $share_modal_css_path_for_preview) . '?ver=' . esc_attr(filemtime(get_stylesheet_directory() . $share_modal_css_path_for_preview)) . '">' . "\n";
    }
    $public_css_path_for_preview = '/forum-features/band-platform/extrch.co-link-page/css/extrch-links.css';
    if (file_exists(get_stylesheet_directory() . $public_css_path_for_preview)) {
        echo '<link rel="stylesheet" href="' . esc_url($theme_uri_for_preview . $public_css_path_for_preview) . '?ver=' . esc_attr(filemtime(get_stylesheet_directory() . $public_css_path_for_preview)) . '">' . "\n";
    }
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">' . "\n";
    $share_modal_js_path_for_preview = '/forum-features/band-platform/extrch.co-link-page/js/extrch-share-modal.js';
    if (file_exists(get_stylesheet_directory() . $share_modal_js_path_for_preview)) {
        echo '<script src="' . esc_url($theme_uri_for_preview . $share_modal_js_path_for_preview) . '?ver=' . esc_attr(filemtime(get_stylesheet_directory() . $share_modal_js_path_for_preview)) . '" defer></script>' . "\n";
    }
    ?>
    <style>body { margin: 0; padding: 0; height:100%; } </style>
    <style>
        /* Initially hide the container to prevent FOUC */
        /* This targets the container class from extrch-link-page-template.php when in preview context */
        .extrch-link-page-preview-container {
            visibility: hidden;
        }
        /* Set a consistent base font size for the preview body to match frontend expectations */
        body {
            font-size: 16px; /* Or whatever the frontend theme's base body/html font-size is */
        }
    </style>
</head>
<body style="height:100%;">
<?php
$data = $preview_template_data;
$template_path = dirname( __FILE__, 3 ) . '/extrch-link-page-template.php';
if ( file_exists( $template_path ) ) {
    include $template_path;
} else {
    echo '<div class="bp-notice bp-notice-error">Shared template file not found. Path: ' . esc_html($template_path) . '</div>';
}
?>
<script>
    // Show the container only after the Customize tab's JavaScript has fully initialized and applied initial styles.
    document.addEventListener('extrchLinkPageCustomizeTabInitialized', function handleCustomizeTabReady() {
        const container = document.querySelector('.extrch-link-page-preview-container.extrch-link-page-container');
        if (container) {
            container.style.visibility = 'visible';
            console.log('[Preview Unhide] Preview container made visible after extrchLinkPageCustomizeTabInitialized.');
            // Notify the parent window that the preview is ready
            if (window.parent) {
                console.log('[Preview PostMessage] Sending extrchPreviewReady to parent.');
                window.parent.postMessage('extrchPreviewReady', '*'); 
            }
        } else {
            // Fallback for older structure or if class name changes, though less ideal
            const genericContainer = document.querySelector('.extrch-link-page-container');
            if (genericContainer) {
                genericContainer.style.visibility = 'visible';
                console.log('[Preview Unhide] Generic preview container made visible after extrchLinkPageCustomizeTabInitialized.');
                // Notify the parent window that the preview is ready (fallback case too)
                if (window.parent) {
                    console.log('[Preview PostMessage] Sending extrchPreviewReady to parent (fallback case).');
                    window.parent.postMessage('extrchPreviewReady', '*');
                }
            }
        }
        // Clean up the event listener if it's meant to run only once implicitly by DOM structure,
        // or explicitly if it were added to window/document and might fire multiple times otherwise.
        // Since this script is at the end of the preview's body, it effectively runs once per preview load.
    }, { once: true }); // Ensure it only runs once if the event were to be dispatched multiple times for some reason
</script>
</body>
</html> 