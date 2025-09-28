/**
 * Responsive tabs system with accordion mode for mobile
 */
document.addEventListener('DOMContentLoaded', function() {
    const components = document.querySelectorAll('.shared-tabs-component');

    components.forEach(component => {
        const tabButtonsContainer = component.querySelector('.shared-tabs-buttons-container');
        if (!tabButtonsContainer) return;

        const tabButtons = tabButtonsContainer.querySelectorAll('.shared-tab-button');
        const desktopContentArea = component.querySelector('.shared-desktop-tab-content-area');
        const mobileBreakpoint = 768;

        const originalPaneInfo = new Map();

        let isInitialLoad = true;

        function storeInitialPaneStructure() {
            if (originalPaneInfo.size > 0) {
                let firstPaneId = component.querySelector('.shared-tab-pane')?.id;
                if (firstPaneId && originalPaneInfo.has(firstPaneId)) return;
            }
            originalPaneInfo.clear();
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

        storeInitialPaneStructure();

        function activateTabFromExternalTrigger(tabId) {
             const targetButton = component.querySelector('.shared-tab-button[data-tab="' + tabId + '"]');
             if (targetButton) {
                     updateTabs(targetButton, true, true, false);
             } else {
             }
        }

        function updateTabs(activeButton, shouldScroll = true, forceOpen = false, isButtonClick = false) {
            if (!activeButton) return; 
            const targetTabId = activeButton.dataset.tab;
            const targetPane = component.querySelector('#' + targetTabId + '.shared-tab-pane');
            const isDesktop = window.innerWidth >= mobileBreakpoint;

            if (!isDesktop && !forceOpen && isButtonClick && activeButton.classList.contains('active')) {
                activeButton.classList.remove('active');
                const arrow = activeButton.querySelector('.shared-tab-arrow');
                if (arrow) arrow.classList.remove('open');
                if (targetPane) targetPane.style.display = 'none';
                 if (history.pushState && targetPane && window.location.hash === '#' + targetPane.id) {
                    history.pushState(null, null, window.location.pathname + window.location.search.split('#')[0]);
                }
                return;
            }



            tabButtons.forEach(btn => {
                if (btn !== activeButton) {
                    btn.classList.remove('active');
                    const arrow = btn.querySelector('.shared-tab-arrow');
                    if (arrow) arrow.classList.remove('open');
                }
            });

            activeButton.classList.add('active');
            const arrow = activeButton.querySelector('.shared-tab-arrow');
            if (arrow) arrow.classList.add('open');

            const allPanes = component.querySelectorAll('.shared-tab-pane');

            allPanes.forEach(pane => {
                const info = originalPaneInfo.get(pane.id);
                if (!info) {
                    return;
                }

                if (pane === targetPane) {
                            if (isDesktop) {
                        if (desktopContentArea && pane.parentElement !== desktopContentArea) {
                             desktopContentArea.appendChild(pane);
                        }
                        pane.classList.add('is-active-pane');
                        pane.style.display = '';
                    } else {
                        if (pane.parentElement !== info.parent) {
                             info.parent.insertBefore(pane, info.nextSibling);
                        }
                         pane.classList.remove('is-active-pane');
                        pane.style.display = 'block';
                    }
                } else {
                    if (pane.parentElement !== info.parent) {
                        info.parent.insertBefore(pane, info.nextSibling);
                    }
                    pane.classList.remove('is-active-pane');
                    pane.style.display = 'none';
                }
            });

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

            // Update URL hash (but NOT on initial load)
            if (targetPane && targetPane.id && activeButton.classList.contains('active')) {
                if (!isInitialLoad) {
                    if (history.pushState) {
                        history.pushState(null, null, window.location.pathname + window.location.search.split('#')[0] + '#' + targetPane.id);
                    } else {
                        window.location.hash = '#' + targetPane.id;
                    }
                }
            }

            // Dispatch specific tab events when content becomes active
            if (targetPane && targetPane.id) {
                // Map tab IDs to specific event names
                const tabEventMap = {
                    'manage-link-page-tab-analytics': 'analyticsTabActivated',
                    'manage-link-page-tab-customize': 'customizeTabActivated',
                    'manage-link-page-tab-links': 'linksTabActivated', 
                    'manage-link-page-tab-info': 'infoTabActivated',
                    'manage-artist-profile-info-content': 'artistInfoTabActivated',
                    'manage-artist-profile-managers-content': 'artistManagersTabActivated',
                    'manage-artist-profile-followers-content': 'artistFollowersTabActivated',
                    'manage-artist-profile-forum-content': 'artistForumTabActivated'
                };
                
                const eventName = tabEventMap[targetPane.id] || 'tabActivated';
                const event = new CustomEvent(eventName, { 
                    detail: { 
                        tabId: targetPane.id,
                        tabPaneElement: targetPane,
                        activeButtonElement: activeButton,
                        componentElement: component
                    } 
                });
                document.dispatchEvent(event);
            }

            // The logic to manage the active class on panes within the desktop area
            // was moved into the main allPanes.forEach loop.

        }

        tabButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                updateTabs(this, window.innerWidth < mobileBreakpoint, false, true);
            });
        });

        function activateTabFromHash() {
            let activatedByHash = false;
            if (window.location.hash) {
                const hash = window.location.hash; 
                const targetPaneByHash = component.querySelector(hash + '.shared-tab-pane');
                if (targetPaneByHash) {
                    const correspondingButton = component.querySelector('.shared-tab-button[data-tab="' + hash.substring(1) + '"]');
                    if (correspondingButton) {
                        // On initial load, do NOT scroll
                        updateTabs(correspondingButton, false, false, false);
                        activatedByHash = true;
                    }
                }
            }
            return activatedByHash;
        }
        
        function initializeDefaultOrActiveTab() {
            storeInitialPaneStructure();

            if (activateTabFromHash()) {
                isInitialLoad = false;
                return;
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
                // On initial load, do NOT scroll
                updateTabs(activeButton, false, false, false);
            } else if (tabButtons.length === 0 && desktopContentArea) {
                desktopContentArea.style.display = 'none';
            }
            isInitialLoad = false;
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
            // Tab activation event received
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