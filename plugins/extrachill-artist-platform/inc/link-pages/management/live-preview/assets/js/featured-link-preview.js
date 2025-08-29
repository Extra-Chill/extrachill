// Featured Link Preview Module - Handles live preview updates for featured links
(function(manager) {
    if (!manager) return;
    
    manager.featuredLinkPreview = manager.featuredLinkPreview || {};
    
    // Track URL to skip during preview to avoid duplicates
    let urlToSkipForPreview = null;
    
    // Main featured link preview update function - Direct DOM manipulation
    function updatePreviewFeaturedLink(featuredLinkData, previewEl, contentWrapper) {
        if (!previewEl || !contentWrapper) return;

        // Find or create featured link container
        let featuredLinkContainer = previewEl.querySelector('.extrch-featured-link-container');
        
        // If not active, remove featured link
        if (!featuredLinkData || !featuredLinkData.isActive) {
            if (featuredLinkContainer) {
                featuredLinkContainer.remove();
            }
            return;
        }

        // Create featured link container if it doesn't exist
        if (!featuredLinkContainer) {
            featuredLinkContainer = document.createElement('div');
            featuredLinkContainer.className = 'extrch-featured-link-container';
            // Insert at the beginning of content wrapper
            contentWrapper.insertBefore(featuredLinkContainer, contentWrapper.firstChild);
        }

        // Build featured link HTML
        let featuredHTML = '<div class="extrch-featured-link">';
        
        // Add thumbnail if available
        if (featuredLinkData.thumbnailUrl && featuredLinkData.thumbnailUrl.trim() !== '') {
            featuredHTML += `<div class="extrch-featured-link-thumbnail">
                <img src="${escapeHtml(featuredLinkData.thumbnailUrl)}" alt="Featured Link Thumbnail" />
            </div>`;
        }
        
        // Add content section
        featuredHTML += '<div class="extrch-featured-link-content">';
        
        // Add title
        if (featuredLinkData.title) {
            featuredHTML += `<h3 class="extrch-featured-link-title">${escapeHtml(featuredLinkData.title)}</h3>`;
        }
        
        // Add description
        if (featuredLinkData.description) {
            featuredHTML += `<p class="extrch-featured-link-description">${escapeHtml(featuredLinkData.description)}</p>`;
        }
        
        // Add URL
        if (featuredLinkData.originalLinkUrl) {
            featuredHTML += `<a href="${escapeHtml(featuredLinkData.originalLinkUrl)}" class="extrch-featured-link-url" target="_blank" rel="noopener noreferrer">
                ${escapeHtml(featuredLinkData.originalLinkUrl)}
            </a>`;
        }
        
        featuredHTML += '</div></div>';
        
        // Update container content
        featuredLinkContainer.innerHTML = featuredHTML;
    }
    
    // Clear featured link from preview
    function clearPreviewFeaturedLink(previewEl) {
        if (!previewEl) return;
        
        const featuredLinkContainer = previewEl.querySelector('.extrch-featured-link-container');
        if (featuredLinkContainer) {
            featuredLinkContainer.remove();
        }
    }
    
    // Set URL to skip for preview (to avoid duplicates in links list)
    function setFeaturedLinkUrlToSkipForPreview(url) {
        urlToSkipForPreview = url;
        
        // Emit event to inform links module to skip this URL
        document.dispatchEvent(new CustomEvent('featuredLinkUrlToSkip', {
            detail: { url: url }
        }));
    }
    
    // Get current URL to skip
    function getFeaturedLinkUrlToSkip() {
        return urlToSkipForPreview;
    }
    
    // Helper function to escape HTML
    function escapeHtml(text) {
        if (typeof text !== 'string') return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Event listeners for featured link updates from management forms
    document.addEventListener('featuredLinkChanged', function(e) {
        if (e.detail) {
            const previewEl = manager.getPreviewEl ? manager.getPreviewEl() : null;
            const contentWrapper = previewEl ? previewEl.querySelector('.extrch-link-page-content-wrapper') : null;
            
            if (previewEl && contentWrapper) {
                updatePreviewFeaturedLink(e.detail.featuredLink, previewEl, contentWrapper);
                
                // Set URL to skip if provided
                if (e.detail.featuredLink && e.detail.featuredLink.originalLinkUrl) {
                    setFeaturedLinkUrlToSkipForPreview(e.detail.featuredLink.originalLinkUrl);
                }
            }
        }
    });
    
    document.addEventListener('featuredLinkCleared', function(e) {
        const previewEl = manager.getPreviewEl ? manager.getPreviewEl() : null;
        if (previewEl) {
            clearPreviewFeaturedLink(previewEl);
            setFeaturedLinkUrlToSkipForPreview(null);
        }
    });

    // Expose functions on manager
    manager.featuredLinkPreview.updatePreviewFeaturedLink = updatePreviewFeaturedLink;
    manager.featuredLinkPreview.clearPreviewFeaturedLink = clearPreviewFeaturedLink;
    manager.featuredLinkPreview.setFeaturedLinkUrlToSkipForPreview = setFeaturedLinkUrlToSkipForPreview;
    manager.featuredLinkPreview.getFeaturedLinkUrlToSkip = getFeaturedLinkUrlToSkip;

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});