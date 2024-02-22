jQuery(document).ready(function($) {
    // Function to switch sections without reloading the page
    function switchSection(section, userId) {
        // Make AJAX request to fetch the section content
        $.ajax({
            url: extrachillQuote.ajaxurl, // Use localized ajaxurl
            type: 'POST',
            data: {
                action: 'load_social_section',
                section: section,
                user_id: userId
            },
            success: function(response) {
                // Assuming your container for displaying the list has a specific class
                $('.list-social-network-page').html(response);
            },
            error: function() {
                console.log('Error loading content');
            }
        });
    }

    // Listen for changes on the dropdown and trigger content switch
    $('#social-section-switch').change(function() {
        var selectedSection = $(this).val();
        var userId = $(this).data('user-id'); // Assuming you pass the user ID as a data attribute to your select element
        switchSection(selectedSection, userId);
    });
});

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




/*tooltips */

document.addEventListener('DOMContentLoaded', function() {
    let tooltip;

    function createTooltip() {
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            document.body.appendChild(tooltip);
        }
    }

    function showTooltip(element) {
        createTooltip();
        tooltip.innerText = element.getAttribute('data-title');
        const rect = element.getBoundingClientRect();
        tooltip.style.left = `${rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)}px`;
        const verticalOffset = 5;
        tooltip.style.top = `${rect.bottom + window.scrollY + verticalOffset}px`;
        tooltip.style.display = 'block';
    }

    function hideTooltip() {
        if (tooltip) {
            tooltip.style.display = 'none';
        }
    }

    document.body.addEventListener('click', function(e) {
        const target = e.target.closest('[data-title]');
        if (target) {
            showTooltip(target);
            e.stopPropagation(); // Prevent immediate hide
        } else {
            hideTooltip();
        }
    });

    // Adjust for touch devices
    document.body.addEventListener('touchstart', function(e) {
        const target = e.target.closest('[data-title]');
        if (target) {
            e.preventDefault(); // Prevent the browser's default touch action
            showTooltip(target);
            // Consider not hiding the tooltip immediately upon touch end to improve experience
        }
    }, {passive: false}); // Ensure we can call preventDefault

    document.querySelectorAll('[data-title]').forEach(element => {
        element.addEventListener('mouseenter', function() {
            showTooltip(this);
        });
        element.addEventListener('mouseleave', hideTooltip);
    });
});
