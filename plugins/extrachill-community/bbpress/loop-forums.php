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

    <!-- Band Platform Section -->
    <?php bbp_get_template_part( 'band-platform', 'homepage-section' ); ?>

	<!-- Community Forums Section (Simplified) -->
    <span class="forum-title-with-icon">
    <h2 class="forum-front-ec">Community Forums</h2>
    <i id="community-forums-collapse" class="fa-solid fa-square-minus" onclick="toggleForumCollapse(this, 'community-forums-container')"></i></span>
    <p><?php echo fetch_latest_post_info_for_homepage(); ?></p>
    <div class="community-forums-container">
        <?php
        $meta_query = array(
            array(
                'key' => '_show_on_homepage',
                'value' => '1',
                'compare' => '='
            ),
        );
        $args = array(
            'post_parent' => 0,
            'meta_query' => $meta_query,
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
