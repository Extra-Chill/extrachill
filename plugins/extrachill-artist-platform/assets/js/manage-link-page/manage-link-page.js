window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {};

// --- Function to get the preview container element ---
ExtrchLinkPageManager.getPreviewEl = function() {
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
ExtrchLinkPageManager.getPreviewContentWrapperEl = function() {
    const previewEl = ExtrchLinkPageManager.getPreviewEl();
    if (previewEl) {
        const contentWrapper = previewEl.querySelector('.extrch-link-page-content-wrapper');
        if (contentWrapper) {
            return contentWrapper;
        }
        // console.warn('[ExtrchLinkPageManager] Preview content wrapper element not found.');
        return null;
    }
    // console.warn('[ExtrchLinkPageManager] Preview element not found when trying to get content wrapper.');
    return null;
};

// --- Debounce function ---
function debounce(func, delay) {
    let timeout;
    return function(...args) {
        const context = this;
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(context, args), delay);
    };
}

// ----- Jump to Preview Button Logic -----
ExtrchLinkPageManager.initializeJumpToPreview = function() {
    const jumpButton = document.getElementById('extrch-jump-to-preview-btn');
    const previewElement = document.querySelector('.manage-link-page-preview-live');
    const mobileBreakpoint = 768; // Screen width in pixels

    if (!jumpButton || !previewElement) return;

    const mainIconElement = jumpButton.querySelector('.main-icon-wrapper i');
    const arrowIconElement = jumpButton.querySelector('.directional-arrow');

    if (!mainIconElement || !arrowIconElement) {
        console.warn('Jump to preview button is missing main or arrow icon elements.');
        return;
    }

    let isPreviewVisible = false;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            isPreviewVisible = entry.isIntersecting;
            toggleButtonState(); 
        });
    }, { threshold: 0.1 });

    observer.observe(previewElement);

    function getActiveSettingsElement() {
        // UPDATED SELECTORS to use shared classes
        let activeTab = document.querySelector('.shared-tabs-buttons-container .shared-tab-button.active');
        if (activeTab) return activeTab;

        // Fallback to accordion item if desktop tab not found (or for explicit mobile check)
        // This selector might also need adjustment if the structure of .shared-tab-item is different
        const activeAccordionHeader = document.querySelector('.shared-tab-item .shared-tab-button.active');
        if (activeAccordionHeader) {
            return activeAccordionHeader.closest('.shared-tab-item') || activeAccordionHeader;
        }
        return null; 
    }

    function toggleButtonState() {
        // Use CSS media query instead of JavaScript mobile detection
        const mediaQuery = window.matchMedia('(max-width: 768px)');
        const isMobile = mediaQuery.matches;
        
        if (isMobile) {
            jumpButton.style.display = 'flex'; // Always show on mobile, icons change
            setTimeout(() => jumpButton.classList.add('visible'), 10);

            const previewRect = previewElement.getBoundingClientRect();

            if (isPreviewVisible) {
                // State 1: At least 10% of Preview is visible (IntersectionObserver is true)
                mainIconElement.className = 'fas fa-cog';
                arrowIconElement.className = 'directional-arrow fas fa-arrow-up';
                    arrowIconElement.style.display = 'block';
                jumpButton.title = 'Scroll to Active Settings';
            } else {
                // Preview is less than 10% visible (IntersectionObserver is false)
                // Determine icon based on whether the TOP of the preview is above or below the viewport top.
                if (previewRect.top < 0) {
                    // State 3: Preview is NOT significantly visible, and its TOP is ABOVE the viewport.
                    // User has scrolled down past the beginning of the preview.
                    mainIconElement.className = 'fas fa-magnifying-glass';
                    arrowIconElement.className = 'directional-arrow fas fa-arrow-up';
                    arrowIconElement.style.display = 'block';
                    jumpButton.title = 'Scroll to Live Preview';
                } else {
                    // State 2: Preview is NOT significantly visible, and its TOP is WITHIN or BELOW the viewport.
                    // User is above or at the very start of the preview.
                    mainIconElement.className = 'fas fa-magnifying-glass';
                    arrowIconElement.className = 'directional-arrow fas fa-arrow-down';
                    arrowIconElement.style.display = 'block';
                    jumpButton.title = 'Scroll to Live Preview';
                }
            }
        } else { // Hide on desktop
            jumpButton.classList.remove('visible');
            // Proper handling for transitionend to set display: none
            const handleTransitionEnd = () => {
                if (!jumpButton.classList.contains('visible')) {
                    jumpButton.style.display = 'none';
                    if(arrowIconElement) arrowIconElement.style.display = 'none'; // Hide arrow too
                }
                jumpButton.removeEventListener('transitionend', handleTransitionEnd);
            };
            if (getComputedStyle(jumpButton).transitionProperty !== 'none' && getComputedStyle(jumpButton).transitionDuration !== '0s' && getComputedStyle(jumpButton).opacity !== '0') {
                 jumpButton.addEventListener('transitionend', handleTransitionEnd);
            } else {
                 // If no transition, hide immediately
                 if (!jumpButton.classList.contains('visible')) {
                    jumpButton.style.display = 'none';
                    if(arrowIconElement) arrowIconElement.style.display = 'none'; // Hide arrow too
                 }
            }
        }
    }

    jumpButton.addEventListener('click', () => {
        if (isPreviewVisible) {
            const activeSettings = getActiveSettingsElement();
            let targetScrollElement = null;

            if (activeSettings) {
                targetScrollElement = activeSettings;
            } else {
                // Fallback: No active tab, scroll to the top of the settings area
                targetScrollElement = document.querySelector('.shared-tabs-buttons-container'); // UPDATED SELECTOR
            }

            if (targetScrollElement) {
                let fixedHeaderHeight = 0;
                const adminBar = document.getElementById('wpadminbar');
                if (adminBar && window.getComputedStyle(adminBar).position === 'fixed') {
                    fixedHeaderHeight += adminBar.offsetHeight;
                }
                
                const elementPosition = targetScrollElement.getBoundingClientRect().top;
                const offsetPosition = elementPosition + window.pageYOffset - fixedHeaderHeight;
                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });
            }
        } else {
            if (previewElement) {
                previewElement.scrollIntoView({ behavior: 'smooth' });
            }
        }
    });

    // Initial check and listen for resize
    window.addEventListener('resize', debounce(toggleButtonState, 150));
    // Listen for tab changes to potentially update button state if needed,
    // though viewport check should be primary driver.
    // Example: if a tab change might affect which element is "active settings" when preview is visible.

    toggleButtonState(); // Initial check
};
// ----- End Jump to Preview Button Logic -----

