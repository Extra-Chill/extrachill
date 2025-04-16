<?php
// Get the location terms for the event
$locations = get_the_terms( $event->ID, 'location' );

if ( ! empty( $locations ) && ! is_wp_error( $locations ) ) :
    foreach ( $locations as $location ) :
        // Define the base URL for the events list view
        $base_url = home_url( '/calendar/list/' );

        // Add 'tribe-bar-location' parameter to the URL while preserving existing parameters
        $location_url = add_query_arg( array_merge( $_GET, [ 'tribe-bar-location' => $location->slug ] ), $base_url );

        // Create a unique CSS class based on location slug
        $location_class = 'location-' . sanitize_html_class( $location->slug );
        ?>
        <div class="tribe-events-calendar-list__event-location <?php echo esc_attr( $location_class ); ?>">
            <a class="location-link" href="<?php echo esc_url( $location_url ); ?>">
                <?php echo esc_html( $location->name ); ?>
            </a>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select all location links
    const locationLinks = document.querySelectorAll('.location-link');
    const locationSelect = document.getElementById('tribe-bar-location');
    const searchButton = document.querySelector('.tribe-events-c-search__button'); // Adjust if needed

    locationLinks.forEach(link => {
        link.addEventListener('click', function(event) {
            event.preventDefault(); // Prevent the default link navigation

            // Get the selected location slug from the link's href
            const locationUrl = new URL(link.href);
            const selectedLocation = locationUrl.searchParams.get('tribe-bar-location');

            // Set the location filter value
            if (locationSelect && searchButton) {
                locationSelect.value = selectedLocation; // Set the dropdown to the selected location

                // Programmatically trigger the search button click
                searchButton.click();
            }
        });
    });
});
</script>

