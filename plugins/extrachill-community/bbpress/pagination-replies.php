<?php

/**
 * Pagination for pages of replies (when viewing a topic)
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

do_action( 'bbp_template_before_pagination_loop' ); 

// Check if the topic has more than 1 reply before displaying pagination
if ( bbp_get_topic_reply_count() > 0 ) : ?>

    <div class="bbp-pagination">
        <div class="bbp-pagination-count"><?php bbp_topic_pagination_count(); ?></div>
        <div class="bbp-pagination-links"><?php bbp_topic_pagination_links(); ?></div>
    </div>

<?php endif; ?>

<?php do_action( 'bbp_template_after_pagination_loop' ); ?>
