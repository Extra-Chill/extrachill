<?php
/**
 * New Topic Modal
 *
 * Modal container for the bbPress topic creation form on the community homepage.
 * For logged-in users: displays the full topic form with forum dropdown.
 * For logged-out users: bbPress displays the login/register block.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div id="new-topic-modal-overlay" class="new-topic-modal-overlay"></div>
<div id="new-topic-modal" class="new-topic-modal" role="dialog" aria-modal="true" aria-labelledby="new-topic-modal-title">
    <div class="new-topic-modal-content">
        <button type="button" class="new-topic-modal-close" aria-label="<?php esc_attr_e( 'Close modal', 'extra-chill-community' ); ?>">&times;</button>
        <h2 id="new-topic-modal-title" class="new-topic-modal-title"><?php esc_html_e( 'Create Discussion', 'extra-chill-community' ); ?></h2>
        <p id="new-topic-modal-description" class="new-topic-modal-description"></p>
        <?php bbp_get_template_part( 'form', 'topic' ); ?>
    </div>
</div>
