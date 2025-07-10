window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}; 

// Provide a canonical method for all modules to get initial data from PHP
ExtrchLinkPageManager.getInitialData = function() {
    return window.extrchLinkPageConfig || {};
};

// Check for the localized config and dispatch an event when ready
(function() {
    const checkConfig = () => {
        if (window.extrchLinkPageConfig && window.extrchLinkPageConfig.supportedLinkTypes && Object.keys(window.extrchLinkPageConfig.supportedLinkTypes).length > 0) {
            // Dispatch a custom event indicating the config is ready
            document.dispatchEvent(new CustomEvent('extrchLinkPageConfigReady', { detail: window.extrchLinkPageConfig }));
        } else {
            // Re-check after a short delay if config isn't ready
            setTimeout(checkConfig, 10); // Check more frequently
        }
    };
    // Start the check
    checkConfig();
})(); 

// Track management activity for edit button fallback logic
function trackManagementActivity() {
    const urlParams = new URLSearchParams(window.location.search);
    const bandId = urlParams.get('band_id');
    
    if (bandId && window.location.pathname.includes('/manage-link-page')) {
        try {
            localStorage.setItem('extrch_recent_band_management', JSON.stringify({
                band_id: bandId,
                timestamp: Date.now(),
                action: 'manage_link_page'
            }));
            console.log('[Management Track] Stored recent management activity for band:', bandId);
        } catch (e) {
            console.warn('[Management Track] Could not store management activity:', e);
        }
    }
}

// Ensure window.extrchLinkPageConfig is set before dispatching the event
if (typeof window.extrchLinkPageConfig === 'undefined') {
    // This indicates an issue with how the config is being passed from PHP
} else {
    // Dispatch a custom event indicating the config is ready

    // Dispatch the event after DOMContentLoaded to ensure listeners are ready
    document.addEventListener('DOMContentLoaded', function() {
        // Track management activity for edit button fallback
        trackManagementActivity();
        
        // Use a small timeout to ensure manage-link-page.js's DOMContentLoaded listener runs first
        setTimeout(() => {
            document.dispatchEvent(new CustomEvent('extrchLinkPageConfigReady', { detail: window.extrchLinkPageConfig }));
        }, 0); // Use 0 for microtask timing after DOMContentLoaded listeners
    });
} 