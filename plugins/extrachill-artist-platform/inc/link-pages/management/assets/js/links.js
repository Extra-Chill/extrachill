// Link Sections Management Module - Self-Contained
(function() {
    'use strict';
    

    let sectionsListEl, addSectionBtn;
    // Expiration functionality moved to dedicated link-expiration.js module


    // DOM-based expiration state detection
    function isExpirationEnabled() {
        const sectionsListEl = document.getElementById('bp-link-sections-list');
        return sectionsListEl && sectionsListEl.dataset.expirationEnabled === 'true';
    }

    // Expiration modal functions moved to link-expiration.js module

    // getLinkExpirationEnabled moved to link-expiration.js and exposed globally

    // AJAX-based link item creation using server template system
    function createLinkItemHTML(sidx, lidx, linkData = {}, callback = null) {
        const sectionsListEl = document.getElementById('bp-link-sections-list');
        const linkPageId = sectionsListEl ? sectionsListEl.dataset.linkPageId : null;
        
        if (!linkPageId) {
            console.error('Link page ID not found in DOM data attributes');
            if (callback) callback('');
            return;
        }
        
        jQuery.post(extraChillArtistPlatform.ajaxUrl, {
            action: 'render_link_item_editor',
            link_page_id: linkPageId,
            sidx: sidx,
            lidx: lidx,
            link_data: linkData,
            expiration_enabled: false, // Always false - icons handled by JavaScript
            nonce: extraChillArtistPlatform.nonce
        }, function(response) {
            if (response.success && response.data.html) {
                if (callback) callback(response.data.html);
            } else {
                console.error('Failed to render link item:', response.data ? response.data.message : 'Unknown error');
                if (callback) callback('');
            }
        }).fail(function() {
            console.error('AJAX request failed for link item rendering');
            if (callback) callback('');
        });
    }
    
    // AJAX-based section creation using server template system
    function createSectionItemHTML(sidx, sectionData = {}, callback = null) {
        const sectionsListEl = document.getElementById('bp-link-sections-list');
        const linkPageId = sectionsListEl ? sectionsListEl.dataset.linkPageId : null;
        
        if (!linkPageId) {
            console.error('Link page ID not found in DOM data attributes');
            if (callback) callback('');
            return;
        }
        
        jQuery.post(extraChillArtistPlatform.ajaxUrl, {
            action: 'render_link_section_editor',
            link_page_id: linkPageId,
            sidx: sidx,
            section_data: sectionData,
            expiration_enabled: false, // Always false - icons handled by JavaScript
            nonce: extraChillArtistPlatform.nonce
        }, function(response) {
            if (response.success && response.data.html) {
                if (callback) callback(response.data.html);
            } else {
                console.error('Failed to render section:', response.data ? response.data.message : 'Unknown error');
                if (callback) callback('');
            }
        }).fail(function() {
            console.error('AJAX request failed for section rendering');
            if (callback) callback('');
        });
    }

    // Event delegation for add/remove/edit actions
    function attachEventListeners() {
        if (!sectionsListEl) return;

        sectionsListEl.addEventListener('click', function(e) {
            const target = e.target;
            let actionTaken = false;

            if (target.classList.contains('bp-remove-link-btn') || target.closest('.bp-remove-link-btn')) {
                e.preventDefault();
                const linkItem = target.closest('.bp-link-item');
                if (linkItem) {
                    const sectionIndex = parseInt(linkItem.dataset.sidx) || 0;
                    const linkIndex = parseInt(linkItem.dataset.lidx) || 0;
                    linkItem.remove();
                    dispatchLinkRemoved(sectionIndex, linkIndex);
                    actionTaken = true;
                }
            } else if (target.classList.contains('bp-remove-link-section-btn') || target.closest('.bp-remove-link-section-btn')) {
                e.preventDefault();
                const section = target.closest('.bp-link-section');
                if (section) {
                    const sectionIndex = parseInt(section.dataset.sidx) || 0;
                    section.remove();
                    dispatchSectionDeleted(sectionIndex);
                    actionTaken = true;
                }
            } else if (target.classList.contains('bp-add-link-btn') || target.closest('.bp-add-link-btn')) {
                e.preventDefault();
                const section = target.closest('.bp-link-section');
                if (section) {
                    const linkList = section.querySelector('.bp-link-list');
                    const sectionIndex = parseInt(section.dataset.sidx) || 0;
                    const linkIndex = linkList.children.length;
                    if (linkList) {
                        createLinkItemHTML(sectionIndex, linkIndex, {}, function(html) {
                            if (html) {
                                linkList.insertAdjacentHTML('beforeend', html);
                                
                                // Get the newly added link element and dispatch event
                                const newLinkElement = linkList.lastElementChild;
                                if (newLinkElement && newLinkElement.classList.contains('bp-link-item')) {
                                    document.dispatchEvent(new CustomEvent('linkItemCreated', {
                                        detail: { linkElement: newLinkElement }
                                    }));
                                }
                                
                                // Remove premature preview updates - let user input trigger events
                            }
                        });
                        // actionTaken handled in callback
                    }
                }
            } else if (target.classList.contains('bp-link-expiration-icon') || target.closest('.bp-link-expiration-icon')) {
                e.preventDefault();
                const linkItem = target.closest('.bp-link-item');
                if (linkItem) {
                    // Dispatch event for expiration module to handle
                    document.dispatchEvent(new CustomEvent('linkExpirationRequested', {
                        detail: { linkElement: linkItem }
                    }));
                }
            }
            // Events are now dispatched individually for each action type
        });

        // Event-driven preview updates on user input (following social icons pattern)
        sectionsListEl.addEventListener('blur', function(e) {
            const target = e.target;
            
            // Handle section title updates
            if (target.classList.contains('bp-link-section-title')) {
                const sectionIndex = getSectionIndexFromElement(target);
                const title = target.value.trim();
                if (title) {
                    dispatchSectionUpdated(sectionIndex, title);
                }
            }
            
            // Handle link text/URL updates
            else if (target.classList.contains('bp-link-text-input') || target.classList.contains('bp-link-url-input')) {
                const { sectionIndex, linkIndex } = getLinkIndicesFromElement(target);
                const linkItem = target.closest('.bp-link-item');
                if (linkItem) {
                    const textInput = linkItem.querySelector('.bp-link-text-input');
                    const urlInput = linkItem.querySelector('.bp-link-url-input');
                    
                    // Only dispatch if both text and URL have values
                    if (textInput && urlInput && textInput.value.trim() && urlInput.value.trim()) {
                        dispatchLinkUpdated(sectionIndex, linkIndex, 'complete', {
                            link_text: textInput.value.trim(),
                            link_url: urlInput.value.trim()
                        });
                    }
                }
            }
        }, true); // Use capture for child events
        
        sectionsListEl.addEventListener('change', function(e) {
            const target = e.target;
            
            // Handle any select/checkbox changes if needed in the future
            if (target.tagName === 'SELECT' || target.type === 'checkbox') {
                // Future: handle dropdown or checkbox changes
            }
        });
        
        // Expiration modal event listeners handled by link-expiration.js module
        // Add section button listener moved to init() function to follow social icons pattern
    }


    function updateAllIndices() {
        if (!sectionsListEl) return;
        let sidx = 0;
        sectionsListEl.querySelectorAll('.bp-link-section').forEach(sectionEl => {
            sectionEl.dataset.sidx = sidx;
            const sectionTitleInput = sectionEl.querySelector('.bp-link-section-title');
            if (sectionTitleInput) sectionTitleInput.dataset.sidx = sidx;
            const addLinkBtnInSection = sectionEl.querySelector('.bp-add-link-btn');
            if (addLinkBtnInSection) addLinkBtnInSection.dataset.sidx = sidx;
            const removeSectionBtnEl = sectionEl.querySelector('.bp-remove-link-section-btn');
            if (removeSectionBtnEl) removeSectionBtnEl.dataset.sidx = sidx;
            
            let lidx = 0;
            sectionEl.querySelectorAll('.bp-link-item').forEach(linkEl => {
                linkEl.dataset.sidx = sidx;
                linkEl.dataset.lidx = lidx;
                const expIcon = linkEl.querySelector('.bp-link-expiration-icon');
                if (expIcon) {
                    expIcon.dataset.sidx = sidx;
                    expIcon.dataset.lidx = lidx;
                }
                lidx++;
            });
            sidx++;
        });
    }

    
    // Extract links data from DOM form fields (similar to social icons system)
    function getLinksDataFromDOM() {
        const linksData = [];
        const sections = document.querySelectorAll('.bp-link-section');
        
        sections.forEach((section, sectionIndex) => {
            const sectionTitleInput = section.querySelector('.bp-link-section-title');
            const sectionTitle = sectionTitleInput ? sectionTitleInput.value.trim() : '';
            
            const sectionData = {
                section_title: sectionTitle,
                links: []
            };
            
            const linkItems = section.querySelectorAll('.bp-link-item');
            linkItems.forEach((linkItem, linkIndex) => {
                const textInput = linkItem.querySelector('input[name*="link_text"]');
                const urlInput = linkItem.querySelector('input[name*="link_url"]');
                
                if (textInput && urlInput && (textInput.value.trim() || urlInput.value.trim())) {
                    sectionData.links.push({
                        link_text: textInput.value.trim(),
                        link_url: urlInput.value.trim()
                    });
                }
            });
            
            // Only include sections that have a title or at least one link
            if (sectionTitle || sectionData.links.length > 0) {
                linksData.push(sectionData);
            }
        });
        
        return linksData;
    }

    // Event dispatchers for specific link actions
    function dispatchLinkAdded(sectionIndex, linkIndex, linkData) {
        document.dispatchEvent(new CustomEvent('linkadded', {
            detail: { sectionIndex, linkIndex, link: linkData }
        }));
    }

    function dispatchLinkUpdated(sectionIndex, linkIndex, field, value) {
        document.dispatchEvent(new CustomEvent('linkupdated', {
            detail: { sectionIndex, linkIndex, field, value }
        }));
    }

    function dispatchLinkRemoved(sectionIndex, linkIndex) {
        document.dispatchEvent(new CustomEvent('linkdeleted', {
            detail: { sectionIndex, linkIndex }
        }));
    }

    function dispatchSectionAdded(sectionIndex, sectionTitle) {
        document.dispatchEvent(new CustomEvent('linksectionadded', {
            detail: { sectionIndex, title: sectionTitle }
        }));
    }

    function dispatchSectionUpdated(sectionIndex, title) {
        document.dispatchEvent(new CustomEvent('linksectiontitleupdated', {
            detail: { sectionIndex, title }
        }));
    }

    function dispatchSectionDeleted(sectionIndex) {
        document.dispatchEvent(new CustomEvent('linksectiondeleted', {
            detail: { sectionIndex }
        }));
    }

    // Helper functions to extract indices from DOM elements
    function getSectionIndexFromElement(element) {
        const sectionElement = element.closest('.bp-link-section');
        return sectionElement ? parseInt(sectionElement.dataset.sidx) || 0 : 0;
    }

    function getLinkIndicesFromElement(element) {
        const linkItem = element.closest('.bp-link-item');
        const sectionElement = element.closest('.bp-link-section');
        const sectionIndex = sectionElement ? parseInt(sectionElement.dataset.sidx) || 0 : 0;
        const linkIndex = linkItem ? parseInt(linkItem.dataset.lidx) || 0 : 0;
        return { sectionIndex, linkIndex };
    }


    // No longer needed - HTML generation moved to server-side templates
    

    // Simple initialization - just set up event listeners
    function init() {
        sectionsListEl = document.getElementById('bp-link-sections-list');
        addSectionBtn = document.getElementById('bp-add-link-section-btn');
        
        // Set up add section button listener
        if (addSectionBtn) {
            addSectionBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Re-query for sections container at click time
                const currentSectionsListEl = document.getElementById('bp-link-sections-list');
                const sectionIndex = currentSectionsListEl.children.length;
                
                createSectionItemHTML(sectionIndex, {}, function(html) {
                    if (html) {
                        currentSectionsListEl.insertAdjacentHTML('beforeend', html);
                        dispatchSectionAdded(sectionIndex, '');
                    }
                });
            });
        }
        
        // Set up section-related listeners if sections container exists
        if (sectionsListEl) {
            attachEventListeners();
        }
        
        console.log('[Links] Form management initialized');
    }

    // Listen for links tab activation
    document.addEventListener('linksTabActivated', function(event) {
        init();
    });

    // Listen for sortable events and update indices automatically
    // Note: Preview movement handled by sorting-preview.js module
    document.addEventListener('linkMoved', function() {
        updateAllIndices();
    });
    
    document.addEventListener('sectionMoved', function() {
        updateAllIndices();
    });


})();