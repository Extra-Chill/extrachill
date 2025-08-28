<?php
/**
 * Template Name: Manage Artist Link Page
 * Description: Frontend management for an artist's extrch.co link page (Linktree-style).
 */

defined( 'ABSPATH' ) || exit;

// Debug logging for manage-link-page
global $wp_query, $post;
error_log('[DEBUG] Manage Link Page Template: Starting template load');
error_log('[DEBUG] Request URI: ' . $_SERVER['REQUEST_URI']);
error_log('[DEBUG] Query vars: ' . print_r($wp_query->query_vars, true));
error_log('[DEBUG] Is page: ' . ($wp_query->is_page ? 'yes' : 'no'));
error_log('[DEBUG] Is singular: ' . ($wp_query->is_singular ? 'yes' : 'no'));
error_log('[DEBUG] Is 404: ' . ($wp_query->is_404 ? 'yes' : 'no'));
if ($post) {
    error_log('[DEBUG] Global post ID: ' . $post->ID);
    error_log('[DEBUG] Global post type: ' . $post->post_type);
}

// --- Permission and Band ID Check (MOVED TO TOP) ---
$current_user_id = get_current_user_id();
$artist_id = isset($_GET['artist_id']) ? absint($_GET['artist_id']) : 0;
$artist_post = $artist_id ? get_post($artist_id) : null;

error_log('[DEBUG] Artist ID from GET: ' . $artist_id);
error_log('[DEBUG] Current user ID: ' . $current_user_id);

require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'artist-platform/extrch.co-link-page/link-page-includes.php';
require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'artist-platform/extrch.co-link-page/data/LinkPageDataProvider.php';

// --- Fetch or Create Associated Link Page ---
$link_page_id = get_post_meta($artist_id, '_extrch_link_page_id', true);
if (!$link_page_id || get_post_type($link_page_id) !== 'artist_link_page') {
    // Create a new artist_link_page post
    $link_page_id = wp_insert_post(array(
        'post_type'   => 'artist_link_page',
        'post_title'  => 'Link Page for ' . get_the_title($artist_id),
        'post_status' => 'publish',
        'meta_input'  => array('_associated_artist_profile_id' => $artist_id),
    ));
    if ($link_page_id && !is_wp_error($link_page_id)) {
        update_post_meta($artist_id, '_extrch_link_page_id', $link_page_id);
        // Add default link section if no links exist yet
        $artist_name = get_the_title($artist_id);
        $artist_profile_url = site_url('/artist/' . get_post_field('post_name', $artist_id));
        $default_link_section = array(
            array(
                'section_title' => '',
                'links' => array(
                    array(
                        'link_url' => $artist_profile_url,
                        'link_text' => $artist_name . ' Forum',
                        'link_is_active' => true
                    )
                )
            )
        );
        update_post_meta($link_page_id, '_link_page_links', $default_link_section);
    } else {
        echo '<div class="bp-notice bp-notice-error"><p>' . esc_html__('Could not create link page.', 'extrachill-artist-platform') . '</p></div>';
        get_footer();
        return;
    }
}

// --- Google Font Preload for Live Preview (Initial Page Load) ---
require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'artist-platform/extrch.co-link-page/link-page-font-config.php';
global $extrch_link_page_fonts;
$custom_vars_data = get_post_meta($link_page_id, '_link_page_custom_css_vars', true);

// Handle both array (new format) and JSON string (legacy) formats
if (is_array($custom_vars_data)) {
    $custom_vars = $custom_vars_data;
} elseif (is_string($custom_vars_data)) {
    $custom_vars = json_decode($custom_vars_data, true);
} else {
    $custom_vars = array();
}
if (!function_exists('extrch_output_google_font_link')) {
    function extrch_output_google_font_link($font_value, $font_config) {
        foreach ($font_config as $font) {
            if ($font['value'] === $font_value && $font['google_font_param'] !== 'local_default') {
                echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=' . esc_attr($font['google_font_param']) . '&display=swap">' . PHP_EOL;
            }
        }
    }
}
if (!empty($custom_vars['--link-page-title-font-family'])) {
    $title_font_stack = $custom_vars['--link-page-title-font-family'];
    $title_font_value = trim(explode(',', trim($title_font_stack), 2)[0], " '");
    extrch_output_google_font_link($title_font_value, $extrch_link_page_fonts);
}
if (!empty($custom_vars['--link-page-body-font-family'])) {
    $body_font_stack = $custom_vars['--link-page-body-font-family'];
    $body_font_value = trim(explode(',', trim($body_font_stack), 2)[0], " '");
    extrch_output_google_font_link($body_font_value, $extrch_link_page_fonts);
}

