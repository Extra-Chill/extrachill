<?php

/**
 * Topics Loop
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

do_action( 'bbp_template_before_topics_loop' ); ?>

<div class="sorting-search">
<!-- Begin Dropdown for Sorting Topics -->
<div class="bbp-sorting-form" data-forum-id="<?php echo bbp_get_forum_id(); ?>" data-nonce="<?php echo wp_create_nonce('bbp_sort_nonce'); ?>">
    <form id="sortingForm" action="" method="get">
        <select name="sort">
            <option value="default" <?php echo (!isset($_GET['sort']) || $_GET['sort'] == 'default') ? 'selected' : ''; ?>>Sort by Recent</option>
            <option value="upvotes" <?php echo (isset($_GET['sort']) && $_GET['sort'] == 'upvotes') ? 'selected' : ''; ?>>Sort by Upvotes</option>
        </select>
        <select name="time_range">
            <option value="">All Time</option>
            <option value="7" <?php selected( isset($_GET['time_range']) && $_GET['time_range'] == '7' ); ?>>Last 7 Days</option>
            <option value="30" <?php selected( isset($_GET['time_range']) && $_GET['time_range'] == '30' ); ?>>Last 30 Days</option>
            <option value="90" <?php selected( isset($_GET['time_range']) && $_GET['time_range'] == '90' ); ?>>Last 90 Days</option>
        </select>
    </form>
<!-- End Dropdown for Sorting Topics -->
</div>
<!-- Begin Custom Search Form -->
<div class="bbp-search-form">
    <form id="bbp-ajax-search-form" method="get" data-forum-id="<?php echo bbp_get_forum_id(); ?>">
        <input type="text" id="bbp-search-input" name="bbp_search" placeholder="Search topics..." value="<?php echo isset($_GET['bbp_search']) ? esc_attr($_GET['bbp_search']) : ''; ?>">
        <button type="submit">Search</button>
    </form>
</div>
<!-- End Custom Search Form -->
</div>
<ul id="bbp-forum-<?php bbp_forum_id(); ?>" class="bbp-topics">
	<li class="bbp-header">
		<ul class="forum-titles">
			<li class="bbp-topic-title"><?php esc_html_e( 'Topic', 'bbpress' ); ?></li>
			<li class="bbp-topic-voice-count"><?php esc_html_e( 'Voices', 'bbpress' ); ?></li>
			<li class="bbp-topic-reply-count"><?php bbp_show_lead_topic()
				? esc_html_e( 'Replies', 'bbpress' )
				: esc_html_e( 'Posts',   'bbpress' );
			?></li>
			<li class="bbp-topic-freshness"><?php esc_html_e( 'Latest', 'bbpress' ); ?></li>
		</ul>
	</li>

	<li class="bbp-body">

		<?php while ( bbp_topics() ) : bbp_the_topic(); ?>

			<?php bbp_get_template_part( 'loop', 'single-topic' ); ?>

		<?php endwhile; ?>

	</li>

	<li class="bbp-footer">
		<div class="tr">
			<p>
				<span class="td colspan<?php echo ( bbp_is_user_home() && ( bbp_is_favorites() || bbp_is_subscriptions() ) ) ? '5' : '4'; ?>">&nbsp;</span>
			</p>
		</div><!-- .tr -->
	</li>
</ul><!-- #bbp-forum-<?php bbp_forum_id(); ?> -->

<?php do_action( 'bbp_template_after_topics_loop' ); ?>
