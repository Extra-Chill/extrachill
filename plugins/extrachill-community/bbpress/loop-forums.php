<?php

/**
 * Forums Loop
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

do_action( 'bbp_template_before_forums_loop' ); ?>

<?php
// Check if the current forum has subforums
if ( bbp_is_single_forum() && bbp_has_forums( array( 'post_parent' => bbp_get_forum_id() ) ) ) : ?>

	<!-- Display Subforums Only -->
	<ul id="forums-list-subforums-<?php bbp_forum_id(); ?>" class="bbp-forums">
		<li class="bbp-body">
			<?php while ( bbp_forums() ) : bbp_the_forum(); ?>
				<?php bbp_get_template_part( 'loop', 'single-forum-card' ); ?>
			<?php endwhile; ?>
		</li><!-- .bbp-body -->
	</ul>

<?php else : ?>

	<?php if (is_user_logged_in()) : ?>
		<p>Welcome back, <a href="<?php echo bbp_get_user_profile_url(get_current_user_id()); ?>"><?php echo wp_get_current_user()->display_name; ?></a>. Thanks for being part of the scene.</p>
	<?php else :?>
		<p><a href="/login">Log in</a> to partake in the online music scene.</p>
	<?php endif; ?>

	<!-- Most Active Topics Section (Front Page Row) -->
    <?php bbp_get_template_part( 'front-page', 'recently-active' ); ?>
    
    <!-- Most Active Users Section -->
    <?php bbp_get_template_part( 'most-active-users' ); ?>

	<!-- Community Boards Section -->
    <span class="forum-title-with-icon">
    <h2 class="forum-front-ec">Community Forums</h2>
    <i id="forum-collapse" class="fa-solid fa-square-minus" onclick="toggleForumCollapse(this, 'top-container')"></i></span>
    <p><?php echo fetch_latest_post_info_by_section('top'); ?></p>
    <div class="top-container">
        <ul id="forums-list-top-<?php bbp_forum_id(); ?>" class="bbp-forums">

            <li class="bbp-body">
                <?php while ( bbp_forums() ) : bbp_the_forum(); ?>
                    <?php
                    $forum_section = get_post_meta(bbp_get_forum_id(), '_bbp_forum_section', true);
                    if ( 'top' === $forum_section ):
                        bbp_get_template_part( 'loop', 'single-forum-card' );
                    endif;
                    ?>
                <?php endwhile; ?>
            </li><!-- .bbp-body -->
        </ul>
    </div>

	<!-- The Rabbit Hole Section -->
    <span class="forum-title-with-icon"><h2 id="TheRabbitHole" class="forum-front-ec">Music Forums</h2>
    <i id="forum-collapse" class="fa-solid fa-square-minus" onclick="toggleForumCollapse(this, 'middle-container')"></i></span>
    <p><?php echo fetch_latest_post_info_by_section('middle'); ?></p>
    <div class="middle-container">
        <ul id="forums-list-middle-<?php bbp_forum_id(); ?>" class="bbp-forums">

            <li class="bbp-body">
                <?php
                // Custom query to sort forums by freshness
                $args = array(
                    'post_type'      => bbp_get_forum_post_type(),
                    'post_parent'    => bbp_get_forum_id(), // Ensure it only fetches child forums of the current forum context if needed
                    'meta_key'       => '_bbp_last_active_time', // Key for sorting
                    'orderby'        => 'meta_value', // Sort by the meta key value (last active time)
                    'order'          => 'DESC', // Most recent first
                    'posts_per_page' => -1, // Adjust if you need pagination or limited number of forums
                );

                // DEBUG OUTPUT
                $forums_query = new WP_Query($args);
                error_log('Music Forums Query Args: ' . print_r($args, true));
                error_log('Music Forums Found: ' . print_r($forums_query->posts, true));
                foreach ($forums_query->posts as $forum) {
                    error_log('Forum ID: ' . $forum->ID . ' | Title: ' . $forum->post_title . ' | Section: ' . get_post_meta($forum->ID, '_bbp_forum_section', true));
                }
                // END DEBUG OUTPUT

                // The query
                if (bbp_has_forums($args)) : while (bbp_forums()) : bbp_the_forum(); ?>
                    <?php
                    $forum_section = get_post_meta(bbp_get_forum_id(), '_bbp_forum_section', true);
                    if ('middle' === $forum_section):
                        bbp_get_template_part('loop', 'single-forum-card');
                    endif;
                    ?>
                <?php endwhile; endif; ?>
            </li><!-- .bbp-body -->
        </ul>
    </div>

<?php endif; ?>

<!-- Footer Section -->
<div class="bbp-footer">
    <div class="tr">
        <p class="td colspan4">&nbsp;</p>
    </div><!-- .tr -->
</div><!-- .bbp-footer -->


<?php do_action( 'bbp_template_after_forums_loop' ); 

?>
