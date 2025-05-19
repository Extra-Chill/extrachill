<?php
/**
 * Template Name: Manage Band Link Page
 * Description: Frontend management for a band's extrch.co link page (Linktree-style).
 */

defined( 'ABSPATH' ) || exit;

require_once get_stylesheet_directory() . '/forum-features/band-platform/extrch.co-link-page/link-page-includes.php';
// require_once get_stylesheet_directory() . '/forum-features/band-platform/extrch.co-link-page/link-page-data.php'; // Deprecated
require_once get_stylesheet_directory() . '/forum-features/band-platform/extrch.co-link-page/config/live-preview/LivePreviewManager.php';

get_header(); ?>

<div id="primary" <?php generate_do_element_classes( 'content' ); ?>>
    <main id="main" <?php generate_do_element_classes( 'main' ); ?>>
        <?php do_action( 'generate_before_main_content' ); ?>

<?php
// --- Permission and Band ID Check ---
$current_user_id = get_current_user_id();
$band_id = isset($_GET['band_id']) ? absint($_GET['band_id']) : 0;
$band_post = $band_id ? get_post($band_id) : null;

if (!$band_post || $band_post->post_type !== 'band_profile') {
    echo '<div class="bp-notice bp-notice-error"><p>' . esc_html__('Invalid band profile.', 'generatepress_child') . '</p></div>';
    get_footer();
    return;
}
if (!current_user_can('manage_band_members', $band_id)) {
    echo '<div class="bp-notice bp-notice-error"><p>' . esc_html__('You do not have permission to manage this band link page.', 'generatepress_child') . '</p></div>';
    get_footer();
    return;
}

// --- Fetch or Create Associated Link Page ---
$link_page_id = get_post_meta($band_id, '_extrch_link_page_id', true);
if (!$link_page_id || get_post_type($link_page_id) !== 'band_link_page') {
    // Create a new band_link_page post
    $link_page_id = wp_insert_post(array(
        'post_type'   => 'band_link_page',
        'post_title'  => 'Link Page for ' . get_the_title($band_id),
        'post_status' => 'publish',
        'meta_input'  => array('_associated_band_profile_id' => $band_id),
    ));
    if ($link_page_id && !is_wp_error($link_page_id)) {
        update_post_meta($band_id, '_extrch_link_page_id', $link_page_id);
        // Add default link section if no links exist yet
        $band_name = get_the_title($band_id);
        $band_profile_url = site_url('/band/' . get_post_field('post_name', $band_id));
        $default_link_section = array(
            array(
                'section_title' => '',
                'links' => array(
                    array(
                        'link_url' => $band_profile_url,
                        'link_text' => $band_name . ' Forum',
                        'link_is_active' => true
                    )
                )
            )
        );
        update_post_meta($link_page_id, '_link_page_links', $default_link_section);
    } else {
        echo '<div class="bp-notice bp-notice-error"><p>' . esc_html__('Could not create link page.', 'generatepress_child') . '</p></div>';
        get_footer();
        return;
    }
}

// --- Canonical Data Fetch ---
if ( class_exists( 'LivePreviewManager' ) ) {
    $data = LivePreviewManager::get_preview_data( $link_page_id, $band_id, array() ); // No overrides for initial page load
} else {
    // Fallback if LivePreviewManager somehow isn't loaded
    // This should ideally not happen if includes are correct.
    $data = array(
        'display_title' => get_the_title($band_id) ?: 'Link Page',
        'bio' => '',
        'profile_img_url' => '',
        'social_links' => array(),
        'links' => array(), // Ensure 'links' key exists for json_encode later
        'custom_css_vars_json' => '',
        'background_style' => '',
        'background_image_url' => '',
        // Add other necessary defaults to prevent errors
    );
     echo '<div class="bp-notice bp-notice-error"><p>' . esc_html__('Error: LivePreviewManager class not found. Link page data may be incomplete.', 'generatepress_child') . '</p></div>';
}