get_header(); ?>

<div id="primary" class="content-area">
    <main id="main" class="main-content">
        <?php do_action( 'extra_chill_before_main_content' ); ?>

<?php
// --- Display Error Notices ---
if (isset($_GET['bp_link_page_error'])) {
    $error_type = sanitize_key($_GET['bp_link_page_error']);
    if ($error_type === 'background_image_size') {
        echo '<div class="bp-notice bp-notice-error"><p>' . esc_html__('Error: Background image file size exceeds the 5MB limit.', 'extrachill-artist-platform') . '</p></div>';
    } elseif ($error_type === 'profile_image_size') {
        echo '<div class="bp-notice bp-notice-error"><p>' . esc_html__('Error: Profile image file size exceeds the 5MB limit.', 'extrachill-artist-platform') . '</p></div>';
    }
    // Add other error types here if needed in the future
}

// --- Permission and Artist ID Check ---
$current_user_id = get_current_user_id();
$artist_id = isset($_GET['artist_id']) ? absint($_GET['artist_id']) : 0;
$artist_post = $artist_id ? get_post($artist_id) : null;

if (!$artist_post || $artist_post->post_type !== 'artist_profile') {
    echo '<div class="bp-notice bp-notice-error"><p>' . esc_html__('Invalid artist profile.', 'extrachill-artist-platform') . '</p></div>';
    get_footer();
    return;
}
if (!current_user_can('manage_artist_members', $artist_id)) {
    echo '<div class="bp-notice bp-notice-error"><p>' . esc_html__('You do not have permission to manage this artist link page.', 'extrachill-artist-platform') . '</p></div>';
    get_footer();
    return;
}

// --- Canonical Data Fetch ---
if ( class_exists( 'LinkPageDataProvider' ) ) {
    $data = LinkPageDataProvider::get_data( $link_page_id, $artist_id, array() ); // No overrides for initial page load
} else {
    // Fallback if LinkPageDataProvider somehow isn't loaded
    // This should ideally not happen if includes are correct.
    $data = array(
        'display_title' => get_the_title($artist_id) ?: 'Link Page',
        'bio' => '',
        'profile_img_url' => '',
        'social_links' => array(),
        'links' => array(), // Ensure 'links' key exists for json_encode later
        'custom_css_vars_json' => '',
        'background_style' => '',
        'background_image_url' => '',
        // Add other necessary defaults to prevent errors
    );
     echo '<div class="bp-notice bp-notice-error"><p>' . esc_html__('Error: LinkPageDataProvider class not found. Link page data may be incomplete.', 'extrachill-artist-platform') . '</p></div>';
}

// Set global font config for JS hydration
global $extrch_link_page_fonts;
set_query_var('extrch_link_page_fonts', $extrch_link_page_fonts);
?>
<?php
// --- Breadcrumb for Manage Link Page ---
$artist_profile_title = get_the_title($artist_id);
$manage_page = get_page_by_path('manage-artist-profiles');
$manage_artist_profile_url = $manage_page 
    ? add_query_arg('artist_id', $artist_id, get_permalink($manage_page))
    : site_url('/manage-artist-profiles/?artist_id=' . $artist_id);
$breadcrumb_separator = '<span class="bbp-breadcrumb-sep"> › </span>';
echo '<div class="bbp-breadcrumb">';
echo '<a href="' . esc_url(home_url('/')) . '">Home</a>' . $breadcrumb_separator;
echo '<a href="' . esc_url($manage_artist_profile_url) . '">' . esc_html($artist_profile_title) . '</a>' . $breadcrumb_separator;
echo '<span class="bbp-breadcrumb-current">' . esc_html__('Manage Link Page', 'extrachill-artist-platform') . '</span>';
echo '</div>';
?>
<h1 class="manage-link-page-title">
    <?php echo esc_html__('Manage Link Page for ', 'extrachill-artist-platform') . esc_html(get_the_title($artist_id)); ?>
