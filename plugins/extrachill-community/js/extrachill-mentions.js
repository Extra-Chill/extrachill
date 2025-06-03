/**
 * File to handle user mention autocomplete in TinyMCE editor
 * and inserting mentions via reply link clicks.
 * Adding top-level log for execution check.
 */

// Ensure this script runs only once
if (typeof window.extrachillMentionsPluginLoaded === 'undefined') {
    window.extrachillMentionsPluginLoaded = true;

    // TinyMCE Plugin for Autocomplete (existing code)
    (function() {
        // Check if TinyMCE is available
        if (typeof tinymce === 'undefined') {
            return;
        }

        // Function to set up the plugin logic
        function setupMentionsPlugin(editor) {
            // Minimal keyup listener for testing
            editor.on('keyup', function(event) {
                if (event.key === '@') {
                }
                // Basic check for mention pattern start
                const node = editor.selection.getNode();
                const range = editor.selection.getRng();
                if (range && node && range.startContainer.nodeType === Node.TEXT_NODE) {
                    const textBeforeCursor = range.startContainer.textContent.substring(0, range.startOffset);
                    if (textBeforeCursor.includes('@')) {
                    }
                }
            });

             // Minimal blur listener for testing
            editor.on('blur', function() {
            });
        }

        // Add the plugin using PluginManager
        try {
            tinymce.PluginManager.add('extrachillmentions', function(editor) {
                // Setup the listeners once the editor is initialized
                editor.on('init', function() {
                     setupMentionsPlugin(editor);
                });
            });
        } catch (e) {
        }

    })(); // Immediately Invoked Function Expression (IIFE)

// Handle clicks on the bbPress Reply link (code moved from quote.js)
jQuery(document).ready(function($) {
    $(document).on('click', '.bbp-reply-to-link', function(e) {
        e.preventDefault();  // Prevent the default behavior of the link.

        var href = $(this).attr('href');

        // Extract the 'bbp_reply_to' parameter from the href
        var replyToIdMatch = href.match(/bbp_reply_to=(\d+)/);
        var replyToId = replyToIdMatch ? replyToIdMatch[1] : null;

        if (!replyToId) {
            return false; // Stop further execution.
        }

        // Find the reply content div using data-reply-id
        var replyElement = $('.bbp-reply-content[data-reply-id="' + replyToId + '"]');
        if (replyElement.length === 0) {
            return false;
        }

        // Traverse up to the main reply card container based on the custom template structure
        var replyCard = replyElement.closest('.bbp-reply-card');
        if (replyCard.length === 0) {
            return false;
        }

        // Find the author link (.bbp-author-name) within that card's header
        var authorLink = replyCard.find('.bbp-reply-header .bbp-author-name');
        if (authorLink.length === 0) {
            return false;
        }

        // Instead of using the text content (which may include unwanted spaces),
        // extract the slug from the author's profile URL.
        var authorUrl = authorLink.attr('href');
        var replySlug = null;
        if (authorUrl) {
            // Remove any trailing slash, then split the URL and take the last segment as the slug.
            var parts = authorUrl.replace(/\/+$/, '').split('/');
            replySlug = parts.pop();
        }
        if (!replySlug) {
            return false;
        }

        // Create the mention HTML using the slug, ensuring no extra space is added.
        var mentionHtml = '@' + replySlug;

        // Insert the mention into the reply content editor
        var replyContent = $('#bbp_reply_content');
        if (replyContent.length) {
            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('bbp_reply_content') && !tinyMCE.get('bbp_reply_content').isHidden()) {
                var editor = tinyMCE.get('bbp_reply_content');
                editor.focus();

                // Use insertContent to add the mention directly and position the cursor after it
                editor.execCommand('mceInsertContent', false, mentionHtml);

                // Move cursor to the end after the mention
                editor.selection.select(editor.getBody(), true); // Select the entire body
                editor.selection.collapse(false); // Collapse selection to the end
            } else {
                // Fallback for plain textarea
                replyContent.focus();
                var currentVal = replyContent.val();
                var cursorPos = replyContent.prop('selectionStart');
                var textBefore = currentVal.substring(0, cursorPos);
                var textAfter  = currentVal.substring(cursorPos);
                replyContent.val(textBefore + mentionHtml + textAfter);
                // Move cursor after the inserted text
                var newCursorPos = cursorPos + mentionHtml.length;
                replyContent.prop('selectionStart', newCursorPos);
                replyContent.prop('selectionEnd', newCursorPos);
            }
        }

        return false;  // Ensure that the link does not trigger navigation.
    });
});

} else {
}