// Set global font config for JS hydration
// (kept for legacy JS, but can be removed if not needed)
global $extrch_link_page_fonts;
set_query_var('extrch_link_page_fonts', $extrch_link_page_fonts);
?>
<?php
// --- Breadcrumb for Manage Link Page ---
$band_profile_title = get_the_title($band_id);
$manage_band_profile_url = function_exists('bp_get_manage_band_page_url')
    ? add_query_arg('band_id', $band_id, bp_get_manage_band_page_url())
    : site_url('/manage-band-profile/?band_id=' . $band_id);
$breadcrumb_separator = '<span class="bbp-breadcrumb-sep"> › </span>';
echo '<div class="bbp-breadcrumb">';
echo '<a href="' . esc_url(home_url('/')) . '">Home</a>' . $breadcrumb_separator;
echo '<a href="' . esc_url($manage_band_profile_url) . '">' . esc_html($band_profile_title) . '</a>' . $breadcrumb_separator;
echo '<span class="bbp-breadcrumb-current">' . esc_html__('Manage Link Page', 'generatepress_child') . '</span>';
echo '</div>';
?>
<h1 class="manage-link-page-title">
    <?php echo esc_html__('Manage Link Page for ', 'generatepress_child') . esc_html(get_the_title($band_id)); ?>
</h1>
<?php
// --- Band Switcher Dropdown (for Link Pages) ---
$current_user_id_for_switcher = get_current_user_id();
// Fetch all band profiles associated with the user
$user_band_ids_for_switcher = get_user_meta( $current_user_id_for_switcher, '_band_profile_ids', true );

// Create a new array to hold only bands that have a valid, existing link page
$valid_bands_for_link_page_switcher = array();
if ( is_array( $user_band_ids_for_switcher ) && ! empty( $user_band_ids_for_switcher ) ) {
    foreach ( $user_band_ids_for_switcher as $user_band_id_item_check ) {
        $band_id_check = absint($user_band_id_item_check);
        if ( $band_id_check > 0 && get_post_status( $band_id_check ) === 'publish' ) {
            $link_page_id_check = get_post_meta( $band_id_check, '_extrch_link_page_id', true );
            if ( $link_page_id_check &&
                 get_post_status( $link_page_id_check ) === 'publish' &&
                 get_post_type( $link_page_id_check ) === 'band_link_page' ) {
                $valid_bands_for_link_page_switcher[] = $band_id_check;
            }
        }
    }
}

// Only show switcher if the user is associated with more than one band profile *that has a link page*
if ( count( $valid_bands_for_link_page_switcher ) > 1 ) :
    $current_page_url_for_switcher = get_permalink(); // Base URL for the manage-link-page
    $current_selected_band_id_for_switcher = isset( $_GET['band_id'] ) ? absint( $_GET['band_id'] ) : 0;
?>
    <div class="band-switcher-container">
        <select name="link-page-band-switcher-select" id="link-page-band-switcher-select">
            <option value=""><?php esc_html_e( '-- Select a Band --', 'generatepress_child'); ?></option>
            <?php
            foreach ( $valid_bands_for_link_page_switcher as $user_band_id_item ) { // Iterate over the filtered list
                $band_title_for_switcher = get_the_title( $user_band_id_item );
                // The previous checks ensure title and publish status are fine for the band profile itself
                // and that a valid link page exists.
                echo '<option value="' . esc_attr( $user_band_id_item ) . '" ' . selected( $current_selected_band_id_for_switcher, $user_band_id_item, false ) . '>' . esc_html( $band_title_for_switcher ) . '</option>';
            }
            ?>
        </select>
    </div>
<?php
endif; // End Band Switcher Dropdown for Link Pages
// --- End Band Switcher ---

