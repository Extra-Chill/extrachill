(function($) {
    $(document).ready(function() {
        // Ensure TEC's scripts are loaded
        if (typeof tribe === 'undefined' || !tribe.events || !tribe.events.views || !tribe.events.views.manager) {
            console.error('TEC scripts not loaded.');
            return;
        }

        // Bind change event to the location select using event delegation
        $(document).on('change', '#tribe-bar-location', function() {
            var $input = $(this);
            var $container = $input.closest('[data-js="tribe-events-view"]');

            // Build the view data
            var viewData = {};

            // Get all filter inputs within the form
            var $form = $input.closest('form');
            var formDataArray = $form.serializeArray();

            // Convert form data into an object
            $.each(formDataArray, function(index, item) {
                // Only process inputs that are part of tribe-events-views
                if (item.name.startsWith('tribe-events-views[')) {
                    // Extract the parameter name
                    var key = item.name.match(/tribe-events-views\[(.*)\]/)[1];
                    viewData[key] = item.value;
                }
            });

            // Prepare the data object for the request
            var data = {
                view_data: viewData,
            };

            // Perform the AJAX request using the manager's request method
            tribe.events.views.manager.request(data, $container);

            // Update the URL to include 'tribe-bar-location'
            var newUrl = new URL(window.location.href);
            newUrl.searchParams.set('tribe-bar-location', $input.val());
            history.replaceState({}, '', newUrl.toString());
        });
    });
})(jQuery);
