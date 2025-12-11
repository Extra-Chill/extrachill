<?php
/**
 * New Topic Button
 *
 * Renders the "New Topic" button that triggers the modal on the community homepage.
 * Fallback href for no-JS navigates to homepage where forum topic forms exist.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<a href="/" id="new-topic-modal-trigger" class="button-1 button-large"><?php esc_html_e('New Topic', 'extra-chill-community'); ?></a>
