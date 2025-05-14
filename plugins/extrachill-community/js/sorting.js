jQuery(document).ready(function($) {
    $('#sortingForm select, #bbp-ajax-search-form').on('change submit', function(e) {
        e.preventDefault();
        updateContent();
    });

    function updateContent() {
        var sort = $('select[name="sort"]').val();
        var search = $('input[name="bbp_search"]').val();
        var forum_id = $('.bbp-sorting-form').data('forum-id');

        $.ajax({
            url: wpSurgeonAjax.ajax_url,
            type: 'GET',
            data: {
                action: 'wp_surgeon_ajax_search',
                sort: sort,
                bbp_search: search,
                forum_id: forum_id,
                nonce: wpSurgeonAjax.nonce
            },
            success: function(response) {
                var $responseHtml = $(response);
                var $newBody = $responseHtml.find('.bbp-body');

                if ($newBody.length > 0 && $newBody.html().trim().length > 0) {
                    $('.bbp-body').html($newBody.html());
                } else {
                    $('.bbp-body').html('<p>No posts found, try some different search terms.</p>');
                }

                // Update pagination if present
                var $newPagination = $responseHtml.find('.bbp-pagination');
                if ($newPagination.length > 0) {
                    $('.bbp-pagination').html($newPagination.html());
                } else {
                    $('.bbp-pagination').empty();
                }
            },
            error: function() {
                alert('Failed to update content. Please try again.');
            }
        });
    }
});
