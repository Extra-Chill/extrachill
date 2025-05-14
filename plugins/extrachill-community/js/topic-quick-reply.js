jQuery(document).ready(function($) {
    console.log('Topic Quick Reply JS: Document Ready'); // Log: Script start

    // --- Config --- 
    const ajaxUrl = typeof quickReplyAjax !== 'undefined' ? quickReplyAjax.ajaxUrl : '';
    const loadingMsg = typeof quickReplyAjax !== 'undefined' ? quickReplyAjax.loadingMessage : '<p>Loading...</p>';
    const errorMsg = typeof quickReplyAjax !== 'undefined' ? quickReplyAjax.errorMessage : '<p>Error loading form.</p>';
    
    console.log('Topic Quick Reply JS: AJAX URL:', ajaxUrl); // Log: AJAX URL

    // --- Desktop Quick Reply --- 
    const $desktopButton = $('#quick-reply-button-desktop');
    const $desktopFormContainer = $('#quick-reply-form-placeholder-desktop');
    
    console.log('Topic Quick Reply JS: Desktop Button Found:', $desktopButton.length); // Log: Button selected?

    if ($desktopButton.length && $desktopFormContainer.length) {
        console.log('Topic Quick Reply JS: Attaching Desktop Click Handler'); // Log: Handler attachment
        $desktopButton.on('click', function() {
                const $button = $(this);
            const topicId = $button.data('topic-id'); // Keep topic ID if needed for future form interaction
            
            // Simple toggle
            const isVisible = $desktopFormContainer.is(':visible');
            $desktopFormContainer.slideToggle(100);

            // Change button text
            if (isVisible) {
                $button.text('Quick Reply');
            } else {
                        $button.text('Cancel Reply');
                        const quickEditorId = 'bbp_reply_content_quick'; 
                // Apply dark mode class if needed & Focus the editor when revealed
                setTimeout(function() {
                    const editor = (typeof tinymce !== 'undefined') ? tinymce.get(quickEditorId) : null;
                    if (editor) {
                        const editorBody = editor.getBody();
                        const rootStyles = getComputedStyle(document.documentElement);
                        const isDarkMode = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

                        if (isDarkMode) {
                            // Apply dark mode styles directly
                            $(editorBody).css({
                                'background-color': rootStyles.getPropertyValue('--background-color').trim(),
                                'color': rootStyles.getPropertyValue('--text-color').trim(),
                                'font-family': 'sans-serif',
                                'font-size': '16px',
                                'font-weight': '400'
                            });
                            // Style links within the editor body
                            $(editorBody).find('a').css('color', rootStyles.getPropertyValue('--link-color').trim());
                        } else {
                            // Remove inline styles to revert to content_css defaults (light)
                            // Apply light mode fonts explicitly too, in case defaults differ
                            $(editorBody).css({
                                'background-color': '', // Remove inline bg
                                'color': '', // Remove inline color
                                'font-family': 'sans-serif',
                                'font-size': '16px',
                                'font-weight': '400'
                            });
                            // Remove link styles
                            $(editorBody).find('a').removeAttr('style');
                        }
                        editor.focus();
                    } else {
                        // Fallback for plain textarea
                        $('#' + quickEditorId).focus();
                    }
                }, 150); // Adjust delay if needed 
            }

            // REMOVED AJAX CALL LOGIC
        });
    }

    // --- Mobile Quick Reply --- 
    const $mobileButton = $('#quick-reply-button-mobile');
    const $mobileFlyout = $('#quick-reply-form-mobile'); // The whole flyout container
    const $mobileCloseButton = $mobileFlyout.find('.close-flyout-button');
    
    console.log('Topic Quick Reply JS: Mobile Button Found:', $mobileButton.length); // Log: Button selected?

    if ($mobileButton.length && $mobileFlyout.length) {
        
        console.log('Topic Quick Reply JS: Attaching Mobile Click Handlers'); // Log: Handler attachment
        $mobileButton.on('click', function() {
            // Toggle the flyout visibility
            $mobileFlyout.toggleClass('is-visible');
            // Optional: Add a class to body to prevent scrolling when flyout is open
            $('body').toggleClass('quick-reply-flyout-open'); 

            // REMOVED AJAX CALL LOGIC
            
            // Optional: Focus editor when opened
            if ($mobileFlyout.hasClass('is-visible')) {
                        const quickEditorId = 'bbp_reply_content_quick'; 
                 const mobileEditorId = 'bbp_reply_content_quick_mobile'; // Mobile specific ID
                  // Apply dark mode class if needed & Focus editor
                  setTimeout(function() {
                    const editor = (typeof tinymce !== 'undefined') ? tinymce.get(mobileEditorId) : null;
                    if (editor) {
                        const editorBody = editor.getBody();
                        const rootStyles = getComputedStyle(document.documentElement);
                        const isDarkMode = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

                        if (isDarkMode) {
                            // Apply dark mode styles directly
                            $(editorBody).css({
                                'background-color': rootStyles.getPropertyValue('--background-color').trim(),
                                'color': rootStyles.getPropertyValue('--text-color').trim(),
                                'font-family': 'sans-serif',
                                'font-size': '16px',
                                'font-weight': '400'
                            });
                             // Style links within the editor body
                            $(editorBody).find('a').css('color', rootStyles.getPropertyValue('--link-color').trim());
                        } else {
                            // Remove inline styles to revert to content_css defaults (light)
                            // Apply light mode fonts explicitly too, in case defaults differ
                            $(editorBody).css({
                                'background-color': '', // Remove inline bg
                                'color': '', // Remove inline color
                                'font-family': 'sans-serif',
                                'font-size': '16px',
                                'font-weight': '400'
                            });
                            // Remove link styles
                            $(editorBody).find('a').removeAttr('style');
                        }
                        editor.focus();
                    } else {
                        $('#' + mobileEditorId).focus(); // Fallback focus for mobile ID
                    }
                 }, 150);
            }
        }); // End $mobileButton.on('click')

        // --- Mobile Close Button Handler ---
        $mobileCloseButton.on('click', function() {
            $mobileFlyout.removeClass('is-visible');
            $('body').removeClass('quick-reply-flyout-open');
        }); // End $mobileCloseButton.on('click')

    } // End if ($mobileButton.length && $mobileFlyout.length)

}); // End jQuery(document).ready