<?php
/**
 * Share Modal Template (reusable for live link page and preview)
 *
 * Renders the share modal markup. JS (inc/link-pages/live/assets/js/extrch-share-modal.js)
 * wires up behavior and populates dynamic bits based on data-share-* attributes
 * on trigger buttons.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div id="extrch-share-modal" class="extrch-share-modal extrch-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="extrch-share-modal-title">
    <div class="extrch-share-modal-overlay extrch-modal-overlay"></div>
    <div class="extrch-share-modal-content extrch-modal-content" data-bg-type="color">
        <button class="extrch-share-modal-close extrch-modal-close" aria-label="<?php esc_attr_e('Close share modal', 'extrachill-artist-platform'); ?>">&times;</button>

        <div class="extrch-share-modal-header">
            <img class="extrch-share-modal-profile-img" src="" alt="" style="display:none;" />
            <div class="extrch-share-modal-titles">
                <h3 id="extrch-share-modal-title" class="extrch-share-modal-main-title"></h3>
                <p class="extrch-share-modal-subtitle"></p>
            </div>
        </div>

        <div class="extrch-share-modal-options-grid extrch-share-modal-options">
            <!-- Native share (shown only when supported) -->
            <button type="button" class="extrch-share-option-button extrch-share-option-native" style="display:none;">
                <span class="extrch-share-option-icon"><i class="fas fa-share-square"></i></span>
                <span class="extrch-share-option-label"><?php esc_html_e('Share', 'extrachill-artist-platform'); ?></span>
            </button>

            <!-- Fallback social links (hidden when native share is available) -->
            <a class="extrch-share-option-button extrch-share-option-facebook" href="#" target="_blank" rel="noopener" style="display:flex;">
                <span class="extrch-share-option-icon"><i class="fab fa-facebook"></i></span>
                <span class="extrch-share-option-label">Facebook</span>
            </a>
            <a class="extrch-share-option-button extrch-share-option-twitter" href="#" target="_blank" rel="noopener" style="display:flex;">
                <span class="extrch-share-option-icon"><i class="fab fa-x-twitter"></i></span>
                <span class="extrch-share-option-label">X</span>
            </a>
            <a class="extrch-share-option-button extrch-share-option-linkedin" href="#" target="_blank" rel="noopener" style="display:flex;">
                <span class="extrch-share-option-icon"><i class="fab fa-linkedin"></i></span>
                <span class="extrch-share-option-label">LinkedIn</span>
            </a>
            <a class="extrch-share-option-button extrch-share-option-email" href="#" target="_blank" rel="noopener" style="display:flex;">
                <span class="extrch-share-option-icon"><i class="fas fa-envelope"></i></span>
                <span class="extrch-share-option-label"><?php esc_html_e('Email', 'extrachill-artist-platform'); ?></span>
            </a>

            <!-- Copy link (always available) -->
            <button type="button" class="extrch-share-option-button extrch-share-option-copy-link">
                <span class="extrch-share-option-icon"><i class="fas fa-copy"></i></span>
                <span class="extrch-share-option-label"><?php esc_html_e('Copy Link', 'extrachill-artist-platform'); ?></span>
            </button>
        </div>
    </div>
</div>
