<?php
// Get locations from the 'location' taxonomy that have events.
$locations = get_terms([
    'taxonomy'   => 'location',
    'hide_empty' => true,
]);

// Exit if no locations are available or if there is an error.
if (empty($locations) || is_wp_error($locations)) {
    return;
}

// Retrieve the selected location from tribe_events_template_var with fallback to GET.
$selected_location = tribe_events_template_var(['bar', 'tribe-bar-location'], '');
?>

<div class="tribe-events-c-search__input-control tribe-events-c-search__input-control--select" data-js="tribe-events-events-bar-input-control">
    <label class="tribe-events-c-search__label tribe-common-form-control-text__label" for="tribe-bar-location">
        <?php esc_html_e('Filter by Location', 'your-text-domain'); ?>
    </label>
    <select
        class="tribe-common-form-control-text__input tribe-events-c-search__input tribe-events-c-search__input--location"
        data-js="tribe-events-events-bar-input-control-input"
        id="tribe-bar-location"
        name="tribe-events-views[tribe-bar-location]"  
        aria-label="<?php esc_attr_e('Filter by Location', 'your-text-domain'); ?>"
    >
        <option value=""><?php esc_html_e('All Locations', 'your-text-domain'); ?></option>
        <?php foreach ($locations as $location) : ?>
            <option value="<?php echo esc_attr($location->slug); ?>" <?php selected($selected_location, $location->slug); ?>>
                <?php echo esc_html($location->name); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
