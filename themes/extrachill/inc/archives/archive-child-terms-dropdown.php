<?php
/**
 * Archive Child Terms Dropdown Component
 *
 * Displays child terms dropdown for hierarchical taxonomies
 *
 * @package ExtraChill
 * @since 1.0
 */

// Hook into archive below description
add_action('extrachill_archive_below_description', 'extrachill_child_terms_dropdown', 10);

/**
 * Display child terms dropdown for hierarchical taxonomies
 * Shows subcategories for categories and sub-locations for location taxonomy
 * Only displays when child terms exist
 *
 * @since 1.0
 */
function extrachill_child_terms_dropdown() {
    $term = get_queried_object();

    if (!$term || !is_a($term, 'WP_Term')) {
        return;
    }

    $child_terms = array();
    $dropdown_class = '';
    $heading = '';
    $select_text = '';

    // Handle different taxonomies
    if (is_category()) {
        $child_terms = get_categories(array(
            'child_of' => $term->term_id,
            'hide_empty' => false,
        ));
        $dropdown_class = 'subcategory-dropdown';
        $heading = __('Select Subcategory:', 'extrachill');
        $select_text = __('Select Subcategory', 'extrachill');
    } elseif (is_tax('location')) {
        $child_terms = get_terms(array(
            'taxonomy' => 'location',
            'hide_empty' => false,
            'parent' => $term->term_id,
        ));
        $dropdown_class = 'sub-location-dropdown';
        $heading = __('Select a Sub-Location:', 'extrachill');
        $select_text = __('Choose a Sub-Location', 'extrachill');
    }

    // Display dropdown if child terms exist
    if (!empty($child_terms)) {
        echo '<div class="' . esc_attr($dropdown_class) . '">';
        echo '<h2 class="filter-head">' . esc_html($heading) . '</h2>';
        echo '<select id="child-terms-select" onchange="if (this.value) window.location.href=this.value;">';
        echo '<option value="">' . esc_html($select_text) . '</option>';

        foreach ($child_terms as $child_term) {
            $term_link = is_category() ? get_category_link($child_term->term_id) : get_term_link($child_term);
            echo '<option value="' . esc_url($term_link) . '">' . esc_html($child_term->name) . '</option>';
        }

        echo '</select>';
        echo '</div>';
    }
}