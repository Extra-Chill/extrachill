// Shared Utilities - Essential DOM and utility functions used across modules
window.ExtrchLinkPageSharedUtils = window.ExtrchLinkPageSharedUtils || {};

// --- Function to get the preview container element ---
ExtrchLinkPageSharedUtils.getPreviewEl = function() {
    const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
    if (previewContainerParent) {
        const previewContainer = previewContainerParent.querySelector('.extrch-link-page-preview-container');
        if (previewContainer) {
            return previewContainer;
        }
        return null;
    }
    return null;
};

// --- Function to get the preview content wrapper element ---
ExtrchLinkPageSharedUtils.getPreviewContentWrapperEl = function() {
    const previewEl = ExtrchLinkPageSharedUtils.getPreviewEl();
    if (previewEl) {
        const contentWrapper = previewEl.querySelector('.extrch-link-page-content-wrapper');
        if (contentWrapper) {
            return contentWrapper;
        }
        return null;
    }
    return null;
};

// --- Debounce utility function ---
ExtrchLinkPageSharedUtils.debounce = function(func, delay) {
    let timeout;
    return function(...args) {
        const context = this;
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(context, args), delay);
    };
};

// --- Get initial config data ---
ExtrchLinkPageSharedUtils.getInitialData = function() {
    return window.extrchLinkPageConfig || {};
};