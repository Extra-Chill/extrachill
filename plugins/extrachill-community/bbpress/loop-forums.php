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
<p><?php echo fetch_latest_post_info_for_homepage(); ?></p>
<?php
$args = extrachill_get_homepage_forums_args();
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
