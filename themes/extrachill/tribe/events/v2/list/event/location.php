<?php
// Get the location terms for the event
$locations = get_the_terms( $event->ID, 'location' );

if ( ! empty( $locations ) && ! is_wp_error( $locations ) ) : ?>
    <div class="taxonomy-badges">
        <?php foreach ( $locations as $location ) : 
			$location_slug_class = 'location-' . sanitize_html_class( $location->slug );
			// Build the correct filter URL based on the current URL
			$location_filter_url = add_query_arg( 'tribe-bar-location', $location->slug ); // Adds/updates the query arg to the current URL
		?>
            <a href="<?php echo esc_url( $location_filter_url ); ?>" class="taxonomy-badge location-badge <?php echo $location_slug_class; ?> location-link"><?php echo esc_html( $location->name ); ?></a>
        <?php endforeach; ?>
    </div>
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

