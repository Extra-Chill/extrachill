<?php
/**
 * Archive Child Terms Dropdown Helper
 *
 * Provides child terms dropdown HTML for hierarchical taxonomies
 * Called by archive filter bar component
 *
 * @package ExtraChill
 * @since 1.0
 */

/**
 * Generate child terms dropdown HTML for hierarchical taxonomies
 * Shows subcategories for categories and sub-locations for location taxonomy
 * Only returns HTML when child terms exist
 *
 * @return string HTML for child terms dropdown or empty string
 * @since 1.0
 */
function extrachill_child_terms_dropdown_html() {
    $term = get_queried_object();

    if (!$term || !is_a($term, 'WP_Term')) {
        return '';
    }

    $child_terms = array();
    $dropdown_class = '';
    $heading = '';
    $select_text = '';

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

    if (empty($child_terms)) {
        return '';
    }

    ob_start();
    ?>
    <div class="<?php echo esc_attr($dropdown_class); ?>">
        <select id="child-terms-select" onchange="if (this.value) window.location.href=this.value;">
            <option value=""><?php echo esc_html($select_text); ?></option>
            <?php foreach ($child_terms as $child_term) :
                $term_link = is_category() ? get_category_link($child_term->term_id) : get_term_link($child_term);
            ?>
                <option value="<?php echo esc_url($term_link); ?>"><?php echo esc_html($child_term->name); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
    return ob_get_clean();
}