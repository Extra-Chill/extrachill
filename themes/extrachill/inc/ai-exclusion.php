<?php
function exclude_category_and_author_from_queries($query) {
    if (is_admin()) {
        return;
    }

    // Initialize tax_query if not already set
    $tax_query = $query->get('tax_query');
    if (!is_array($tax_query)) {
        $tax_query = array();
    }

    // Exclude category 2646 from the homepage and category 724
    if ($query->is_home() || $query->is_category(724)) {
        $tax_query[] = array(
            'taxonomy' => 'category',
            'field'    => 'term_id',
            'terms'    => array(2646),
            'operator' => 'NOT IN',
        );
    }

    // Exclude posts by author 39 in category 2101 from the homepage, category 2101 archive, and category 724
    if ($query->is_home() || $query->is_category(array(2101, 724))) {
        // Add the author exclusion
        $authors_to_exclude = $query->get('author__not_in');
        if (!is_array($authors_to_exclude)) {
            $authors_to_exclude = array();
        }
        $authors_to_exclude[] = 39;
        $query->set('author__not_in', $authors_to_exclude);

        // Ensure we don't exclude category 2101 or 724 posts entirely
        if ($query->is_category(array(2101, 724))) {
            $tax_query[] = array(
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => array(2101, 724),
                'operator' => 'IN',
            );
        }
    }

    // Set the modified tax_query
    $query->set('tax_query', $tax_query);
}
add_action('pre_get_posts', 'exclude_category_and_author_from_queries');
?>
