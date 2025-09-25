/**
 * Quote functionality for bbPress replies
 */

jQuery(document).ready(function($) {
    var isQuoteInserted = false;
    var nonce, postId, authorName, postPermalink, quotedUserId;
    var currentUserId = extrachill_ajax.current_user_id;

    $('.bbp-quote-link').click(function(e) {
        e.preventDefault();

        function insertFormattedQuoteIntoEditor(formattedQuote) {
            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('bbp_reply_content') && !tinyMCE.get('bbp_reply_content').hidden) {
                tinyMCE.get('bbp_reply_content').execCommand('mceInsertContent', false, formattedQuote);
            } else {
                $('#bbp_reply_content').val(function(index, value) {
                    return value + '\n' + formattedQuote;
                });
            }
            isQuoteInserted = true;
        }

        postId = $(this).data('post-id');
        authorName = $(this).data('author-name');
        postPermalink = $(this).data('post-permalink');
        nonce = $(this).data('nonce');
        quotedUserId = $(this).data('quoted-user-id');

        var $postContent = $('.bbp-reply-content[data-reply-id="' + postId + '"]').clone();
        $postContent.find('.bbp-reply-ip, .bbp-topic-revision-log, .bbp-reply-revision-log').remove();

        var postContentHtml = $postContent.html();

        if ($postContent.length === 0 || typeof postContentHtml === 'undefined') {
            console.error('Post content not found for ID:', postId);
            return;
        }

        var cleanContent = postContentHtml.trim();

        var formattedQuote = '<blockquote>' +
            '<p><strong>' + authorName + ' <a href="' + postPermalink + '">said:</a></strong></p>' +
            '<p>' + cleanContent + '</p>' +
            '</blockquote><br>';

        insertFormattedQuoteIntoEditor(formattedQuote);
        isQuoteInserted = true;
    });

    $('form#new-post').submit(function(event) {
        if (isQuoteInserted) {
            event.preventDefault();
            $.ajax({
                url: extrachill_ajax.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'notify_quoted_user',
                    nonce: nonce,
                    quoting_user_id: currentUserId,
                    quoted_user_id: quotedUserId,
                    post_id: postId,
                    author_name: authorName,
                    post_permalink: postPermalink,
                },
                success: function(response) {
                    if (response.success) {
                        console.log('Quote notification sent successfully.');
                        isQuoteInserted = false;
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
    var maxLength = 350;

    function truncateBlockquotes() {
        $('blockquote').each(function() {
            var $blockquote = $(this);
            var fullHtml = $blockquote.html();
            var text = $blockquote.text();

            if (text.length > maxLength) {
                var $tempDiv = $('<div></div>').html(fullHtml);
                var truncatedHtml = $tempDiv.text().substring(0, maxLength) + '...';
                $tempDiv.html(truncatedHtml);
                
                var $fullContent = $('<div style="display: none;" class="full-quote-content"></div>').html(fullHtml);
                var $truncatedContent = $('<div class="truncated-quote-content"></div>').html($tempDiv.html());

                $blockquote.empty().append($truncatedContent, $fullContent);

                var $toggleLink = $('<a href="javascript:void(0);" class="expand-quote">Click to expand</a>');
                $blockquote.append($toggleLink);
                $toggleLink.click(function() {
                    var isExpanded = $fullContent.is(':visible');
                    if (isExpanded) {
                        $fullContent.hide();
                        $truncatedContent.show();
                        $(this).text('Click to expand');
                    } else {
                        $fullContent.show();
                        $truncatedContent.hide();
                        $(this).text('Click to collapse');
                    }
                });
            }
        });
    }

    truncateBlockquotes();

});

jQuery(document).ready(function($) {
    $(document).on('click', '.bbp-reply-to-link', function(e) {
        e.preventDefault();

        var href = $(this).attr('href');

        var replyToIdMatch = href.match(/bbp_reply_to=(\d+)/);
        var replyToId = replyToIdMatch ? replyToIdMatch[1] : null;

        if (!replyToId) {
            console.error('Failed to extract replyToId.');
            return false;
        }

        var replyElement = $('.bbp-reply-content[data-reply-id="' + replyToId + '"]');
        if (replyElement.length === 0) {
            console.error('Reply element not found for replyToId:', replyToId);
            return false;
        }

        var authorDiv = replyElement.siblings('.bbp-reply-author');
        if (authorDiv.length === 0) {
            console.error('Author div not found among siblings of replyElement.');
            return false;
        }

        var authorLink = authorDiv.find('.bbp-author-link');
        if (authorLink.length === 0) {
            console.error('Author link not found in author div.');
            return false;
        }

        var authorHref = authorLink.attr('href');

        var slugMatch = authorHref.match(/\/u\/([^\/]+)/);
        if (slugMatch && slugMatch[1]) {
            var replyAuthor = slugMatch[1];
        } else {
            console.error('Failed to extract author slug from URL.');
            return false;
        }

        var replyContent = $('#bbp_reply_content');
        if (replyContent.length) {
            var mentionHtml = '@' + replyAuthor + '&nbsp;';

            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('bbp_reply_content') && !tinyMCE.get('bbp_reply_content').isHidden()) {
                var editor = tinyMCE.get('bbp_reply_content');
                editor.focus();

                editor.execCommand('mceInsertContent', false, mentionHtml);

                editor.selection.collapse(editor.getBody(), editor.getBody().childNodes.length);
            } else {
                replyContent.focus();
                replyContent.val(replyContent.val() + '@' + replyAuthor + ' ');
            }
        }

        return false;
    });
});











