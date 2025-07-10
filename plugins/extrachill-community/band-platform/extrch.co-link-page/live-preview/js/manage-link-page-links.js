// Link Sections Management Module
(function(manager) {
    if (!manager) {
        // console.error('ExtrchLinkPageManager is not defined. Link sections script cannot run.'); // Keep critical errors
        return;
    }
    manager.links = manager.links || {};
    manager.links.allowPreviewUpdate = false; // Initialize the flag

    const sectionsListEl = document.getElementById('bp-link-sections-list');
    const addSectionBtn = document.getElementById('bp-add-link-section-btn');
    let expirationModal, expirationDatetimeInput, saveExpirationBtn, clearExpirationBtn, cancelExpirationBtn;
    let currentEditingLinkItem = null; // To store the .bp-link-item being edited for expiration

    // Debounce for input updates to preview
    const debouncedUpdateLinksPreview = debounce(updateLinksPreview, 300);

    function initializeExpirationModalDOM() {
        expirationModal = document.getElementById('bp-link-expiration-modal');
        if (!expirationModal) {
            // console.error('Expiration modal DOM not found.'); // Less noisy
            return false;
        }
        expirationDatetimeInput = document.getElementById('bp-link-expiration-datetime');
        saveExpirationBtn = document.getElementById('bp-save-link-expiration');
        clearExpirationBtn = document.getElementById('bp-clear-link-expiration');
        cancelExpirationBtn = document.getElementById('bp-cancel-link-expiration');

        if (!expirationDatetimeInput || !saveExpirationBtn || !clearExpirationBtn || !cancelExpirationBtn) {
            console.error('One or more expiration modal controls not found.'); // Keep critical errors
            return false;
        }
        return true;
    }

    function openExpirationModal(linkItem) {
        if (!expirationModal || !expirationDatetimeInput) return;
        currentEditingLinkItem = linkItem;
        const currentExpiration = linkItem.dataset.expiresAt || '';
        expirationDatetimeInput.value = currentExpiration;
        expirationModal.style.display = 'flex'; // Or 'block', depending on your modal CSS
        expirationDatetimeInput.focus();
    }

    function closeExpirationModal() {
        if (!expirationModal) return;
        expirationModal.style.display = 'none';
        currentEditingLinkItem = null;
    }

    function saveLinkExpiration() {
        if (!currentEditingLinkItem || !expirationDatetimeInput) return;
        currentEditingLinkItem.dataset.expiresAt = expirationDatetimeInput.value;
        closeExpirationModal();
        updateLinksPreview();
        dispatchLinksUpdatedEvent();
    }

    function clearLinkExpiration() {
        if (!currentEditingLinkItem) return;
        currentEditingLinkItem.dataset.expiresAt = '';
        closeExpirationModal();
        updateLinksPreview();
        dispatchLinksUpdatedEvent();
    }

    function getLinkExpirationEnabled() {
        // Prefer the new global config, fallback to data attribute if global is not set (for safety)
        if (window.extrchLinkPageConfig && typeof window.extrchLinkPageConfig.linkExpirationEnabled !== 'undefined') {
            return window.extrchLinkPageConfig.linkExpirationEnabled;
        }
        return sectionsListEl && sectionsListEl.dataset.expirationEnabled === 'true';
    }

    function createLinkItemHTML(sidx, lidx, linkData = {}, initialFeaturedUrl = null) {
        const linkText = linkData.link_text || '';
        const linkUrl = linkData.link_url || '';
        const expiresAt = linkData.expires_at || '';
        const linkId = linkData.id || 'link_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9); // Generate unique ID if not present
        const isExpirationEnabled = getLinkExpirationEnabled();
        let expirationIconHTML = '';
        if (isExpirationEnabled) {
            expirationIconHTML = `<span class="bp-link-expiration-icon" title="Set expiration date" data-sidx="${sidx}" data-lidx="${lidx}">&#x23F3;</span>`;
        }

        let itemClasses = 'bp-link-item';
        if (initialFeaturedUrl && linkUrl && linkUrl.replace(/\/$/, '') === initialFeaturedUrl.replace(/\/$/, '')) {
            itemClasses += ' bp-editor-featured-link';
        }

        return `
            <div class="${itemClasses}" data-sidx="${sidx}" data-lidx="${lidx}" data-expires-at="${escapeHTML(expiresAt)}" data-link-id="${escapeHTML(linkId)}">
                        <span class="bp-link-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                <input type="text" class="bp-link-text-input" placeholder="Link Text" value="${escapeHTML(linkText)}">
                <input type="url" class="bp-link-url-input" placeholder="URL" value="${escapeHTML(linkUrl)}">
                ${expirationIconHTML}
                        <a href="#" class="bp-remove-link-btn bp-remove-item-link ml-auto" title="Remove Link">&times;</a>
            </div>
        `;
    }
    
    function createSectionItemHTML(sidx, sectionData = {}, initialFeaturedUrl = null) {
        const sectionTitle = sectionData.section_title || '';
        let linksHTML = '';
        if (sectionData.links && Array.isArray(sectionData.links)) {
            sectionData.links.forEach((link, lidx) => {
                linksHTML += createLinkItemHTML(sidx, lidx, link, initialFeaturedUrl);
            });
        }

        return `
            <div class="bp-link-section" data-sidx="${sidx}">
                <div class="bp-link-section-header">
                    <span class="bp-section-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <input type="text" class="bp-link-section-title" placeholder="Section Title (optional)" value="${escapeHTML(sectionTitle)}" data-sidx="${sidx}">
                    <div class="bp-section-actions-group ml-auto">
                        <a href="#" class="bp-remove-link-section-btn bp-remove-item-link" data-sidx="${sidx}" title="Remove Section">&times;</a>
                    </div>
                </div>
                <div class="bp-link-list">
                    ${linksHTML}
                </div>
                <button type="button" class="button button-secondary bp-add-link-btn" data-sidx="${sidx}"><i class="fas fa-plus"></i> Add Link</button>
            </div>
        `;
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
                    linkItem.remove();
                    updateAllIndices();
                    actionTaken = true;
                }
            } else if (target.classList.contains('bp-remove-link-section-btn') || target.closest('.bp-remove-link-section-btn')) {
                e.preventDefault();
                const section = target.closest('.bp-link-section');
                if (section) {
                    section.remove();
                    updateAllIndices();
                    actionTaken = true;
                }
            } else if (target.classList.contains('bp-add-link-btn') || target.closest('.bp-add-link-btn')) {
                e.preventDefault();
                const section = target.closest('.bp-link-section');
                if (section) {
                    const linkList = section.querySelector('.bp-link-list');
                    const sidx = section.dataset.sidx;
                    const lidx = linkList.children.length;
                    if (linkList) {
                        const newLinkHTML = createLinkItemHTML(sidx, lidx);
                        linkList.insertAdjacentHTML('beforeend', newLinkHTML);
                        initializeSortableForLinksInSections(); // Re-init for the list containing the new link
                        actionTaken = true;
                    }
                }
            } else if (target.classList.contains('bp-link-expiration-icon') || target.closest('.bp-link-expiration-icon')) {
                e.preventDefault();
                const linkItem = target.closest('.bp-link-item');
                if (linkItem) {
                    openExpirationModal(linkItem);
                }
            }
            if (actionTaken) {
                updateLinksPreview();
                dispatchLinksUpdatedEvent();
            }
        });

        sectionsListEl.addEventListener('input', function(e) {
            const target = e.target;
            if (target.classList.contains('bp-link-section-title') ||
                target.classList.contains('bp-link-text-input') ||
                (target.classList.contains('bp-link-url-input') && !target.dataset.isFetchingTitle)) {
                debouncedUpdateLinksPreview();

                // --- Real-time featured link title update ---
                if (target.classList.contains('bp-link-text-input')) {
                    // Find the URL input in the same .bp-link-item
                    const linkItem = target.closest('.bp-link-item');
                    if (linkItem) {
                        const urlInput = linkItem.querySelector('.bp-link-url-input');
                        if (urlInput) {
                            // Get the current featured link URL from the featured link select (if present)
                            const featuredLinkSelect = document.getElementById('bp-featured-link-original-id');
                            if (featuredLinkSelect && featuredLinkSelect.value) {
                                const featuredUrl = featuredLinkSelect.value.replace(/\/$/, '');
                                const thisUrl = urlInput.value.replace(/\/$/, '');
                                if (thisUrl === featuredUrl) {
                                    // Call the featured link preview update with the new title
                                    if (window.ExtrchLinkPageManager && window.ExtrchLinkPageManager.featuredLink && typeof window.ExtrchLinkPageManager.featuredLink.triggerFeaturedLinkPreviewUpdate === 'function') {
                                        window.ExtrchLinkPageManager.featuredLink.triggerFeaturedLinkPreviewUpdate({ title: target.value });
                                    }
                                }
                            }
                        }
                    }
                }
                // --- End real-time featured link title update ---
            }
        });

        // Listen for 'blur' on URL inputs to fetch title
        sectionsListEl.addEventListener('blur', function(e) {
            const target = e.target;
            if (target.classList.contains('bp-link-url-input')) {
                const linkItem = target.closest('.bp-link-item');
                if (linkItem) {
                    const textInput = linkItem.querySelector('.bp-link-text-input');
                    // Fetch if text input is empty and URL input has a potential URL
                    if (textInput && textInput.value.trim() === '' && target.value.trim() !== '' && (target.value.startsWith('http') || target.value.startsWith('www'))) {
                        fetchAndSetLinkTitle(target, textInput);
                    }
                }
            }
        }, true); // Use capture phase to ensure blur is caught

        if (addSectionBtn) {
            addSectionBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const sidx = sectionsListEl.children.length;
                const newSectionHTML = createSectionItemHTML(sidx);
                sectionsListEl.insertAdjacentHTML('beforeend', newSectionHTML);
                const newSectionEl = sectionsListEl.lastElementChild;
                if (newSectionEl) {
                    initializeSortableForLinksInSections();
                }
                initializeSortableForSections();
                updateLinksPreview();
                dispatchLinksUpdatedEvent();
            });
        }

        if (expirationModal) {
             saveExpirationBtn.addEventListener('click', saveLinkExpiration);
             clearExpirationBtn.addEventListener('click', clearLinkExpiration);
             cancelExpirationBtn.addEventListener('click', closeExpirationModal);
             expirationModal.addEventListener('click', function(e) {
                 if (e.target === expirationModal) {
                     closeExpirationModal();
                 }
             });
        }
    }

    async function fetchAndSetLinkTitle(urlInputElement, textInputElement) {
        if (!window.extrchLinkPageConfig || !window.extrchLinkPageConfig.ajax_url || !window.extrchLinkPageConfig.fetch_link_title_nonce) {
            console.error('AJAX config for fetching link title not available.'); // Keep critical errors
            return;
        }

        const urlToFetch = urlInputElement.value.trim();
        if (!urlToFetch) return;

        // Set a flag to prevent debouncedUpdateLinksPreview from firing due to this programmatic change
        urlInputElement.dataset.isFetchingTitle = 'true';


        const formData = new FormData();
        formData.append('action', 'fetch_link_meta_title');
        formData.append('_ajax_nonce', window.extrchLinkPageConfig.fetch_link_title_nonce);
        formData.append('url', urlToFetch);

        // Add a visual cue (optional)
        textInputElement.placeholder = 'Fetching title...';

        try {
            const response = await fetch(window.extrchLinkPageConfig.ajax_url, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                console.error('Network response was not ok for fetching title.', response); // Keep critical errors
                textInputElement.placeholder = 'Link Text'; // Reset placeholder
                return;
            }

            const result = await response.json();

            if (result.success && result.data && result.data.title) {
                textInputElement.value = result.data.title;
                debouncedUpdateLinksPreview(); // Update preview as text has changed
            } else {
                // console.warn('Failed to fetch title or title not found:', result.data ? result.data.message : 'No message'); // Comment out non-critical warning
            }
        } catch (error) {
            console.error('Error fetching link title:', error); // Keep critical errors
        } finally {
            textInputElement.placeholder = 'Link Text'; // Always reset placeholder
            delete urlInputElement.dataset.isFetchingTitle; // Remove flag
        }
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

    let sectionsSortableInstance = null;
        function initializeSortableForSections() {
            if (sectionsSortableInstance) {
                sectionsSortableInstance.destroy();
            }
        if (sectionsListEl && typeof Sortable !== 'undefined') {
                sectionsSortableInstance = new Sortable(sectionsListEl, {
                    animation: 150,
                    handle: '.bp-section-drag-handle',
                onEnd: function () {
                    updateAllIndices();
                    updateLinksPreview();
                    }
                });
            }
        }

        function initializeSortableForLinksInSections() {
        if (!sectionsListEl || typeof Sortable === 'undefined') return;
        sectionsListEl.querySelectorAll('.bp-link-list').forEach(listEl => {
            // Destroy existing instance if any (important for re-initialization)
            if (listEl.sortableLinkInstance) {
                    listEl.sortableLinkInstance.destroy();
                }
                listEl.sortableLinkInstance = new Sortable(listEl, {
                    animation: 150,
                    handle: '.bp-link-drag-handle', 
                group: 'linksGroup', // Allows dragging between sections
                onEnd: function() {
                    updateAllIndices();
                    updateLinksPreview();
                }
            });
        });
                                }
    
    function getLinksDataFromDOM() {
        if (!sectionsListEl) return [];
        const sectionsData = [];
        sectionsListEl.querySelectorAll('.bp-link-section').forEach(sectionEl => {
            const sectionTitle = sectionEl.querySelector('.bp-link-section-title')?.value || '';
            const linksData = [];
            sectionEl.querySelectorAll('.bp-link-item').forEach(linkEl => {
                linksData.push({
                    id: linkEl.dataset.linkId || ('link_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9)), // Include id, generate if missing
                    link_text: linkEl.querySelector('.bp-link-text-input')?.value || '',
                    link_url: linkEl.querySelector('.bp-link-url-input')?.value || '',
                    expires_at: linkEl.dataset.expiresAt || '',
                    // link_is_active can be added here if a toggle is implemented in the future
                });
            });
            sectionsData.push({ section_title: sectionTitle, links: linksData });
        });
        return sectionsData;
    }

    // REVISED updateLinksPreview: Only updates preview, NO hidden input updates during user interactions
    function updateLinksPreview() {
        const sectionsData = getLinksDataFromDOM(); // Get current links from the editor UI

        // Always set the skip URL for the featured link before rendering
        if (
            manager.featuredLink &&
            typeof manager.featuredLink.getCurrentFeaturedUrlToSkip === 'function'
        ) {
            manager.contentPreview.setFeaturedLinkUrlToSkipForPreview(
                manager.featuredLink.getCurrentFeaturedUrlToSkip()
            );
        }

        if (manager.contentPreview && typeof manager.contentPreview.renderLinkSections === 'function') {
            const previewEl = manager.getPreviewEl();
            const contentWrapperEl = manager.getPreviewContentWrapperEl();
            if (previewEl && contentWrapperEl) {
                manager.contentPreview.renderLinkSections(sectionsData, previewEl, contentWrapperEl);
            }
        }
        
        // NO HIDDEN INPUT UPDATES during user interactions - wait for save time
        // This prevents scattered save logic and race conditions
    }
    manager.links.updateLinksPreview = updateLinksPreview; // Expose it on manager.links

    /**
     * Serializes current links data into the hidden input for form submission.
     * This method should ONLY be called by the save handler, not during user interactions.
     */
    function serializeLinksForSave() {
        const sectionsData = getLinksDataFromDOM();
        const hiddenInput = document.getElementById('link_page_links_json');
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(sectionsData);
            console.log('[LinksManager] Serialized links for save:', sectionsData.length, 'sections');
            return true;
        } else {
            console.warn('[LinksManager] Hidden input link_page_links_json not found for saving');
            return false;
        }
    }
    
    // Expose the serialize method for the save handler
    manager.links.serializeForSave = serializeLinksForSave;

    // Utility function to escape HTML entities for use in HTML attributes or content
    function escapeHTML(str) {
        if (typeof str !== 'string') return '';
        return str.replace(/[&<"'`]/g, function (match) {
            switch (match) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#39;';
                case '`': return '&#96;';
                default: return match;
            }
        });
    }
    
    // Debounce utility (local to this module if not globally available via manager)
    function debounce(func, delay) {
        let timeout;
        return function(...args) {
            const context = this;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), delay);
        };
    }

    // --- Initialize the Links Manager ---
    function initLinksManager() {
        if (!sectionsListEl) {
            return;
        }

        const initialData = manager.getInitialData(); // Get data passed from PHP
        let initialLinksToRender = [];
        let initialFeaturedUrlForDomClass = null;

        if (initialData) {
            if (initialData.links && Array.isArray(initialData.links)) {
                initialLinksToRender = initialData.links;
            }
            if (initialData.featuredLinkUrlToSkip) { // This comes from PHP via LivePreviewManager
                initialFeaturedUrlForDomClass = initialData.featuredLinkUrlToSkip;
            }
        }
        
        // Override with data from hidden input if it exists and is primary for link structure
        // This ensures the editor UI matches exactly what would be saved if no changes are made.
        const linksJsonInput = document.getElementById('link_page_links_json');
        if (linksJsonInput && linksJsonInput.value) {
            try {
                const parsedLinks = JSON.parse(linksJsonInput.value);
                if (Array.isArray(parsedLinks)) {
                    initialLinksToRender = parsedLinks; 
                }
            } catch (e) {
                console.error('[LinksManager] Error parsing initial links JSON from hidden input:', e);
            }
        }
        
        window.bpLinkPageLinks = JSON.parse(JSON.stringify(initialLinksToRender)); // Deep clone for global

        // populateInitialLinks(initialLinksToRender, initialFeaturedUrlForDomClass);

        if (initializeExpirationModalDOM()) {
            // console.log("Expiration modal initialized."); 
        }
        attachEventListeners();
        initializeSortableForSections();
        initializeSortableForLinksInSections(); 

        // 5. Set allowPreviewUpdate to true now that initial setup is done
        manager.links.allowPreviewUpdate = true;
        // updateLinksPreview(); // REMOVE this call to prevent JS from re-rendering the preview on initial load

        // 6. Dispatch event indicating links are ready and window.bpLinkPageLinks is populated
        dispatchLinksUpdatedEvent(); 
        console.log('[LinksManager] Initialized, window.bpLinkPageLinks populated, event dispatched.');
    }

    manager.links.init = initLinksManager; 

    // Remove automatic DOMContentLoaded initialization
    /*
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLinksManager);
    } else {
        initLinksManager();
    }
    */

    // After updateLinksPreview, dispatch the custom event for Advanced tab hydration
    function dispatchLinksUpdatedEvent() {
        document.dispatchEvent(new CustomEvent('ExtrchLinkPageLinksUpdated'));
        document.dispatchEvent(new CustomEvent('bpLinkPageLinksRefreshed')); // Fire both for now
    }

    manager.links.getLinksData = getLinksDataFromDOM; // Expose the function

    function populateInitialLinks(initialLinkSectionsData, initialFeaturedUrl = null) {
        if (!sectionsListEl) return;
        sectionsListEl.innerHTML = ''; // Clear existing sections
        if (initialLinkSectionsData && Array.isArray(initialLinkSectionsData)) {
            initialLinkSectionsData.forEach((sectionData, sidx) => {
                const sectionHTML = createSectionItemHTML(sidx, sectionData, initialFeaturedUrl);
                sectionsListEl.insertAdjacentHTML('beforeend', sectionHTML);
            });
        }
        initializeSortableForSections();
    }

    // Listen for featured link changes and update the highlighted class in the editor UI
    document.addEventListener('featuredLinkOriginalUrlChanged', function(e) {
        const newFeaturedUrl = (e.detail && e.detail.newUrl) ? e.detail.newUrl.replace(/\/$/, '') : null;
        if (!sectionsListEl) return;
        // Remove the class from all link items
        sectionsListEl.querySelectorAll('.bp-link-item').forEach(item => {
            item.classList.remove('bp-editor-featured-link');
        });
        if (newFeaturedUrl) {
            // Find the link item whose URL input matches the new featured URL
            sectionsListEl.querySelectorAll('.bp-link-item').forEach(item => {
                const urlInput = item.querySelector('.bp-link-url-input');
                if (urlInput && urlInput.value.replace(/\/$/, '') === newFeaturedUrl) {
                    item.classList.add('bp-editor-featured-link');
                }
            });
        }
    });

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});