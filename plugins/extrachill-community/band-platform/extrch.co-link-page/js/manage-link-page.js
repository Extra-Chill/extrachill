window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {};
console.log('[Manager - Top Level] window.extrchLinkPageConfig state:', window.extrchLinkPageConfig);

// --- Function to get the preview container element ---
ExtrchLinkPageManager.getPreviewEl = function() {
    const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
    if (previewContainerParent) {
        const previewContainer = previewContainerParent.querySelector('.extrch-link-page-preview-container');
        if (previewContainer) {
            return previewContainer;
        }
        // console.warn('[Manager] .extrch-link-page-preview-container not found within .manage-link-page-preview-live.');
        return null;
    }
    // console.warn('[Manager] .manage-link-page-preview-live not found.');
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
        const isMobile = window.innerWidth <= mobileBreakpoint;
        
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
    // document.addEventListener('ExtrchLinkPageTabChanged', toggleButtonState); // REMOVED - Assuming such an event is dispatched
    // --- Note: 'ExtrchLinkPageTabChanged' is a custom event. If shared-tabs.js doesn't dispatch this,
    // this listener might not fire. However, shared-tabs.js *does* handle active states which should
    // make getActiveSettingsElement work correctly when called.
    // Consider dispatching a generic 'sharedTabChanged' event from shared-tabs.js if needed by other scripts.

    toggleButtonState(); // Initial check
};
// ----- End Jump to Preview Button Logic -----

ExtrchLinkPageManager.isInitialized = false;

// --- Main Initialization Orchestrator ---
ExtrchLinkPageManager.init = function() {
    console.log('[ExtrchLinkPageManager] Initializing...');

    // Initialize Jump to Preview functionality
    if (typeof ExtrchLinkPageManager.initializeJumpToPreview === 'function') {
        ExtrchLinkPageManager.initializeJumpToPreview();
    }

    // Initialize Customization Module
    if (ExtrchLinkPageManager.customization && typeof ExtrchLinkPageManager.customization.init === 'function') {
        console.log('[ExtrchLinkPageManager] Calling customization.init()...');
        ExtrchLinkPageManager.customization.init();
    } else {
        console.warn('[ExtrchLinkPageManager] Customization module or its init function not found.');
    }

    // Initialize Sizing Module (must come after customization for correct hydration)
    if (ExtrchLinkPageManager.sizing && typeof ExtrchLinkPageManager.sizing.init === 'function') {
        console.log('[ExtrchLinkPageManager] Calling sizing.init()...');
        ExtrchLinkPageManager.sizing.init();
    }

    // Initialize Background Module (ensure correct controls are shown)
    if (ExtrchLinkPageManager.background && typeof ExtrchLinkPageManager.background.init === 'function') {
        ExtrchLinkPageManager.background.init();
    }
    
    // Initialize Links Module (Example - to be created/refactored)
    if (ExtrchLinkPageManager.links && typeof ExtrchLinkPageManager.links.init === 'function') {
        console.log('[ExtrchLinkPageManager] Calling links.init()...');
        ExtrchLinkPageManager.links.init();
    } else {
        console.warn('[ExtrchLinkPageManager] Links module or its init function not found.');
    }

    // Initialize Social Icons Module (Example)
    if (ExtrchLinkPageManager.socialIcons && typeof ExtrchLinkPageManager.socialIcons.init === 'function') {
        console.log('[ExtrchLinkPageManager] Calling socialIcons.init()...');
        ExtrchLinkPageManager.socialIcons.init(window.extrchLinkPageConfig);
        console.log('[ExtrchLinkPageManager] socialIcons.init() called.');
    } else {
        console.warn('[ExtrchLinkPageManager] SocialIcons module or its init function not found.');
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
        console.log('[ExtrchLinkPageManager] Info Card manager initialized.');
    } else {
        console.warn('[ExtrchLinkPageManager] Info Card manager not found.');
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
        console.log('[ExtrchLinkPageManager] Attaching save handler...');
        ExtrchLinkPageManager.save.attachSaveHandlerToForm();
    } else {
        console.warn('[ExtrchLinkPageManager] Save module or its attachSaveHandlerToForm function not found.');
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

    console.log('[ExtrchLinkPageManager] Initialization complete.');
};

// --- DOMContentLoaded Listener --- 
document.addEventListener('DOMContentLoaded', function() {
    if (typeof ExtrchLinkPageManager.init === 'function') {
        ExtrchLinkPageManager.init();
    } else {
        console.error('[ExtrchLinkPageManager] Main init function not found on DOMContentLoaded.');
    }
});

// Ensure other self-initializing modules or event listeners are respected or integrated above.
