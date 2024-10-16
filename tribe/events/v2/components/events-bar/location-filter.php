<?php
/**
 * View: Events Bar - Location Filter
 */

$locations = get_terms( array(
    'taxonomy'   => 'location',
    'hide_empty' => false,
) );

if ( empty( $locations ) || is_wp_error( $locations ) ) {
    return;
}

// Retrieve the selected location from the request
$selected_location = isset( $_REQUEST['tribe-bar-location'] ) ? sanitize_text_field( $_REQUEST['tribe-bar-location'] ) : '';
?>
<div class="tribe-common-form-control-text__input tribe-events-c-search__input tribe-events-c-search__input--location">
    <label class="tribe-common-form-control-text__label" for="tribe-bar-location">
        <?php esc_html_e( 'Filter by Location', 'your-text-domain' ); ?>
    </label>
    <select
        id="tribe-bar-location"
        name="tribe-bar-location"
        class="tribe-common-form-control-text__input--select tribe-common-form-control-select"
        onchange="document.getElementById('tribe-bar-form').submit();"
    >
        <option value=""><?php esc_html_e( 'All Locations', 'your-text-domain' ); ?></option>
        <?php foreach ( $locations as $location ) : ?>
            <option value="<?php echo esc_attr( $location->slug ); ?>" <?php selected( $selected_location, $location->slug ); ?>>
                <?php echo esc_html( $location->name ); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
