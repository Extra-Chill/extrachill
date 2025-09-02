<?php
/**
 * Partial: extrch-link-page-template.php
 * Shared markup for extrch.co Link Page (public and preview).
 *
 * @param string $profile_img_url
 * @param string $display_title
 * @param string $bio
 * @param array  $links
 * @param array  $social_links (optional)
 * @param bool   $powered_by (optional, default true)
 */

// error_log('[DEBUG TEMPLATE] extrch-link-page-template.php loaded. Current Post ID in global $post: ' . (isset($GLOBALS['post']) ? $GLOBALS['post']->ID : 'Not set'));
// REMOVED: error_log('[DEBUG TEMPLATE] Query vars: ' . print_r(get_defined_vars(), true)); // THIS WAS CAUSING MEMORY EXHAUSTION

// If $data is not already set by the caller (e.g., AJAX handler or direct include with params), then fetch it.
if ( ! isset( $data ) || ! is_array( $data ) ) {
    // Resolve IDs using centralized helpers with sensible fallbacks
    if ( ! isset( $link_page_id ) || ! is_numeric( $link_page_id ) ) {
        $link_page_id = apply_filters('ec_get_link_page_id', 0);
    }
    if ( ! isset( $artist_id ) || ! is_numeric( $artist_id ) ) {
        $artist_id = apply_filters('ec_get_artist_id', $link_page_id);
    }

    // Ensure ec_get_link_page_data filter function is available.
    $data_filter_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/filters/data.php';
    if ( file_exists( $data_filter_path ) ) {
        require_once $data_filter_path;
    }

    if ( $link_page_id && $artist_id ) {
        $data = ec_get_link_page_data( (int) $artist_id, (int) $link_page_id );
    } else {
        $data = array( 
            'display_title' => 'Error: Link Page Data Unavailable',
            'bio' => '',
            'profile_img_url' => '',
            'social_links' => array(),
            'link_sections' => array(),
            'powered_by' => true,
            'css_vars' => array(),
            'background_style' => 'background-color: #f0f0f0;',
        );
    }
}

// If $extrch_link_page_template_data is set (passed explicitly), use it as $data
if (isset($extrch_link_page_template_data) && is_array($extrch_link_page_template_data)) {
    $data = $extrch_link_page_template_data;
}

// Ensure essential keys exist in $data to prevent undefined index errors,
// especially if ec_get_link_page_data() might not return them all in some edge case.
$data['powered_by'] = isset($data['powered_by']) ? $data['powered_by'] : true;
$data['display_title'] = isset($data['display_title']) ? $data['display_title'] : '';
$data['bio'] = isset($data['bio']) ? $data['bio'] : '';
$data['profile_img_url'] = isset($data['profile_img_url']) ? $data['profile_img_url'] : '';
$data['social_links'] = isset($data['social_links']) && is_array($data['social_links']) ? $data['social_links'] : [];

// ec_get_link_page_data() now returns 'link_sections' directly.
$link_sections = isset($data['link_sections']) && is_array($data['link_sections']) ? $data['link_sections'] : [];

// Determine the inline style for the container.
// For the preview iframe, it receives 'initial_container_style_for_php_preview' via query_var.
// For the public page, the container's background is now primarily controlled by CSS
// (e.g., transparent to show body background, or a card color via CSS var).
// We only apply the direct $data['background_style'] here if it's the preview iframe.

$initial_container_style_attr = '';
$container_classes = 'extrch-link-page-container'; // Base class
$is_preview_iframe_context = (bool) get_query_var('is_extrch_preview_iframe', false); // This query var should be set in management/live-preview/preview.php

if ( $is_preview_iframe_context ) {
    $container_classes .= ' extrch-link-page-preview-container'; // Add preview-specific class
    // For the preview iframe, ensure it behaves as a flex container filling height.
    // All other styles (background, colors, fonts) are applied via CSS variables injected
    // into the iframe's :root by preview.php.
    $initial_container_style_attr = ' style="display:flex; flex-direction:column; height:100%; min-height:100%; box-sizing:border-box;"';
} else {
    // For the public page (single-artist_link_page.php)
    // The .extrch-link-page-container's background should be handled by css/extrch-links.css.
    // It might be transparent (if body has the main bg) or a card color (using a CSS var).
    // We no longer apply $data['background_style'] directly here for the public page,
    // nor do we append CSS variable definitions to its inline style.
    // If a specific inline style is ever needed for the public container beyond what CSS can do,
    // it would be constructed carefully here, but typically it won't be for background.
    // For now, no inline style is applied to the public page container from this template.
}

// Note: The $data['css_vars'] are outputted as a :root style block in single-artist_link_page.php
// and are used by extrch-links.css. They are not applied as inline styles here.

// Determine profile image shape class
$profile_img_shape_class = 'shape-square'; // Default to square
if (isset($data['profile_img_shape'])) {
    if ($data['profile_img_shape'] === 'circle') {
        $profile_img_shape_class = 'shape-circle';
    } elseif ($data['profile_img_shape'] === 'rectangle') {
        $profile_img_shape_class = 'shape-rectangle';
    } elseif ($data['profile_img_shape'] === 'square') {
        $profile_img_shape_class = 'shape-square';
    } else {
        $profile_img_shape_class = 'shape-square'; // fallback for unknown values
    }
}

$overlay_enabled = true;
if (isset($data['overlay'])) {
    $overlay_enabled = $data['overlay'] === '1';
}
$wrapper_class = 'extrch-link-page-content-wrapper' . ($overlay_enabled ? '' : ' no-overlay');

