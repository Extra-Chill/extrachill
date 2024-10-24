<?php
// Get the available locations from the 'location' taxonomy
$locations = get_terms( array(
    'taxonomy'   => 'location',
    'hide_empty' => false,
) );

// If no locations are available, exit early
if ( empty( $locations ) || is_wp_error( $locations ) ) {
    return;
}

// Use tribe_context() to get the selected location from the current context
$context = tribe_context();
$selected_location = $context->get( 'tribe-bar-location', '' );

?>
<div class="tribe-events-c-search__input-control tribe-events-c-search__input-control--select" data-js="tribe-events-events-bar-input-control">
    <label class="tribe-events-c-search__label tribe-common-form-control-text__label" for="tribe-bar-location">
        <?php esc_html_e( 'Filter by Location', 'your-text-domain' ); ?>
    </label>
    <select
        class="tribe-common-form-control-text__input tribe-events-c-search__input tribe-events-c-search__input--location"
        data-js="tribe-events-events-bar-input-control-input"
        id="tribe-bar-location"
        name="tribe-events-views[tribe-bar-location]"
        aria-label="<?php esc_attr_e( 'Filter by Location', 'your-text-domain' ); ?>"
    >
        <option value=""><?php esc_html_e( 'All Locations', 'your-text-domain' ); ?></option>
        <?php foreach ( $locations as $location ) : ?>
            <option value="<?php echo esc_attr( $location->slug ); ?>" <?php selected( $selected_location, $location->slug ); ?>>
                <?php echo esc_html( $location->name ); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
