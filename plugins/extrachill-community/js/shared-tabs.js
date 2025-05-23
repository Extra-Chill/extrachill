document.addEventListener('DOMContentLoaded', function() {
    const components = document.querySelectorAll('.shared-tabs-component');

    components.forEach(component => {
        const tabButtonsContainer = component.querySelector('.shared-tabs-buttons-container');
        if (!tabButtonsContainer) return;

        const tabButtons = tabButtonsContainer.querySelectorAll('.shared-tab-button');
        const desktopContentArea = component.querySelector('.shared-desktop-tab-content-area');
        const mobileBreakpoint = 768; // Or your desired breakpoint

        const originalPaneInfo = new Map(); // Stores { parent: originalParent, nextSibling: originalNextSibling }

        function storeInitialPaneStructure() {
            if (originalPaneInfo.size > 0) { // Basic check to see if it's already populated for this component
                // A more robust check might involve verifying if keys still match current panes
                let firstPaneId = component.querySelector('.shared-tab-pane')?.id;
                if (firstPaneId && originalPaneInfo.has(firstPaneId)) return;
            }
            originalPaneInfo.clear(); // Clear if re-populating (e.g. in a dynamic content scenario, though not typical here)
            const panes = component.querySelectorAll('.shared-tab-pane');
            panes.forEach(pane => {
                if (pane.id && pane.parentElement) {
                    originalPaneInfo.set(pane.id, {
                        parent: pane.parentElement,
                        nextSibling: pane.nextElementSibling
                    });
                }
            });
        }

        // Store the initial structure once per component instance
        storeInitialPaneStructure();

        // Add a new function to activate a tab, specifically for external triggers like the join flow modal
        function activateTabFromExternalTrigger(tabId) {
             const targetButton = component.querySelector('.shared-tab-button[data-tab="' + tabId + '"]');
             if (targetButton) {
                 // Call updateTabs, forcing it to open/activate regardless of current state or device size
                 updateTabs(targetButton, true, true, false); // Args: activeButton, shouldScroll, forceOpen, isButtonClick
             } else {
                 console.warn('Attempted to activate non-existent tab:', tabId);
             }
        }

        // Modify updateTabs to accept an optional forceOpen parameter
        function updateTabs(activeButton, shouldScroll = true, forceOpen = false, isButtonClick = false) {
            if (!activeButton) return; 
            const targetTabId = activeButton.dataset.tab;
            const targetPane = component.querySelector('#' + targetTabId + '.shared-tab-pane');
            const isDesktop = window.innerWidth >= mobileBreakpoint;

             // On mobile, if not forced open and clicking an active button, close it.
            if (!isDesktop && !forceOpen && isButtonClick && activeButton.classList.contains('active')) {
                activeButton.classList.remove('active');
                const arrow = activeButton.querySelector('.shared-tab-arrow');
                if (arrow) arrow.classList.remove('open');
                if (targetPane) targetPane.style.display = 'none';
                 if (history.pushState && targetPane && window.location.hash === '#' + targetPane.id) {
                    history.pushState(null, null, window.location.pathname + window.location.search.split('#')[0]);
                }
                // Exit early as we've closed the tab
                return;
            }


            // If we reach here, we are either forced open, on desktop, or clicking a non-active tab

            // Deactivate all other buttons
            tabButtons.forEach(btn => {
                if (btn !== activeButton) {
                    btn.classList.remove('active');
                    const arrow = btn.querySelector('.shared-tab-arrow');
                    if (arrow) arrow.classList.remove('open');
                    // Hiding of non-active panes is handled below based on layout
                }
            });

            // Activate current button
            activeButton.classList.add('active');
            const arrow = activeButton.querySelector('.shared-tab-arrow');
            if (arrow) arrow.classList.add('open');

            // --- Refactored Pane Management Logic --- 
            const allPanes = component.querySelectorAll('.shared-tab-pane');

            allPanes.forEach(pane => {
                const info = originalPaneInfo.get(pane.id);
                if (!info) {
                     console.warn('Pane not found in originalPaneInfo during layout update:', pane.id);
                    return; // Skip this pane if its original info is missing
                }

                if (pane === targetPane) {
                    // This is the active pane
                    if (isDesktop) {
                        // On desktop, move active pane to desktop content area
                        if (desktopContentArea && pane.parentElement !== desktopContentArea) {
                             desktopContentArea.appendChild(pane); // Move to desktop area
                        }
                        pane.classList.add('is-active-pane'); // Add active class
                        pane.style.display = ''; // Remove inline display style, rely on CSS
                    } else {
                        // On mobile, ensure active pane is in its original location and shown
                        if (pane.parentElement !== info.parent) {
                             info.parent.insertBefore(pane, info.nextSibling); // Move back to original
                        }
                         pane.classList.remove('is-active-pane'); // Remove desktop active class
                        pane.style.display = 'block'; // Show on mobile
                    }
                } else {
                    // This is an inactive pane
                    // Always move inactive panes back to their original location
                    if (pane.parentElement !== info.parent) {
                        info.parent.insertBefore(pane, info.nextSibling); // Move back to original
                    }
                    pane.classList.remove('is-active-pane'); // Remove desktop active class
                    pane.style.display = 'none'; // Hide inactive pane
                }
            });
            // --- End Refactored Pane Management Logic ---

            // Ensure the desktop content area container display is correct based on mode
            if (desktopContentArea) {
                 if (isDesktop) {
                     desktopContentArea.style.display = 'block';
                 } else {
                     desktopContentArea.style.display = 'none';
                     // Optionally clear desktop area content if switching to mobile
                     // desktopContentArea.innerHTML = ''; // Removed to avoid destroying elements
                 }
            }

            // Mobile scrolling logic remains the same, but only for mobile mode
            if (!isDesktop && targetPane && shouldScroll) {
                let fixedHeaderHeight = 0;
                const adminBar = document.getElementById('wpadminbar');
                if (adminBar && window.getComputedStyle(adminBar).position === 'fixed') {
                    fixedHeaderHeight += adminBar.offsetHeight;
                }
                // Scroll to the button that was clicked to open this pane
                if (activeButton.offsetParent !== null) {
                     const elementPosition = activeButton.getBoundingClientRect().top;
                     const offsetPosition = elementPosition + window.pageYOffset - fixedHeaderHeight;
                     window.scrollTo({
                         top: offsetPosition,
                         behavior: 'smooth'
                     });
                 }
            }

            // Update URL hash
            if (targetPane && targetPane.id && activeButton.classList.contains('active')) {
                if (history.pushState) {
                    history.pushState(null, null, window.location.pathname + window.location.search.split('#')[0] + '#' + targetPane.id);
                } else {
                    window.location.hash = '#' + targetPane.id;
                }
            }

            // Dispatch a custom event when a tab's content is considered active and updated
            if (targetPane && targetPane.id) {
                const event = new CustomEvent('sharedTabActivated', { 
                    detail: { 
                        tabId: targetPane.id,
                        tabPaneElement: targetPane,
                        activeButtonElement: activeButton,
                        componentElement: component
                    } 
                });
                document.dispatchEvent(event);
                // console.log('Dispatched sharedTabActivated for:', targetPane.id);
            }

            // The logic to manage the active class on panes within the desktop area
            // was moved into the main allPanes.forEach loop.

        }

        // Existing click handler for tab buttons
        tabButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                // Pass shouldScroll = true only if on mobile (accordion mode)
                updateTabs(this, window.innerWidth < mobileBreakpoint, false, true); // Args: activeButton, shouldScroll, forceOpen, isButtonClick
            });
        });

        function activateTabFromHash() {
            let activatedByHash = false;
            if (window.location.hash) {
                const hash = window.location.hash; 
                // Ensure hash is a valid ID for a pane within this component
                const targetPaneByHash = component.querySelector(hash + '.shared-tab-pane');
                if (targetPaneByHash) {
                    const correspondingButton = component.querySelector('.shared-tab-button[data-tab="' + hash.substring(1) + '"]');
                    if (correspondingButton) {
                        // Call updateTabs with shouldScroll based on initial load state
                         // Determine if initial load is mobile or desktop for scrolling
                         const isInitialLoadMobile = window.innerWidth < mobileBreakpoint;
                        updateTabs(correspondingButton, isInitialLoadMobile, false, false); // Args: activeButton, shouldScroll, forceOpen, isButtonClick
                        activatedByHash = true;
                    }
                }
            }
            return activatedByHash;
        }
        
        function initializeDefaultOrActiveTab() {
            storeInitialPaneStructure(); // Ensure structure is stored before any tab activation

            if (activateTabFromHash()) {
                return; // Hash determined the active tab, updateTabs was called
            }

            let activeButton = tabButtonsContainer.querySelector('.shared-tab-button.active');
            
            if (!activeButton) {
                const preActivePane = component.querySelector('.shared-tab-pane[style*="display:block"], .shared-tab-pane[style*="display: block"]');
                if (preActivePane && preActivePane.id) {
                    activeButton = tabButtonsContainer.querySelector('.shared-tab-button[data-tab="' + preActivePane.id + '"]');
                }
            }

            if (!activeButton && tabButtons.length > 0) {
                activeButton = tabButtons[0];
            }

            if (activeButton) {
                // Call updateTabs with shouldScroll based on initial load state
                // Determine if initial load is mobile or desktop for scrolling
                const isInitialLoadMobile = window.innerWidth < mobileBreakpoint;
                updateTabs(activeButton, isInitialLoadMobile, false, false); // Args: activeButton, shouldScroll, forceOpen, isButtonClick
            } else if (tabButtons.length === 0 && desktopContentArea) {
                desktopContentArea.style.display = 'none';
            }
        }
        
        initializeDefaultOrActiveTab();

        let resizeTimeout;
        // Keep track of the last determined layout mode (mobile or desktop)
        let lastLayoutMode = window.innerWidth < mobileBreakpoint ? 'mobile' : 'desktop';

        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                const currentLayoutMode = window.innerWidth < mobileBreakpoint ? 'mobile' : 'desktop';
                const activeButtonOnResize = tabButtonsContainer.querySelector('.shared-tab-button.active');
                
                // Determine if a layout mode change occurred
                const layoutModeChanged = currentLayoutMode !== lastLayoutMode;

                // Should we scroll on resize?
                // - Yes, if layout mode changed to mobile AND there is an active button.
                // - No otherwise (scrolling not needed on desktop resize, or if no active button).
                const shouldScrollOnResize = layoutModeChanged && currentLayoutMode === 'mobile' && activeButtonOnResize;

                if (activeButtonOnResize) {
                    // Pass the determined shouldScroll flag
                    updateTabs(activeButtonOnResize, shouldScrollOnResize, false, false); // Args: activeButton, shouldScroll, forceOpen, isButtonClick
                } else {
                     // If no active button after resize, re-initialize without specific scroll
                     // initializeDefaultOrActiveTab(); // initializeDefaultOrActiveTab handles its own scrolling logic
                     // Instead of re-initializing the default, let's just ensure correct state based on resize
                     // If no button is active, ensure all panes are in original location and hidden, and desktop area is hidden
                     allPanes.forEach(pane => {
                         const info = originalPaneInfo.get(pane.id);
                         if (info && info.parent && pane.parentElement !== info.parent) {
                             info.parent.insertBefore(pane, info.nextSibling);
                         }
                         pane.classList.remove('is-active-pane');
                         pane.style.display = 'none';
                     });
                     if (desktopContentArea) {
                         desktopContentArea.style.display = 'none';
                         desktopContentArea.innerHTML = ''; // Safe to clear if no active tab and moving all back
                     }
                }
                
                // Update the stored last layout mode
                 lastLayoutMode = currentLayoutMode;

            }, 250); // Debounce time
        });

        // Listen for the custom event dispatched by join-flow-ui.js
        document.addEventListener('activateJoinFlowTab', function(event) {
            console.log('activateJoinFlowTab event received for tab:', event.detail.targetTab);
            if (event.detail && event.detail.targetTab) {
                 // Find the component this event is intended for (assuming one shared tabs component on the page)
                 // If there were multiple, we'd need to pass component ID in the event detail.
                activateTabFromExternalTrigger(event.detail.targetTab); // This calls updateTabs with forceOpen=true
            }
        });

        // Add a public method to activate a tab by ID (useful for external scripts/flows)
         component.activateTab = function(tabId) {
             activateTabFromExternalTrigger(tabId); // Reuse the existing trigger logic
         };

    }); // End components.forEach

    // Make a global function to activate tabs, perhaps tied to the first component found
     // This might be less robust if there are multiple shared-tabs-components on a page
     // but for simple cases or specific templates, it can work.
    const firstComponent = document.querySelector('.shared-tabs-component');
    if (firstComponent) {
         window.activateSharedTab = function(tabId) {
             firstComponent.activateTab(tabId);
         };
    }

}); // End DOMContentLoaded 