ExtrchLinkPageManager.isInitialized = false;

// --- Main Initialization Orchestrator ---
ExtrchLinkPageManager.init = function() {
    // Initialize Jump to Preview functionality
    if (typeof ExtrchLinkPageManager.initializeJumpToPreview === 'function') {
        ExtrchLinkPageManager.initializeJumpToPreview();
    }

    // Initialize Customization Module
    if (ExtrchLinkPageManager.customization && typeof ExtrchLinkPageManager.customization.init === 'function') {
        ExtrchLinkPageManager.customization.init();
    } else {
        // console.warn('[ExtrchLinkPageManager] Customization module or its init function not found.'); // Keep for now if critical
    }

    // Initialize Sizing Module (must come after customization for correct hydration)
    if (ExtrchLinkPageManager.sizing && typeof ExtrchLinkPageManager.sizing.init === 'function') {
        ExtrchLinkPageManager.sizing.init();
    }

    // Initialize Background Module (ensure correct controls are shown)
    if (ExtrchLinkPageManager.background && typeof ExtrchLinkPageManager.background.init === 'function') {
        ExtrchLinkPageManager.background.init();
    }
    
    // Initialize Links Module (Example - to be created/refactored)
    if (ExtrchLinkPageManager.links && typeof ExtrchLinkPageManager.links.init === 'function') {
        ExtrchLinkPageManager.links.init();
    } else {
        // console.warn('[ExtrchLinkPageManager] Links module or its init function not found.');
    }

    // Initialize Social Icons Module (Example)
    if (ExtrchLinkPageManager.socialIcons && typeof ExtrchLinkPageManager.socialIcons.init === 'function') {
        ExtrchLinkPageManager.socialIcons.init(window.extrchLinkPageConfig);
    } else {
        // console.warn('[ExtrchLinkPageManager] SocialIcons module or its init function not found.');
    }

    // Initialize Advanced Settings Module (Example)
    if (ExtrchLinkPageManager.advancedSettings && typeof ExtrchLinkPageManager.advancedSettings.init === 'function') {
        ExtrchLinkPageManager.advancedSettings.init();
    }

    // Initialize Preview Updater (if it has its own init, e.g., for iframe readiness)
    if (ExtrchLinkPageManager.previewUpdater && typeof ExtrchLinkPageManager.previewUpdater.init === 'function') {
        ExtrchLinkPageManager.previewUpdater.init();
    }

    // Initialize Info Tab Manager (Info Card)
    if (window.ExtrchLinkPageInfoManager && typeof window.ExtrchLinkPageInfoManager.init === 'function') {
        window.ExtrchLinkPageInfoManager.init(ExtrchLinkPageManager);
    } else {
        // console.warn('[ExtrchLinkPageManager] Info Card manager not found.');
    }

    // Initialize QR Code Module
    if (ExtrchLinkPageManager.qrcode && typeof ExtrchLinkPageManager.qrcode.init === 'function') {
        ExtrchLinkPageManager.qrcode.init();
    }

    // Initialize Analytics Module
    if (ExtrchLinkPageManager.analytics && typeof ExtrchLinkPageManager.analytics.init === 'function') {
        ExtrchLinkPageManager.analytics.init();
    }

    // --- Initialize Save Handler ---
    if (ExtrchLinkPageManager.save && typeof ExtrchLinkPageManager.save.attachSaveHandlerToForm === 'function') {
        ExtrchLinkPageManager.save.attachSaveHandlerToForm();
    }

    // --- Listen for tab activation to re-run background control visibility ---
    // Assumes tab buttons have data-tab and shared-tab-button class, and the customize tab has id 'customize-tab' or similar
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.shared-tab-button');
        if (btn && btn.dataset.tab && btn.dataset.tab.includes('customize')) {
            if (ExtrchLinkPageManager.background && typeof ExtrchLinkPageManager.background.syncAndUpdateUI === 'function') {
                ExtrchLinkPageManager.background.syncAndUpdateUI();
            }
        }
    });

    // Other initializations can go here...

    ExtrchLinkPageManager.isInitialized = true;
    if (ExtrchLinkPageManager.socialIcons) {
        ExtrchLinkPageManager.socialIcons.allowPreviewUpdate = true;
    }
    if (ExtrchLinkPageManager.links) {
        ExtrchLinkPageManager.links.allowPreviewUpdate = true;
    }

    if (ExtrchLinkPageManager.socials && typeof ExtrchLinkPageManager.socials.init === 'function') {
        ExtrchLinkPageManager.socials.init();
    }
    if (ExtrchLinkPageManager.subscribe && typeof ExtrchLinkPageManager.subscribe.init === 'function') {
        ExtrchLinkPageManager.subscribe.init();
    }
    if (ExtrchLinkPageManager.featuredLink && typeof ExtrchLinkPageManager.featuredLink.init === 'function') {
        ExtrchLinkPageManager.featuredLink.init();
    }
    if (ExtrchLinkPageManager.advanced && typeof ExtrchLinkPageManager.advanced.init === 'function') {
        ExtrchLinkPageManager.advanced.init();
    }
};

// --- DOMContentLoaded Listener --- 
document.addEventListener('DOMContentLoaded', function() {
    if (typeof ExtrchLinkPageManager.init === 'function') {
        ExtrchLinkPageManager.init();
    } else {
        console.error('[ExtrchLinkPageManager] Main init function not found on DOMContentLoaded.'); // Keep
    }
});

// Ensure other self-initializing modules or event listeners are respected or integrated above.
