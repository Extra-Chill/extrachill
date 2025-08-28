// Link Page Content Renderer Module (The "Content Engine")
//
// ARCHITECTURE: On initial page load, the preview DOM is rendered by PHP and should NOT be wiped out or re-rendered by JS.
// JS should only update/add/remove the specific link or section that was changed by the user, in response to user actions.
// Never clear or re-render the entire links DOM on initialization.
(function(manager) {
    if (!manager) {
        return;
    }
    manager.contentPreview = manager.contentPreview || {}; // Changed from linksPreview

    let currentFeaturedUrlToSkipInJsPreview = null; // Added: Stores URL of link to be skipped by JS

    const PREVIEW_LINKS_CONTAINER_SELECTOR = '.extrch-link-page-links';
    const PREVIEW_SOCIALS_CONTAINER_SELECTOR = '.extrch-link-page-socials'; // Added for socials
    const PREVIEW_TITLE_SELECTOR = '.extrch-link-page-title';       // CORRECTED SELECTOR
    const PREVIEW_BIO_SELECTOR = '.extrch-link-page-bio';           // CORRECTED SELECTOR
    const PREVIEW_PROFILE_IMAGE_SELECTOR = '.extrch-link-page-profile-img img'; // CORRECTED SELECTOR
    const PROFILE_IMAGE_CONTAINER_SELECTOR = '.extrch-link-page-profile-img'; // CORRECTED SELECTOR for the container div
    const PREVIEW_SOCIAL_ICONS_BOTTOM_SELECTOR = '#link-page-social-icons-bottom';
    const PREVIEW_SUBSCRIBE_SECTION_SELECTOR = '.link-page-subscribe-section';
    const PREVIEW_FEATURED_LINK_SECTION_SELECTOR = '.link-page-featured-link-section'; // New selector
    const FEATURED_LINK_HIGHLIGHT_CLASS = 'extrch-featured-item-highlight'; // Added class

    function getPreviewContainer(previewEl, selector, type) {
        if (!previewEl) {
            return null;
        }
        const container = previewEl.querySelector(selector);
        if (!container) {
        }
        return container;
    }

    /**
     * Renders link sections and their links in the live preview.
     * @param {Array} sectionsArray An array of section objects.
     * @param {HTMLElement} previewEl The main preview container element.
     * @param {HTMLElement} contentWrapperEl The content wrapper within the preview.
     */
    manager.contentPreview.renderLinkSections = function(sectionsArray, previewEl, contentWrapperEl) {
        if (!previewEl || !contentWrapperEl) {
            return;
        }

        // Remove only the existing section titles and links containers (not the entire content wrapper)
        const existingSectionTitles = contentWrapperEl.querySelectorAll('.extrch-link-page-section-title');
        const existingLinkContainers = contentWrapperEl.querySelectorAll(PREVIEW_LINKS_CONTAINER_SELECTOR);
        existingSectionTitles.forEach(el => el.remove());
        existingLinkContainers.forEach(el => el.remove());

        if (!Array.isArray(sectionsArray) || sectionsArray.length === 0) {
            return;
        }

        let insertBeforeElement = contentWrapperEl.querySelector('.extrch-link-page-subscribe-inline-form-container');
        if (!insertBeforeElement) {
            insertBeforeElement = contentWrapperEl.querySelector('.extrch-link-page-powered');
        }
        if (!insertBeforeElement) {
            insertBeforeElement = null; 
        }

        sectionsArray.forEach(sectionData => {
            if (!sectionData || !Array.isArray(sectionData.links)) {
                return;
            }

            if (sectionData.section_title && String(sectionData.section_title).trim() !== '') {
                const titleElement = document.createElement('div');
                titleElement.className = 'extrch-link-page-section-title';
                titleElement.textContent = sectionData.section_title;
                if (insertBeforeElement) {
                    contentWrapperEl.insertBefore(titleElement, insertBeforeElement);
                } else {
                    contentWrapperEl.appendChild(titleElement);
                }
            }

            const linksContainer = document.createElement('div');
            linksContainer.className = 'extrch-link-page-links';

            if (insertBeforeElement) {
                contentWrapperEl.insertBefore(linksContainer, insertBeforeElement);
            } else {
                contentWrapperEl.appendChild(linksContainer);
            }
            
            if (sectionData.links.length > 0) {
                sectionData.links.forEach(linkData => {
                    if (!linkData || !linkData.link_url || !linkData.link_text) {
                        return;
                    }

                    // Check if this link URL is currently set to be skipped by JS override
                    const normalizedLinkUrl = linkData.link_url.replace(/\/$/, '');
                    if (currentFeaturedUrlToSkipInJsPreview && normalizedLinkUrl === currentFeaturedUrlToSkipInJsPreview) {
                        // console.log('[ContentRenderer] JS Skipping featured link in main list:', linkData.link_url);
                        return; // Skip this link as it's currently featured (decision by JS)
                    }

                    const isActive = (typeof linkData.link_is_active !== 'undefined') ? Boolean(linkData.link_is_active) : true;
                    if (!isActive) return;

                    const linkElement = document.createElement('a');
                    linkElement.href = linkData.link_url;
                    // Text content will be wrapped in a span to allow sibling button
                    // linkElement.textContent = linkData.link_text;
                    linkElement.className = 'extrch-link-page-link'; // This class now expects display:flex from CSS
                    linkElement.target = '_blank';
                    linkElement.rel = 'noopener';
                    if (typeof linkData.link_id !== 'undefined') {
                        linkElement.setAttribute('data-id', linkData.link_id);
                    }

                    // Create span for link text
                    const textSpan = document.createElement('span');
                    textSpan.className = 'extrch-link-page-link-text';
                    textSpan.textContent = linkData.link_text;
                    linkElement.appendChild(textSpan);

                    // Create wrapper span for the icon/button
                    const iconSpan = document.createElement('span');
                    iconSpan.className = 'extrch-link-page-link-icon';

                    // Create share button
                    const shareButton = document.createElement('button');
                    shareButton.className = 'extrch-share-trigger extrch-share-item-trigger';
                    shareButton.setAttribute('aria-label', 'Share this link');
                    shareButton.setAttribute('data-share-type', 'link');
                    shareButton.setAttribute('data-share-url', linkData.link_url); // Use the raw URL for data attribute
                    shareButton.setAttribute('data-share-title', linkData.link_text);
                    
                    const shareIcon = document.createElement('i');
                    shareIcon.className = 'fas fa-ellipsis-v';
                    shareButton.appendChild(shareIcon);

                    iconSpan.appendChild(shareButton);
                    linkElement.appendChild(iconSpan);

                    linksContainer.appendChild(linkElement);
                });
            }
        });
    };

    /**
     * Renders the social media icons in the live preview.
     * @param {Array} socialsArray An array of social icon objects.
     *                           Example: [{ type: 'instagram', url: 'https://...', icon: 'fab fa-instagram' (optional) }, ...]
     * @param {HTMLElement} previewEl The main preview container element.
     * @param {HTMLElement} contentWrapperEl The content wrapper within the preview where socials are located.
     * @param {string} position Optional. Expected values: 'above' or 'below'. Determines where to render or look for the container.
     */
    manager.contentPreview.renderSocials = function(socialsArray, previewEl, contentWrapperEl, position = 'above') {
        if (!previewEl || !contentWrapperEl) {
            return;
        }

        const isFirstJsRender = !contentWrapperEl.dataset.socialsJsRendered;

        if (position !== 'above' && position !== 'below') {
            const checkedRadio = document.querySelector('input[name="link_page_social_icons_position"]:checked');
            position = checkedRadio ? checkedRadio.value : 'above';
        }

        // --- Define insertion points ---
        // For 'above', insert before featured link section if it exists, else before first link section/title, subscribe form, or powered by link.
        const featuredLinkSection = contentWrapperEl.querySelector('.link-page-featured-link-section');
        const firstLinkSectionTitle = contentWrapperEl.querySelector('.extrch-link-page-section-title');
        const firstLinksContainer = contentWrapperEl.querySelector(PREVIEW_LINKS_CONTAINER_SELECTOR);
        const subscribeFormContainer = contentWrapperEl.querySelector('.extrch-link-page-subscribe-inline-form-container');
        const poweredByLink = contentWrapperEl.querySelector('.extrch-link-page-powered');

        let insertBeforeElForAbove = featuredLinkSection || firstLinkSectionTitle || firstLinksContainer || subscribeFormContainer || poweredByLink;
        
        // Element to insert "below" container before: typically the "powered by" link, but after subscribe form if present.
        let insertBeforeElForBelow = poweredByLink;
        let afterSubscribeForm = false;
        if (subscribeFormContainer && poweredByLink) {
            // Insert after subscribe form, before powered by
            // We'll insertBefore poweredByLink, but move subscribe form up if needed
            afterSubscribeForm = true;
        }

        // --- Find or create the socials container ---
        let socialsContainer = null;
        const existingSocialsContainers = Array.from(contentWrapperEl.querySelectorAll(PREVIEW_SOCIALS_CONTAINER_SELECTOR));
        
        if (existingSocialsContainers.length > 1) {
            // This case should ideally not happen if PHP renders only one.
            // If it does, remove all but the first one to simplify, or choose based on current position.
            // For now, let's assume we prefer the one that matches the target position if available.
            let preferredContainer = existingSocialsContainers.find(c => 
                (position === 'below' && c.classList.contains('extrch-socials-below')) ||
                (position === 'above' && !c.classList.contains('extrch-socials-below'))
            );
            if (preferredContainer) {
                existingSocialsContainers.forEach(c => {
                    if (c !== preferredContainer) c.remove();
                });
                socialsContainer = preferredContainer;
            } else {
                // None match, remove all but the first, and we'll re-position it.
                existingSocialsContainers.slice(1).forEach(c => c.remove());
                socialsContainer = existingSocialsContainers[0] || null;
            }
        } else if (existingSocialsContainers.length === 1) {
            socialsContainer = existingSocialsContainers[0];
        }

        // At this point, socialsContainer is either the single existing one, or null.

        if (socialsContainer) { // An existing container was found
            const isCurrentlyBelow = socialsContainer.classList.contains('extrch-socials-below');
            if (position === 'below' && !isCurrentlyBelow) {
                socialsContainer.classList.add('extrch-socials-below');
                if (afterSubscribeForm && subscribeFormContainer) {
                    // Insert after subscribe form
                    contentWrapperEl.insertBefore(socialsContainer, poweredByLink);
                } else if (insertBeforeElForBelow) {
                    contentWrapperEl.insertBefore(socialsContainer, insertBeforeElForBelow);
                } else {
                    contentWrapperEl.appendChild(socialsContainer); // Fallback append
                }
            } else if (position === 'above' && isCurrentlyBelow) {
                socialsContainer.classList.remove('extrch-socials-below');
                if (insertBeforeElForAbove) {
                    contentWrapperEl.insertBefore(socialsContainer, insertBeforeElForAbove);
                } else {
                    // If no specific "above" anchor, try to put it before the first child of contentWrapper
                    contentWrapperEl.insertBefore(socialsContainer, contentWrapperEl.firstChild);
                }
            }
            // If it's already in the correct position, no DOM move is needed, just class check.
            if (position === 'below' && !socialsContainer.classList.contains('extrch-socials-below')) {
                 socialsContainer.classList.add('extrch-socials-below');
            } else if (position === 'above' && socialsContainer.classList.contains('extrch-socials-below')) {
                 socialsContainer.classList.remove('extrch-socials-below');
            }

        } else { // No existing container, create a new one
            socialsContainer = document.createElement('div');
            socialsContainer.className = 'extrch-link-page-socials';
            if (position === 'below') {
                socialsContainer.classList.add('extrch-socials-below');
                if (afterSubscribeForm && subscribeFormContainer) {
                    contentWrapperEl.insertBefore(socialsContainer, poweredByLink);
                } else if (insertBeforeElForBelow) {
                    contentWrapperEl.insertBefore(socialsContainer, insertBeforeElForBelow);
                } else {
                    contentWrapperEl.appendChild(socialsContainer);
                }
            } else { // position is 'above'
                if (insertBeforeElForAbove) {
                    contentWrapperEl.insertBefore(socialsContainer, insertBeforeElForAbove);
                } else {
                     contentWrapperEl.insertBefore(socialsContainer, contentWrapperEl.firstChild);
                }
            }
        }
        
        // Clear and populate the container
        socialsContainer.innerHTML = '';

        if (!Array.isArray(socialsArray) || socialsArray.length === 0) {
            socialsContainer.style.display = 'none';
            // contentWrapperEl.dataset.socialsJsRendered = 'true'; // Set flag even if hiding
            return;
        }
        socialsContainer.style.display = '';

        socialsArray.forEach(socialData => {
            if (!socialData || !socialData.url || !socialData.type) {
                return;
            }

            const linkElement = document.createElement('a');
            linkElement.href = socialData.url;
            linkElement.className = 'extrch-social-icon'; // Match class from extrch-link-page-template.php
            linkElement.target = '_blank';
            linkElement.rel = 'noopener';
            linkElement.setAttribute('aria-label', socialData.type);

            const iconElement = document.createElement('i');
            // If 'icon' field is provided (e.g. 'fab fa-instagram'), use it directly.
            // Otherwise, construct from 'type' (e.g. 'instagram' -> 'fab fa-instagram').
            let iconClass = '';
            const typeLower = socialData.type.toLowerCase();
            // Look up the icon class from the localized supportedLinkTypes data
            if (window.extrchLinkPageConfig?.supportedLinkTypes && window.extrchLinkPageConfig.supportedLinkTypes[typeLower]) {
                iconClass = window.extrchLinkPageConfig.supportedLinkTypes[typeLower].icon;
            } else {
                // Fallback or warning if type is not found in the centralized list
                iconClass = 'fas fa-globe'; // Generic default icon
            }
            
            iconElement.className = iconClass;
            iconElement.setAttribute('aria-hidden', 'true');

            linkElement.appendChild(iconElement);
            socialsContainer.appendChild(linkElement);
        });

        if (isFirstJsRender) {
            contentWrapperEl.dataset.socialsJsRendered = 'true';
        }
    };

    /**
     * Updates the display title in the live preview.
     * @param {string} newTitle The new title text.
     * @param {HTMLElement} previewEl The main preview container element.
     */
    manager.contentPreview.updatePreviewTitle = function(newTitle, previewEl) {
        if (!previewEl) {
            return;
        }
        const titleElement = previewEl.querySelector(PREVIEW_TITLE_SELECTOR);
        if (titleElement) {
            titleElement.textContent = newTitle;
        } else {
        }
    };

    /**
     * Updates the bio text in the live preview.
     * @param {string} newBio The new bio text.
     * @param {HTMLElement} previewEl The main preview container element.
     */
    manager.contentPreview.updatePreviewBio = function(newBio, previewEl) {
        if (!previewEl) {
            return;
        }
        const bioElement = previewEl.querySelector(PREVIEW_BIO_SELECTOR);
        if (bioElement) {
             // Use innerHTML to allow basic HTML tags (like line breaks) if needed from wp_kses_post
            bioElement.innerHTML = newBio;
        } else {
        }
    };

    /**
     * Updates the profile image in the live preview.
     * @param {string} imgUrl The new image URL.
     * @param {HTMLElement} previewEl The main preview container element.
     */
    manager.contentPreview.updateProfileImage = function(imgUrl, previewEl) {
        if (!previewEl) {
            return;
        }
        const imgElement = previewEl.querySelector(PREVIEW_PROFILE_IMAGE_SELECTOR);
        const imgContainer = previewEl.querySelector(PROFILE_IMAGE_CONTAINER_SELECTOR);

        if (imgElement && imgContainer) {
            if (imgUrl) {
                imgElement.src = imgUrl;
                imgContainer.style.display = 'block'; // Ensure container is visible
            } else {
                imgElement.src = ''; // Clear source
                imgContainer.style.display = 'none'; // Hide container if no image
            }
        } else {
        }
    };

     /**
      * Ensures the profile image container exists in the preview DOM and has basic structure.
      * Useful for initial rendering or if the element might be conditionally present.
      * @param {HTMLElement} previewEl The main preview container element.
      * @returns {HTMLElement|null} The profile image container element, or null if previewEl is missing.
      */
    function ensureProfileImageContainer(previewEl) {
        if (!previewEl) return null;

        let imgContainer = previewEl.querySelector(PROFILE_IMAGE_CONTAINER_SELECTOR);

        if (!imgContainer) {
            imgContainer = document.createElement('div');
            imgContainer.className = PROFILE_IMAGE_CONTAINER_SELECTOR.substring(1);
            imgContainer.classList.add('extrch-link-page-info-section'); // Add info section class for spacing

            const imgElement = document.createElement('img');
            imgElement.className = PREVIEW_PROFILE_IMAGE_SELECTOR.split(' ').pop(); // Get only the img class
            imgElement.alt = 'Profile Image'; // Add alt text
            imgElement.style.display = 'none'; // Hide by default until image is set

            imgContainer.appendChild(imgElement);

            // Find where to insert it - typically after bio, before socials/links
            const bioElement = previewEl.querySelector(PREVIEW_BIO_SELECTOR);
            let insertBeforeTarget = previewEl.querySelector(PREVIEW_SOCIALS_CONTAINER_SELECTOR);
            if (!insertBeforeTarget) {
                insertBeforeTarget = previewEl.querySelector(PREVIEW_LINKS_CONTAINER_SELECTOR);
            }
            if (!insertBeforeTarget) {
                 insertBeforeTarget = previewEl.querySelector('.extrch-link-page-powered');
            }
            
            if (bioElement) {
                 bioElement.parentNode.insertBefore(imgContainer, insertBeforeTarget || bioElement.nextSibling);
            } else if (insertBeforeTarget) {
                 previewEl.querySelector('.extrch-link-page-content-wrapper').insertBefore(imgContainer, insertBeforeTarget);
            } else { // Fallback - append to content wrapper
                previewEl.querySelector('.extrch-link-page-content-wrapper').appendChild(imgContainer);
            }

            // console.log('[ContentRenderer-Info] Created profile image container.'); // Optional log
        }
        return imgContainer;
    }

    /**
     * Updates the featured link section in the live preview.
     * @param {Object} data - The data for the featured link.
     * @param {string} data.originalLinkUrl - The URL of the original link.
     * @param {string} data.thumbnailUrl - The URL for the thumbnail (can be dataURL or actual URL).
     * @param {string} data.title - The custom or original title for the featured link.
     * @param {string} data.description - The custom description for the featured link.
     * @param {string} data.originalLinkId - The ID of the original link.
     * @param {HTMLElement} previewEl - The main preview container element.
     * @param {HTMLElement} contentWrapperEl - The content wrapper within the preview.
     */
    manager.contentPreview.updatePreviewFeaturedLink = function(data, previewEl, contentWrapperEl) {
        if (!previewEl || !contentWrapperEl) {
            return;
        }

        // Remove any existing featured link section first
        const existingFeaturedSection = contentWrapperEl.querySelector(PREVIEW_FEATURED_LINK_SECTION_SELECTOR);
        if (existingFeaturedSection) {
            existingFeaturedSection.remove();
        }

        // If data is null/undefined or isActive is false, we just wanted to remove it.
        if (!data || data.isActive === false) {
            currentFeaturedUrlToSkipInJsPreview = null;
            return;
        }

        currentFeaturedUrlToSkipInJsPreview = data.originalLinkUrl ? data.originalLinkUrl.replace(/\/$/, '') : null;

        const featuredSection = document.createElement('div');
        featuredSection.className = PREVIEW_FEATURED_LINK_SECTION_SELECTOR.substring(1);

        const anchor = document.createElement('a');
        anchor.href = data.originalLinkUrl || '#';
        anchor.target = '_blank';
        anchor.rel = 'noopener';
        anchor.className = 'featured-link-anchor';

        if (data.thumbnailUrl) {
            const img = document.createElement('img');
            img.src = data.thumbnailUrl;
            img.alt = data.title || 'Featured link thumbnail';
            img.className = 'featured-link-thumbnail';
            anchor.appendChild(img);
        }

        const contentDiv = document.createElement('div');
        contentDiv.className = 'featured-link-content';

        // --- Canonical: Title row with title and share button inline ---
        const titleRow = document.createElement('div');
        titleRow.className = 'featured-link-title-row';

        const titleEl = document.createElement('h3');
        titleEl.className = 'featured-link-title';
        titleEl.textContent = data.title || (data.originalLinkData ? data.originalLinkData.title : 'Featured Link');
        const titleFontFamily = previewEl.style.getPropertyValue('--link-page-title-font-family') || getComputedStyle(previewEl).getPropertyValue('--link-page-title-font-family');
        if (titleFontFamily) {
            titleEl.style.fontFamily = titleFontFamily;
        }
        titleRow.appendChild(titleEl);

        const shareButtonWrapper = document.createElement('span');
        shareButtonWrapper.className = 'extrch-link-page-link-icon';
        const shareButton = document.createElement('button');
        shareButton.className = 'extrch-share-trigger extrch-share-item-trigger extrch-share-featured-trigger';
        shareButton.setAttribute('aria-label', 'Share this link');
        shareButton.setAttribute('data-share-type', 'link');
        shareButton.setAttribute('data-share-url', data.originalLinkUrl || '#');
        shareButton.setAttribute('data-share-title', data.title || 'Featured Link');
        const shareItemId = data.originalLinkData && data.originalLinkData.id ? 'featured-' + data.originalLinkData.id : 'featured-' + Date.now();
        shareButton.setAttribute('data-share-item-id', shareItemId);
        shareButton.innerHTML = '<i class="fas fa-ellipsis-v"></i>';
        shareButtonWrapper.appendChild(shareButton);
        titleRow.appendChild(shareButtonWrapper);

        contentDiv.appendChild(titleRow);

        if (data.description) {
            const descEl = document.createElement('p');
            descEl.className = 'featured-link-description';
            descEl.textContent = data.description;
            contentDiv.appendChild(descEl);
        }

        anchor.appendChild(contentDiv);
        featuredSection.appendChild(anchor);

        // Determine where to insert the featured link
        const socialsContainer = contentWrapperEl.querySelector(PREVIEW_SOCIALS_CONTAINER_SELECTOR);
        const isSocialsAbove = socialsContainer && !socialsContainer.classList.contains('extrch-socials-below');

        let insertBeforeElement = null;

        if (isSocialsAbove) {
            // Insert AFTER socials, but before first link section/title
            let nextSibling = socialsContainer.nextElementSibling;
            while(nextSibling) {
                if (nextSibling.matches('.extrch-link-page-section-title, .extrch-link-page-links, .extrch-link-page-subscribe-inline-form-container, .extrch-link-page-powered')) {
                    insertBeforeElement = nextSibling;
                    break;
                }
                nextSibling = nextSibling.nextElementSibling;
            }
            if (!insertBeforeElement) { // If no clear element after socials (e.g., only socials exist), append to wrapper
                 contentWrapperEl.appendChild(featuredSection);
            } else {
                 contentWrapperEl.insertBefore(featuredSection, insertBeforeElement);
            }
        } else {
            // Socials are below or not present. Insert before first link section/title or other known elements.
            insertBeforeElement = contentWrapperEl.querySelector('.extrch-link-page-section-title, .extrch-link-page-links, .extrch-link-page-subscribe-inline-form-container, .extrch-link-page-powered');
            if (insertBeforeElement) {
                contentWrapperEl.insertBefore(featuredSection, insertBeforeElement);
            } else {
                // If no links or other content markers, insert after the main header content.
                const headerContent = contentWrapperEl.querySelector('.extrch-link-page-header-content');
                if (headerContent && headerContent.nextSibling) {
                    contentWrapperEl.insertBefore(featuredSection, headerContent.nextSibling);
                } else if (headerContent) {
                    contentWrapperEl.appendChild(featuredSection); // Append after header if it's the last thing
                } else {
                    contentWrapperEl.insertBefore(featuredSection, contentWrapperEl.firstChild); // Fallback: insert at the beginning of wrapper
                }
            }
        }
    };

    /**
     * Clears the featured link section from the live preview.
     * @param {HTMLElement} previewEl - The main preview container element.
     */
    manager.contentPreview.clearPreviewFeaturedLink = function(previewEl, contentWrapperEl) {
        if (!previewEl || !contentWrapperEl) return;
        const existingFeaturedLink = contentWrapperEl.querySelector(PREVIEW_FEATURED_LINK_SECTION_SELECTOR);
        if (existingFeaturedLink) {
            existingFeaturedLink.remove();
        }
        // After removing, we might need to re-evaluate which link in the main list is skipped.
        // This is handled by currentFeaturedUrlToSkipInJsPreview being set to null or a new URL.
    };

    /**
     * Sets or clears the URL of a link that should be skipped by renderLinkSections (JS override).
     * @param {string|null} url - The URL to skip (normalized), or null to clear.
     */
    manager.contentPreview.setFeaturedLinkUrlToSkipForPreview = function(urlToSkip) {
        if (urlToSkip && typeof urlToSkip === 'string') {
            currentFeaturedUrlToSkipInJsPreview = urlToSkip.replace(/\/$/, ''); // Normalize by removing trailing slash
        } else {
            currentFeaturedUrlToSkipInJsPreview = null;
        }
        // console.log('[ContentRenderer] Set URL to skip in JS preview:', currentFeaturedUrlToSkipInJsPreview);
    };

})(window.ExtrchLinkPageManager); 