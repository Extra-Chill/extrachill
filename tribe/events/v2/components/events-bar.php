<?php
/**
 * View: Events Bar
 */

if ( empty( $display_events_bar ) ) {
    return;
}

$heading = $disable_event_search
    ? __( 'Views Navigation', 'the-events-calendar' )
    : sprintf( __( '%s Search and Views Navigation', 'the-events-calendar' ), tribe_get_event_label_plural() );

$classes = [ 'tribe-events-header__events-bar', 'tribe-events-c-events-bar' ];
if ( empty( $disable_event_search ) ) {
    $classes[] = 'tribe-events-c-events-bar--border';
}
?>
<div
    <?php tribe_classes( $classes ); ?>
    data-js="tribe-events-events-bar"
>
    <h2 class="tribe-common-a11y-visual-hide">
        <?php echo esc_html( $heading ); ?>
    </h2>
    <!-- Location Icon for Mobile -->
    <div class="tribe-events-c-events-bar__location-icon" onclick="toggleSearchMenu()">
        <?php $this->template( 'components/icons/location' ); ?>
    </div>


    <?php if ( empty( $disable_event_search ) ) : ?>
        <?php $this->template( 'components/events-bar/search-button' ); ?>
        <div
            class="tribe-events-c-events-bar__search-container"
            id="tribe-events-search-container"
            data-js="tribe-events-search-container"
        >
            <?php $this->template( 'components/events-bar/search' ); ?>
        </div>
    <?php endif; ?>

    <?php 
    
    /* $this->template( 'components/events-bar/views' ); */ 
    
    ?>


    <!-- Include Event Submission Modal -->
 <?php 

     $this->template( 'components/event-submission-modal' ); 
    
    ?> 
    
</div>

<script>
function toggleSearchMenu() {
    var searchContainer = document.getElementById('tribe-events-search-container');
    if (searchContainer) {
        // Check if the search menu is currently displayed
        if (searchContainer.style.display === 'block') {
            // Hide the menu
            searchContainer.style.display = 'none';
        } else {
            // Show the menu
            searchContainer.style.display = 'block';
        }
    }
}
</script>
