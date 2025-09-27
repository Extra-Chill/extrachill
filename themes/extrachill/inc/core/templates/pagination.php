<?php
/**
 * Pagination Template
 *
 * Native WordPress pagination system for ExtraChill theme.
 * Replaces wp-pagenavi plugin with clean, lightweight solution.
 * Visual design inspired by community theme pagination.
 *
 * @package ExtraChill
 * @since 69.58
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display pagination for posts with professional count and navigation.
 *
 * @param WP_Query|null $query Optional custom query object. Uses global $wp_query if null.
 * @param string $context Optional context for styling (default, archive, search).
 */
function extrachill_pagination($query = null, $context = 'default') {
    global $wp_query;

    // Use provided query or fall back to global
    $pagination_query = $query ? $query : $wp_query;

    // Don't show pagination if not needed
    if (!$pagination_query || $pagination_query->max_num_pages <= 1) {
        return;
    }

    // Handle pagination for both global and custom queries
    if ($query) {
        // Custom query - use query's paged value
        $current_page = max(1, $pagination_query->get('paged', 1));
    } else {
        // Global query - use standard method
        $current_page = max(1, get_query_var('paged'));
    }

    $total_pages = $pagination_query->max_num_pages;
    $total_posts = $pagination_query->found_posts;
    $per_page = $pagination_query->query_vars['posts_per_page'];

    // Calculate pagination display values
    $start = (($current_page - 1) * $per_page) + 1;
    $end = min($current_page * $per_page, $total_posts);

    // Generate count display
    if ($total_posts == 1) {
        $count_html = 'Viewing 1 post';
    } elseif ($end == $start) {
        $count_html = sprintf('Viewing post %s of %s', number_format($start), number_format($total_posts));
    } else {
        $count_html = sprintf('Viewing posts %s-%s of %s total', number_format($start), number_format($end), number_format($total_posts));
    }

    // Generate navigation links with proper URL handling
    $big = 999999999; // Need an unlikely integer

    // Preserve existing query parameters in pagination URLs
    $current_url = home_url(add_query_arg(array(), $GLOBALS['wp']->request));
    $base_url = str_replace($big, '%#%', esc_url(get_pagenum_link($big)));

    // For pages with existing query params, use add_query_arg format
    if (!empty($_GET)) {
        $format = '&paged=%#%';
        // If no existing query params, use standard format
        if (strpos($base_url, '?') === false) {
            $format = '?paged=%#%';
        }
    } else {
        $format = '?paged=%#%';
    }

    $links_html = paginate_links(array(
        'base' => $base_url,
        'format' => $format,
        'total' => $total_pages,
        'current' => $current_page,
        'prev_text' => '&laquo; Previous',
        'next_text' => 'Next &raquo;',
        'type' => 'list',
        'end_size' => 1,
        'mid_size' => 2,
        'add_args' => array_merge($_GET, array('paged' => '%#%'))
    ));

    // Output pagination HTML
    if ($links_html) {
        echo '<div class="extrachill-pagination pagination-' . esc_attr($context) . '">';
        echo '<div class="pagination-count">' . esc_html($count_html) . '</div>';
        echo '<div class="pagination-links">' . $links_html . '</div>';
        echo '</div>';
    }
}

/**
 * Template part wrapper for pagination.
 * Allows easy integration with get_template_part() calls.
 */
function extrachill_pagination_template_part() {
    extrachill_pagination();
}