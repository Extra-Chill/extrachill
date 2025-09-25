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

	<div class="homepage-description">
        <p>A hub for the underground music community, bringing artists and fans of DIY music together</p>
    </div>

	<div class="homepage-top-actions">
		<?php if (is_user_logged_in()) : ?>
			<a href="<?php echo bbp_get_user_profile_url(get_current_user_id()); ?>" class="button profile-btn">View Profile</a>
			<a href="/settings" class="button settings-btn">Settings</a>
		<?php else : ?>
			<a href="/login" class="button login-btn">Log In</a>
			<a href="/login?register=1" class="button signup-btn">Sign Up</a>
		<?php endif; ?>
	</div>

	<!-- Most Active Topics Section (Front Page Row) -->
    <?php bbp_get_template_part( 'front-page', 'recently-active' ); ?>


	<!-- Community Forums Section (Simplified) -->
    <span class="forum-title-with-icon">
    <h2 class="forum-front-ec">Community Forums</h2>
    <i id="community-forums-collapse" class="fa-solid fa-square-minus" onclick="toggleForumCollapse(this, 'community-forums-container')"></i></span>
    <p><?php echo fetch_latest_post_info_for_homepage(); ?></p>
    <div class="community-forums-container">
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

        <!-- Bottom Action Buttons -->
        <div class="homepage-bottom-actions">
            <?php do_action( 'extrachill_below_forums' ); ?>
        </div>
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
