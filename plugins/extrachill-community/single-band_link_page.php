<?php
/**
 * Public Link Page Template for extrch.co
 * Blank slate, mobile-first, Linktree-style.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

require_once( __DIR__ . '/forum-features/band-platform/extrch.co-link-page/config/link-page-font-config.php' );
global $extrch_link_page_fonts;

// Use the current post as the link page
global $post;
$link_page = $post;
$link_page_id = $post->ID;
$band_id = get_post_meta($link_page_id, '_associated_band_profile_id', true);
$band_profile = $band_id ? get_post($band_id) : null;

if ( !$band_profile ) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not Found</title></head><body><h1>Link Page Not Found</h1></body></html>';
    exit;
}

// Ensure LivePreviewManager class is available.
// It should be included by link-page-includes.php.
if ( ! class_exists( 'LivePreviewManager' ) ) {
    $live_preview_manager_path = dirname( __FILE__ ) . '/forum-features/band-platform/extrch.co-link-page/config/live-preview/LivePreviewManager.php';
    if ( file_exists( $live_preview_manager_path ) ) {
        require_once $live_preview_manager_path;
    }
}

if ( class_exists( 'LivePreviewManager' ) ) {
    $data = LivePreviewManager::get_preview_data( $link_page_id, $band_id, array() ); // No overrides for public page
} else {
    // Fallback if LivePreviewManager somehow isn't loaded
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
<?php if (is_user_logged_in() && current_user_can('manage_band_members', $band_id)) :
    $manage_url = site_url('/manage-link-page/?band_id=' . $band_id);
?>
    <a href="<?php echo esc_url($manage_url); ?>" class="extrch-link-page-edit-btn">
        <i class="fas fa-pencil-alt"></i>
    </a>
<?php endif; ?>
    <?php
    // Pass $data explicitly to the template so overlay and all settings are available
    $extrch_link_page_template_data = $data;
    require locate_template('forum-features/band-platform/extrch.co-link-page/extrch-link-page-template.php');
    ?>
    <?php wp_print_footer_scripts(); // Output scripts enqueued for footer ?>
</body>
</html> 