</h1>
<?php
// --- Artist Switcher Dropdown (for Link Pages) ---
$current_user_id_for_switcher = get_current_user_id();
// Fetch all artist profiles associated with the user
$user_artist_ids_for_switcher = get_user_meta( $current_user_id_for_switcher, '_artist_profile_ids', true );

// Create a new array to hold only artists that have a valid, existing link page
$valid_artists_for_link_page_switcher = array();
if ( is_array( $user_artist_ids_for_switcher ) && ! empty( $user_artist_ids_for_switcher ) ) {
    foreach ( $user_artist_ids_for_switcher as $user_artist_id_item_check ) {
        $artist_id_check = absint($user_artist_id_item_check);
        if ( $artist_id_check > 0 && get_post_status( $artist_id_check ) === 'publish' ) {
            $link_page_id_check = get_post_meta( $artist_id_check, '_extrch_link_page_id', true );
            if ( $link_page_id_check &&
                 get_post_status( $link_page_id_check ) === 'publish' &&
                 get_post_type( $link_page_id_check ) === 'artist_link_page' ) {
                $valid_artists_for_link_page_switcher[] = $artist_id_check;
            }
        }
    }
}

// Only show switcher if the user is associated with more than one artist profile *that has a link page*
if ( count( $valid_artists_for_link_page_switcher ) > 1 ) :
    $current_page_url_for_switcher = get_permalink(); // Base URL for the manage-link-page
    $current_selected_artist_id_for_switcher = isset( $_GET['artist_id'] ) ? absint( $_GET['artist_id'] ) : 0;
?>
    <div class="artist-switcher-container">
        <select name="link-page-artist-switcher-select" id="link-page-artist-switcher-select">
            <option value=""><?php esc_html_e( '-- Select an Artist --', 'extrachill-artist-platform'); ?></option>
            <?php
            foreach ( $valid_artists_for_link_page_switcher as $user_artist_id_item ) { // Iterate over the filtered list
                $artist_title_for_switcher = get_the_title( $user_artist_id_item );
                // The previous checks ensure title and publish status are fine for the artist profile itself
                // and that a valid link page exists.
                echo '<option value="' . esc_attr( $user_artist_id_item ) . '" ' . selected( $current_selected_artist_id_for_switcher, $user_artist_id_item, false ) . '>' . esc_html( $artist_title_for_switcher ) . '</option>';
            }
            ?>
        </select>
    </div>
<?php
endif; // End Band Switcher Dropdown for Link Pages
// --- End Band Switcher ---

