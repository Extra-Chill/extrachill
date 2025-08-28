// UI Utilities for Manage Link Page

// Tab switching for manage link page
(function(){
    const tabs = document.querySelectorAll('.manage-link-page-tab');
    const tabContents = document.querySelectorAll('.manage-link-page-tab-content');
    const activeTabStorageKey = 'activeLinkPageTab';
    const desktopBreakpoint = 769; // px, matches CSS
    let currentLayoutMode = getLayoutMode();
    let desktopTabContentArea = null; // Will hold the #desktop-tab-content-area div
    const originalContentLocations = new Map(); // To store original parent and next sibling for each content panel

    function getLayoutMode() {
        return window.innerWidth < desktopBreakpoint ? 'accordion' : 'tabs';
    }

    // Store original locations of content panels relative to their button containers
    function storeOriginalContentLocations() {
        if (originalContentLocations.size > 0) return; // Only run once
        tabContents.forEach(content => {
            const parentItem = content.closest('.manage-link-page-item');
            if (parentItem) {
                originalContentLocations.set(content.id, { parent: parentItem, nextSibling: content.nextSibling });
            }
        });
    }

    function switchToDesktopTabsLayout() {
        if (!desktopTabContentArea) {
            desktopTabContentArea = document.getElementById('desktop-tab-content-area');
            if (!desktopTabContentArea) {
                console.error('Desktop tab content area not found!');
                return;
            }
        }
        tabContents.forEach(content => {
            desktopTabContentArea.appendChild(content); // Move content to the shared area
        });
        desktopTabContentArea.style.display = 'block';
    }

    function switchToAccordionLayout() {
        if (desktopTabContentArea) {
            desktopTabContentArea.style.display = 'none';
        }
        tabContents.forEach(content => {
            const originalLocation = originalContentLocations.get(content.id);
            if (originalLocation && originalLocation.parent) {
                // Insert content back after its corresponding button inside the item
                // Assumes button is the first child of parentItem
                const button = originalLocation.parent.querySelector('.manage-link-page-tab');
                if (button) {
                    originalLocation.parent.insertBefore(content, button.nextSibling);
                } else {
                    originalLocation.parent.appendChild(content); // Fallback
                }
            } else {
                 // Fallback if original location not found (should not happen)
                 // Attempt to place it in the corresponding item based on data-tab
                const tabId = content.id.replace('manage-link-page-tab-', '');
                const item = document.querySelector(`.manage-link-page-item .manage-link-page-tab[data-tab="${tabId}"]`)?.closest('.manage-link-page-item');
                if(item) item.appendChild(content);
            }
        });
    }

    function setActiveTab(tabId, isInitialization = false) {
        // currentLayoutMode = getLayoutMode(); // This is already called at the start or by handleResize

        const tabToActivate = document.querySelector(`.manage-link-page-tab[data-tab="${tabId}"]`);
        // Content to activate is always found by ID, regardless of its current parent
        const contentToActivate = document.getElementById('manage-link-page-tab-' + tabId);

        if (currentLayoutMode === 'accordion') {
            if (tabToActivate && contentToActivate) {
                if (tabToActivate.classList.contains('active') && !isInitialization) {
                    tabToActivate.classList.remove('active');
                    contentToActivate.style.display = 'none';
                    try { localStorage.removeItem(activeTabStorageKey); } catch (e) { /* console.warn("Could not remove active tab", e); */ }
                } else {
                    tabs.forEach(t => { if (t !== tabToActivate) t.classList.remove('active'); });
                    tabContents.forEach(tc => { if (tc !== contentToActivate) tc.style.display = 'none'; });
                    tabToActivate.classList.add('active');
                    contentToActivate.style.display = 'block';
                    try { localStorage.setItem(activeTabStorageKey, tabId); } catch (e) { /* console.warn("Could not save active tab", e); */ }
                    
                    // --- Scroll active tab into view on mobile/accordion ---
                    if (window.innerWidth < desktopBreakpoint) { // Re-check, though currentLayoutMode should be 'accordion'
                        let fixedHeaderHeight = 0;
                        const adminBar = document.getElementById('wpadminbar');
                        if (adminBar && window.getComputedStyle(adminBar).position === 'fixed') {
                            fixedHeaderHeight += adminBar.offsetHeight;
                        }
                        // Assuming tabToActivate is the button itself
                        const elementPosition = tabToActivate.getBoundingClientRect().top;
                        const offsetPosition = elementPosition + window.pageYOffset - fixedHeaderHeight;
                        window.scrollTo({
                            top: offsetPosition,
                            behavior: 'smooth'
                        });
                    }
                    // --- End scroll active tab ---

                    // --- Initialize tab-specific JS ---
                    if (tabId === 'analytics' && window.ExtrchLinkPageAnalytics && typeof window.ExtrchLinkPageAnalytics.handleTabBecameVisible === 'function') {
                        // console.log('UI Utils: Analytics tab activated, calling its handler.');
                        window.ExtrchLinkPageAnalytics.handleTabBecameVisible();
                    }
                    if (tabId === 'customize' && window.ExtrchLinkPageManager && window.ExtrchLinkPageManager.customization && typeof window.ExtrchLinkPageManager.customization.init === 'function' && !window.ExtrchLinkPageManager.customization.isInitialized) {
                        // console.log('UI Utils: Customize tab activated, calling its init().');
                        window.ExtrchLinkPageManager.customization.init();
                    }
                    // Add more tab-specific initializations here if needed
                    // --- End Initialize tab-specific JS ---
                }
            } else if (tabs.length > 0 && isInitialization) { // Default to first tab if none explicitly active
                const firstTabToActivate = tabs[0];
                const firstTabId = firstTabToActivate.getAttribute('data-tab');
                const firstContentToActivate = document.getElementById('manage-link-page-tab-' + firstTabId);

                firstTabToActivate.classList.add('active');
                if (firstContentToActivate) { 
                     firstContentToActivate.style.display = 'block';
                }
                try { localStorage.setItem(activeTabStorageKey, firstTabId); } catch (e) { /* console.warn("Could not save active tab", e); */ }
                
                // --- Scroll active tab into view on mobile/accordion (for default first tab) ---
                if (window.innerWidth < desktopBreakpoint) { // Re-check
                    let fixedHeaderHeight = 0;
                    const adminBar = document.getElementById('wpadminbar');
                    if (adminBar && window.getComputedStyle(adminBar).position === 'fixed') {
                        fixedHeaderHeight += adminBar.offsetHeight;
                    }
                    const elementPosition = firstTabToActivate.getBoundingClientRect().top;
                    const offsetPosition = elementPosition + window.pageYOffset - fixedHeaderHeight;
                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                }
                // --- End scroll active tab ---
                
                // --- Initialize tab-specific JS for default first tab ---
                if (firstTabId === 'analytics' && window.ExtrchLinkPageAnalytics && typeof window.ExtrchLinkPageAnalytics.handleTabBecameVisible === 'function') {
                    // console.log('UI Utils: Analytics tab (default) activated, calling its handler.');
                    window.ExtrchLinkPageAnalytics.handleTabBecameVisible();
                }
                if (firstTabId === 'customize' && window.ExtrchLinkPageManager && window.ExtrchLinkPageManager.customization && typeof window.ExtrchLinkPageManager.customization.init === 'function' && !window.ExtrchLinkPageManager.customization.isInitialized) {
                    // console.log('UI Utils: Customize tab (default) activated, calling its init().');
                    window.ExtrchLinkPageManager.customization.init();
                }
                // Add more tab-specific initializations here if needed
                // --- End Initialize tab-specific JS for default first tab ---
            }
        } else { // Tabs mode (desktop)
            tabs.forEach(t => t.classList.remove('active'));
            // Hide all content panels (they are in desktopTabContentArea or should be)
            if (desktopTabContentArea) {
                 Array.from(desktopTabContentArea.children).forEach(child => child.style.display = 'none');
            }

            if (tabToActivate && contentToActivate) {
                tabToActivate.classList.add('active');
                contentToActivate.style.display = 'block'; // Show the target one
                try { localStorage.setItem(activeTabStorageKey, tabId); } catch (e) { /* console.warn("Could not save active tab", e); */ }
                
                // --- Scroll active tab into view on mobile/accordion (Desktop tabs, but still check width for safety) ---
                // This case might be less relevant if layout is strictly 'tabs' on desktop, but good for consistency
                // if (window.innerWidth < desktopBreakpoint) { 
                //     let fixedHeaderHeight = 0;
                //     const adminBar = document.getElementById('wpadminbar');
                //     if (adminBar && window.getComputedStyle(adminBar).position === 'fixed') {
                //         fixedHeaderHeight += adminBar.offsetHeight;
                //     }
                //     const elementPosition = tabToActivate.getBoundingClientRect().top;
                //     const offsetPosition = elementPosition + window.pageYOffset - fixedHeaderHeight;
                //     window.scrollTo({
                //         top: offsetPosition,
                //         behavior: 'smooth'
                //     });
                // }
                // --- End scroll active tab ---
                
                // --- Initialize tab-specific JS ---
                if (tabId === 'analytics' && window.ExtrchLinkPageAnalytics && typeof window.ExtrchLinkPageAnalytics.handleTabBecameVisible === 'function') {
                    // console.log('UI Utils: Analytics tab activated, calling its handler.');
                    window.ExtrchLinkPageAnalytics.handleTabBecameVisible();
                }
                 if (tabId === 'customize' && window.ExtrchLinkPageManager && window.ExtrchLinkPageManager.customization && typeof window.ExtrchLinkPageManager.customization.init === 'function' && !window.ExtrchLinkPageManager.customization.isInitialized) {
                    // console.log('UI Utils: Customize tab activated, calling its init().');
                    window.ExtrchLinkPageManager.customization.init();
                }
                // Add more tab-specific initializations here if needed
                // --- End Initialize tab-specific JS ---

            } else if (tabs.length > 0) { // Default to first tab
                const firstTabToActivate = tabs[0];
                const firstTabId = firstTabToActivate.getAttribute('data-tab');
                const firstTabContent = document.getElementById('manage-link-page-tab-' + firstTabId);

                firstTabToActivate.classList.add('active');
                if (firstTabContent) { 
                    firstTabContent.style.display = 'block';
                }
                try { localStorage.setItem(activeTabStorageKey, firstTabId); } catch (e) { /* console.warn("Could not save active tab", e); */ }
                
                // --- Scroll active tab into view on mobile/accordion (Desktop tabs, default first tab) ---
                // if (window.innerWidth < desktopBreakpoint) {
                //     let fixedHeaderHeight = 0;
                //     const adminBar = document.getElementById('wpadminbar');
                //     if (adminBar && window.getComputedStyle(adminBar).position === 'fixed') {
                //         fixedHeaderHeight += adminBar.offsetHeight;
                //     }
                //     const elementPosition = firstTabToActivate.getBoundingClientRect().top;
                //     const offsetPosition = elementPosition + window.pageYOffset - fixedHeaderHeight;
                //     window.scrollTo({
                //         top: offsetPosition,
                //         behavior: 'smooth'
                //     });
                // }
                // --- End scroll active tab ---
                
                // --- Initialize tab-specific JS for default first tab ---
                if (firstTabId === 'analytics' && window.ExtrchLinkPageAnalytics && typeof window.ExtrchLinkPageAnalytics.handleTabBecameVisible === 'function') {
                    // console.log('UI Utils: Analytics tab (default) activated, calling its handler.');
                    window.ExtrchLinkPageAnalytics.handleTabBecameVisible();
                }
                if (firstTabId === 'customize' && window.ExtrchLinkPageManager && window.ExtrchLinkPageManager.customization && typeof window.ExtrchLinkPageManager.customization.init === 'function' && !window.ExtrchLinkPageManager.customization.isInitialized) {
                    // console.log('UI Utils: Customize tab (default) activated, calling its init().');
                    window.ExtrchLinkPageManager.customization.init();
                }
                // Add more tab-specific initializations here if needed
                // --- End Initialize tab-specific JS for default first tab ---
            }
        }
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function() { setActiveTab(this.getAttribute('data-tab')); });
    });

    function activateInitialTab(isResizing = false) {
        currentLayoutMode = getLayoutMode(); // Determine current mode first
        
        const urlParams = new URLSearchParams(window.location.search);
        const tabFromUrl = urlParams.get('tab');
        let savedTabId = null;
        try { savedTabId = localStorage.getItem(activeTabStorageKey); } catch (e) { /* console.warn("Could not retrieve active tab", e); */ }
        
        let tabToActivateId = null;

        if (tabFromUrl && document.querySelector(`.manage-link-page-tab[data-tab="${tabFromUrl}"]`)) {
            tabToActivateId = tabFromUrl;
            // If tab is activated from URL, also update localStorage to reflect this as the new "active" tab.
            try { localStorage.setItem(activeTabStorageKey, tabFromUrl); } catch (e) { /* console.warn("Could not save active tab from URL", e); */ }
        } else if (savedTabId && document.querySelector(`.manage-link-page-tab[data-tab="${savedTabId}"]`)) {
            tabToActivateId = savedTabId;
        } else if (tabs.length > 0) {
            tabToActivateId = tabs[0].getAttribute('data-tab');
            // If defaulting to the first tab, also set it in localStorage.
            if (tabToActivateId) {
                try { localStorage.setItem(activeTabStorageKey, tabToActivateId); } catch (e) { /* console.warn("Could not save default active tab", e); */ }
            }
        }

        if (tabToActivateId) {
            setActiveTab(tabToActivateId, true);
        }
    }
    
    function handleResize() {
        const newLayoutMode = getLayoutMode();
        if (newLayoutMode !== currentLayoutMode) {
            currentLayoutMode = newLayoutMode;
            if (currentLayoutMode === 'tabs') {
                switchToDesktopTabsLayout();
            } else {
                switchToAccordionLayout();
            }
            activateInitialTab(true); // Re-apply active state to the new layout
        }
    }

    function initializeTabs() {
        storeOriginalContentLocations(); // Store original structure first
        desktopTabContentArea = document.getElementById('desktop-tab-content-area'); // Get ref to desktop area

        // Initial layout setup based on current window size
        currentLayoutMode = getLayoutMode();
        if (currentLayoutMode === 'tabs') {
            switchToDesktopTabsLayout();
        }
        // Accordion layout is default if not tabs, content already in place.
        
        activateInitialTab(false); // Set the active tab/accordion item

        window.addEventListener('resize', handleResize);
    }
    
    // Ensure DOM is ready for manipulations
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeTabs);
    } else {
        initializeTabs();
    }
})();

