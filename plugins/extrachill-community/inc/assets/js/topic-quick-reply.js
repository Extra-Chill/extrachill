jQuery(document).ready(function($) {
    const ajaxUrl = typeof quickReplyAjax !== 'undefined' ? quickReplyAjax.ajaxUrl : '';
    const loadingMsg = typeof quickReplyAjax !== 'undefined' ? quickReplyAjax.loadingMessage : '<p>Loading...</p>';
    const errorMsg = typeof quickReplyAjax !== 'undefined' ? quickReplyAjax.errorMessage : '<p>Error loading form.</p>';
    
    const $desktopButton = $('#quick-reply-button-desktop');
    const $desktopFormContainer = $('#quick-reply-form-placeholder-desktop');
    
    if ($desktopButton.length && $desktopFormContainer.length) {
        $desktopButton.on('click', function() {
                const $button = $(this);
            const topicId = $button.data('topic-id');
            
            const isVisible = $desktopFormContainer.is(':visible');
            $desktopFormContainer.slideToggle(100);

            if (isVisible) {
                $button.text('Quick Reply');
            } else {
                        $button.text('Cancel Reply');
                        const quickEditorId = 'bbp_reply_content_quick';
                setTimeout(function() {
                    const editor = (typeof tinymce !== 'undefined') ? tinymce.get(quickEditorId) : null;
                    if (editor) {
                        const editorBody = editor.getBody();
                        const rootStyles = getComputedStyle(document.documentElement);
                        const isDarkMode = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

                        if (isDarkMode) {
                            $(editorBody).css({
                                'background-color': rootStyles.getPropertyValue('--background-color').trim(),
                                'color': rootStyles.getPropertyValue('--text-color').trim(),
                                'font-family': 'sans-serif',
                                'font-size': '16px',
                                'font-weight': '400'
                            });
                            $(editorBody).find('a').css('color', rootStyles.getPropertyValue('--link-color').trim());
                        } else {
                            $(editorBody).css({
                                'background-color': '',
                                'color': ''
                                'font-family': 'sans-serif',
                                'font-size': '16px',
                                'font-weight': '400'
                            });
                            $(editorBody).find('a').removeAttr('style');
                        }
                        editor.focus();
                    } else {
                        $('#' + quickEditorId).focus();
                    }
                }, 150);
            }
        });
    }

    const $mobileButton = $('#quick-reply-button-mobile');
    const $mobileFlyout = $('#quick-reply-form-mobile'); // The whole flyout container
    const $mobileCloseButton = $mobileFlyout.find('.close-flyout-button');
    
    if ($mobileButton.length && $mobileFlyout.length) {
        $mobileButton.on('click', function() {
            $mobileFlyout.toggleClass('is-visible');
            $('body').toggleClass('quick-reply-flyout-open'); 

            if ($mobileFlyout.hasClass('is-visible')) {
                        const quickEditorId = 'bbp_reply_content_quick';
                 const mobileEditorId = 'bbp_reply_content_quick_mobile';
                  setTimeout(function() {
                    const editor = (typeof tinymce !== 'undefined') ? tinymce.get(mobileEditorId) : null;
                    if (editor) {
                        const editorBody = editor.getBody();
                        const rootStyles = getComputedStyle(document.documentElement);
                        const isDarkMode = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

                        if (isDarkMode) {
                            $(editorBody).css({
                                'background-color': rootStyles.getPropertyValue('--background-color').trim(),
                                'color': rootStyles.getPropertyValue('--text-color').trim(),
                                'font-family': 'sans-serif',
                                'font-size': '16px',
                                'font-weight': '400'
                            });
                             $(editorBody).find('a').css('color', rootStyles.getPropertyValue('--link-color').trim());
                        } else {
                            $(editorBody).css({
                                'background-color': '',
                                'color': ''
                                'font-family': 'sans-serif',
                                'font-size': '16px',
                                'font-weight': '400'
                            });
                            $(editorBody).find('a').removeAttr('style');
                        }
                        editor.focus();
                    } else {
                        $('#' + mobileEditorId).focus();
                    }
                 }, 150);
            }
        });

        $mobileCloseButton.on('click', function() {
            $mobileFlyout.removeClass('is-visible');
            $('body').removeClass('quick-reply-flyout-open');
        });
    }
});