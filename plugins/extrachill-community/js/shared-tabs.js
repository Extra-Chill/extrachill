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

        function updateTabs(activeButton) {
            if (!activeButton) return; 
            const targetTabId = activeButton.dataset.tab;
            const targetPane = component.querySelector('#' + targetTabId + '.shared-tab-pane');

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

            if (window.innerWidth < mobileBreakpoint) {
                // MOBILE / ACCORDION MODE
                if (desktopContentArea) {
                    // Move any panes from desktopContentArea back to their original parents
                    Array.from(desktopContentArea.children).forEach(paneInDesktopArea => {
                        const info = originalPaneInfo.get(paneInDesktopArea.id);
                        if (info && info.parent) {
                            info.parent.insertBefore(paneInDesktopArea, info.nextSibling);
                        }
                    });
                    desktopContentArea.style.display = 'none';
                    // desktopContentArea.innerHTML = ''; // Clear after moving
                }

                // Ensure all panes are in their original accordion structure and manage display
                const allPanes = component.querySelectorAll('.shared-tab-pane');
                allPanes.forEach(pane => {
                    const info = originalPaneInfo.get(pane.id);
                    if (info && info.parent && pane.parentElement !== info.parent) {
                        // If a pane is not in its original parent, move it back
                        info.parent.insertBefore(pane, info.nextSibling);
                    }
                    // Hide or show based on whether it's the target pane
                    if (pane !== targetPane) {
                        pane.style.display = 'none';
                    }
                });

                if (targetPane) {
                    // Ensure targetPane is in its original accordion position if somehow missed
                    const targetInfo = originalPaneInfo.get(targetPane.id);
                    if (targetInfo && targetInfo.parent && targetPane.parentElement !== targetInfo.parent) {
                         targetInfo.parent.insertBefore(targetPane, targetInfo.nextSibling);
                    }
                    targetPane.style.display = 'block';
                    // Scroll into view on mobile
                    let fixedHeaderHeight = 0;
                    const adminBar = document.getElementById('wpadminbar');
                    if (adminBar && window.getComputedStyle(adminBar).position === 'fixed') {
                        fixedHeaderHeight += adminBar.offsetHeight;
                    }
                    if (activeButton.offsetParent !== null) { 
                        const elementPosition = activeButton.getBoundingClientRect().top;
                        const offsetPosition = elementPosition + window.pageYOffset - fixedHeaderHeight;
                        window.scrollTo({
                            top: offsetPosition,
                            behavior: 'smooth'
                        });
                    }
                }
            } else {
                // DESKTOP / TABS MODE
                if (desktopContentArea) {
                    // desktopContentArea.innerHTML = ''; // Clear first

                    // Move inactive panes back to their original (hidden) locations
                    const allPanesAgain = component.querySelectorAll('.shared-tab-pane');
                    allPanesAgain.forEach(pane => {
                        if (pane !== targetPane) {
                            const info = originalPaneInfo.get(pane.id);
                            if (info && info.parent && pane.parentElement !== info.parent) {
                                 info.parent.insertBefore(pane, info.nextSibling);
                            }
                            pane.style.display = 'none'; // Ensure they are hidden
                        }
                    });
                    
                    // Clear desktop area after ensuring inactive panes are moved out
                    desktopContentArea.innerHTML = '';


                    // Move the active targetPane into the desktopContentArea
                    if (targetPane) {
                        desktopContentArea.appendChild(targetPane);
                        targetPane.style.display = 'block';
                    }
                    desktopContentArea.style.display = 'block';
                } else {
                     console.error("Desktop content area not found for component:", component);
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
        }
        
        function closeAllTabsExcept(activeButtonToKeep) {
            tabButtons.forEach(btn => {
                // const paneId = btn.dataset.tab; // Not strictly needed here as updateTabs handles display
                // const pane = component.querySelector('#' + paneId + '.shared-tab-pane');

                if (btn !== activeButtonToKeep) {
                    btn.classList.remove('active');
                    const arrow = btn.querySelector('.shared-tab-arrow');
                    if (arrow) arrow.classList.remove('open');
                    // if (pane) pane.style.display = 'none'; // Let updateTabs handle display logic
                } else {
                    // btn.classList.add('active'); // updateTabs will set this
                    // const arrow = btn.querySelector('.shared-tab-arrow');
                    // if (arrow) arrow.classList.add('open'); // updateTabs will set this
                }
            });
        }

        tabButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const targetContentPaneId = this.dataset.tab;
                const targetContentPane = component.querySelector('#' + targetContentPaneId + '.shared-tab-pane');

                if (this.classList.contains('active') && window.innerWidth < mobileBreakpoint) {
                    // Accordion: clicking active button closes it
                    this.classList.remove('active');
                    const arrow = this.querySelector('.shared-tab-arrow');
                    if (arrow) arrow.classList.remove('open');
                    if (targetContentPane) targetContentPane.style.display = 'none';
                     if (history.pushState && targetContentPane && window.location.hash === '#' + targetContentPane.id) {
                        history.pushState(null, null, window.location.pathname + window.location.search.split('#')[0]);
                    }
                } else if (!this.classList.contains('active') || window.innerWidth >= mobileBreakpoint) {
                    // If it's not active, OR if it's desktop view (even if already active, re-trigger to update desktop area)
                    // closeAllTabsExcept(this); // updateTabs handles deactivating others
                    updateTabs(this);
                }
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
                        // closeAllTabsExcept(correspondingButton); // updateTabs handles deactivating others
                        updateTabs(correspondingButton);
                        activatedByHash = true;
                    }
                }
            }
            return activatedByHash;
        }
        
        function initializeDefaultOrActiveTab() {
            storeInitialPaneStructure(); // Ensure structure is stored before any tab activation

            if (activateTabFromHash()) {
                return; // Hash determined the active tab
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
                // closeAllTabsExcept(activeButton); // updateTabs handles deactivating others
                updateTabs(activeButton);
            } else if (tabButtons.length === 0 && desktopContentArea) {
                desktopContentArea.style.display = 'none';
            }
        }
        
        initializeDefaultOrActiveTab();

        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                // Potentially re-store structure if dynamic changes are possible, though unlikely for this setup
                // storeInitialPaneStructure(); 
                
                const activeButton = tabButtonsContainer.querySelector('.shared-tab-button.active');
                if (activeButton) {
                    updateTabs(activeButton); 
                } else {
                    initializeDefaultOrActiveTab(); 
                }
            }, 250);
        });
    });
}); 