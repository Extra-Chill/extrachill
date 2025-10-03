<?php

/**
 * Forums Loop
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;
?>

<!-- Community Forums Section -->
<h2>Community Forums</h2>
<?php do_action('extrachill_community_home_before_forums'); ?>
<?php
$args = array(
    'post_parent' => 0,
    'meta_query' => array(
        array(
            'key' => '_show_on_homepage',
            'value' => '1',
            'compare' => '='
        ),
    ),
    'orderby' => 'meta_value',
    'meta_key' => '_bbp_last_active_time',
    'order' => 'DESC',
    'posts_per_page' => -1,
);
if ( bbp_has_forums( $args ) ) : ?>
    <ul id="forums-list-homepage" class="bbp-forums">
        <li class="bbp-body">
            <?php while ( bbp_forums() ) : bbp_the_forum(); ?>
                <?php bbp_get_template_part( 'loop', 'single-forum-card' ); ?>
            <?php endwhile; ?>
        </li>
    </ul>
<?php else : ?>
    <p>No forums are currently set to display on the homepage.</p>
<?php endif; ?>
