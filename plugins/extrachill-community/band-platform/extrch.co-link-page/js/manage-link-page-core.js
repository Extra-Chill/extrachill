window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}; 

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

// Ensure window.extrchLinkPageConfig is set before dispatching the event
if (typeof window.extrchLinkPageConfig === 'undefined') {
    // This indicates an issue with how the config is being passed from PHP
    console.error('[Core] window.extrchLinkPageConfig is not defined.');
} else {
    console.log('[Core] extrchLinkPageConfig is ready.');
    // Dispatch a custom event indicating the config is ready

    // Dispatch the event after DOMContentLoaded to ensure listeners are ready
    document.addEventListener('DOMContentLoaded', function() {
        // Use a small timeout to ensure manage-link-page.js's DOMContentLoaded listener runs first
        setTimeout(() => {
            document.dispatchEvent(new CustomEvent('extrchLinkPageConfigReady', { detail: window.extrchLinkPageConfig }));
            console.log('[Core] extrchLinkPageConfigReady event dispatched after DOMContentLoaded (async).');
        }, 0); // Use 0 for microtask timing after DOMContentLoaded listeners
    });
} 