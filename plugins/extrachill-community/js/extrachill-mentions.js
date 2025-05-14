/**
 * File to handle user mention autocomplete in TinyMCE editor
 * and inserting mentions via reply link clicks.
 * Adding top-level log for execution check.
 */
console.log("Mentions JS: File Execution Start."); // DEBUG TOP LEVEL

// Ensure this script runs only once
if (typeof window.extrachillMentionsPluginLoaded === 'undefined') {
    window.extrachillMentionsPluginLoaded = true;
    console.log("Mentions JS: Script loading for the first time."); // DEBUG 0.1

    // TinyMCE Plugin for Autocomplete (existing code)
    (function() {
        // Check if TinyMCE is available
        if (typeof tinymce === 'undefined') {
            console.log("Mentions JS: TinyMCE not found initially."); // DEBUG 0
            return;
        }

        // Function to set up the plugin logic
        function setupMentionsPlugin(editor) {
            console.log("Mentions JS: setupMentionsPlugin called for editor:", editor.id); // DEBUG 3

            // Minimal keyup listener for testing
            editor.on('keyup', function(event) {
                if (event.key === '@') {
                    console.log("Mentions JS: @ key pressed in editor:", editor.id); // DEBUG 4
                }
                // Basic check for mention pattern start
                const node = editor.selection.getNode();
                const range = editor.selection.getRng();
                if (range && node && range.startContainer.nodeType === Node.TEXT_NODE) {
                    const textBeforeCursor = range.startContainer.textContent.substring(0, range.startOffset);
                    if (textBeforeCursor.includes('@')) {
                         console.log("Mentions JS: Text includes @"); // DEBUG 5
                    }
                }
            });

             // Minimal blur listener for testing
            editor.on('blur', function() {
                console.log("Mentions JS: Editor blurred:", editor.id); // DEBUG 6
            });
        }

        // Add the plugin using PluginManager
        try {
            tinymce.PluginManager.add('extrachillmentions', function(editor) {
                console.log("Mentions JS: Plugin 'extrachillmentions' added via PluginManager for editor:", editor.id); // DEBUG 7
                // Setup the listeners once the editor is initialized
                editor.on('init', function() {
                     console.log("Mentions JS: Editor init event fired for:", editor.id); // DEBUG 7.1
                     setupMentionsPlugin(editor);
                });
            });
            console.log("Mentions JS: Plugin 'extrachillmentions' registered with PluginManager."); // DEBUG 8
        } catch (e) {
            console.error("Mentions JS: Error adding plugin:", e); // DEBUG 9
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
            console.error('Failed to extract replyToId.');
            return false; // Stop further execution.
        }

        // Find the reply content div using data-reply-id
        var replyElement = $('.bbp-reply-content[data-reply-id="' + replyToId + '"]');
        if (replyElement.length === 0) {
            console.error('Reply element not found for replyToId:', replyToId);
            return false;
        }

        // Traverse up to the main reply card container based on the custom template structure
        var replyCard = replyElement.closest('.bbp-reply-card');
        if (replyCard.length === 0) {
            console.error('Could not find parent reply card (.bbp-reply-card) for replyToId:', replyToId);
            return false;
        }

        // Find the author link (.bbp-author-name) within that card's header
        var authorLink = replyCard.find('.bbp-reply-header .bbp-author-name');
        if (authorLink.length === 0) {
            console.error('Author link (.bbp-author-name) not found within the reply card header.');
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
            console.error('Failed to extract author slug from URL.');
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
    console.log("Mentions JS: Script already loaded, skipping execution."); // DEBUG 0.2
}