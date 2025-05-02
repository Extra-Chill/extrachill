<?php
// Get all locations with events associated with them, including both parent and child terms.
$event_locations = get_terms([
    'taxonomy'   => 'location',
    'hide_empty' => true, // Only terms with events
]);

// Array to hold only specific child terms (explicit event locations) with events.
$locations_with_events = [];

foreach ($event_locations as $location) {
    // Check if the term is a direct child or has no children (indicating a specific event location).
    $is_direct_location = ($location->parent !== 0) || !get_term_children($location->term_id, 'location');

    if ($is_direct_location) {
        // Query to confirm the location has events directly assigned to it.
        $has_events = new WP_Query([
            'post_type'      => 'tribe_events',
            'tax_query'      => [
                [
                    'taxonomy' => 'location',
                    'field'    => 'term_id',
                    'terms'    => $location->term_id,
                    'include_children' => false, // Exclude events under child locations
                ],
            ],
            'posts_per_page' => 1, // Only need to know if at least one event exists
            'fields'         => 'ids', // Only retrieve the event ID
        ]);

        // Add location to array if it has at least one event
        if ($has_events->have_posts()) {
            $locations_with_events[] = $location;
        }
        
        wp_reset_postdata(); // Clean up after the query
    }
}

// Exit if no locations with events are found.
if (empty($locations_with_events)) {
    return;
}

// Retrieve the selected location from tribe_events_template_var with fallback to GET.
$selected_location = tribe_events_template_var(['bar', 'tribe-bar-location'], '');
?>

<div class="tribe-events-c-search__input-control tribe-events-c-search__input-control--select" data-js="tribe-events-events-bar-input-control">
    <label class="tribe-events-c-search__label tribe-common-form-control-text__label" for="tribe-bar-location">
        <?php esc_html_e('Filter by Location', 'extrachill'); ?>
    </label>
    <select
        class="tribe-common-form-control-text__input tribe-events-c-search__input tribe-events-c-search__input--location"
        data-js="tribe-events-events-bar-input-control-input"
        id="tribe-bar-location"
        name="tribe-events-views[tribe-bar-location]"
        aria-label="<?php esc_attr_e('Filter by Location', 'extrachill'); ?>"
    >
        <option value=""><?php esc_html_e('All Locations', 'extrachill'); ?></option>
        <?php foreach ($locations_with_events as $location) : ?>
            <option value="<?php echo esc_attr($location->slug); ?>" <?php selected($selected_location, $location->slug); ?>>
                <?php echo esc_html($location->name); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
