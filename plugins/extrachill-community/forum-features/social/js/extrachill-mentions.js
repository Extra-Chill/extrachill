/**
 * Enhanced user mention autocomplete for TinyMCE editor
 * Provides real-time search and dropdown selection for @mentions
 */

// Ensure this script runs only once
if (typeof window.extrachillMentionsPluginLoaded === 'undefined') {
    window.extrachillMentionsPluginLoaded = true;

    // TinyMCE Plugin for Autocomplete
    (function() {
        // Check if TinyMCE is available
        if (typeof tinymce === 'undefined') {
            return;
        }

        // Autocomplete state management
        let mentionsState = {
            dropdown: null,
            isActive: false,
            currentSearch: '',
            selectedIndex: -1,
            searchResults: [],
            debounceTimeout: null,
            currentRange: null,
            mentionStart: 0,
            editor: null
        };

        // Create and position dropdown element
        function createDropdown(editor) {
            if (mentionsState.dropdown) {
                return mentionsState.dropdown;
            }

            const dropdown = document.createElement('div');
            dropdown.className = 'mentions-dropdown';
            dropdown.style.cssText = `
                position: absolute;
                z-index: 10000;
                max-height: 200px;
                overflow-y: auto;
                display: none;
                background: var(--card-background);
                border: 1px solid var(--border-color);
                border-radius: 6px;
                box-shadow: var(--card-shadow);
                min-width: 200px;
            `;

            document.body.appendChild(dropdown);
            mentionsState.dropdown = dropdown;
            return dropdown;
        }

        // Position dropdown relative to cursor using improved positioning logic
        function positionDropdown(editor) {
            if (!mentionsState.dropdown || !mentionsState.currentRange) return;

            try {
                // Use TinyMCE's native selection methods for better positioning
                const selection = editor.selection;
                const range = selection.getRng();

                // Get the bounding rect of the current selection
                const rect = range.getBoundingClientRect();
                const editorRect = editor.getContainer().getBoundingClientRect();

                // Position dropdown below the cursor
                mentionsState.dropdown.style.left = rect.left + 'px';
                mentionsState.dropdown.style.top = (rect.bottom + 5) + 'px';

            } catch (e) {
                // Fallback positioning relative to editor
                const editorRect = editor.getContainer().getBoundingClientRect();
                mentionsState.dropdown.style.left = (editorRect.left + 20) + 'px';
                mentionsState.dropdown.style.top = (editorRect.top + 60) + 'px';
            }
        }

        // Render search results in dropdown
        function renderResults(results) {
            if (!mentionsState.dropdown) return;

            mentionsState.searchResults = results;
            mentionsState.selectedIndex = -1;

            if (results.length === 0) {
                mentionsState.dropdown.innerHTML = `
                    <div class="mentions-item mentions-no-results">
                        No users found
                    </div>
                `;
                return;
            }

            const html = results.map((user, index) => `
                <div class="mentions-item" data-index="${index}" data-username="${user.slug}">
                    <div class="mentions-avatar">
                        <div class="avatar-placeholder"></div>
                    </div>
                    <div class="mentions-user-info">
                        <div class="mentions-username">@${user.slug}</div>
                        <div class="mentions-login">${user.username}</div>
                    </div>
                </div>
            `).join('');

            mentionsState.dropdown.innerHTML = html;

            // Add click handlers
            mentionsState.dropdown.querySelectorAll('.mentions-item').forEach(item => {
                item.addEventListener('click', (e) => {
                    const index = parseInt(e.currentTarget.dataset.index);
                    if (!isNaN(index)) {
                        selectMention(index);
                    }
                });
            });
        }

        // Update visual selection in dropdown
        function updateSelection() {
            if (!mentionsState.dropdown) return;

            const items = mentionsState.dropdown.querySelectorAll('.mentions-item:not(.mentions-no-results)');
            items.forEach((item, index) => {
                item.classList.toggle('selected', index === mentionsState.selectedIndex);
            });
        }

        // Select and insert mention
        function selectMention(index) {
            if (!mentionsState.editor || !mentionsState.searchResults[index]) return;

            const user = mentionsState.searchResults[index];
            const mention = `@${user.slug}`;

            const editor = mentionsState.editor;
            const selection = editor.selection;

            // Restore the range and select the @ and search term
            selection.setRng(mentionsState.currentRange);

            const range = selection.getRng();
            const textNode = range.startContainer;

            if (textNode.nodeType === Node.TEXT_NODE) {
                // Replace the @search with the full mention
                const beforeText = textNode.textContent.substring(0, mentionsState.mentionStart);
                const afterText = textNode.textContent.substring(range.startOffset);

                textNode.textContent = beforeText + mention + ' ' + afterText;

                // Position cursor after the mention
                const newRange = editor.dom.createRng();
                newRange.setStart(textNode, beforeText.length + mention.length + 1);
                newRange.setEnd(textNode, beforeText.length + mention.length + 1);
                selection.setRng(newRange);
            }

            hideDropdown();
        }

        // Show dropdown
        function showDropdown(editor) {
            const dropdown = createDropdown(editor);
            dropdown.style.display = 'block';
            positionDropdown(editor);
            mentionsState.isActive = true;
        }

        // Hide dropdown
        function hideDropdown() {
            if (mentionsState.dropdown) {
                mentionsState.dropdown.style.display = 'none';
            }
            mentionsState.isActive = false;
            mentionsState.currentSearch = '';
            mentionsState.selectedIndex = -1;
            mentionsState.searchResults = [];
        }

        // Search for users via REST API
        function searchUsers(term) {
            if (!term || term.length < 1) {
                hideDropdown();
                return;
            }

            // Show loading state
            if (mentionsState.dropdown) {
                mentionsState.dropdown.innerHTML = `
                    <div class="mentions-item mentions-loading">
                        Searching...
                    </div>
                `;
            }

            // Make API call
            const apiUrl = `/wp-json/extrachill/v1/users/search?term=${encodeURIComponent(term)}`;

            fetch(apiUrl)
                .then(response => response.json())
                .then(data => {
                    if (Array.isArray(data) && data.length > 0) {
                        renderResults(data);
                    } else {
                        renderResults([]);
                    }
                })
                .catch(error => {
                    console.error('Error searching users:', error);
                    renderResults([]);
                });
        }

        // Debounced search function
        function debouncedSearch(term) {
            clearTimeout(mentionsState.debounceTimeout);
            mentionsState.debounceTimeout = setTimeout(() => {
                searchUsers(term);
            }, 200);
        }

        // Check for @ mention pattern
        function checkForMention(editor) {
            const selection = editor.selection;
            const range = selection.getRng();

            if (!range || !range.startContainer || range.startContainer.nodeType !== Node.TEXT_NODE) {
                if (mentionsState.isActive) {
                    hideDropdown();
                }
                return false;
            }

            const textNode = range.startContainer;
            const cursorPos = range.startOffset;
            const text = textNode.textContent;

            // Find the last @ before cursor
            let mentionStart = -1;
            for (let i = cursorPos - 1; i >= 0; i--) {
                if (text[i] === '@') {
                    // Check if @ is at start or preceded by whitespace
                    if (i === 0 || /\s/.test(text[i - 1])) {
                        mentionStart = i;
                        break;
                    }
                }
                // Stop at whitespace
                if (/\s/.test(text[i])) {
                    break;
                }
            }

            if (mentionStart === -1) {
                if (mentionsState.isActive) {
                    hideDropdown();
                }
                return false;
            }

            // Extract search term after @
            const searchTerm = text.substring(mentionStart + 1, cursorPos);

            // Check if search term contains only valid characters (letters, numbers, underscore, hyphen)
            if (!/^[a-zA-Z0-9_-]*$/.test(searchTerm)) {
                if (mentionsState.isActive) {
                    hideDropdown();
                }
                return false;
            }

            // Store state
            mentionsState.currentRange = range.cloneRange();
            mentionsState.mentionStart = mentionStart;
            mentionsState.currentSearch = searchTerm;
            mentionsState.editor = editor;

            // Show dropdown and search
            showDropdown(editor);
            debouncedSearch(searchTerm);

            return true;
        }

        // Handle keyboard navigation
        function handleKeyDown(editor, e) {
            if (!mentionsState.isActive) {
                return;
            }

            switch (e.keyCode) {
                case 27: // Escape
                    e.preventDefault();
                    hideDropdown();
                    break;

                case 38: // Up arrow
                    e.preventDefault();
                    if (mentionsState.searchResults.length > 0) {
                        if (mentionsState.selectedIndex > 0) {
                            mentionsState.selectedIndex--;
                        } else {
                            mentionsState.selectedIndex = mentionsState.searchResults.length - 1;
                        }
                        updateSelection();
                    }
                    break;

                case 40: // Down arrow
                    e.preventDefault();
                    if (mentionsState.searchResults.length > 0) {
                        if (mentionsState.selectedIndex < mentionsState.searchResults.length - 1) {
                            mentionsState.selectedIndex++;
                        } else {
                            mentionsState.selectedIndex = 0;
                        }
                        updateSelection();
                    }
                    break;

                case 13: // Enter
                    e.preventDefault();
                    if (mentionsState.selectedIndex >= 0) {
                        selectMention(mentionsState.selectedIndex);
                    }
                    break;
            }
        }

        // Setup mentions plugin logic
        function setupMentionsPlugin(editor) {
            // Keydown handler for navigation
            editor.on('keydown', function(e) {
                handleKeyDown(editor, e);
            });

            // Keyup handler for mention detection
            editor.on('keyup', function(e) {
                // Skip navigation keys
                const navKeys = [37, 38, 39, 40, 27, 13];
                if (navKeys.includes(e.keyCode)) {
                    return;
                }

                checkForMention(editor);
            });

            // Click handler to hide dropdown
            editor.on('click', function() {
                setTimeout(() => {
                    if (mentionsState.isActive && !checkForMention(editor)) {
                        hideDropdown();
                    }
                }, 10);
            });

            // Blur handler to hide dropdown
            editor.on('blur', function() {
                setTimeout(() => {
                    hideDropdown();
                }, 150); // Delay to allow for dropdown clicks
            });
        }

        // Add the plugin using PluginManager with unique name to avoid conflicts
        try {
            tinymce.PluginManager.add('extrachillmentionssocial', function(editor) {
                editor.on('init', function() {
                    setupMentionsPlugin(editor);
                });
            });
        } catch (e) {
            console.error('Error loading extrachill mentions social plugin:', e);
        }

    })(); // IIFE

// Handle clicks on the bbPress Reply link (existing functionality preserved)
jQuery(document).ready(function($) {
    $(document).on('click', '.bbp-reply-to-link', function(e) {
        e.preventDefault();

        var href = $(this).attr('href');
        var replyToIdMatch = href.match(/bbp_reply_to=(\d+)/);
        var replyToId = replyToIdMatch ? replyToIdMatch[1] : null;

        if (!replyToId) {
            return false;
        }

        var replyElement = $('.bbp-reply-content[data-reply-id="' + replyToId + '"]');
        if (replyElement.length === 0) {
            return false;
        }

        var replyCard = replyElement.closest('.bbp-reply-card');
        if (replyCard.length === 0) {
            return false;
        }

        var authorLink = replyCard.find('.bbp-reply-header .bbp-author-name');
        if (authorLink.length === 0) {
            return false;
        }

        var authorUrl = authorLink.attr('href');
        var replySlug = null;
        if (authorUrl) {
            var parts = authorUrl.replace(/\/+$/, '').split('/');
            replySlug = parts.pop();
        }
        if (!replySlug) {
            return false;
        }

        var mentionHtml = '@' + replySlug;

        var replyContent = $('#bbp_reply_content');
        if (replyContent.length) {
            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('bbp_reply_content') && !tinyMCE.get('bbp_reply_content').isHidden()) {
                var editor = tinyMCE.get('bbp_reply_content');
                editor.focus();
                editor.execCommand('mceInsertContent', false, mentionHtml);
                editor.selection.select(editor.getBody(), true);
                editor.selection.collapse(false);
            } else {
                replyContent.focus();
                var currentVal = replyContent.val();
                var cursorPos = replyContent.prop('selectionStart');
                var textBefore = currentVal.substring(0, cursorPos);
                var textAfter = currentVal.substring(cursorPos);
                replyContent.val(textBefore + mentionHtml + textAfter);
                var newCursorPos = cursorPos + mentionHtml.length;
                replyContent.prop('selectionStart', newCursorPos);
                replyContent.prop('selectionEnd', newCursorPos);
            }
        }

        return false;
    });
});

} else {
    console.log('Extrachill mentions plugin already loaded');
}