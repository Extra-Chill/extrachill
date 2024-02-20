/*tabs */
jQuery(document).ready(function($) {
    // Function to show the tab content
    function showTabContent(hash) {
        $('.tabs li').removeClass('active'); // Remove active class from all tabs
        $('.tabs a[href="' + hash + '"]').parent('li').addClass('active'); // Add active class to the selected tab
        
        $('.tab-content').hide(); // Hide all tab contents
        $(hash).show(); // Show the selected tab content
    }

    // Add click event to tabs
    $('.tabs a').on('click', function(e) {
        e.preventDefault();
        var hash = $(this).attr('href');
        showTabContent(hash);
        window.location.hash = hash; // Optional: Update the URL hash
    });

    // Handle initial tab or hash change
    if (window.location.hash) {
        showTabContent(window.location.hash);
    } else {
        var firstTabHash = $('.tabs li:first-child a').attr('href');
        showTabContent(firstTabHash);
    }

    // Optional: Handle hash change in URL
    $(window).on('hashchange', function() {
        var hash = window.location.hash;
        showTabContent(hash);
    });
});

/* quotes */

jQuery(document).ready(function($) {
    // Listen for clicks on "Quote" buttons
    $('.bbp-reply-quote-link').click(function(e) {
        e.preventDefault(); // Prevent the default link behavior

        // Retrieve the reply ID from the button's data attribute
        var replyId = $(this).data('reply-id');

        // Use the reply ID to find the corresponding reply content by matching data-reply-id attribute
        var replyContent = $('.bbp-reply-content[data-reply-id="' + replyId + '"]').html();

        // Check if the reply content was successfully retrieved
        if (typeof replyContent === 'undefined') {
            console.error('Reply content not found for ID:', replyId);
            return; // Exit if no content found
        }

        // Clean the content (trim whitespace)
        var cleanContent = replyContent.trim();

        // Format the content as a blockquote for quoting
        var formattedQuote = '<blockquote>' + cleanContent + '</blockquote><br>';

        // Function call to insert the formatted quote into the editor
        insertFormattedQuoteIntoEditor(formattedQuote);
    });

    // Function to insert formatted quote into the TinyMCE editor or a textarea
    function insertFormattedQuoteIntoEditor(formattedQuote) {
        // Check if TinyMCE is available and the specific editor is active
        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('bbp_reply_content') && !tinyMCE.get('bbp_reply_content').hidden) {
            // TinyMCE editor is active, insert the formatted quote
            tinyMCE.get('bbp_reply_content').execCommand('mceInsertContent', false, formattedQuote);
        } else {
            // Fallback to inserting directly into a textarea if TinyMCE is not active
            $('#bbp_reply_content').val(function(index, value) {
                return value + formattedQuote; // Append the formatted quote
            });
        }
    }
});
