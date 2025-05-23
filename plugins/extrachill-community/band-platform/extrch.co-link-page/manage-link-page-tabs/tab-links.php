<?php
/**
 * Template Part: Links Tab for Manage Link Page
 *
 * Loaded from manage-link-page.php
 */

defined( 'ABSPATH' ) || exit;

// Ensure variables from parent scope are available if needed.
// For this tab, most content is JS-rendered, but PHP comments/structure are preserved.
?>
<div class="link-page-content-card">
    <div id="bp-social-icons-section">
        <h2><?php esc_html_e('Social Icons', 'generatepress_child'); ?></h2>
        <div id="bp-social-icons-list">
            <!-- JS will render the list here -->
        </div>
        <button type="button" id="bp-add-social-icon-btn" class="button button-secondary bp-add-social-icon-btn"><i class="fas fa-plus"></i> <?php esc_html_e('Add Social Icon', 'generatepress_child'); ?></button>
    </div>
</div> 

<div class="link-page-content-card">
    <div id="bp-link-list-section">
        <h2><?php esc_html_e('Link Sections', 'generatepress_child'); ?></h2>
        <div id="bp-link-sections-list">
            <!-- JS will render the sections and links here -->
        </div>
        <button type="button" id="bp-add-link-section-btn" class="button button-secondary bp-add-link-section-btn"><i class="fas fa-plus"></i> <?php esc_html_e('Add Link Section', 'generatepress_child'); ?></button>
    </div>
</div>