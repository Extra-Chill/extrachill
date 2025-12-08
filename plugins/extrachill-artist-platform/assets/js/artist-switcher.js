/**
 * Artist Switcher Module - Shared Component
 * 
 * Handles artist switching functionality across all management pages.
 * Supports both switcher variants:
 * - link-page-artist-switcher-select (Link Page Management)
 * - artist-switcher-select (Artist Profile Management)
 */
(function() {
    'use strict';
    
    /**
     * Initialize artist switcher functionality
     */
    function initArtistSwitchers() {
        // Find all artist switcher elements by class
        const switchers = document.querySelectorAll('.artist-switcher-select');
        
        switchers.forEach(function(switcher) {
            switcher.addEventListener('change', function() {
                if (this.value) {
                    handleArtistSwitch(this);
                }
            });
        });
    }
    
    /**
     * Handle artist switch navigation
     * @param {HTMLSelectElement} selectElement The switcher select element
     */
    function handleArtistSwitch(selectElement) {
        const artistId = selectElement.value;
        const baseUrl = selectElement.dataset.baseUrl || getCurrentPageUrl();
        
        if (!artistId || !baseUrl) {
            console.warn('Artist switcher: Missing artist ID or base URL');
            return;
        }
        
        // Build redirect URL with artist_id parameter
        const separator = baseUrl.includes('?') ? '&' : '?';
        const redirectUrl = baseUrl + separator + 'artist_id=' + encodeURIComponent(artistId);
        
        // Navigate to the selected artist's management page
        window.location.href = redirectUrl;
    }
    
    /**
     * Get current page URL as fallback
     * @returns {string} Current page URL without query parameters
     */
    function getCurrentPageUrl() {
        const location = window.location;
        return location.protocol + '//' + location.host + location.pathname;
    }
    
    /**
     * Initialize when DOM is ready
     */
    function initialize() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initArtistSwitchers);
        } else {
            initArtistSwitchers();
        }
    }
    
    // Start initialization
    initialize();
    
})();