// --- Copy Link Page URL ---
(function() {
    const copyButton = document.getElementById('copy-link-page-url');
    const urlTextElement = document.querySelector('.bp-link-page-url-text');
    const confirmElement = document.getElementById('copy-link-page-url-confirm');

    if (copyButton && urlTextElement && confirmElement) {
        copyButton.addEventListener('click', function(event) {
            event.preventDefault();
            const urlToCopy = urlTextElement.textContent || urlTextElement.innerText;
            navigator.clipboard.writeText(urlToCopy).then(() => {
                confirmElement.style.display = 'inline';
                setTimeout(() => { confirmElement.style.display = 'none'; }, 2500);
            }).catch(err => {
                console.error('Failed to copy URL: ', err);
                try {
                    const textArea = document.createElement("textarea");
                    Object.assign(textArea.style, { position: "fixed", top: "0", left: "0", width: "2em", height: "2em", padding: "0", border: "none", outline: "none", boxShadow: "none", background: "transparent" });
                    textArea.value = urlToCopy;
                    document.body.appendChild(textArea); textArea.focus(); textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);
                    confirmElement.style.display = 'inline';
                    setTimeout(() => { confirmElement.style.display = 'none'; }, 2500);
                } catch (e) {
                    console.error('Fallback copy method failed:', e);
                    alert('Failed to copy URL. Please copy it manually.');
                }
            });
        });
    }
})();
