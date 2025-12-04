// Socials Preview Module - Handles live preview updates for social icons
(function() {
    'use strict';
    
    // Template rendering function using AJAX
    async function renderSocialTemplate(socialType, socialUrl) {
        if (!extraChillArtistPlatform?.ajaxUrl) {
            console.error('AJAX URL not available for social template rendering');
            return null;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'render_social_template');
            formData.append('nonce', extraChillArtistPlatform.nonce || '');
            formData.append('social_type', socialType);
            formData.append('social_url', socialUrl);

            const response = await fetch(extraChillArtistPlatform.ajaxUrl, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            
            if (data.success && data.data.html) {
                return data.data.html;
            } else {
                console.error('Social template rendering failed:', data.data?.message || 'Unknown error');
                return null;
            }
        } catch (error) {
            console.error('Error rendering social template:', error);
            return null;
        }
    }
    
    // Legacy bulk update function - for backward compatibility with socialIconsChanged event
    async function updateSocialsPreview(socialsData, position) {
        const previewEl = document.querySelector('.manage-link-page-preview-live .extrch-link-page-preview-container');
        if (!previewEl) return;

        const contentWrapper = previewEl.querySelector('.extrch-link-page-content-wrapper');
        if (!contentWrapper) return;

        // Remove existing container
        const existingContainer = previewEl.querySelector('.extrch-link-page-socials');
        if (existingContainer) {
            existingContainer.remove();
        }

        // If no socials data, just return (container already removed)
        if (!socialsData || socialsData.length === 0) {
            return;
        }

        // Create new container
        const socialsContainer = document.createElement('div');
        socialsContainer.className = 'extrch-link-page-socials';
        
        // Position the container
        insertSocialContainerAtPosition(socialsContainer, contentWrapper, position);

        // Render all social icons
        const validSocials = socialsData.filter(social => social.type && social.url);
        const htmlResults = await Promise.all(
            validSocials.map(social => renderSocialTemplate(social.type, social.url))
        );

        const validHtml = htmlResults.filter(html => html);
        if (validHtml.length > 0) {
            socialsContainer.innerHTML = validHtml.join('');
        }
    }

    // Granular preview update functions
    async function addSocialIconToPreview(socialData) {
        const previewEl = document.querySelector('.manage-link-page-preview-live .extrch-link-page-preview-container');
        if (!previewEl) return;

        let socialsContainer = previewEl.querySelector('.extrch-link-page-socials');
        
        // Create container if it doesn't exist
        if (!socialsContainer) {
            socialsContainer = document.createElement('div');
            socialsContainer.className = 'extrch-link-page-socials';
            
            // Position based on current position setting
            const position = getCurrentSocialIconsPosition();
            const contentWrapper = previewEl.querySelector('.extrch-link-page-content-wrapper');
            if (contentWrapper) {
                insertSocialContainerAtPosition(socialsContainer, contentWrapper, position);
            }
        }

        // Render and append the new social icon
        const html = await renderSocialTemplate(socialData.type, socialData.url);
        if (html) {
            socialsContainer.insertAdjacentHTML('beforeend', html);
        }
    }

    function removeSocialIconFromPreview(socialData, index) {
        const previewEl = document.querySelector('.manage-link-page-preview-live .extrch-link-page-preview-container');
        if (!previewEl) return;

        const socialsContainer = previewEl.querySelector('.extrch-link-page-socials');
        if (!socialsContainer) return;

        // Remove by index position (more reliable than URL matching)
        const socialIcons = socialsContainer.querySelectorAll('a');
        if (typeof index === 'number' && index >= 0 && socialIcons[index]) {
            socialIcons[index].remove();
        }

        // Remove container if empty
        if (socialsContainer.children.length === 0) {
            socialsContainer.remove();
        }
    }

    async function reorderSocialIconsInPreview(socialsData) {
        const previewEl = document.querySelector('.manage-link-page-preview-live .extrch-link-page-preview-container');
        if (!previewEl) return;

        const socialsContainer = previewEl.querySelector('.extrch-link-page-socials');
        if (!socialsContainer) return;

        // Re-render all social icons in new order
        const validSocials = socialsData.filter(social => social.type && social.url);
        const htmlResults = await Promise.all(
            validSocials.map(social => renderSocialTemplate(social.type, social.url))
        );

        const validHtml = htmlResults.filter(html => html);
        if (validHtml.length > 0) {
            socialsContainer.innerHTML = validHtml.join('');
        }
    }

    function repositionSocialIconsContainer(position) {
        const previewEl = document.querySelector('.manage-link-page-preview-live .extrch-link-page-preview-container');
        if (!previewEl) return;

        const socialsContainer = previewEl.querySelector('.extrch-link-page-socials');
        const contentWrapper = previewEl.querySelector('.extrch-link-page-content-wrapper');
        
        if (!socialsContainer || !contentWrapper) return;

        // Remove from current position
        socialsContainer.remove();
        
        // Re-insert at correct position
        insertSocialContainerAtPosition(socialsContainer, contentWrapper, position);
    }

    // Helper function to get current position setting
    function getCurrentSocialIconsPosition() {
        const checkedRadio = document.querySelector('input[name="link_page_social_icons_position"]:checked');
        return checkedRadio ? checkedRadio.value : 'above';
    }

    // Helper function to insert container at correct position
    function insertSocialContainerAtPosition(socialsContainer, contentWrapper, position) {
        // Update CSS class
        if (position === 'below') {
            socialsContainer.classList.add('extrch-socials-below');
            // Insert after links but before powered-by section
            const poweredByEl = contentWrapper.querySelector('.extrch-link-page-powered');
            if (poweredByEl) {
                contentWrapper.insertBefore(socialsContainer, poweredByEl);
            } else {
                contentWrapper.appendChild(socialsContainer);
            }
        } else {
            socialsContainer.classList.remove('extrch-socials-below');
            // Insert after header content but before links (above)
            const headerContent = contentWrapper.querySelector('.extrch-link-page-header-content');
            if (headerContent) {
                contentWrapper.insertBefore(socialsContainer, headerContent.nextSibling);
            } else {
                contentWrapper.insertBefore(socialsContainer, contentWrapper.firstChild);
            }
        }
    }

    // Event listeners for granular social icon updates
    document.addEventListener('socialIconAdded', function(e) {
        if (e.detail && e.detail.socialData && e.detail.socialData.type && e.detail.socialData.url) {
            addSocialIconToPreview(e.detail.socialData);
        }
    });

    document.addEventListener('socialIconDeleted', function(e) {
        if (e.detail && e.detail.socialData) {
            removeSocialIconFromPreview(e.detail.socialData, e.detail.index);
        }
    });

    document.addEventListener('socialIconsMoved', function(e) {
        if (e.detail && e.detail.socials) {
            reorderSocialIconsInPreview(e.detail.socials);
        }
    });

    document.addEventListener('socialIconsPositionChanged', function(e) {
        if (e.detail && e.detail.position) {
            repositionSocialIconsContainer(e.detail.position);
        }
    });

    // Legacy event listeners for backward compatibility
    document.addEventListener('socialIconsChanged', function(e) {
        if (e.detail && e.detail.socials !== undefined) {
            const position = e.detail.position || 'above';
            updateSocialsPreview(e.detail.socials, position);
        }
    });

    // Self-contained module - no global exposure needed

    // Initialize preview with centralized data on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Preview initializes from DOM events, not initial data
    });

})();