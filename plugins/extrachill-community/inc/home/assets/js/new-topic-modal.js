/**
 * New Topic Modal
 *
 * Handles modal open/close, TinyMCE reinitialization, and accessibility.
 */
(function() {
    'use strict';

    const trigger = document.getElementById('new-topic-modal-trigger');
    const modal = document.getElementById('new-topic-modal');
    const overlay = document.getElementById('new-topic-modal-overlay');
    const closeButton = modal ? modal.querySelector('.new-topic-modal-close') : null;

    if (!trigger || !modal || !overlay) {
        return;
    }

    let editorInitialized = false;

    function openModal(e) {
        e.preventDefault();
        modal.classList.add('is-open');
        overlay.classList.add('is-open');
        document.body.classList.add('new-topic-modal-open');

        // Initialize TinyMCE if not already done and wp.editor is available
        if (!editorInitialized && typeof wp !== 'undefined' && wp.editor) {
            initializeEditor();
        }

        // Focus the first focusable element
        const firstInput = modal.querySelector('input[type="text"], textarea');
        if (firstInput) {
            firstInput.focus();
        }

        // Trap focus within modal
        document.addEventListener('keydown', trapFocus);
    }

    function closeModal() {
        modal.classList.remove('is-open');
        overlay.classList.remove('is-open');
        document.body.classList.remove('new-topic-modal-open');
        document.removeEventListener('keydown', trapFocus);
        trigger.focus();
    }

    function initializeEditor() {
        const editorId = 'bbp_topic_content';
        const editorElement = document.getElementById(editorId);

        if (!editorElement) {
            editorInitialized = true;
            return;
        }

        // If TinyMCE is already initialized for this element, just show it
        if (typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
            editorInitialized = true;
            return;
        }

        // Initialize wp.editor if available
        if (typeof wp !== 'undefined' && wp.editor && wp.editor.initialize) {
            wp.editor.initialize(editorId, {
                tinymce: {
                    wpautop: true,
                    plugins: 'charmap,colorpicker,hr,lists,paste,tabfocus,textcolor,wordpress,wpautoresize,wpeditimage,wpemoji,wpgallery,wplink,wptextpattern',
                    toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,wp_more,spellchecker,fullscreen,wp_adv',
                    toolbar2: 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help'
                },
                quicktags: true,
                mediaButtons: false
            });
        }

        editorInitialized = true;
    }

    function trapFocus(e) {
        if (e.key !== 'Tab') {
            if (e.key === 'Escape') {
                closeModal();
            }
            return;
        }

        const focusableElements = modal.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        if (e.shiftKey) {
            if (document.activeElement === firstElement) {
                lastElement.focus();
                e.preventDefault();
            }
        } else {
            if (document.activeElement === lastElement) {
                firstElement.focus();
                e.preventDefault();
            }
        }
    }

    // Event listeners
    trigger.addEventListener('click', openModal);

    if (closeButton) {
        closeButton.addEventListener('click', closeModal);
    }

    overlay.addEventListener('click', closeModal);
})();
