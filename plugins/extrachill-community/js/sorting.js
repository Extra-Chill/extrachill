jQuery(document).ready(function($) {
    // Bind event listener to the sorting form and search form
    $('#sortingForm select, #bbp-ajax-search-form').on('change submit', function(e) {
        e.preventDefault(); // Prevent the default form submission
        updateContent(); // Call the updateContent() function
    });

    function updateContent() {
        var sort = $('select[name="sort"]').val();
        var time_range = $('select[name="time_range"]').val();
        var search = $('input[name="bbp_search"]').val();

        $.ajax({
            url: window.location.href, // Use the current page URL
            type: 'GET',
            data: {
                sort: sort,
                time_range: time_range,
                bbp_search: search
            },
            success: function(response) {
                if ($(response).find('.bbp-body').children().length > 0) {
                    var updatedContent = $(response).find('.bbp-body').html();
                    $('.bbp-body').html(updatedContent);
                } else {
                    $('.bbp-body').html('<p>No posts found, try some different search terms.</p>');
                }
            },
            error: function() {
                alert('Failed to update content. Please try again.');
            }
        });
    }
});
