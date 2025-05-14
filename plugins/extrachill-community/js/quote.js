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

        // Extract the 'bbp_reply_to' parameter from the href
        var replyToIdMatch = href.match(/bbp_reply_to=(\d+)/);
        var replyToId = replyToIdMatch ? replyToIdMatch[1] : null;

        if (!replyToId) {
            console.error('Failed to extract replyToId.');
            return false; // Stop further execution.
        }

        // Find the reply content div using data-reply-id
        var replyElement = $('.bbp-reply-content[data-reply-id="' + replyToId + '"]');
        if (replyElement.length === 0) {
            console.error('Reply element not found for replyToId:', replyToId);
            return false;
        }

        // Since bbp-reply-author and bbp-reply-content are siblings, find the bbp-reply-author sibling
        var authorDiv = replyElement.siblings('.bbp-reply-author');
        if (authorDiv.length === 0) {
            console.error('Author div not found among siblings of replyElement.');
            return false;
        }

        // Find the author link within the authorDiv
        var authorLink = authorDiv.find('.bbp-author-link');
        if (authorLink.length === 0) {
            console.error('Author link not found in author div.');
            return false;
        }

        // Get the href attribute of the author link
        var authorHref = authorLink.attr('href');

        // Extract the username slug from the href
        var slugMatch = authorHref.match(/\/u\/([^\/]+)/);
        if (slugMatch && slugMatch[1]) {
            var replyAuthor = slugMatch[1];
        } else {
            console.error('Failed to extract author slug from URL.');
            return false;
        }

        // Insert the mention into the reply content editor
        var replyContent = $('#bbp_reply_content');
        if (replyContent.length) {
            // add a non-breaking space
            var mentionHtml = '@' + replyAuthor + '&nbsp;';

            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('bbp_reply_content') && !tinyMCE.get('bbp_reply_content').isHidden()) {
                var editor = tinyMCE.get('bbp_reply_content');
                editor.focus();

                // Use insertContent to add the mention directly and position the cursor after it
                editor.execCommand('mceInsertContent', false, mentionHtml);

                // Move cursor to the end after the mention
                editor.selection.collapse(editor.getBody(), editor.getBody().childNodes.length);
            } else {
                replyContent.focus();
                // Insert the mention at the end of the textarea
                replyContent.val(replyContent.val() + '@' + replyAuthor + ' ');
            }
        }

        return false;  // Ensure that the link does not trigger navigation.
    });
});











