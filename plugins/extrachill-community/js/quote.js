/* quotes */

jQuery(document).ready(function($) {
    var isQuoteInserted = false;
    // Define variables at a higher scope
    var nonce, postId, authorName, postPermalink, quotedUserId;
    var currentUserId = extrachill_ajax.current_user_id; // Assuming 'current_user_id' is defined server-side

    $('.bbp-quote-link').click(function(e) {
        e.preventDefault();

        function insertFormattedQuoteIntoEditor(formattedQuote) {
            // Assuming tinyMCE is your editor for bbPress replies
            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('bbp_reply_content') && !tinyMCE.get('bbp_reply_content').hidden) {
                tinyMCE.get('bbp_reply_content').execCommand('mceInsertContent', false, formattedQuote);
            } else {
                $('#bbp_reply_content').val(function(index, value) {
                    return value + '\n' + formattedQuote;
                });
            }
            isQuoteInserted = true; // Set the flag here after the quote has been successfully inserted
        }

        // Update variables with the current quote's data
        postId = $(this).data('post-id');
        authorName = $(this).data('author-name');
        postPermalink = $(this).data('post-permalink');
        nonce = $(this).data('nonce'); // Ensure nonce is passed correctly
        quotedUserId = $(this).data('quoted-user-id'); // Extract the quoted user ID from the clicked link

        // Target both replies and topics
        var $postContent = $('.bbp-reply-content[data-reply-id="' + postId + '"]').clone(); // Targeting replies

        // Remove elements that should not be cloned into the quote
        $postContent.find('.bbp-reply-ip, .bbp-topic-revision-log, .bbp-reply-revision-log').remove(); // Added .bbp-topic-revision-log to the selector

        var postContentHtml = $postContent.html();

        if ($postContent.length === 0 || typeof postContentHtml === 'undefined') {
            console.error('Post content not found for ID:', postId);
            return; // Exit if no content found
        }

        var cleanContent = postContentHtml.trim();

        var formattedQuote = '<blockquote>' +
            '<p><strong>' + authorName + ' <a href="' + postPermalink + '">said:</a></strong></p>' +
            '<p>' + cleanContent + '</p>' +
            '</blockquote><br>';

        // Insert the formatted quote into the editor
        insertFormattedQuoteIntoEditor(formattedQuote);
        // AJAX call to send the quote notification
        // Your existing code to handle the quote insertion...
        isQuoteInserted = true;
    });

    // Move the form submit handler outside the click event
    $('form#new-post').submit(function(event) {
        // Check if a quote was inserted
        if (isQuoteInserted) {
            event.preventDefault(); // Prevent form from submitting immediately

            // AJAX call to send the quote notification
            $.ajax({
                url: extrachill_ajax.ajaxurl, // Make sure this variable is correctly defined
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'notify_quoted_user',
                    nonce: nonce, // Use the nonce from the higher scope
                    quoting_user_id: currentUserId, // Use the ID of the quoting user
                    quoted_user_id: quotedUserId, // Pass the quoted user ID
                    post_id: postId,
                    author_name: authorName,
                    post_permalink: postPermalink,
                },
                success: function(response) {
                    if (response.success) {
                        console.log('Quote notification sent successfully.');
                        isQuoteInserted = false; // Reset the flag
                        // Submit the form programmatically
                        $('form#new-post').off('submit').submit();
                    } else {
                        console.error('Failed to send quote notification.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                }
            });
        }
    });

});

jQuery(document).ready(function($) {
    var maxLength = 350; // Max length for text inside the blockquote before truncation

    function truncateBlockquotes() {
        $('blockquote').each(function() {
            var $blockquote = $(this);
            var fullHtml = $blockquote.html(); // Get HTML content
            var text = $blockquote.text();

            if (text.length > maxLength) {
                var $tempDiv = $('<div></div>').html(fullHtml);
                var truncatedHtml = $tempDiv.text().substring(0, maxLength) + '...';
                $tempDiv.html(truncatedHtml);
                
                var $fullContent = $('<div style="display: none;" class="full-quote-content"></div>').html(fullHtml);
                var $truncatedContent = $('<div class="truncated-quote-content"></div>').html($tempDiv.html());

                $blockquote.empty().append($truncatedContent, $fullContent);

                // Adjusted to toggle between expand and collapse
                var $toggleLink = $('<a href="javascript:void(0);" class="expand-quote">Click to expand</a>');
                $blockquote.append($toggleLink);
                $toggleLink.click(function() {
                    var isExpanded = $fullContent.is(':visible');
                    if (isExpanded) {
                        $fullContent.hide();
                        $truncatedContent.show();
                        $(this).text('Click to expand'); // Change the link text to "expand"
                    } else {
                        $fullContent.show();
                        $truncatedContent.hide();
                        $(this).text('Click to collapse'); // Change the link text to "collapse"
                    }
                });
            }
        });
    }

    truncateBlockquotes();

});

jQuery(document).ready(function($) {
    $(document).on('click', '.bbp-reply-to-link', function(e) {
        e.preventDefault();  // Prevent the default behavior of the link.

        var href = $(this).attr('href');
        var replyToId = new URL(href, window.location.origin).searchParams.get('bbp_reply_to');

        if (!replyToId) {
            console.error('Failed to extract replyToId.');
            return false; // Stop further execution.
        }

        // Using a slight delay to allow other handlers (if any) to process.
        setTimeout(() => {
            // Retrieve the author name using the data-author-name attribute from the quote link associated with the reply
            var replyAuthor = $('[data-post-id="' + replyToId + '"][data-author-name]').data('author-name');

            if (!replyAuthor) {
                console.error('Failed to retrieve author name.');
                return false;
            }

            var replyContent = $('#bbp_reply_content');
            if (replyContent.length) {
                var mentionText = '@' + replyAuthor + ' ';
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('bbp_reply_content') && !tinyMCE.get('bbp_reply_content').isHidden()) {
                    tinyMCE.get('bbp_reply_content').execCommand('mceInsertContent', false, mentionText);
                } else {
                    replyContent.val(mentionText + replyContent.val());
                }
                replyContent.focus();
            }
        }, 100);  // Adjust the delay as needed based on your setup.

        return false;  // Ensure that the link does not trigger navigation.
    });
});