// Ensure all variables used in data attributes are defined in the scope
$single_artist_link_page_id = isset($data['_actual_link_page_id_for_template']) ? $data['_actual_link_page_id_for_template'] : (isset($link_page_id) ? $link_page_id : 0);
if (empty($single_artist_link_page_id) && isset($extrch_link_page_template_data['original_link_page_id'])) {
    $single_artist_link_page_id = $extrch_link_page_template_data['original_link_page_id'];
}

// Manually construct the extrachill.link URL using the artist slug
$artist_slug = isset($data['artist_profile']->post_name) ? $data['artist_profile']->post_name : '';
$share_page_url = !empty($artist_slug) ? 'https://extrachill.link/' . $artist_slug : home_url('/'); // Fallback to home_url if slug is empty

// If we're on extrachill.link and no session token exists, check if user came from management interface
$current_host = strtolower($_SERVER['HTTP_HOST'] ?? '');
if ($current_host === 'extrachill.link' && empty($_COOKIE['ecc_user_session_token'])) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (strpos($referer, 'community.extrachill.com/manage-link-page') !== false || 
        strpos($referer, 'community.extrachill.com/manage-artist-profile') !== false) {
        // User came from management interface but has no session token on extrachill.link
        // This suggests they need cross-domain session synchronization
    }
}

$bg_type = isset($data['css_vars']['--link-page-background-type']) ? $data['css_vars']['--link-page-background-type'] : 'color';

if (isset($data) && is_array($data)) {
    $data['original_link_page_id'] = $link_page_id; 
}

// Fetch subscribe display mode setting
$subscribe_display_mode = $data['_link_page_subscribe_display_mode'] ?? ec_get_link_page_default( 'settings', 'subscribe_display_mode', 'icon_modal' );

// Fetch social icons position setting
$social_icons_position = $data['_link_page_social_icons_position'] ?? ec_get_link_page_default( 'settings', 'social_icons_position', 'above' );

$body_bg_style = '';


?>
<div class="<?php echo esc_attr($container_classes); ?>"
     data-bg-type="<?php echo esc_attr($bg_type); ?>"
     <?php echo $initial_container_style_attr; ?>>
    <div class="<?php echo esc_attr($wrapper_class); ?>" style="flex-grow:1;">
        <div class="extrch-link-page-header-content">
            <?php 
            // Absolutely positioned bell (subscribe) and ellipses (share) triggers in top left/right
            if ($subscribe_display_mode === 'icon_modal') : ?>
                <button class="extrch-share-trigger extrch-subscribe-icon-trigger extrch-bell-page-trigger" aria-label="Subscribe to this artist">
                    <i class="fas fa-bell"></i>
                </button>
            <?php endif; ?>
            <button class="extrch-share-trigger extrch-share-page-trigger" aria-label="Share this page" data-share-type="page" data-share-url="<?php echo esc_url($share_page_url); ?>" data-share-title="<?php echo esc_attr($data['display_title']); ?>">
                <i class="fas fa-ellipsis-h"></i>
            </button>
            <!-- Main flex content below -->
            <?php 
            $img_container_classes = "extrch-link-page-profile-img " . $profile_img_shape_class;
            $no_image_class = empty($data['profile_img_url']) ? ' no-image' : '';
            if ($is_preview_iframe_context) : ?>
                <div class="<?php echo esc_attr($img_container_classes . $no_image_class); ?>">
                    <img src="<?php echo esc_url($data['profile_img_url']); ?>" alt="<?php echo esc_attr($data['display_title']); ?>">
                </div>
            <?php elseif (!empty($data['profile_img_url'])): ?>
                <div class="<?php echo esc_attr($img_container_classes); ?>"><img src="<?php echo esc_url($data['profile_img_url']); ?>" alt="<?php echo esc_attr($data['display_title']); ?>"></div>
            <?php endif; ?>
            <h1 class="extrch-link-page-title"><?php echo esc_html($data['display_title']); ?></h1>
            <?php if (!empty($data['bio'])): ?><div class="extrch-link-page-bio"><?php echo esc_html($data['bio']); ?></div><?php endif; ?>
        </div>

        <?php 
        // Conditionally render social icons ABOVE regular links using filter system
        if ($social_icons_position === 'above' && !empty($data['social_links'])) {
            echo ec_render_social_icons_container($data['social_links'], 'above');
        } // end if $social_icons_position === 'above'

        // Prepare for rendering actual link sections
        // ...
        ?>
        <?php if (!empty($link_sections)): ?>
            <?php 
            foreach ($link_sections as $section):
                $section_args = array(
                    'section_title' => $section['section_title'] ?? '',
                    'links' => $section['links'] ?? array(),
                    'link_page_id' => $link_page_id
                );
                echo ec_render_link_section( $section, $section_args );
            endforeach; 
            ?>
        <?php endif; ?>
        <?php
        // Output the inline subscribe form below all links if in inline_form mode
        if ($subscribe_display_mode === 'inline_form') {
            echo ec_render_template('subscribe-inline-form', array(
                'artist_id' => $artist_id,
                'data' => $data
            ));
        }
        // Output the modal partial (but not the bell icon) if in icon_modal mode
        if ($subscribe_display_mode === 'icon_modal') {
            echo ec_render_template('subscribe-modal', array(
                'artist_id' => $artist_id,
                'data' => $data
            ));
        }

        // Conditionally render social icons below links (and below subscribe form if present) using filter system
        if ($social_icons_position === 'below' && !empty($data['social_links'])) {
            echo ec_render_social_icons_container($data['social_links'], 'below');
        }
        ?>

        <?php if ($data['powered_by']): ?>
        <div class="extrch-link-page-powered" style="margin-top:auto; padding-top:1em; padding-bottom:1em;">
            <a href="https://extrachill.link" rel="noopener">Powered by Extra Chill</a>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // Include reusable share modal template (JS populates and controls it)
    echo ec_render_template('share-modal');
    ?>
</div>
