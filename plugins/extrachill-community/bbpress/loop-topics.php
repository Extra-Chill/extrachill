<?php
/**
 * Topics Loop (Simplified, Non-AJAX Sorting & Search)
 *
 * @package bbPress
 * @subpackage Theme
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

do_action('bbp_template_before_topics_loop');

// Get current sort and search selections
$current_sort = $_GET['sort'] ?? 'default';
$current_search = $_GET['bbp_search'] ?? '';

// Set forum ID for query
$forum_id = bbp_get_forum_id();

// Query Arguments based on sort parameter
$args = [
    'post_type'      => bbp_get_topic_post_type(),
    'posts_per_page' => get_option('_bbp_topics_per_page', 15),
    'paged'          => bbp_get_paged(),
    'post_status'    => 'publish',
];

// Apply sorting logic
if ($current_sort === 'upvotes') {
    $args['meta_key'] = 'upvote_count';
    $args['orderby']  = 'meta_value_num';
    $args['order']    = 'DESC';
} elseif ($current_sort === 'popular') {
    global $wpdb;
    $popular_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.post_parent FROM $wpdb->posts p
         INNER JOIN $wpdb->posts t ON p.post_parent = t.ID
         WHERE p.post_type = %s AND t.post_type = %s AND p.post_date >= %s
         GROUP BY p.post_parent ORDER BY COUNT(p.ID) DESC LIMIT 100",
        bbp_get_reply_post_type(), bbp_get_topic_post_type(), date('Y-m-d H:i:s', strtotime('-45 days'))
    ));
    $args['post__in'] = !empty($popular_ids) ? $popular_ids : [0];
    $args['orderby'] = 'post__in';
} else {
    $args['orderby']  = 'meta_value';
    $args['meta_key'] = '_bbp_last_active_time';
    $args['meta_type'] = 'DATETIME';
    $args['order']    = 'DESC';
}

// Apply search logic
if (!empty($current_search)) {
    $args['s'] = sanitize_text_field($current_search);
}

// Execute query
$query = new WP_Query($args);
?>

<!-- Sorting & Search UI -->
<div class="sorting-search">

    <!-- Sorting Form -->
    <div class="bbp-sorting-form">
        <form id="sortingForm" method="get">
            <select name="sort" id="sortSelect">
                <option value="default" <?php selected($current_sort, 'default'); ?>>Sort by Recent</option>
                <option value="upvotes" <?php selected($current_sort, 'upvotes'); ?>>Sort by Upvotes</option>
                <option value="popular" <?php selected($current_sort, 'popular'); ?>>Sort by Popular</option>
            </select>

            <?php if (!empty($current_search)): ?>
                <input type="hidden" name="bbp_search" value="<?php echo esc_attr($current_search); ?>">
            <?php endif; ?>
        </form>
    </div>

    <!-- Search Form -->
    <div class="bbp-search-form">
        <form method="get">
            <input type="text" name="bbp_search" placeholder="Search topics..." value="<?php echo esc_attr($current_search); ?>">
            <input type="hidden" name="sort" value="<?php echo esc_attr($current_sort); ?>">
            <button type="submit">Search</button>
        </form>
    </div>

</div>


<?php
// Execute query using bbp_has_topics() to correctly set bbPress globals
if (bbp_has_topics($args)) :
?>
    <div id="bbp-topic-loop-<?php echo esc_attr($forum_id); ?>" class="bbp-topics-grid">
        <div class="bbp-body">
            <?php while (bbp_topics()) : bbp_the_topic(); ?>
                <?php bbp_get_template_part('loop', 'single-topic-card'); ?>
            <?php endwhile; ?>
        </div>
    </div>
<?php else : ?>
    <div class="bbp-body"><p>No topics found.</p></div>
<?php endif; ?>



<?php
// Pagination template (standard bbPress)
bbp_get_template_part('pagination', 'topics');

wp_reset_postdata();

do_action('bbp_template_after_topics_loop');
?>

<!-- JavaScript to submit dropdown on change (simplified, no AJAX) -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('sortSelect').addEventListener('change', () => {
        document.getElementById('sortingForm').submit();
    });
});
</script>
