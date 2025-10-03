<?php
/**
 * Search Results Header
 *
 * Displays search query in page title.
 *
 * @package ExtraChill
 * @since 69.58
 */

$search_query = get_search_query();
?>

<header class="page-header">
    <h1 class="page-title">
        <span>
            <?php printf(__('Search Results for: %s', 'extrachill'), '<span class="search-query">' . esc_html($search_query) . '</span>'); ?>
        </span>
    </h1>
</header><!-- .page-header -->
