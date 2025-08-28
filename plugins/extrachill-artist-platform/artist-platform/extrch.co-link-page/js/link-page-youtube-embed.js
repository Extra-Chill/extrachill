(function() {
    'use strict';

    const YOUTUBE_VIDEO_PLACEHOLDER_CLASS = 'extrch-youtube-video-placeholder';
    const YOUTUBE_EMBEDDABLE_LINK_CLASS = 'extrch-youtube-embeddable';
    const YOUTUBE_VIDEO_ID_DATA_ATTR = 'data-youtube-video-id';

    /**
     * Parses a YouTube URL and extracts the video ID.
     * @param {string} url The YouTube URL.
     * @returns {string|null} The video ID or null if not found.
     */
    function getYouTubeVideoId(url) {
        if (typeof url !== 'string') {
            return null;
        }
        // Standard YouTube video IDs are 11 characters long and can contain A-Z, a-z, 0-9, _, -
        // Regular expression to cover common YouTube URL formats
        // const regExp = /^.*(?:youtu\\.be\\/|v\\/|u\\/\\w\\/|embed\\/|watch\\?v=|&v=)([^#&?]{11}).*/;
        // Using new RegExp constructor for clarity with string escaping
        const pattern = '^.*(?:youtu\\.be\\/|v\\/|u\\/\\w\\/|embed\\/|watch\\?v=|&v=)([^#&?]{11}).*';
        const regExp = new RegExp(pattern);
        const match = url.match(regExp);
        if (match && match[1]) {
            return match[1];
        }
        return null;
    }

    /**
     * Creates or retrieves the video placeholder for a given link element.
     * @param {HTMLElement} linkElement The link element (<a> tag).
     * @returns {HTMLElement} The placeholder div.
     */
    function getOrCreateVideoPlaceholder(linkElement) {
        let placeholder = linkElement.nextElementSibling;
        if (!placeholder || !placeholder.classList.contains(YOUTUBE_VIDEO_PLACEHOLDER_CLASS)) {
            placeholder = document.createElement('div');
            placeholder.className = YOUTUBE_VIDEO_PLACEHOLDER_CLASS;
            // No need for placeholder.style.display = 'none'; CSS handles initial hidden state via max-height
            // Insert after the button's parent if the button is wrapped, or after the button itself.
            // Assuming buttons might be wrapped in <li> or <p>
            const parentContainer = linkElement.parentElement.classList.contains('extrch-link-button-wrapper') ? linkElement.parentElement : linkElement;
            parentContainer.parentNode.insertBefore(placeholder, parentContainer.nextSibling);
        }
        return placeholder;
    }

    // --- GLOBAL DELEGATED YOUTUBE EMBED HANDLER (BODY, CAPTURING PHASE) --- //
    function globalDelegatedYoutubeEmbedHandler(event) {
        const link = event.target.closest('a.extrch-link-page-link');
        if (!link) return;
        if (!link.closest('.extrch-link-page-links')) return;
        const href = link.href;
        if (href.includes('youtube.com') || href.includes('youtu.be')) {
            if (event.target.closest('.extrch-share-trigger')) return;
            event.preventDefault();

            // Check if the next sibling is a visible placeholder for this link (toggle close)
            let placeholder = link.nextElementSibling;
            if (placeholder && placeholder.classList.contains('extrch-youtube-video-placeholder')) {
                if (placeholder.classList.contains('video-visible')) {
                    // Already open, so close (remove video after transition, remove placeholder from DOM)
                    placeholder.classList.remove('video-visible');
                    setTimeout(() => {
                        if (!placeholder.classList.contains('video-visible')) {
                            placeholder.innerHTML = '';
                            if (placeholder.parentNode) {
                                placeholder.parentNode.removeChild(placeholder);
                            }
                        }
                    }, 300); // match transition duration
                    return;
                }
            }

            // Only allow one video open at a time: close all others and clear their content
            document.querySelectorAll('.extrch-youtube-video-placeholder.video-visible').forEach(other => {
                if (other !== placeholder) {
                    other.classList.remove('video-visible');
                    setTimeout(() => {
                        if (!other.classList.contains('video-visible')) {
                            other.innerHTML = '';
                            // Do NOT remove the placeholder div itself
                        }
                    }, 300);
                }
            });

            // Create and insert placeholder after the link
            placeholder = document.createElement('div');
            placeholder.className = 'extrch-youtube-video-placeholder';
            link.parentNode.insertBefore(placeholder, link.nextSibling);

            // Extract videoId for embed
            const videoId = getYouTubeVideoId(href);
            if (!videoId) return;
            placeholder.innerHTML = `<iframe src="https://www.youtube.com/embed/${videoId}?autoplay=1&rel=0&mute=0" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>`;
            // Force reflow for transition
            const _ = placeholder.offsetHeight;
            placeholder.classList.add('video-visible');
        }
    }

    // --- INITIALIZATION --- //
    function initializeGlobalDelegatedYoutubeEmbeds() {
        if (document.body._extrchYoutubeEmbedHandlerAttached) return;
        document.body.addEventListener('click', globalDelegatedYoutubeEmbedHandler, true);
        document.body._extrchYoutubeEmbedHandlerAttached = true;
    }

    // Wait for DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeGlobalDelegatedYoutubeEmbeds);
    } else {
        initializeGlobalDelegatedYoutubeEmbeds();
    }

    // Expose for manual re-init if needed
    window.ExtrchLinkPageYoutubeEmbeds = {
        init: initializeGlobalDelegatedYoutubeEmbeds
    };

})(); 