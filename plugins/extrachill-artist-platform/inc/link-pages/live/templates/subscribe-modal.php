<?php
/**
 * Subscribe Modal for Artist Link Page
 * Used in extrch-link-page-template.php when display mode is 'icon_modal'.
 *
 * Assumes $artist_id is set by the including template.
 */

defined( 'ABSPATH' ) || exit;

$current_artist_id = apply_filters('ec_get_artist_id', isset($artist_id) ? compact('artist_id') : []);

if (empty($current_artist_id)) {
    return;
}

$artist_name = isset($artist_name) ? $artist_name : (isset($data['display_title']) ? $data['display_title'] : '');
?>

<div id="extrch-subscribe-modal" class="extrch-subscribe-modal extrch-modal extrch-modal-hidden" role="dialog" aria-modal="true" aria-labelledby="extrch-subscribe-modal-title">
    <div class="extrch-subscribe-modal-overlay extrch-modal-overlay"></div>
    <div class="extrch-subscribe-modal-content extrch-modal-content">
        <button class="extrch-subscribe-modal-close extrch-modal-close" aria-label="<?php esc_attr_e('Close subscription modal', 'extrachill-artist-platform'); ?>">&times;</button>

        <div class="extrch-subscribe-modal-header">
            <h3 id="extrch-subscribe-modal-title" class="extrch-subscribe-header">
                Subscribe<?php if (!empty($artist_name)) echo ' to ' . esc_html($artist_name); ?>
            </h3>
            <p><?php 
            $subscribe_description = isset($data['_link_page_subscribe_description']) && $data['_link_page_subscribe_description'] !== '' ? $data['_link_page_subscribe_description'] : sprintf(__('Enter your email address to receive occasional news and updates from %s.', 'extrachill-artist-platform'), $artist_name);
            echo esc_html($subscribe_description);
            ?></p>
        </div>

        <form id="extrch-subscribe-form-modal" class="extrch-subscribe-form" data-artist-id="<?php echo esc_attr($current_artist_id); ?>">
            <div class="form-group">
                <input type="email" name="subscriber_email" id="subscriber_email_modal" placeholder="<?php esc_attr_e('Your email address', 'extrachill-artist-platform'); ?>" required aria-label="<?php esc_attr_e('Email Address', 'extrachill-artist-platform'); ?>">
            </div>

            <button type="submit" class="button-1 button-medium"><?php esc_html_e('Subscribe', 'extrachill-artist-platform'); ?></button>

            <div class="extrch-form-message" aria-live="polite"></div>
        </form>
    </div>
</div>