// Display the public link page URL as plain text with a small copy link
if ($link_page_id && get_post_type($link_page_id) === 'band_link_page') {
    $band_slug = $band_post->post_name;
    // Always show the extrachill.link URL as the public URL
    $public_url = 'https://extrachill.link/' . $band_slug;
    
    echo '<div class="bp-notice bp-notice-info bp-link-page-url">';
    // Make the URL clickable
    $display_url = str_replace(array('https://', 'http://'), '', $public_url ?? '');
    echo '<a href="' . esc_url($public_url ?? '') . '" class="bp-link-page-url-text" target="_blank" rel="noopener">' . esc_html($display_url) . '</a>';
    // Change button to display Font Awesome QR code icon
    echo '<button type="button" id="bp-get-qr-code-btn" class="button button-secondary" title="' . esc_attr__("Get QR Code", "generatepress_child") . '" style="margin-left: 0.5em;"><i class="fa-solid fa-qrcode"></i></button>';
    echo '</div>';
    echo '<div id="bp-qr-code-container" style="margin-top: 1em; text-align: left;"></div>'; // Existing Container for QR code (can be repurposed or removed if modal is sufficient)
    // --- QR Code Modal ---
    echo '<div id="bp-qr-code-modal" class="bp-modal" style="display:none;">';
    echo '  <div class="bp-modal-content">';
    echo '    <span class="bp-modal-close">&times;</span>';
    echo '    <h2>' . esc_html__("Your Link Page QR Code", "generatepress_child") . '</h2>';
    echo '    <div id="bp-qr-code-modal-image-container">';
    echo '      <p class="loading-message">' . esc_html__("Loading QR Code...", "generatepress_child") . '</p>';
    echo '      <img src="" alt="' . esc_attr__("Link Page QR Code", "generatepress_child") . '" style="display:none; max-width: 100%; height: auto;" />';
    echo '    </div>';
    echo '    <p class="bp-modal-instructions">' . esc_html__("Right-click or long-press the image to save it.", "generatepress_child") . '</p>';
    echo '  </div>';
    echo '</div>';
    // --- End QR Code Modal ---
}
?>
<div class="manage-link-page-flex">
    <div class="manage-link-page-edit shared-tabs-component">
        <form method="post" id="bp-manage-link-page-form" enctype="multipart/form-data">
            <?php wp_nonce_field('bp_save_link_page_action', 'bp_save_link_page_nonce'); ?>
            <input type="hidden" name="band_profile_social_links_json" id="band_profile_social_links_json" value="<?php echo esc_attr(json_encode($data['social_links'] ?? [])); ?>">
            <input type="hidden" name="link_page_links_json" id="link_page_links_json" value="<?php echo esc_attr(json_encode($data['link_sections'] ?? [])); ?>">
            <input type="hidden" name="link_expiration_enabled" id="link_expiration_enabled" value="<?php echo esc_attr((get_post_meta($link_page_id, '_link_expiration_enabled', true) === '1' ? '1' : '0') ?? '0'); ?>">
            
            <div class="shared-tabs-buttons-container">
                <!-- Item 1: Info -->
                <div class="shared-tab-item">
                    <button type="button" class="shared-tab-button active" data-tab="manage-link-page-tab-info">
                        Info
                        <span class="shared-tab-arrow open"></span>
                    </button>
                    <div class="shared-tab-pane" id="manage-link-page-tab-info">
                        <?php
                        // Set up variables for tab-info.php from $data
                        // $display_title = $data['display_title'] ?? ''; // $display_title is not directly used by tab-info.php itself
                        // $bio_text = $data['bio'] ?? ''; // This was previously declared but not passed
                        
                        // Pass necessary data to the template part
                        set_query_var('tab_info_band_id', $band_id);
                        set_query_var('tab_info_bio_text', $data['bio'] ?? '');

                        // Potentially other variables if tab-info.php uses them directly
                        get_template_part('forum-features/band-platform/extrch.co-link-page/manage-link-page-tabs/tab-info');
                        ?>
                    </div>
                </div>
                <!-- End Item 1: Info -->

                <!-- Item 2: Links -->
                <div class="shared-tab-item">
                    <button type="button" class="shared-tab-button" data-tab="manage-link-page-tab-links">
                        Links
                        <span class="shared-tab-arrow"></span>
                    </button>
                    <div class="shared-tab-pane" id="manage-link-page-tab-links">
                        <?php
                        // $data['link_sections'] is used by JS, tab-links.php might not need direct PHP vars for links now
                        get_template_part('forum-features/band-platform/extrch.co-link-page/manage-link-page-tabs/tab-links');
                        ?>
                    </div>
                </div>
                <!-- End Item 2: Links -->

                <!-- Item 3: Customize -->
                <div class="shared-tab-item">
                    <button type="button" class="shared-tab-button" data-tab="manage-link-page-tab-customize">
                        Customize
                        <span class="shared-tab-arrow"></span>
                    </button>
                    <div class="shared-tab-pane" id="manage-link-page-tab-customize">
                        <?php
                        // Set up variables for tab-customize.php from $data
                        // These are for the initial HTML 'value' attributes of the form inputs
                        $background_type = $data['background_type'] ?? 'color';
                        $background_color = $data['background_color'] ?? '#1a1a1a';
                        $background_gradient_start = $data['background_gradient_start'] ?? '#0b5394';
                        $background_gradient_end = $data['background_gradient_end'] ?? '#53940b';
                        $background_gradient_direction = $data['background_gradient_direction'] ?? 'to right';
                        $background_image_id = $data['background_image_id'] ?? '';
                        $background_image_url = $data['background_image_url'] ?? '';

                        // CSS variable related values (for color pickers not directly tied to background type)
                        $button_color = $data['css_vars']['--link-page-button-color'] ?? '#0b5394';
                        $text_color = $data['css_vars']['--link-page-text-color'] ?? '#e5e5e5';
                        $link_text_color = $data['css_vars']['--link-page-link-text-color'] ?? '#ffffff';
                        $hover_color = $data['css_vars']['--link-page-hover-color'] ?? '#083b6c';
                        // $custom_css_vars is used for the font family select, $link_page_id for profile image shape meta
                        $custom_css_vars = $data['css_vars'] ?? [];


                        get_template_part('forum-features/band-platform/extrch.co-link-page/manage-link-page-tabs/tab-customize');
                        ?>
                    </div>
                </div>
                <!-- End Item 3: Customize -->

                <!-- Item 4: Advanced -->
                <div class="shared-tab-item">
                    <button type="button" class="shared-tab-button" data-tab="manage-link-page-tab-advanced">
                        Advanced
                        <span class="shared-tab-arrow"></span>
                    </button>
                    <div class="shared-tab-pane" id="manage-link-page-tab-advanced">
                        <?php
                        // Pass $link_page_id to the advanced tab template if needed
                        set_query_var('link_page_id', $link_page_id);
                        get_template_part('forum-features/band-platform/extrch.co-link-page/manage-link-page-tabs/tab-advanced');
                        ?>
                    </div>
                </div>
                <!-- End Item 4: Advanced -->

                <!-- Item 5: Analytics -->
                <div class="shared-tab-item">
                    <button type="button" class="shared-tab-button" data-tab="manage-link-page-tab-analytics">
                        Analytics
                        <span class="shared-tab-arrow"></span>
                    </button>
                    <div class="shared-tab-pane" id="manage-link-page-tab-analytics">
                        <?php
                        // Pass $link_page_id to the analytics tab template if needed
                        set_query_var('link_page_id', $link_page_id);
                        get_template_part('forum-features/band-platform/extrch.co-link-page/manage-link-page-tabs/tab-analytics');
                        ?>
                    </div>
                </div>
                <!-- End Item 5: Analytics -->
            </div>
            <div id="desktop-tab-content-area" class="shared-desktop-tab-content-area" style="display: none;"></div>

            <div class="bp-link-page-save-btn-wrap" style="margin-top:2em;">
                <button type="submit" name="bp_save_link_page" class="button button-primary bp-link-page-save-btn"><?php esc_html_e('Save Link Page', 'generatepress_child'); ?></button>
            </div>
        </form>
    </div>
    <div class="manage-link-page-preview">
        <div class="manage-link-page-preview-inner">
            <div class="extrch-link-page-preview-indicator">Live Preview</div>
            <div class="manage-link-page-preview-live">
                <?php
                // 1. Output the INITIAL CSS Variables Style Block (for first load, server-rendered)
                // Use $data['css_vars'] which is the processed array from LivePreviewManager,
                // ensuring consistency with what JS will use.
                if ( !empty($data['css_vars']) && is_array($data['css_vars']) ) {
                    echo '<style id="extrch-link-page-initial-custom-vars">:root {';
                    foreach ($data['css_vars'] as $k => $v) {
                        // Ensure $k is a valid CSS variable name (starts with --)
                        if (is_string($k) && strpos($k, '--') === 0 && !empty($v) && is_scalar($v)) {
                            echo esc_html($k) . ':' . esc_html($v) . ';';
                        }
                    }
                    echo '}</style>';
                }
                // 2. Add a DEDICATED style tag for LIVE JS-driven CSS variable updates for the preview.
                echo '<style id="extrch-link-page-live-preview-custom-vars"></style>';
                // 3. Prepare and set the initial container style for the template
                $initial_container_style_for_php_preview = isset($data['background_style']) ? $data['background_style'] : '';
                set_query_var('initial_container_style_for_php_preview', $initial_container_style_for_php_preview);
                // 4. Prepare preview data for the new modular preview partial
                $preview_template_data_for_php = LivePreviewManager::get_preview_data($link_page_id, $band_id);
                set_query_var('preview_template_data', $preview_template_data_for_php);
                require locate_template('forum-features/band-platform/extrch.co-link-page/config/live-preview/preview.php');
                ?>
            </div>
        </div>
    </div>
