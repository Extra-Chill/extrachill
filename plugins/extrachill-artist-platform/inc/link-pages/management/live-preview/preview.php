<?php
/**
 * Partial: preview.php (live preview partial)
 * Modular, isolated live preview for extrch.co Link Page customization.
 *
 * Expects query_vars:
 * - 'preview_template_data': Array containing all content data (title, bio, links, etc.)
 * - 'initial_container_style_for_php_preview': String for the main container's style attribute (primarily background).
 */

// Extract arguments passed from ec_render_template
$preview_template_data = $preview_data ?? array();
$container_style_attr = $preview_template_data['background_style'] ?? '';

if (!isset($preview_template_data) || !is_array($preview_template_data)) {
    echo '<div class="bp-notice bp-notice-error">Preview data not provided correctly.</div>';
    return;
}

// Extract variables from the main data array for clarity in the template
$display_title   = isset($preview_template_data['display_title']) ? $preview_template_data['display_title'] : '';
$bio             = isset($preview_template_data['bio']) ? $preview_template_data['bio'] : '';
$profile_img_url = isset($preview_template_data['profile_img_url']) ? $preview_template_data['profile_img_url'] : '';
$social_links    = isset($preview_template_data['social_links']) && is_array($preview_template_data['social_links']) ? $preview_template_data['social_links'] : array();
$link_sections   = isset($preview_template_data['link_sections']) && is_array($preview_template_data['link_sections']) ? $preview_template_data['link_sections'] : array();
$powered_by      = isset($preview_template_data['powered_by']) ? (bool)$preview_template_data['powered_by'] : true;

// Extract CSS variables for live preview
$css_vars = isset($preview_template_data['css_vars']) && is_array($preview_template_data['css_vars']) ? $preview_template_data['css_vars'] : array();

$overlay_enabled = true;
if (isset($preview_template_data['overlay'])) {
    $overlay_enabled = $preview_template_data['overlay'] === '1';
}
$wrapper_class = 'extrch-link-page-content-wrapper' . ($overlay_enabled ? '' : ' no-overlay');

// Profile Image Shape Class
$profile_img_shape = isset($preview_template_data['profile_img_shape']) ? $preview_template_data['profile_img_shape'] : 'circle'; // Default to circle
$profile_img_shape_class = ' shape-' . esc_attr($profile_img_shape);

// Set up container classes and style
$container_classes = 'extrch-link-page-container extrch-link-page-preview-container';
$container_style = 'display:flex; flex-direction:column; height:100%; min-height:100%; box-sizing:border-box;';
$bg_type = isset($preview_template_data['background_type']) ? $preview_template_data['background_type'] : 'color';

$background_image_url = isset($preview_template_data['background_image_url']) ? $preview_template_data['background_image_url'] : '';
$inline_bg_style = $background_image_url ?
    'background-image:url(' . esc_url($background_image_url) . ');background-size:cover;background-position:center;background-repeat:no-repeat;min-height:100vh;' :
    '';

// Fetch social icons position setting for preview
$social_icons_position = isset($preview_template_data['_link_page_social_icons_position']) ? $preview_template_data['_link_page_social_icons_position'] : 'above';

// Get IDs from preview data
$link_page_id = isset($preview_template_data['link_page_id']) ? $preview_template_data['link_page_id'] : 0;
$artist_id = apply_filters('ec_get_artist_id', $link_page_id);

// Load required stylesheets for preview
$plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
$plugin_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;

$extrch_links_css = 'assets/css/extrch-links.css';
$share_modal_css = 'assets/css/extrch-share-modal.css';

// Output stylesheets directly since we're not using wp_enqueue_style in preview
if (file_exists($plugin_dir . $extrch_links_css)): ?>
    <link rel="stylesheet" href="<?php echo esc_url($plugin_url . $extrch_links_css); ?>?ver=<?php echo esc_attr(filemtime($plugin_dir . $extrch_links_css)); ?>">
<?php endif;

if (file_exists($plugin_dir . $share_modal_css)): ?>
    <link rel="stylesheet" href="<?php echo esc_url($plugin_url . $share_modal_css); ?>?ver=<?php echo esc_attr(filemtime($plugin_dir . $share_modal_css)); ?>">
<?php endif; ?>

<!-- Load Font Awesome for icons (same as live page) -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.1/css/all.min.css">

<?php
// Load Google Fonts for preview using raw font values (base names, not processed stacks)
if (class_exists('ExtraChillArtistPlatform_Fonts') && isset($preview_template_data['raw_font_values'])) {
    $font_values = array();
    $raw_fonts = $preview_template_data['raw_font_values'];
    
    if (!empty($raw_fonts['title_font'])) {
        $font_values[] = $raw_fonts['title_font'];
    }
    if (!empty($raw_fonts['body_font'])) {
        $font_values[] = $raw_fonts['body_font'];
    }
    
    if (!empty($font_values)) {
        $font_manager = ExtraChillArtistPlatform_Fonts::instance();
        $font_url = $font_manager->get_google_fonts_url($font_values);
        if (!empty($font_url)) {
            echo '<link rel="stylesheet" href="' . esc_url($font_url) . '" media="print" onload="this.media=\'all\'">';
            echo '<noscript><link rel="stylesheet" href="' . esc_url($font_url) . '"></noscript>';
        }
        
        // Generate @font-face CSS for local fonts (dynamic loading like Google Fonts)
        $local_fonts_css = $font_manager->get_local_fonts_css($font_values);
        if (!empty($local_fonts_css)) {
            echo '<style>' . $local_fonts_css . '</style>';
        }
    }
}
?>

