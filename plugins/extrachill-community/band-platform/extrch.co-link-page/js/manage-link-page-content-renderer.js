// Link Page Content Renderer Module (The "Content Engine")
(function(manager) {
    if (!manager) {
        console.error('ExtrchLinkPageManager is not defined. Content Renderer script cannot run.');
        return;
    }
    manager.contentPreview = manager.contentPreview || {}; // Changed from linksPreview

    const PREVIEW_LINKS_CONTAINER_SELECTOR = '.extrch-link-page-links';
    const PREVIEW_SOCIALS_CONTAINER_SELECTOR = '.extrch-link-page-socials'; // Added for socials
    const PREVIEW_TITLE_SELECTOR = '.extrch-link-page-title';       // CORRECTED SELECTOR
    const PREVIEW_BIO_SELECTOR = '.extrch-link-page-bio';           // CORRECTED SELECTOR
    const PREVIEW_PROFILE_IMAGE_SELECTOR = '.extrch-link-page-profile-img img'; // CORRECTED SELECTOR
    const PROFILE_IMAGE_CONTAINER_SELECTOR = '.extrch-link-page-profile-img'; // CORRECTED SELECTOR for the container div

    function getPreviewContainer(previewEl, selector, type) {
        if (!previewEl) {
            console.error(`[ContentRenderer-${type}] Preview element not provided.`);
            return null;
        }
        const container = previewEl.querySelector(selector);
        if (!container) {
            console.error(`[ContentRenderer-${type}] Container ('${selector}') not found in preview DOM.`);
        }
        return container;
    }

    /**
     * Renders link sections and their links in the live preview.
     * @param {Array} sectionsArray An array of section objects.
     * @param {HTMLElement} previewEl The main preview container element.
     * @param {HTMLElement} contentWrapperEl The content wrapper within the preview.
     */
    manager.contentPreview.renderLinkSections = function(sectionsArray, previewEl, contentWrapperEl) { // Renamed from renderSections
        if (!previewEl || !contentWrapperEl) {
            console.error('[ContentRenderer-Links] renderLinkSections called without previewEl or contentWrapperEl.');
            return;
        }

        const existingSectionTitles = contentWrapperEl.querySelectorAll('.extrch-link-page-section-title');
        const existingLinkContainers = contentWrapperEl.querySelectorAll(PREVIEW_LINKS_CONTAINER_SELECTOR);
        
        existingSectionTitles.forEach(el => el.remove());
        existingLinkContainers.forEach(el => el.remove());

        if (!Array.isArray(sectionsArray) || sectionsArray.length === 0) {
            return;
        }

        let insertBeforeElement = contentWrapperEl.querySelector('.extrch-link-page-powered');
        // If no powered by, try to insert before the first link container if it exists (though we just removed them)
        // This logic is mainly for initial placement if other elements like social icons are also being managed.
        if (!insertBeforeElement) {
            insertBeforeElement = contentWrapperEl.querySelector(PREVIEW_LINKS_CONTAINER_SELECTOR); 
        }
        if (!insertBeforeElement) {
            insertBeforeElement = null; 
        }

        sectionsArray.forEach(sectionData => {
            if (!sectionData || !Array.isArray(sectionData.links)) {
                console.warn('[ContentRenderer-Links] Skipping section due to invalid format:', sectionData);
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
                        console.warn('[ContentRenderer-Links] Skipping link due to missing data:', linkData);
                        return;
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
     */
    manager.contentPreview.renderSocials = function(socialsArray, previewEl, contentWrapperEl) {
        if (!previewEl || !contentWrapperEl) {
            console.error('[ContentRenderer-Socials] renderSocials called without previewEl or contentWrapperEl.');
            return;
        }

        let socialsContainer = contentWrapperEl.querySelector(PREVIEW_SOCIALS_CONTAINER_SELECTOR); // Directly query for the container
        
        // If container doesn't exist, create and append it
        if (!socialsContainer) {
            // console.log('[ContentRenderer-Socials] Socials container not found, creating it.'); // Optional log for debugging creation
            socialsContainer = document.createElement('div');
            socialsContainer.className = PREVIEW_SOCIALS_CONTAINER_SELECTOR.substring(1); // remove leading dot for class name
            
            // Determine where to insert the new socials container
            // Ideally, after bio, before first link section title, or before powered-by footer
            let insertBeforeTarget = contentWrapperEl.querySelector('.extrch-link-page-section-title');
            if (!insertBeforeTarget) {
                insertBeforeTarget = contentWrapperEl.querySelector('.extrch-link-page-links'); // Use the main links container as a fallback landmark
            }
            if (!insertBeforeTarget) {
                insertBeforeTarget = contentWrapperEl.querySelector('.extrch-link-page-powered'); // Powered by footer
            }

            if (insertBeforeTarget) {
                contentWrapperEl.insertBefore(socialsContainer, insertBeforeTarget);
            } else {
                // Fallback: append to content wrapper if no other landmarks found (e.g. empty page)
                contentWrapperEl.appendChild(socialsContainer);
            }
        }

        // Clear existing social icons from the (now guaranteed to exist) container
        socialsContainer.innerHTML = '';

        if (!Array.isArray(socialsArray) || socialsArray.length === 0) {
            // If array is empty, container is already cleared. We can hide it if desired, or leave it empty.
            // For now, leave it empty. If it should be hidden: socialsContainer.style.display = 'none';
            return;
        }
        // socialsContainer.style.display = ''; // Ensure visible if previously hidden

        socialsArray.forEach(socialData => {
            if (!socialData || !socialData.url || !socialData.type) {
                console.warn('[ContentRenderer-Socials] Skipping social icon due to missing data (url or type):', socialData);
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
                console.warn(`[ContentRenderer-Socials] Icon class not found for social type: ${socialData.type}. Using a default.`);
                iconClass = 'fas fa-globe'; // Generic default icon
            }
            
            iconElement.className = iconClass;
            iconElement.setAttribute('aria-hidden', 'true');

            linkElement.appendChild(iconElement);
            socialsContainer.appendChild(linkElement);
        });
    };

    /**
     * Updates the display title in the live preview.
     * @param {string} newTitle The new title text.
     * @param {HTMLElement} previewEl The main preview container element.
     */
    manager.contentPreview.updatePreviewTitle = function(newTitle, previewEl) {
        if (!previewEl) {
            console.error('[ContentRenderer-Info] updatePreviewTitle called without previewEl.');
            return;
        }
        const titleElement = previewEl.querySelector(PREVIEW_TITLE_SELECTOR);
        if (titleElement) {
            titleElement.textContent = newTitle;
        } else {
            console.warn('[ContentRenderer-Info] Title element (' + PREVIEW_TITLE_SELECTOR + ') not found in preview DOM.');
        }
    };

    /**
     * Updates the bio text in the live preview.
     * @param {string} newBio The new bio text.
     * @param {HTMLElement} previewEl The main preview container element.
     */
    manager.contentPreview.updatePreviewBio = function(newBio, previewEl) {
        if (!previewEl) {
            console.error('[ContentRenderer-Info] updatePreviewBio called without previewEl.');
            return;
        }
        const bioElement = previewEl.querySelector(PREVIEW_BIO_SELECTOR);
        if (bioElement) {
            bioElement.textContent = newBio; // Using textContent to prevent HTML injection if bio contains it
        } else {
            console.warn('[ContentRenderer-Info] Bio element (' + PREVIEW_BIO_SELECTOR + ') not found in preview DOM.');
        }
    };

    /**
     * Ensures the profile image container and <img> exist in the preview DOM, creating them if missing.
     * Returns the <img> element.
     */
    function ensureProfileImageContainer(previewEl) {
        if (!previewEl) return null;
        let container = previewEl.querySelector(PROFILE_IMAGE_CONTAINER_SELECTOR);
        if (!container) {
            container = document.createElement('div');
            container.className = 'extrch-link-page-profile-img';
            // Insert at the top of the preview, or after the title if present
            const title = previewEl.querySelector('.extrch-link-page-title');
            if (title && title.nextSibling) {
                previewEl.insertBefore(container, title.nextSibling);
            } else {
                previewEl.insertBefore(container, previewEl.firstChild);
            }
        }
        let img = container.querySelector('img');
        if (!img) {
            img = document.createElement('img');
            img.alt = 'Profile Image';
            img.style.display = 'none';
            container.appendChild(img);
        }
        return img;
    }

    /**
     * Updates the profile image in the live preview.
     * @param {string} newImageUrl The new image URL (can be a data URL).
     * @param {HTMLElement} previewEl The main preview container element.
     */
    manager.contentPreview.updatePreviewProfileImage = function(newImageUrl, previewEl) {
        if (!previewEl) {
            console.error('[ContentRenderer-Info] updatePreviewProfileImage called without previewEl.');
            return;
        }
        // Ensure container and img exist
        const imageElement = ensureProfileImageContainer(previewEl);
        const imageContainer = imageElement ? imageElement.parentElement : null;
        if (imageElement) {
            imageElement.src = newImageUrl;
            imageElement.style.display = newImageUrl ? 'block' : 'none';
            if (imageContainer) {
                if (newImageUrl) {
                    imageContainer.classList.remove('no-image');
                    imageContainer.style.display = 'block';
                } else {
                    imageContainer.classList.add('no-image');
                    imageContainer.style.display = '';
                }
            }
        } else {
            console.warn('[ContentRenderer-Info] Could not ensure profile image element in preview DOM.');
        }
    };

    /**
     * Removes the profile image from the live preview (or sets to a default/placeholder if applicable).
     * @param {HTMLElement} previewEl The main preview container element.
     */
    manager.contentPreview.removePreviewProfileImage = function(previewEl) {
        if (!previewEl) {
            console.error('[ContentRenderer-Info] removePreviewProfileImage called without previewEl.');
            return;
        }
        // Ensure container and img exist
        const imageElement = ensureProfileImageContainer(previewEl);
        const imageContainer = imageElement ? imageElement.parentElement : null;
        if (imageElement) {
            imageElement.src = '';
            imageElement.style.display = 'none';
            if (imageContainer) {
                imageContainer.classList.add('no-image');
                imageContainer.style.display = '';
            }
        } else {
            console.warn('[ContentRenderer-Info] Could not ensure profile image element in preview DOM for removal.');
        }
    };

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}); 