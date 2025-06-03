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

// --- Featured Link Data for Preview ---
$featured_link_html_preview = isset($preview_template_data['featured_link_html']) ? $preview_template_data['featured_link_html'] : '';
$featured_link_url_to_skip_preview = isset($preview_template_data['featured_link_url_to_skip']) 
                                      ? $preview_template_data['featured_link_url_to_skip'] 
                                      : null;

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
                <button class="extrch-share-trigger extrch-subscribe-icon-trigger extrch-bell-page-trigger" aria-label="Subscribe to this band (preview)">
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
        // --- Social Icons (Preview) - Conditionally render above links ---
        if ($social_icons_position === 'above') {
            if (!empty($social_links) && is_array($social_links)) :
                // Ensure the social types config file is included
                if ( ! function_exists( 'bp_get_supported_social_link_types' ) ) {
                    $social_types_path = dirname( __FILE__ ) . '/../extrch.co-link-page/link-page-social-types.php';
                    if ( file_exists( $social_types_path ) ) {
                        require_once $social_types_path;
                    }
                }
                $supported_social_types = function_exists( 'bp_get_supported_social_link_types' ) ? bp_get_supported_social_link_types() : array();
            ?>
                <div class="extrch-link-page-socials">
                    <?php foreach ($social_links as $icon):
                        if (empty($icon['url']) || empty($icon['type'])) continue;
                        $social_type = strtolower($icon['type']);
                        $icon_class = '';
                        if (isset($supported_social_types[$social_type]['icon'])) {
                            $icon_class = $supported_social_types[$social_type]['icon'];
                        } elseif (!empty($icon['icon'])) {
                            $icon_class = $icon['icon'];
                        } else {
                            $icon_class = 'fab fa-' . preg_replace('/[^a-z0-9_-]/', '', $social_type);
                        }
                        if (empty($icon_class)) continue;
                        $icon_class = esc_attr($icon_class);
                        $url = esc_url($icon['url']);
                    ?>
                        <a href="<?php echo $url; ?>" class="extrch-social-icon" rel="noopener" target="_blank">
                            <i class="<?php echo $icon_class; ?>" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif;
        }
        // --- Output Featured Link HTML for Preview (if any) ---
        if (!empty($featured_link_html_preview)) {
            echo $featured_link_html_preview; // This HTML is already sanitized by the handler function
        }
        ?>
        <?php if (!empty($link_sections)): ?>
            <?php foreach ($link_sections as $section): ?>
                <?php if (!empty($section['section_title'])): ?>
                    <div class="extrch-link-page-section-title"><?php echo esc_html($section['section_title']); ?></div>
                <?php endif; ?>

                <?php if (!empty($section['links']) && is_array($section['links'])): ?>
                    <div class="extrch-link-page-links">
                        <?php 
                        $normalized_url_to_skip_for_php_preview = $featured_link_url_to_skip_preview ? trailingslashit($featured_link_url_to_skip_preview) : null;
                        foreach ($section['links'] as $link):
                            if (empty($link['link_url']) || empty($link['link_text'])) continue;
                            // Skip if this link is the featured link (PHP-side filtering for initial preview render)
                            $current_link_url_normalized_for_php_preview = trailingslashit($link['link_url']);
                            if ($normalized_url_to_skip_for_php_preview && $current_link_url_normalized_for_php_preview === $normalized_url_to_skip_for_php_preview) {
                                continue;
                            }
                            ?>
                            <a href="<?php echo esc_url($link['link_url']); ?>" class="extrch-link-page-link" rel="noopener">
                                <span class="extrch-link-page-link-text"><?php echo esc_html($link['link_text']); ?></span>
                                <span class="extrch-link-page-link-icon">
                                    <button class="extrch-share-trigger extrch-share-item-trigger" 
                                            aria-label="Share this link" 
                                            data-share-type="link"
                                            data-share-url="<?php echo esc_url($link['link_url']); ?>" 
                                            data-share-title="<?php echo esc_attr($link['link_text']); ?>">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                </span>
                            </a>
                        <?php endforeach; // End link in section ?>
                    </div> <?php // End .extrch-link-page-links for this section ?>
                <?php endif; // End check for $section['links'] ?>
            <?php endforeach; // End section in $link_sections ?>
        <?php endif; // End check for $link_sections ?>
        <?php if ($subscribe_display_mode === 'inline_form') : ?>
            <div class="extrch-link-page-subscribe-inline-form-container">
                <h3 style="margin-bottom:0.5em;">
                    <?php
                    $subscribe_inline_title = 'Subscribe'; // Default title
                    if (!empty($display_title)) { // $display_title comes from $preview_template_data
                        $subscribe_inline_title = 'Subscribe to ' . esc_html($display_title);
                    }
                    echo $subscribe_inline_title;
                    ?>
                </h3>
                <p style="margin-bottom:1em; color:#888; font-size:0.97em;">
                    <?php echo esc_html(isset($preview_template_data['_link_page_subscribe_description']) && $preview_template_data['_link_page_subscribe_description'] !== '' ? $preview_template_data['_link_page_subscribe_description'] : 'Enter your email address to receive occasional news and updates from this band.'); ?>
                </p>
                <form class="extrch-subscribe-form" onsubmit="return false;">
                    <input type="email" placeholder="Your email address" style="width:100%;max-width:250px;">
                    <button type="submit" class="button button-primary" style="margin-top:0.5em;">Subscribe</button>
                </form>
            </div>
        <?php endif; ?>
        <?php
        // --- Social Icons (Preview) - Conditionally render below links and subscribe form ---
        if ($social_icons_position === 'below') {
            if (!empty($social_links) && is_array($social_links)) :
                // Ensure the social types config file is included
                if ( ! function_exists( 'bp_get_supported_social_link_types' ) ) {
                    $social_types_path = dirname( __FILE__ ) . '/../extrch.co-link-page/link-page-social-types.php';
                    if ( file_exists( $social_types_path ) ) {
                        require_once $social_types_path;
                    }
                }
                $supported_social_types = function_exists( 'bp_get_supported_social_link_types' ) ? bp_get_supported_social_link_types() : array();
            ?>
                <div class="extrch-link-page-socials extrch-socials-below">
                    <?php foreach ($social_links as $icon):
                        if (empty($icon['url']) || empty($icon['type'])) continue;
                        $social_type = strtolower($icon['type']);
                        $icon_class = '';
                        if (isset($supported_social_types[$social_type]['icon'])) {
                            $icon_class = $supported_social_types[$social_type]['icon'];
                        } elseif (!empty($icon['icon'])) {
                            $icon_class = $icon['icon'];
                        } else {
                            $icon_class = 'fab fa-' . preg_replace('/[^a-z0-9_-]/', '', $social_type);
                        }
                        if (empty($icon_class)) continue;
                        $icon_class = esc_attr($icon_class);
                        $url = esc_url($icon['url']);
                    ?>
                        <a href="<?php echo $url; ?>" class="extrch-social-icon" rel="noopener" target="_blank">
                            <i class="<?php echo $icon_class; ?>" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif;
        }
        ?>
        <div class="extrch-link-page-powered" style="margin-top:auto; padding-top:1em; padding-bottom:1em;">
            <a href="https://extrachill.link" rel="noopener">Powered by Extra Chill</a>
        </div>
    </div>
    <div id="extrch-share-modal" class="extrch-share-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="extrch-share-modal-main-title">
        <div class="extrch-share-modal-overlay"></div>
        <div class="extrch-share-modal-content">
            <button class="extrch-share-modal-close" aria-label="Close share modal">&times;</button>
            <div class="extrch-share-modal-header">
                <img src="" alt="Profile" class="extrch-share-modal-profile-img" style="display:none;">
                <h3 id="extrch-share-modal-main-title" class="extrch-share-modal-main-title">Share Page</h3>
                <p class="extrch-share-modal-subtitle"></p>
            </div>
            <div class="extrch-share-modal-options-grid">
                <button class="extrch-share-option-button extrch-share-option-copy-link" aria-label="Copy Link">
                    <span class="extrch-share-option-icon"><i class="fas fa-copy"></i></span>
                    <span class="extrch-share-option-label">Copy Link</span>
                </button>
                <button class="extrch-share-option-button extrch-share-option-native" aria-label="More sharing options">
                    <span class="extrch-share-option-icon"><i class="fas fa-ellipsis-h"></i></span>
                    <span class="extrch-share-option-label">More</span>
                </button>
                <a href="#" class="extrch-share-option-button extrch-share-option-facebook" rel="noopener" aria-label="Share on Facebook">
                    <span class="extrch-share-option-icon"><i class="fab fa-facebook-f"></i></span>
                    <span class="extrch-share-option-label">Facebook</span>
                </a>
                <a href="#" class="extrch-share-option-button extrch-share-option-twitter" rel="noopener" aria-label="Share on Twitter">
                    <span class="extrch-share-option-icon"><i class="fab fa-x-twitter"></i></span>
                    <span class="extrch-share-option-label">Twitter</span>
                </a>
                 <a href="#" class="extrch-share-option-button extrch-share-option-linkedin" rel="noopener" aria-label="Share on LinkedIn">
                    <span class="extrch-share-option-icon"><i class="fab fa-linkedin-in"></i></span>
                    <span class="extrch-share-option-label">LinkedIn</span>
                </a>
                <a href="#" class="extrch-share-option-button extrch-share-option-email" aria-label="Share via Email">
                    <span class="extrch-share-option-icon"><i class="fas fa-envelope"></i></span>
                    <span class="extrch-share-option-label">Email</span>
                </a>
            </div>
        </div>
    </div>
</div>
</div>

<script>
    // Only send postMessage to parent when preview is ready, no visibility logic needed
    if (window.parent) {
        window.parent.postMessage('extrchPreviewReady', '*');
    }
</script>