<?php
/**
 * Pagination
 *
 * Technical Implementation:
 * - URL preservation: Maintains GET parameters across pagination using add_args
 * - Dynamic formatting: Adjusts URL format based on existing query string presence
 *
 * @package ExtraChill
 * @since 69.58
 */

if (!defined('ABSPATH')) {
    exit;
}

function extrachill_pagination($query = null, $context = 'default') {
    global $wp_query;

    $pagination_query = $query ? $query : $wp_query;

    if (!$pagination_query || $pagination_query->max_num_pages <= 1) {
        return;
    }

    if ($query) {
        $current_page = max(1, $pagination_query->get('paged', 1));
    } else {
        $current_page = max(1, get_query_var('paged'));
    }

    $total_pages = $pagination_query->max_num_pages;
    $total_posts = $pagination_query->found_posts;
    $per_page = $pagination_query->query_vars['posts_per_page'];

    if ($per_page == -1) {
        $per_page = $total_posts;
    }

    $start = (($current_page - 1) * $per_page) + 1;
    $end = min($current_page * $per_page, $total_posts);

    if ($total_posts == 1) {
        $count_html = 'Viewing 1 post';
    } elseif ($end == $start) {
        $count_html = sprintf('Viewing post %s of %s', number_format($start), number_format($total_posts));
    } else {
        $count_html = sprintf('Viewing posts %s-%s of %s total', number_format($start), number_format($end), number_format($total_posts));
    }

    $big = 999999999;
    $current_url = home_url(add_query_arg(array(), $GLOBALS['wp']->request));
    $base_url = str_replace($big, '%#%', esc_url(get_pagenum_link($big)));

    if (!empty($_GET)) {
        $format = '&paged=%#%';
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
        'add_args' => $_GET
    ));

    // Add button-2 class to pagination links and current page
    if ($links_html) {
        $links_html = str_replace('class="prev page-numbers', 'class="prev page-numbers button-2 button-medium', $links_html);
        $links_html = str_replace('class="next page-numbers', 'class="next page-numbers button-2 button-medium', $links_html);
        $links_html = str_replace('class="page-numbers', 'class="page-numbers button-2 button-medium', $links_html);
    }

    if ($links_html) {
        echo '<div class="extrachill-pagination pagination-' . esc_attr($context) . '">';
        echo '<div class="pagination-count">' . esc_html($count_html) . '</div>';
        echo '<div class="pagination-links">' . $links_html . '</div>';
        echo '</div>';
    }
}

function extrachill_pagination_template_part() {
    extrachill_pagination();
}