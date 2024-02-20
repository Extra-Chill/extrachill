jQuery(document).ready(function($) {
    // Bind event listener to the sorting form
    $('#sortingForm select').on('change', function(e) {
        e.preventDefault(); // Prevent the default form submission
        updateContent(); // Call the updateContent() function
    });

    function updateContent() {
        var sort = $('select[name="sort"]').val();
        var time_range = $('select[name="time_range"]').val();

        $.ajax({
            url: window.location.href, // Use the current page URL
            type: 'GET',
            data: {
                sort: sort,
                time_range: time_range,
            },
            success: function(response) {
                var updatedContent = $(response).find('.bbp-body').html();
                $('.bbp-body').html(updatedContent);

                // No need to rebind events if using event delegation as shown above
            },
            error: function() {
                alert('Failed to update content. Please try again.');
            }
        });
    }
});
