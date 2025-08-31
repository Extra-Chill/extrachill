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
    
    // Main socials preview update function - Using template system
    function updateSocialsPreview(socialsData, position) {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live'); const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        if (!previewEl) return;

        // Find or create the socials container in the preview
        let socialsContainer = previewEl.querySelector('.extrch-link-page-socials');
        const contentWrapper = previewEl.querySelector('.extrch-link-page-content-wrapper');
        
        if (!contentWrapper) return;

        // Remove existing socials container if it exists
        if (socialsContainer) {
            socialsContainer.remove();
        }

        // If no socials data, just remove and return
        if (!socialsData || socialsData.length === 0) {
            return;
        }

        // Create new socials container
        socialsContainer = document.createElement('div');
        socialsContainer.className = 'extrch-link-page-socials';
        
        // Add position class if needed
        if (position === 'below') {
            socialsContainer.classList.add('extrch-socials-below');
        }
        
        // Create social icons using template system
        Promise.all(
            socialsData
                .filter(social => social.type && social.url)
                .map(social => renderSocialTemplate(social.type, social.url))
        ).then(htmlResults => {
            htmlResults.forEach(html => {
                if (html) {
                    socialsContainer.insertAdjacentHTML('beforeend', html);
                }
            });
        }).catch(error => {
            console.error('Error rendering social icons:', error);
        });

        // Position the socials container based on position setting
        if (position === 'below') {
            // Insert after links but before powered-by section within content wrapper
            const poweredByEl = contentWrapper.querySelector('.extrch-link-page-powered');
            if (poweredByEl) {
                contentWrapper.insertBefore(socialsContainer, poweredByEl);
            } else {
                // Fallback: append to end of content wrapper
                contentWrapper.appendChild(socialsContainer);
            }
        } else {
            // Insert after header content but before links (default: above)
            const headerContent = contentWrapper.querySelector('.extrch-link-page-header-content');
            if (headerContent) {
                contentWrapper.insertBefore(socialsContainer, headerContent.nextSibling);
            } else {
                // Fallback: prepend to content wrapper
                contentWrapper.insertBefore(socialsContainer, contentWrapper.firstChild);
            }
        }
    }

    // Social icon rendering now handled by PHP templates via AJAX

    // Event listeners for socials updates from management forms
    document.addEventListener('socialIconsChanged', function(e) {
        if (e.detail && e.detail.socials !== undefined) {
            const position = e.detail.position || 'above';
            updateSocialsPreview(e.detail.socials, position);
        }
    });

    // Event listener for position changes
    document.addEventListener('socialIconsPositionChanged', function(e) {
        if (e.detail && e.detail.position && e.detail.socials) {
            updateSocialsPreview(e.detail.socials, e.detail.position);
        }
    });

    // Self-contained module - no global exposure needed

    // Initialize preview with centralized data on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Preview initializes from DOM events, not initial data
    });

})();