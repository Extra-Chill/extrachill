// Link Sections Management Module
(function(manager) {
    if (!manager) {
        console.error('ExtrchLinkPageManager is not defined. Link sections script cannot run.');
        return;
    }
    manager.linkSections = manager.linkSections || {};

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
            console.error('One or more expiration modal controls not found.');
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

    function createLinkItemHTML(sidx, lidx, linkData = {}) {
        const linkText = linkData.link_text || '';
        const linkUrl = linkData.link_url || '';
        const expiresAt = linkData.expires_at || '';
        const isExpirationEnabled = getLinkExpirationEnabled();
        let expirationIconHTML = '';
        if (isExpirationEnabled) {
            expirationIconHTML = `<span class="bp-link-expiration-icon" title="Set expiration date" data-sidx="${sidx}" data-lidx="${lidx}">&#x23F3;</span>`;
        }

        return `
            <div class="bp-link-item" data-sidx="${sidx}" data-lidx="${lidx}" data-expires-at="${escapeHTML(expiresAt)}">
                        <span class="bp-link-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                <input type="text" class="bp-link-text-input" placeholder="Link Text" value="${escapeHTML(linkText)}">
                <input type="url" class="bp-link-url-input" placeholder="URL" value="${escapeHTML(linkUrl)}">
                ${expirationIconHTML}
                        <a href="#" class="bp-remove-link-btn bp-remove-item-link ml-auto" title="Remove Link">&times;</a>
            </div>
        `;
    }
    
    function createSectionItemHTML(sidx, sectionData = {}) {
        const sectionTitle = sectionData.section_title || '';
        let linksHTML = '';
        if (sectionData.links && Array.isArray(sectionData.links)) {
            sectionData.links.forEach((link, lidx) => {
                linksHTML += createLinkItemHTML(sidx, lidx, link);
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
            console.error('AJAX config for fetching link title not available.');
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
                console.error('Network response was not ok for fetching title.', response);
                textInputElement.placeholder = 'Link Text'; // Reset placeholder
                return;
            }

            const result = await response.json();

            if (result.success && result.data && result.data.title) {
                textInputElement.value = result.data.title;
                debouncedUpdateLinksPreview(); // Update preview as text has changed
            } else {
                console.warn('Failed to fetch title or title not found:', result.data ? result.data.message : 'No message');
                // textInputElement.placeholder = 'Link Text'; // Reset placeholder - or leave "fetching" if desired on fail
            }
        } catch (error) {
            console.error('Error fetching link title:', error);
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

    function updateLinksPreview() {
        if (!manager.contentPreview || typeof manager.contentPreview.renderLinkSections !== 'function') {
            // console.warn('[Links Mod] renderLinkSections function not found on contentPreview.');
            return;
        }
        const linksData = getLinksDataFromDOM();
        const previewEl = manager.getPreviewEl ? manager.getPreviewEl() : null;
        if (!previewEl) {
            // console.warn('[Links Mod] Preview element not found for updating links preview.');
            return;
        }
        const contentWrapperEl = previewEl.querySelector('.extrch-link-page-content-wrapper');
        if (!contentWrapperEl) {
            // console.warn('[Links Mod] Content wrapper element not found in preview for links.');
            return;
        }
        manager.contentPreview.renderLinkSections(linksData, previewEl, contentWrapperEl);
    }

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

    manager.linkSections.init = function() {
        if (!sectionsListEl) {
            console.log("Links tab sections list DOM element not found. Skipping links initialization.");
            return;
        }
        if (!initializeExpirationModalDOM()) {
            // console.warn("Expiration Modal could not be initialized. Expiration features might not work."); // Less noisy
        }
        attachEventListeners();
        initializeSortableForSections();
        initializeSortableForLinksInSections();
        updateLinksPreview(); // Initial preview render based on PHP-generated DOM
        console.log("Links Tab Initialized (New Architecture with Preview Update).");
    };

    // Expose getLinksDataFromDOM for the save handler
    manager.linkSections.getLinksDataFromDOM = getLinksDataFromDOM;

    // Initialize when the main manager is ready
    // This assumes ExtrchLinkPageManager.init() calls this module's init.
    // Or, if you prefer direct DOMContentLoaded:
    // document.addEventListener('DOMContentLoaded', manager.linkSections.init);

    // After updateLinksPreview, dispatch the custom event for Advanced tab hydration
    function dispatchLinksUpdatedEvent() {
        document.dispatchEvent(new CustomEvent('ExtrchLinkPageLinksUpdated'));
    }

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});