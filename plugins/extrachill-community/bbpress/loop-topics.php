<?php
/**
 * Topics Loop (Context-Aware)
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

do_action('bbp_template_before_topics_loop');

// Build the context-aware loop args (sort/search resolution lives in
// inc/content/forum-queries.php; this template stays presentational).
$loop_args = ec_get_topics_loop_args();

extrachill_filter_bar();

if ( bbp_has_topics($loop_args) ) :
	?>
	<div id="bbp-topic-loop-<?php echo esc_attr(bbp_get_forum_id()); ?>" class="bbp-topics-grid ec-mobile-full-width-panel">
		<div class="bbp-body">
			<?php
			while ( bbp_topics() ) :
				bbp_the_topic();
				?>
				<?php bbp_get_template_part('loop', 'single-topic-card'); ?>
			<?php endwhile; ?>
		</div>
	</div>
<?php else : ?>
	<div class="bbp-body"><p>No topics found matching your criteria.</p></div>
<?php endif; ?>

<?php
// Access the actual query object used by bbp_has_topics()
$bbp           = bbpress();
$current_query = ! empty( $bbp->topic_query ) ? $bbp->topic_query : $GLOBALS['wp_query'];

// Only show pagination if there are multiple pages
if ( ! empty( $current_query ) && $current_query->max_num_pages > 1 ) {
	extrachill_pagination( $current_query, 'bbpress' );
}
do_action('bbp_template_after_topics_loop');