</div>

<div class="bp-link-page-manage-band-btn-wrap" style="margin-top:2em; margin-bottom: 2em; text-align: center;">
    <a href="<?php echo esc_url(site_url('/manage-band-profile/?band_id=' . $band_id)); ?>" class="button button-secondary"><?php esc_html_e('Manage Band', 'generatepress_child'); ?></a>
</div>

<button id="extrch-jump-to-preview-btn" class="extrch-jump-to-preview-btn" aria-label="<?php esc_attr_e('Scroll to Preview / Settings', 'generatepress_child'); ?>" title="<?php esc_attr_e('Scroll to Preview', 'generatepress_child'); ?>">
    <span class="main-icon-wrapper">
        <i class="fas fa-magnifying-glass"></i> <!-- Default/initial main icon -->
    </span>
    <i class="directional-arrow fas fa-arrow-down"></i> <!-- Default/initial directional arrow -->
</button>

        <?php do_action( 'generate_after_main_content' ); ?>
    </main><!-- #main -->
</div><!-- #primary -->

<?php get_footer(); ?>

<script type="text/javascript">
    window.extrchInitialLinkPageData = <?php echo json_encode($data); ?>;
    window.bpLinkPageLinks = <?php echo json_encode($data['link_sections'] ?? []); // Use the processed 'link_sections' ?>;
    window.extrchLinkPagePreviewAJAX = {
        ajax_url: "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>",
        nonce: "<?php echo esc_js( wp_create_nonce( 'bp_save_link_page_action' ) ); ?>",
        link_page_id: <?php echo absint( $link_page_id ); ?>,
        band_id: <?php echo absint( $band_id ); ?>,
        initial_profile_img_url: <?php echo json_encode(isset($data['profile_img_url']) ? $data['profile_img_url'] : ''); ?>,
        initial_background_img_url: <?php echo json_encode(isset($data['background_image_url']) ? $data['background_image_url'] : ''); ?>,
        initial_redirect_target_url: <?php echo json_encode(get_post_meta($link_page_id, '_link_page_redirect_target_url', true) ?: ''); ?>
    };
</script>
<?php // QR Code JS removed as per user feedback to skip AJAX generation for now ?>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    const linkPageBandSwitcher = document.getElementById('link-page-band-switcher-select');
    if (linkPageBandSwitcher) {
        linkPageBandSwitcher.addEventListener('change', function() {
            if (this.value) {
                const baseUrl = "<?php echo esc_url(get_permalink(get_the_ID())); ?>";
                // Check if baseUrl already has query parameters
                const separator = baseUrl.includes('?') ? '&' : '?';
                window.location.href = baseUrl + separator + 'band_id=' + this.value;
            }
        });
    }
});
</script>

<?php
do_action( 'generate_after_primary_content_area' );
generate_construct_sidebars();