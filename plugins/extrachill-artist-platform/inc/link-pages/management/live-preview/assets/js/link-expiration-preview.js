// Link Expiration Preview Module
// Handles visual states for expiring/expired links in the live preview

(function() {
    'use strict';
    
    /**
     * Check if a link is expired based on its expiration date
     * @param {string} expiresAt - ISO datetime string
     * @returns {boolean} True if link is expired
     */
    function isLinkExpired(expiresAt) {
        if (!expiresAt) return false;
        
        const expirationDate = new Date(expiresAt);
        const now = new Date();
        
        return expirationDate <= now;
    }
    
    /**
     * Check if a link is near expiration (within 24 hours)
     * @param {string} expiresAt - ISO datetime string
     * @returns {boolean} True if link expires within 24 hours
     */
    function isLinkNearExpiration(expiresAt) {
        if (!expiresAt) return false;
        
        const expirationDate = new Date(expiresAt);
        const now = new Date();
        const twentyFourHoursFromNow = new Date(now.getTime() + (24 * 60 * 60 * 1000));
        
        return expirationDate <= twentyFourHoursFromNow && expirationDate > now;
    }
    
    /**
     * Apply expiration visual state to a preview link element
     * @param {HTMLElement} previewLinkEl - Preview link element
     * @param {string} expiresAt - Expiration datetime string
     */
    function applyExpirationState(previewLinkEl, expiresAt) {
        if (!previewLinkEl) return;
        
        // Remove existing expiration classes
        previewLinkEl.classList.remove('extrch-link-expired', 'extrch-link-near-expiry');
        
        if (!expiresAt) return;
        
        if (isLinkExpired(expiresAt)) {
            previewLinkEl.classList.add('extrch-link-expired');
            previewLinkEl.setAttribute('title', 'This link has expired');
        } else if (isLinkNearExpiration(expiresAt)) {
            previewLinkEl.classList.add('extrch-link-near-expiry');
            const expirationDate = new Date(expiresAt);
            previewLinkEl.setAttribute('title', `This link expires on ${expirationDate.toLocaleDateString()}`);
        }
    }
    
    /**
     * Update expiration states for all links in the preview
     */
    function updateAllExpirationStates() {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
        const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        if (!previewEl) return;
        
        // Find all link elements in preview
        const previewLinks = previewEl.querySelectorAll('.extrch-link-page-link');
        
        previewLinks.forEach(previewLinkEl => {
            // Try to find corresponding management link to get expiration data
            const linkText = previewLinkEl.textContent?.trim();
            const linkUrl = previewLinkEl.href;
            
            if (linkText && linkUrl) {
                const managementLink = findManagementLinkByData(linkText, linkUrl);
                if (managementLink) {
                    const expiresAt = managementLink.dataset.expiresAt || '';
                    applyExpirationState(previewLinkEl, expiresAt);
                }
            }
        });
    }
    
    /**
     * Find management link element by text and URL
     * @param {string} linkText - Link text to match
     * @param {string} linkUrl - Link URL to match
     * @returns {HTMLElement|null} Management link element or null
     */
    function findManagementLinkByData(linkText, linkUrl) {
        const managementLinks = document.querySelectorAll('.bp-link-item');
        
        for (const managementLink of managementLinks) {
            const textInput = managementLink.querySelector('.bp-link-text-input');
            const urlInput = managementLink.querySelector('.bp-link-url-input');
            
            if (textInput && urlInput) {
                const managementText = textInput.value?.trim();
                const managementUrl = urlInput.value?.trim();
                
                if (managementText === linkText && managementUrl === linkUrl) {
                    return managementLink;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Handle specific link expiration updates
     * @param {Object} eventDetail - Event detail with link element and expiration data
     */
    function handleSingleLinkExpirationUpdate(eventDetail) {
        if (!eventDetail || !eventDetail.linkElement) return;
        
        const managementLink = eventDetail.linkElement;
        const textInput = managementLink.querySelector('.bp-link-text-input');
        const urlInput = managementLink.querySelector('.bp-link-url-input');
        
        if (!textInput || !urlInput) return;
        
        const linkText = textInput.value?.trim();
        const linkUrl = urlInput.value?.trim();
        const expiresAt = eventDetail.newValue || '';
        
        if (!linkText || !linkUrl) return;
        
        // Find corresponding preview link
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
        const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        if (!previewEl) return;
        
        const previewLinks = previewEl.querySelectorAll('.extrch-link-page-link');
        
        for (const previewLink of previewLinks) {
            const previewText = previewLink.textContent?.trim();
            const previewUrl = previewLink.href;
            
            if (previewText === linkText && previewUrl === linkUrl) {
                applyExpirationState(previewLink, expiresAt);
                break;
            }
        }
    }
    
    /**
     * Initialize event listeners
     */
    function initializeEventListeners() {
        // Listen for individual link expiration updates
        document.addEventListener('linkExpirationUpdated', function(e) {
            if (e.detail) {
                handleSingleLinkExpirationUpdate(e.detail);
            }
        });
        
        // Listen for general link updates that might affect expiration states
        document.addEventListener('linksUpdated', function() {
            // Debounce to avoid excessive updates
            setTimeout(updateAllExpirationStates, 300);
        });
        
        // Refresh expiration states periodically (every minute)
        setInterval(updateAllExpirationStates, 60000);
    }
    
    /**
     * Initialize the link expiration preview module
     */
    function init() {
        initializeEventListeners();
        
        // Initial update of expiration states
        setTimeout(updateAllExpirationStates, 1000);
        
        console.log('[Link Expiration Preview] Module initialized successfully');
    }
    
    // Self-contained module - no global exposure needed
    
    // Auto-initialize when DOM is ready
    if (document.readyState !== 'loading') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }

})();