// Display the public link page URL as plain text with a small copy link
if ($link_page_id && get_post_type($link_page_id) === 'artist_link_page') {
    $artist_slug = $artist_post->post_name;
    // Always show the extrachill.link URL as the public URL
    $public_url = 'https://extrachill.link/' . $artist_slug;
    
    echo '<div class="bp-notice bp-notice-info bp-link-page-url">';
    // Make the URL clickable
    $display_url = str_replace(array('https://', 'http://'), '', $public_url ?? '');
    echo '<a href="' . esc_url($public_url ?? '') . '" class="bp-link-page-url-text" target="_blank" rel="noopener">' . esc_html($display_url) . '</a>';
    // Change button to display Font Awesome QR code icon
    echo '<button type="button" id="bp-get-qr-code-btn" class="button button-secondary" title="' . esc_attr__("Get QR Code", "extrachill-artist-platform") . '" style="margin-left: 0.5em;"><i class="fa-solid fa-qrcode"></i></button>';
    echo '</div>';
    echo '<div id="bp-qr-code-container" style="margin-top: 1em; text-align: left;"></div>'; // Existing Container for QR code (can be repurposed or removed if modal is sufficient)
    // --- QR Code Modal ---
    echo '<div id="bp-qr-code-modal" class="bp-modal" style="display:none;">';
    echo '  <div class="bp-modal-content">';
    echo '    <span class="bp-modal-close">&times;</span>';
    echo '    <h2>' . esc_html__("Your Link Page QR Code", "extrachill-artist-platform") . '</h2>';
    echo '    <div id="bp-qr-code-modal-image-container">';
    echo '      <p class="loading-message">' . esc_html__("Loading QR Code...", "extrachill-artist-platform") . '</p>';
    echo '      <img src="" alt="' . esc_attr__("Link Page QR Code", "extrachill-artist-platform") . '" style="display:none; max-width: 100%; height: auto;" />';
    echo '    </div>';
    echo '    <p class="bp-modal-instructions">' . esc_html__("Right-click or long-press the image to save it.", "extrachill-artist-platform") . '</p>';
    echo '  </div>';
    echo '</div>';
    // --- End QR Code Modal ---
}
?>
<div class="manage-link-page-flex">
    <div class="manage-link-page-edit shared-tabs-component">
        <form method="post" id="bp-manage-link-page-form" enctype="multipart/form-data" action="">
            <?php wp_nonce_field('bp_save_link_page_action', 'bp_save_link_page_nonce'); ?>
            <input type="hidden" name="extrch_action" value="save_link_page_data">
            <input type="hidden" name="artist_id" value="<?php echo esc_attr($artist_id); ?>">
            <input type="hidden" name="link_page_id" value="<?php echo esc_attr($link_page_id); ?>">
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
                        // --- START Join Flow Guidance Notice (New User) ---
                        // Display this notice if the user just completed the new user join flow (registered + created artist)
                        // Assumes from_join=true is passed after successful artist creation redirect
                        if ( isset( $_GET['from_join'] ) && $_GET['from_join'] === 'true' ) {
                            echo '<div class="bp-notice bp-notice-info" style="margin-top: 15px; margin-bottom: 15px;">';
                            echo '<p>' . esc_html__( 'Welcome to your new Extrachill.link! Your Artist Profile info (Name, Bio, Picture) is synced here. Use the tabs to add links and customize your page appearance.', 'extrachill-artist-platform' ) . '</p>';
                            echo '</div>';
                        }
                        // --- END Join Flow Guidance Notice (New User) ---

                        // --- START Join Flow Success Notice (Existing User Redirect - Moved) ---
                        if ( isset( $_GET['from_join_success'] ) && $_GET['from_join_success'] === 'existing_user_link_page' ) {
                            echo '<div class="bp-notice bp-notice-success" style="margin-top: 15px; margin-bottom: 15px;">';
                            echo '<p>' . esc_html__( 'Welcome back! You\'ve been redirected to manage your extrachill.link page.', 'extrachill-artist-platform' ) . '</p>';
                            echo '</div>';
                        }
                        // --- END Join Flow Success Notice (Existing User Redirect - Moved) ---

                        // Set up variables for tab-info.php from $data
                        // $display_title = $data['display_title'] ?? ''; // $display_title is not directly used by tab-info.php itself
                        // $bio_text = $data['bio'] ?? ''; // This was previously declared but not passed
                        
                        // Pass necessary data to the template part
                        set_query_var('tab_info_artist_id', $artist_id);
                        set_query_var('tab_info_bio_text', $data['bio'] ?? '');

                        // Potentially other variables if tab-info.php uses them directly
                        set_query_var('data', $data);
                        include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'artist-platform/extrch.co-link-page/manage-link-page-tabs/tab-info.php';
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
                        set_query_var('data', $data);
                        include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'artist-platform/extrch.co-link-page/manage-link-page-tabs/tab-links.php';
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

                        set_query_var('data', $data);
                        include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'artist-platform/extrch.co-link-page/manage-link-page-tabs/tab-customize.php';
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
                        set_query_var('data', $data);
                        include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'artist-platform/extrch.co-link-page/manage-link-page-tabs/tab-advanced.php';
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
                        set_query_var('data', $data);
                        include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'artist-platform/extrch.co-link-page/manage-link-page-tabs/tab-analytics.php';
                        ?>
                    </div>
                </div>
                <!-- End Item 5: Analytics -->
            </div>
            <div id="desktop-tab-content-area" class="shared-desktop-tab-content-area" style="display: none;"></div>
        </form>
    </div>
    <div class="manage-link-page-preview">
        <div class="manage-link-page-preview-inner">
            <div class="extrch-link-page-preview-indicator">Live Preview</div>
            <div class="manage-link-page-preview-live">
                <?php
                // 2. Prepare and set the initial container style for the template
                $initial_container_style_for_php_preview = isset($data['background_style']) ? $data['background_style'] : '';
                set_query_var('initial_container_style_for_php_preview', $initial_container_style_for_php_preview);
                // 4. Prepare preview data for the new modular preview partial
                $preview_template_data_for_php = LinkPageDataProvider::get_data($link_page_id, $artist_id);
                // Add the link_page_id to the data array before passing it to the preview iframe
                $preview_template_data_for_php['link_page_id'] = $link_page_id;
                set_query_var('preview_template_data', $preview_template_data_for_php);
                set_query_var('data', $data);
                require EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'artist-platform/extrch.co-link-page/live-preview/preview.php';
                ?>
            </div>
        </div>
    </div>
