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

<!-- Community Boards Section -->
<span class="forum-title-with-icon">
<h2 class="forum-front-ec">Community Forums</h2>
<i id="forum-collapse" class="fa-solid fa-square-minus" onclick="toggleForumCollapse(this, 'top-container')"></i></span>
<p><?php echo fetch_latest_post_info_by_section('top'); ?></p>
<div class="top-container">
    <ul id="forums-list-top-<?php bbp_forum_id(); ?>" class="bbp-forums">
        <li class="bbp-header">
            <ul class="forum-titles">
                <li class="bbp-forum-info"><?php esc_html_e( 'Forum', 'bbpress' ); ?></li>
                <li class="bbp-forum-topic-count"><?php esc_html_e( 'Topics', 'bbpress' ); ?></li>
                <li class="bbp-forum-reply-count"><?php bbp_show_lead_topic()
                    ? esc_html_e( 'Replies', 'bbpress' )
                    : esc_html_e( 'Posts',   'bbpress' );
                ?></li>
                <li class="bbp-forum-freshness"><?php esc_html_e( 'Latest', 'bbpress' ); ?></li>
            </ul>
        </li><!-- .bbp-header -->
        <li class="bbp-body">
            <?php while ( bbp_forums() ) : bbp_the_forum(); ?>
                <?php 
                $forum_section = get_post_meta(bbp_get_forum_id(), '_bbp_forum_section', true);
                if ( 'top' === $forum_section ): 
                    bbp_get_template_part( 'loop', 'single-forum' );
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
        <li class="bbp-header">
            <ul class="forum-titles">
                <li class="bbp-forum-info"><?php esc_html_e( 'Forum', 'bbpress' ); ?></li>
                <li class="bbp-forum-topic-count"><?php esc_html_e( 'Topics', 'bbpress' ); ?></li>
                <li class="bbp-forum-reply-count"><?php bbp_show_lead_topic()
                    ? esc_html_e( 'Replies', 'bbpress' )
                    : esc_html_e( 'Posts',   'bbpress' );
                ?></li>
                <li class="bbp-forum-freshness"><?php esc_html_e( 'Latest', 'bbpress' ); ?></li>
            </ul>
        </li><!-- .bbp-header -->
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

            // The query
            if (bbp_has_forums($args)) : while (bbp_forums()) : bbp_the_forum(); ?>
                <?php
                $forum_section = get_post_meta(bbp_get_forum_id(), '_bbp_forum_section', true);
                if ('middle' === $forum_section): 
                    bbp_get_template_part('loop', 'single-forum');
                endif;
                ?>
            <?php endwhile; endif; ?>
        </li><!-- .bbp-body -->
    </ul>
</div>



<?php
// Check if the current user has the 'extrachill_team' meta
if ( is_user_logged_in() && get_user_meta( get_current_user_id(), 'extrachill_team', true ) == '1' ): ?>

    <!-- Private Section -->
    <h2 class="forum-front-ec">Private</h2>
    <p class="private-forums">If you can see this, you're part of the Extra Chill team.</p>
    <div class="private-container">
        <ul id="forums-list-private-<?php bbp_forum_id(); ?>" class="bbp-forums">
            <li class="bbp-header">
                <ul class="forum-titles">
                    <li class="bbp-forum-info"><?php esc_html_e( 'Forum', 'bbpress' ); ?></li>
                    <li class="bbp-forum-topic-count"><?php esc_html_e( 'Topics', 'bbpress' ); ?></li>
                    <li class="bbp-forum-reply-count"><?php bbp_show_lead_topic() 
                        ? esc_html_e( 'Replies', 'bbpress' )
                        : esc_html_e( 'Posts',   'bbpress' );
                    ?></li>
                    <li class="bbp-forum-freshness"><?php esc_html_e( 'Latest', 'bbpress' ); ?></li>
                </ul>
            </li><!-- .bbp-header -->
            <li class="bbp-body">
                <?php while ( bbp_forums() ) : bbp_the_forum(); ?>
                    <?php 
                    $forum_section = get_post_meta(bbp_get_forum_id(), '_bbp_forum_section', true);
                    if ( 'private' === $forum_section ): 
                        bbp_get_template_part( 'loop', 'single-forum' );
                    endif;
                    ?>
                <?php endwhile; ?>
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

<?php do_action( 'bbp_template_after_forums_loop' ); ?>