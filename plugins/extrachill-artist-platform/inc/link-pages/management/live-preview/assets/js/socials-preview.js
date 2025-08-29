// Socials Preview Module - Handles live preview updates for social icons
(function(manager, config) {
    if (!manager) return;
    
    manager.socialsPreview = manager.socialsPreview || {};
    
    // Main socials preview update function - Direct DOM manipulation
    function updateSocialsPreview(socialsData, position) {
        const previewEl = manager.getPreviewEl ? manager.getPreviewEl() : null;
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
        
        // Create social icons HTML
        socialsData.forEach(social => {
            if (social.type && social.url) {
                const socialLink = document.createElement('a');
                socialLink.href = social.url;
                socialLink.className = 'extrch-social-icon';
                socialLink.setAttribute('data-type', social.type);
                socialLink.target = '_blank';
                socialLink.rel = 'noopener noreferrer';
                
                // Create icon element
                const iconElement = document.createElement('i');
                iconElement.className = getSocialIconClass(social.type);
                socialLink.appendChild(iconElement);
                
                socialsContainer.appendChild(socialLink);
            }
        });

        // Position the socials container based on position setting
        if (position === 'below') {
            // Insert after content wrapper
            contentWrapper.parentNode.insertBefore(socialsContainer, contentWrapper.nextSibling);
        } else {
            // Insert before content wrapper (default: above)
            contentWrapper.parentNode.insertBefore(socialsContainer, contentWrapper);
        }
    }

    // Helper function to get appropriate icon class for social type
    // Uses the PHP filter data passed via config instead of duplicating
    function getSocialIconClass(type) {
        const supportedTypes = (config && config.supportedLinkTypes) ? config.supportedLinkTypes : {};
        
        if (supportedTypes[type] && supportedTypes[type].icon) {
            return supportedTypes[type].icon;
        }
        
        // Fallback for any missing types
        return 'fas fa-globe';
    }

    // Event listeners for socials updates from management forms
    document.addEventListener('socialIconsChanged', function(e) {
        if (e.detail && e.detail.socials !== undefined) {
            const position = e.detail.position || 'above';
            updateSocialsPreview(e.detail.socials, position);
        }
    });

    // Event listener for position changes
    document.addEventListener('socialIconsPositionChanged', function(e) {
        if (e.detail && e.detail.position) {
            // Get current socials data from management module if available
            let socialsData = [];
            if (manager.socialIcons && typeof manager.socialIcons.getSocialsDataFromDOM === 'function') {
                socialsData = manager.socialIcons.getSocialsDataFromDOM();
            }
            updateSocialsPreview(socialsData, e.detail.position);
        }
    });

    // Expose functions on manager
    manager.socialsPreview.update = updateSocialsPreview;

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}, window.extrchLinkPageConfig);