<div class="extrch-link-page-preview-bg-wrapper" style="<?php echo esc_attr($inline_bg_style); ?>">
<div class="<?php echo esc_attr($container_classes); ?>"
     data-bg-type="<?php echo esc_attr($bg_type); ?>">
    <div class="<?php echo esc_attr($wrapper_class); ?>" style="flex-grow:1;">
        <div class="extrch-link-page-header-content">
    <?php
            // --- Subscribe Bell Icon (Preview) ---
            $subscribe_display_mode = isset($preview_template_data['_link_page_subscribe_display_mode']) ? $preview_template_data['_link_page_subscribe_display_mode'] : 'icon_modal';
            if ($subscribe_display_mode === 'icon_modal') : ?>
                <button class="extrch-share-trigger extrch-subscribe-icon-trigger extrch-bell-page-trigger" aria-label="Subscribe to this artist (preview)">
                    <i class="fas fa-bell"></i>
                </button>
            <?php endif; ?>
            <?php
            $img_container_classes = "extrch-link-page-profile-img " . $profile_img_shape_class;
            $no_image_class = empty($profile_img_url) ? ' no-image' : '';
            ?>
            <div class="<?php echo esc_attr($img_container_classes . $no_image_class); ?>">
                <img src="<?php echo esc_url($profile_img_url); ?>" alt="<?php echo esc_attr($display_title); ?>">
            </div>
            <h1 class="extrch-link-page-title"><?php echo esc_html($display_title); ?></h1>
            <?php if (!empty($bio)): ?><div class="extrch-link-page-bio"><?php echo esc_html($bio); ?></div><?php endif; ?>
            <button class="extrch-share-trigger extrch-share-page-trigger" aria-label="Share this page" data-share-type="page" data-share-url="<?php echo esc_url(home_url('/')); ?>" data-share-title="<?php echo esc_attr($display_title); ?>">
                <i class="fas fa-ellipsis-h"></i>
            </button>
        </div>
        <?php
        // --- Social Icons (Preview) - Conditionally render above links using filter system ---
        if ($social_icons_position === 'above' && !empty($social_links)) {
            echo ec_render_social_icons_container($social_links, 'above');
        }
        
        // --- Inject Custom CSS Variables for Live Preview ---
        // Use centralized CSS variables from ec_get_link_page_data (single source of truth)
        // $css_vars already defined earlier in the file
            
        // Output CSS variables using centralized function
        if ( function_exists( 'ec_generate_css_variables_style_block' ) ) {
            echo ec_generate_css_variables_style_block( $css_vars, 'extrch-link-page-custom-vars' );
        }
        
        if (!empty($link_sections)): ?>
            <div class="extrch-link-page-links">
                <?php 
                foreach ($link_sections as $section_index => $section):
                    $section_args = array(
                        'section_title' => $section['section_title'] ?? '',
                        'links' => $section['links'] ?? array(),
                        'link_page_id' => 0 // No YouTube embed for preview
                    );
                    ?>
                    <div class="extrch-link-page-section" data-section-index="<?php echo esc_attr($section_index); ?>">
                        <?php echo ec_render_link_section( $section, $section_args ); ?>
                    </div>
                    <?php
                endforeach; 
                ?>
            </div>
        <?php endif; // End check for $link_sections ?>
        <?php
        // Output the inline subscribe form using the same template as the live page
        if ($subscribe_display_mode === 'inline_form') {
            echo ec_render_template('subscribe-inline-form', array(
                'artist_id' => $artist_id,
                'data' => $preview_template_data
            ));
        }
        ?>
        <?php
        // --- Social Icons (Preview) - Conditionally render below links and subscribe form using filter system ---
        if ($social_icons_position === 'below' && !empty($social_links)) {
            echo ec_render_social_icons_container($social_links, 'below');
        } ?>
        <div class="extrch-link-page-powered" style="margin-top:auto; padding-top:1em; padding-bottom:1em;">
            <a href="https://extrachill.link" rel="noopener">Powered by Extra Chill</a>
        </div>
    </div>
    <?php
    // Include reusable share modal template (same markup as live page)
    echo ec_render_template('share-modal');
    ?>
    <?php
    // Output the subscribe modal using the same template as the live page
    if ($subscribe_display_mode === 'icon_modal') {
        echo ec_render_template('subscribe-modal', array(
            'artist_id' => $artist_id,
            'data' => $preview_template_data
        ));
    }
    ?>
</div>
</div>

<script>
    // Configure font data for preview JavaScript
    window.extrchLinkPageConfig = window.extrchLinkPageConfig || {};
    <?php 
    // Add Google Font metadata for dynamic loading
    if (class_exists('ExtraChillArtistPlatform_Fonts')) {
        $font_manager = ExtraChillArtistPlatform_Fonts::instance();
        $supported_fonts = $font_manager->get_supported_fonts();
        echo 'window.extrchLinkPageConfig.fonts = ' . wp_json_encode($supported_fonts) . ';';
    }
    ?>
    
    // Only send postMessage to parent when preview is ready, no visibility logic needed
    if (window.parent) {
        window.parent.postMessage('extrchPreviewReady', '*');
    }
</script>