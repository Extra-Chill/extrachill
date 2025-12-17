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

global $bbp;

// Get current sort and search selections from URL
$current_sort = $_GET['sort'] ?? 'default';
$current_search = $_GET['bbp_search'] ?? '';


// --- Determine Base Query Arguments --- 
// Priority: 1. Arguments passed directly to this template part (e.g., from a custom feed).
//           2. Arguments already set in the global $bbp->topic_query (less reliable for this). 
//           3. Default arguments for a standard forum view.

$base_args = array();

if (isset($bbp->extrachill_passthrough_args) && !empty($bbp->extrachill_passthrough_args)) {
    $base_args = $bbp->extrachill_passthrough_args;
    unset($bbp->extrachill_passthrough_args); // Clean up to prevent interference elsewhere
} else {
    // Fallback: Try $bbp->topic_query or build defaults
    // (Original logic for $extrachill_query_args or $bbp->topic_query->query_vars would go here if this global method also fails)
    // For now, let's be explicit about the fallback to $bbp->topic_query->query_vars directly
    if (!empty($bbp->topic_query->query_vars)) {
        $base_args = $bbp->topic_query->query_vars;
    } else {
        // Default args from your original loop-topics if query_vars also empty
        $base_args['post_type'] = bbp_get_topic_post_type();
        $base_args['posts_per_page'] = get_option('_bbp_topics_per_page', 15);
        $base_args['paged'] = bbp_get_paged();
        $base_args['post_status'] = 'publish';
    }
}

// Ensure essential defaults if not set by any context
$base_args['post_type'] = $base_args['post_type'] ?? bbp_get_topic_post_type();
$base_args['posts_per_page'] = $base_args['posts_per_page'] ?? get_option('_bbp_topics_per_page', 15);
$base_args['paged'] = $base_args['paged'] ?? bbp_get_paged(); // Crucial for pagination
$base_args['post_status'] = $base_args['post_status'] ?? 'publish';

// Working args for this loop, starting with the determined base
$loop_args = $base_args;

// --- Apply Sorting Logic (modifies $loop_args) ---
$default_sort_args = array(
    'orderby'  => 'meta_value',
    'meta_key' => '_bbp_last_active_time',
    'meta_type' => 'DATETIME',
    'order'    => 'DESC',
);

if ($current_sort === 'upvotes') {
    $loop_args['meta_key'] = 'upvote_count';
    $loop_args['orderby']  = 'meta_value_num';
    $loop_args['order']    = 'DESC';
} elseif ($current_sort === 'popular') {
    global $wpdb;
    $popular_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.post_parent FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->posts} t ON p.post_parent = t.ID
         WHERE p.post_type = %s AND t.post_type = %s AND p.post_date >= %s
         GROUP BY p.post_parent ORDER BY COUNT(p.ID) DESC LIMIT 100",
        bbp_get_reply_post_type(), bbp_get_topic_post_type(), date('Y-m-d H:i:s', strtotime('-45 days'))
    ));
    $loop_args['post__in'] = !empty($popular_ids) ? $popular_ids : array(0);
    $loop_args['orderby'] = 'post__in';
    // If 'popular' is chosen, it might override post_parent__in from a feed.
    // unset($loop_args['post_parent__in']); // Uncomment if 'popular' should be truly global
} else { // Default sort (or if $current_sort is 'default')
    // Merge default sort, but prioritize what might already be in $loop_args from $base_args for these keys
    $loop_args = array_merge($default_sort_args, $loop_args); 
}

// Apply search logic (bbPress default search 's' parameter)
if (!empty($current_search)) {
    $loop_args['s'] = sanitize_text_field($current_search);
}


extrachill_filter_bar();

if (bbp_has_topics($loop_args)) :
?>
    <div id="bbp-topic-loop-<?php echo esc_attr(bbp_get_forum_id()); ?>" class="bbp-topics-grid">
        <div class="bbp-body">
            <?php while (bbp_topics()) : bbp_the_topic(); ?>
                <?php bbp_get_template_part('loop', 'single-topic-card'); ?>
            <?php endwhile; ?>
        </div>
    </div>
<?php else : ?>
    <div class="bbp-body"><p>No topics found matching your criteria.</p></div>
<?php endif; ?>

<?php
// Access the actual query object used by bbp_has_topics()
$bbp = bbpress();
$current_query = ! empty( $bbp->topic_query ) ? $bbp->topic_query : $GLOBALS['wp_query'];

// Only show pagination if there are multiple pages
if ( ! empty( $current_query ) && $current_query->max_num_pages > 1 ) {
	extrachill_pagination( $current_query, 'bbpress' );
}
do_action('bbp_template_after_topics_loop');
