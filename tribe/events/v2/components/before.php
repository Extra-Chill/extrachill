<?php
/**
 * Component: Before Events
 */

do_action( 'extrachill_before_events' );

// Get the selected location slug from the query
$selected_location_slug = tribe_events_template_var(['bar', 'tribe-bar-location'], '');
$selected_location_name = '';

// If a location is selected, get the location name from the taxonomy term
if ( $selected_location_slug ) {
    $location_term = get_term_by( 'slug', $selected_location_slug, 'location' );
    if ( $location_term && ! is_wp_error( $location_term ) ) {
        $selected_location_name = $location_term->name;
    }
}

// H1 with default text, dynamically updated via JavaScript
?>
<h1 id="selected-location-title">
    <?php echo esc_html( $selected_location_name ? "$selected_location_name Live Music Calendar" : "Live Music Calendar" ); ?>
</h1>

<?php if ( ! empty( $before_events ) ) : ?>
    <div class="tribe-events-before-html">
        <?php echo $before_events; ?>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var locationSelect = document.getElementById('tribe-bar-location');
    var titleElement = document.getElementById('selected-location-title');
    var findEventsButton = document.querySelector('button[name="submit-bar"]');

    // Listen for click on the "Find Events" button
    findEventsButton.addEventListener('click', function(event) {
        var selectedLocation = locationSelect.options[locationSelect.selectedIndex].text;
        
        // Update the title based on selected location
        if (selectedLocation === 'All Locations' || !selectedLocation) {
            titleElement.textContent = 'Live Music Calendar';
        } else {
            titleElement.textContent = selectedLocation + ' Live Music Calendar';
        }
    });
});
</script>
