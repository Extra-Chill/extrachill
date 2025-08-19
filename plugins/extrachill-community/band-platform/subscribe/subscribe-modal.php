<?php
/**
 * Subscribe Modal for Band Link Page
 * Used in extrch-link-page-template.php when display mode is 'icon_modal'.
 *
 * Assumes $band_id is set by the including template.
 */

defined( 'ABSPATH' ) || exit;

// Ensure $band_id is available
$current_band_id = isset($band_id) ? absint($band_id) : 0;

if (empty($current_band_id)) {
    // Don't render the modal if band ID is missing
    // error_log('Subscribe modal partial: band_id is missing.'); // For debugging
    return;
}

// Create a nonce for the AJAX form submission
$subscribe_nonce = wp_create_nonce( 'extrch_subscribe_nonce' );

$band_name = isset($band_name) ? $band_name : (isset($data['display_title']) ? $data['display_title'] : '');

?>

<div id="extrch-subscribe-modal" class="extrch-subscribe-modal extrch-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="extrch-subscribe-modal-title">
    <div class="extrch-subscribe-modal-overlay extrch-modal-overlay"></div>
    <div class="extrch-subscribe-modal-content extrch-modal-content">
        <button class="extrch-subscribe-modal-close extrch-modal-close" aria-label="<?php esc_attr_e('Close subscription modal', 'extra-chill-community'); ?>">&times;</button>

        <div class="extrch-subscribe-modal-header">
            <h3 id="extrch-subscribe-modal-title" class="extrch-subscribe-header">
                Subscribe<?php if (!empty($band_name)) echo ' to ' . esc_html($band_name); ?>
            </h3>
            <p><?php 
            $subscribe_description = isset($data['_link_page_subscribe_description']) && $data['_link_page_subscribe_description'] !== '' ? $data['_link_page_subscribe_description'] : sprintf(__('Enter your email address to receive occasional news and updates from %s.', 'extra-chill-community'), $band_name);
            echo esc_html($subscribe_description);
            ?></p>
        </div>

        <form id="extrch-subscribe-form-modal" class="extrch-subscribe-form">
            <input type="hidden" name="action" value="extrch_link_page_subscribe">
            <input type="hidden" name="band_id" value="<?php echo esc_attr($current_band_id); ?>">
            <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($subscribe_nonce); ?>">

            <div class="form-group">
                <label for="subscriber_email_modal" class="screen-reader-text"><?php esc_html_e('Email Address', 'extra-chill-community'); ?></label>
                <input type="email" name="subscriber_email" id="subscriber_email_modal" placeholder="<?php esc_attr_e('Your email address', 'extra-chill-community'); ?>" required>
            </div>

            <button type="submit" class="button button-primary"><?php esc_html_e('Subscribe', 'extra-chill-community'); ?></button>

            <div class="extrch-form-message" aria-live="polite"></div> <?php // For success/error messages ?>
        </form>
    </div>
</div>

<?php
// The JavaScript for handling the modal display and form submission
// will be in a separate file (link-page-subscribe.js)
?> 