</div>

<?php
// Output the expiration modal markup (hidden by default)
extrch_render_link_expiration_modal();
?>

<div class="link-page-footer-actions" style="display: flex; justify-content: center; align-items: center; gap: 20px; width: 100%; margin-top: 2em; margin-bottom: 2em;">
    <button type="submit" form="bp-manage-link-page-form" name="bp_save_link_page" class="button button-primary bp-link-page-save-btn"><?php esc_html_e('Save Link Page', 'extrachill-artist-platform'); ?></button>
    <div id="link-page-loading-message" style="display: none; margin-left: 1em; font-weight: bold;"><?php esc_html_e('Please wait...', 'extrachill-artist-platform'); ?></div>
    <a href="<?php echo esc_url(site_url('/manage-artist-profile/?artist_id=' . $artist_id)); ?>" class="button button-secondary"><?php esc_html_e('Manage Artist', 'extrachill-artist-platform'); ?></a>
</div>

<button id="extrch-jump-to-preview-btn" class="extrch-jump-to-preview-btn" aria-label="<?php esc_attr_e('Scroll to Preview / Settings', 'extrachill-artist-platform'); ?>" title="<?php esc_attr_e('Scroll to Preview', 'extrachill-artist-platform'); ?>">
    <span class="main-icon-wrapper">
        <i class="fas fa-magnifying-glass"></i> <!-- Default/initial main icon -->
    </span>
    <i class="directional-arrow fas fa-arrow-down"></i> <!-- Default/initial directional arrow -->
</button>

        <?php do_action( 'extra_chill_after_main_content' ); ?>
    </main><!-- #main -->
</div><!-- #primary -->

<?php get_footer(); ?>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    const linkPageArtistSwitcher = document.getElementById('link-page-artist-switcher-select');
    if (linkPageArtistSwitcher) {
        linkPageArtistSwitcher.addEventListener('change', function() {
            if (this.value) {
                const baseUrl = "<?php echo esc_url(get_permalink(get_the_ID())); ?>";
                // Check if baseUrl already has query parameters
                const separator = baseUrl.includes('?') ? '&' : '?';
                window.location.href = baseUrl + separator + 'artist_id=' + this.value;
            }
        });
    }
});
</script>

<?php
do_action( 'extra_chill_after_primary_content_area' );

/**
 * Debug: Check initial value of hidden social links input in the DOM on page load.
 * This script runs *after* the form HTML is rendered, but *before* most external JS executes.
 */
?>
<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        const initialSocialInput = document.getElementById('artist_profile_social_links_json');
        if (initialSocialInput) {
            console.log('[LinkPageLoad - Initial DOM Check] #artist_profile_social_links_json value:', initialSocialInput.value);
            console.log('[LinkPageLoad - Initial DOM Check] #artist_profile_social_links_json typeof value:', typeof initialSocialInput.value);
            try {
                 const parsedValue = JSON.parse(initialSocialInput.value);
                 console.log('[LinkPageLoad - Initial DOM Check] #artist_profile_social_links_json parsed JSON:', parsedValue);
                 console.log('[LinkPageLoad - Initial DOM Check] #artist_profile_social_links_json typeof parsed JSON:', typeof parsedValue);
            } catch (e) {
                 console.error('[LinkPageLoad - Initial DOM Check] Failed to parse JSON from #artist_profile_social_links_json', e);
            }
        } else {
            console.warn('[LinkPageLoad - Initial DOM Check] #artist_profile_social_links_json element not found.');
        }
    });
</script>