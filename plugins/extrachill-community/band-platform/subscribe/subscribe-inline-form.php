<?php
/**
 * Inline Subscribe Form for Band Link Page
 * Used in extrch-link-page-template.php when display mode is 'inline_form'.
 *
 * Assumes $band_id is set by the including template.
 */

defined( 'ABSPATH' ) || exit;

// Ensure $band_id is available
$current_band_id = isset($band_id) ? absint($band_id) : 0;

if (empty($current_band_id)) {
    // Don't render the form if band ID is missing
    // error_log('Subscribe inline form partial: band_id is missing.'); // For debugging
    return;
}

// Create a nonce for the AJAX form submission
$subscribe_nonce = wp_create_nonce( 'extrch_subscribe_nonce' );

$band_name = isset($band_name) ? $band_name : (isset($data['display_title']) ? $data['display_title'] : '');
?>

<div class="extrch-link-page-subscribe-inline-form-container">
    <h3 class="extrch-subscribe-header">
        Subscribe<?php if (!empty($band_name)) echo ' to ' . esc_html($band_name); ?>
    </h3>
    <p><?php 
    $subscribe_description = isset($data['_link_page_subscribe_description']) && $data['_link_page_subscribe_description'] !== '' ? $data['_link_page_subscribe_description'] : sprintf(__('Enter your email address to receive occasional news and updates from %s.', 'generatepress_child'), $band_name);
    echo esc_html($subscribe_description);
    ?></p>

    <form id="extrch-subscribe-form-inline" class="extrch-subscribe-form">
        <input type="hidden" name="action" value="extrch_link_page_subscribe">
        <input type="hidden" name="band_id" value="<?php echo esc_attr($current_band_id); ?>">
        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($subscribe_nonce); ?>">

        <div class="form-group">
            <label for="subscriber_email_inline" class="screen-reader-text"><?php esc_html_e('Email Address', 'generatepress_child'); ?></label>
            <input type="email" name="subscriber_email" id="subscriber_email_inline" placeholder="<?php esc_attr_e('Your email address', 'generatepress_child'); ?>" required>
        </div>

        <button type="submit" class="button button-primary"><?php esc_html_e('Subscribe', 'generatepress_child'); ?></button>

        <div class="extrch-form-message" aria-live="polite"></div> <?php // For success/error messages ?>
    </form>
</div>

<?php
// The JavaScript for handling form submission
// will be in a separate file (link-page-subscribe.js)
?> 