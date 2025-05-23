// Link Sections Management Module
(function(manager) {
    if (!manager) {
        console.error('ExtrchLinkPageManager is not defined. Link sections script cannot run.');
        return;
    }
    manager.linkSections = manager.linkSections || {};

    // Utility function to escape HTML entities
    function escapeHTML(str) {
        if (typeof str !== 'string') return '';
        return str.replace(/[&<>"'`]/g, function (match) {
            switch (match) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#39;'; // &apos; is not recommended by HTML5/XHTML1; &#39; is better.
                case '`': return '&#96;'; // Grave accent
                default: return match;
            }
        });
    }

    const TEMPORARY_REDIRECT_CODE = '302';
    const PERMANENT_REDIRECT_CODE = '301';

    let isInitialized = false;
    let isInitialLinkRender = true; // To prevent console warnings on first load if previewEl is not yet ready

    let sectionsSortableInstance = null; // For the main sections list
    // For links within sections, instances will be attached to their respective DOM elements.

    manager.linkSections.init = function() {
        const inputEl = document.getElementById('link_page_links_json');
        let initialSectionsDataString = '[]'; // Default to an empty array string
        if (inputEl && inputEl.value) {
            initialSectionsDataString = inputEl.value;
        } else if (manager.initialLinkSectionsData) { // Fallback to the manager's property if input is missing
            try {
                initialSectionsDataString = JSON.stringify(manager.initialLinkSectionsData);
            } catch (e) {
                console.error('[LinksBrain] Error stringifying manager.initialLinkSectionsData:', e);
                initialSectionsDataString = '[]';
            }
        }
        
        let sections = [];
        try {
            sections = JSON.parse(initialSectionsDataString);
            // Ensure it's an array after parsing
            if (!Array.isArray(sections)) {
                console.warn('[LinksBrain] Parsed initial links data is not an array. Defaulting to empty array. Data:', sections);
                sections = [];
            }
        } catch (e) {
            console.error('[LinksBrain] Error parsing initial links JSON from input/manager. Defaulting to empty array. Error:', e, 'Data string:', initialSectionsDataString);
            sections = [];
        }

        // Backward compatibility for old flat link structure OR ensure sections.links is an array
        if (sections.length > 0) {
            if (sections[0] && typeof sections[0].links === 'undefined' && typeof sections[0].section_title === 'undefined') {
                // This condition suggests it's an old flat array of links, not sections. Wrap it in a default section.
                console.warn('[LinksBrain] Detected old flat link structure. Wrapping in a default section.');
                sections = [{ section_title: '', links: sections }];
            } else {
                // Ensure all sections have a `links` array
                sections.forEach(section => {
                    if (!section || !Array.isArray(section.links)) {
                        if (section) section.links = []; // Initialize as empty array if not already an array
                        else section = { section_title: '', links: [] }; // Should not happen if initial parsing is robust
                    }
                });
            }
        }

        const sectionsListEl = document.getElementById('bp-link-sections-list');

        let expirationModal = document.getElementById('bp-link-expiration-modal');
        if (!expirationModal) {
            expirationModal = document.createElement('div');
            expirationModal.id = 'bp-link-expiration-modal';
            expirationModal.className = 'bp-link-expiration-modal';
            expirationModal.innerHTML = `
                <div class="bp-link-expiration-modal-inner">
                    <h3 class="bp-link-expiration-modal-title">Set Link Expiration</h3>
                    <label class="bp-link-expiration-modal-label">
                        Expiration Date/Time:<br>
                        <input type="datetime-local" id="bp-link-expiration-datetime" class="bp-link-expiration-datetime">
                    </label>
                    <div class="bp-link-expiration-modal-actions">
                        <button type="button" class="button button-primary" id="bp-save-link-expiration">Save</button>
                        <button type="button" class="button" id="bp-clear-link-expiration">Clear</button>
                        <button type="button" class="button" id="bp-cancel-link-expiration">Cancel</button>
                    </div>
                </div>
            `;
            document.body.appendChild(expirationModal);
        }
        let expirationModalSectionIdx = null;
        let expirationModalLinkIdx = null;

        function openExpirationModal(sectionIdx, linkIdx, currentValue) {
            expirationModal.classList.add('is-active');
            expirationModalSectionIdx = sectionIdx;
            expirationModalLinkIdx = linkIdx;
            const dtInput = document.getElementById('bp-link-expiration-datetime');
            dtInput.value = currentValue || '';
            dtInput.focus();
        }
        function closeExpirationModal() {
            expirationModal.classList.remove('is-active');
            expirationModalSectionIdx = null;
            expirationModalLinkIdx = null;
        }
        expirationModal.addEventListener('click', function(e) {
            if (e.target === expirationModal) closeExpirationModal();
        });
        document.getElementById('bp-cancel-link-expiration').onclick = closeExpirationModal;
        document.getElementById('bp-clear-link-expiration').onclick = function() {
            if (expirationModalSectionIdx !== null && expirationModalLinkIdx !== null) {
                sections[expirationModalSectionIdx].links[expirationModalLinkIdx].expires_at = '';
                updateInput(); // This will call renderSections and re-init sortables
            }
            closeExpirationModal();
        };
        document.getElementById('bp-save-link-expiration').onclick = function() {
            const dtInput = document.getElementById('bp-link-expiration-datetime');
            if (expirationModalSectionIdx !== null && expirationModalLinkIdx !== null) {
                sections[expirationModalSectionIdx].links[expirationModalLinkIdx].expires_at = dtInput.value;
                updateInput(); // This will call renderSections and re-init sortables
            }
            closeExpirationModal();
        };

        let expirationEnabled = false;
        const expirationToggle = document.getElementById('bp-enable-link-expiration');
        const expirationHiddenInput = document.getElementById('link_expiration_enabled');
        
        if (expirationHiddenInput) {
            expirationEnabled = expirationHiddenInput.value === '1';
            if (expirationToggle) {
                expirationToggle.checked = expirationEnabled;
            }
        }
        if (expirationToggle) {
            expirationToggle.addEventListener('change', function() {
                expirationEnabled = !!expirationToggle.checked;
                if (expirationHiddenInput) {
                    expirationHiddenInput.value = expirationEnabled ? '1' : '0';
                }
                renderSections(); // Re-render to show/hide expiration icons
            });
            if (expirationHiddenInput && expirationToggle.checked !== (expirationHiddenInput.value === '1')) {
                 expirationHiddenInput.value = expirationToggle.checked ? '1' : '0';
            }
        }
        
        const form = document.getElementById('bp-manage-link-page-form');
        if (form) {
            form.addEventListener('submit', function() {
                if (expirationHiddenInput && expirationToggle) {
                    expirationHiddenInput.value = expirationToggle.checked ? '1' : '0';
                }
                // Ensure the final state of sections is in the input before submit
                if (inputEl) {
                    inputEl.value = JSON.stringify(sections);
                }
            });
        }

        function initializeSortableForSections() {
            if (sectionsSortableInstance) {
                sectionsSortableInstance.destroy();
                sectionsSortableInstance = null;
            }
            if (sectionsListEl && typeof Sortable !== 'undefined') {
                sectionsSortableInstance = new Sortable(sectionsListEl, {
                    animation: 150,
                    handle: '.bp-section-drag-handle',
                    onEnd: function (evt) {
                        if (evt.oldIndex === evt.newIndex) return;
                        const item = sections.splice(evt.oldIndex, 1)[0];
                        sections.splice(evt.newIndex, 0, item);
                        renderSections(); // Re-render to fix indices, which calls updateInput()
                    }
                });
            }
        }

        function initializeSortableForLinksInSections() {
            if (typeof Sortable === 'undefined') return;
            document.querySelectorAll('.bp-link-list').forEach((listEl) => {
                if (listEl.sortableLinkInstance) { // Check for instance attached to the element
                    listEl.sortableLinkInstance.destroy();
                    listEl.sortableLinkInstance = null;
                }
                listEl.sortableLinkInstance = new Sortable(listEl, {
                    animation: 150,
                    handle: '.bp-link-drag-handle', 
                    group: 'linksGroup',
                    onEnd: function(evt) {
                        const fromSectionEl = evt.from.closest('.bp-link-section');
                        const toSectionEl = evt.to.closest('.bp-link-section');
                        if (!fromSectionEl || !toSectionEl) return; // Should not happen

                        const fromSectionIdx = parseInt(fromSectionEl.dataset.sidx);
                        const toSectionIdx = parseInt(toSectionEl.dataset.sidx);
                        const oldLinkIndex = evt.oldDraggableIndex;
                        const newLinkIndex = evt.newDraggableIndex;

                        if (isNaN(fromSectionIdx) || isNaN(toSectionIdx)) return;

                        if (fromSectionIdx === toSectionIdx) {
                            if (sections[fromSectionIdx] && sections[fromSectionIdx].links) {
                                const item = sections[fromSectionIdx].links.splice(oldLinkIndex, 1)[0];
                                sections[fromSectionIdx].links.splice(newLinkIndex, 0, item);
                            }
                        } else {
                            if (sections[fromSectionIdx] && sections[fromSectionIdx].links && sections[toSectionIdx] && sections[toSectionIdx].links) {
                                const linkToMove = sections[fromSectionIdx].links.splice(oldLinkIndex, 1)[0];
                                sections[toSectionIdx].links.splice(newLinkIndex, 0, linkToMove);
                            }
                        }
                        renderSections(); // Re-render to fix indices, which calls updateInput()
                    }
                });
            });
        }

        function renderSections() {
            if (!sectionsListEl) return;

            if (sectionsSortableInstance) { // Destroy sortable for main sections list
                sectionsSortableInstance.destroy();
                sectionsSortableInstance = null;
            }
            // Destroy sortable for individual link lists before clearing their parent container
            document.querySelectorAll('.bp-link-list').forEach(listEl => {
                if (listEl.sortableLinkInstance) {
                    listEl.sortableLinkInstance.destroy();
                    listEl.sortableLinkInstance = null;
                }
            });

            sectionsListEl.innerHTML = '';
            const showSectionMoveButtons = sections.length > 1;

            sections.forEach((section, sidx) => {
                const sectionDiv = document.createElement('div');
                sectionDiv.className = 'bp-link-section';
                sectionDiv.dataset.sidx = sidx; // Add section index for SortableJS reference

                const sectionHeaderDiv = document.createElement('div');
                sectionHeaderDiv.className = 'bp-link-section-header';

                // Add drag handle for the section, ensure .bp-section-drag-handle class is used.
                sectionHeaderDiv.innerHTML = `
                    <span class="bp-section-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <input type="text" class="bp-link-section-title" placeholder="Section Title (optional)" value="${escapeHTML(section.section_title || '')}" data-sidx="${sidx}">
                    <div class="bp-section-actions-group ml-auto">
                        <a href="#" class="bp-remove-link-section-btn bp-remove-item-link" data-sidx="${sidx}" title="Remove Section">&times;</a>
                    </div>
                `;

                sectionDiv.appendChild(sectionHeaderDiv);

                const sectionTitleInput = sectionHeaderDiv.querySelector('.bp-link-section-title');
                sectionTitleInput.addEventListener('input', function() {
                    sections[sidx].section_title = this.value;
                    updateInput();
                });

                const removeSectionBtn = sectionHeaderDiv.querySelector('.bp-remove-link-section-btn');
                removeSectionBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    sections.splice(sidx, 1);
                    renderSections(); // This will call updateInput() at the end
                });

                const linksListDiv = document.createElement('div');
                linksListDiv.className = 'bp-link-list';
                sectionDiv.appendChild(linksListDiv);

                const addLinkBtn = document.createElement('button');
                addLinkBtn.type = 'button';
                addLinkBtn.className = 'button button-secondary bp-add-link-btn';
                addLinkBtn.innerHTML = '<i class="fas fa-plus"></i> Add Link';
                addLinkBtn.dataset.sidx = sidx;
                sectionDiv.appendChild(addLinkBtn);

                addLinkBtn.addEventListener('click', function() {
                    sections[sidx].links.push({link_text:'',link_url:'', link_is_active: true, expires_at: ''});
                    renderSections();
                });
                
                const listEl = linksListDiv;
                if (!listEl) return; // Should not happen
                listEl.innerHTML = '';
                
                const numLinks = section.links.length;
                section.links.forEach((link, lidx) => {
                    const row = document.createElement('div');
                    row.className = 'bp-link-item';

                    let expirationIconHtml = '';
                    if (expirationEnabled) {
                        expirationIconHtml = `<span class="bp-link-expiration-icon" title="Set expiration date" data-sidx="${sidx}" data-lidx="${lidx}">&#x23F3;</span>`;
                    }
                    row.innerHTML = `
                        <span class="bp-link-drag-handle drag-handle"><i class="fas fa-grip-vertical"></i></span>
                        <input type="text" class="bp-link-text-input" placeholder="Link Text" value="${escapeHTML(link.link_text || '')}">
                        <input type="url" class="bp-link-url-input" placeholder="URL" value="${escapeHTML(link.link_url || '')}">
                        ${expirationIconHtml}
                        <a href="#" class="bp-remove-link-btn bp-remove-item-link ml-auto" title="Remove Link">&times;</a>
                    `;

                    row.querySelector('.bp-link-text-input').addEventListener('input', function() {
                        sections[sidx].links[lidx].link_text = this.value;
                        updateInput();
                    });
                    row.querySelector('.bp-link-url-input').addEventListener('input', function() {
                        sections[sidx].links[lidx].link_url = this.value;
                        updateInput();
                    });
                    const removeLinkBtn = row.querySelector('.bp-remove-link-btn');
                    removeLinkBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        sections[sidx].links.splice(lidx, 1);
                        renderSections();
                    });
                    
                    if (expirationEnabled) {
                        const expIcon = row.querySelector('.bp-link-expiration-icon');
                        if (expIcon) {
                            expIcon.addEventListener('click', function() {
                                const currentExpiration = sections[sidx].links[lidx].expires_at || '';
                                openExpirationModal(sidx, lidx, currentExpiration);
                            });
                        }
                    }
                    listEl.appendChild(row);
                });
                sectionsListEl.appendChild(sectionDiv);
            });
            updateInput(); // Call updateInput after rendering admin UI to also update preview
            
            // Re-initialize SortableJS for sections and for links within sections
            initializeSortableForSections();
            initializeSortableForLinksInSections();
        }

        function updateInput() {
            if (inputEl) {
                inputEl.value = JSON.stringify(sections);
            }

            // Update the live preview
            if (manager.contentPreview && typeof manager.contentPreview.renderLinkSections === 'function') {
                const { previewEl, contentWrapperEl } = getPreviewElements();

                if (previewEl && contentWrapperEl) {
                    manager.contentPreview.renderLinkSections(sections, previewEl, contentWrapperEl);
                } else {
                    // console.warn('[LinksBrain] Cannot render link sections to preview: previewEl or contentWrapperEl not found.');
                }
            } else {
                // console.warn('[LinksBrain] manager.contentPreview.renderLinkSections is not available.');
            }
        }

        const addSectionBtn = document.getElementById('bp-add-link-section-btn');
        if (addSectionBtn) {
            addSectionBtn.onclick = function() {
                sections.push({section_title:'',links:[]});
                renderSections();
            };
        }

        if (sectionsListEl && inputEl) {
            renderSections(); // Initial render
        } else {
            console.error("Link sections list or JSON input element not found. Cannot initialize link sections UI.");
        }
    };

    function getPreviewElements() {
        let previewElInsideIframe = null;
        if (typeof manager.getPreviewEl === 'function') {
            previewElInsideIframe = manager.getPreviewEl();
        } else {
            if (!isInitialLinkRender) console.warn('[LinksBrain] manager.getPreviewEl is not available.');
            return { previewEl: null, contentWrapperEl: null };
        }

        let contentWrapperEl = null;
        if (previewElInsideIframe) {
            contentWrapperEl = previewElInsideIframe.querySelector('.extrch-link-page-content-wrapper');
        } else {
            // REMOVE: if (!isInitialLinkRender) console.warn('[LinksBrain] previewElInsideIframe is null, cannot find .extrch-link-page-content-wrapper');
        }
        
        if (!contentWrapperEl && previewElInsideIframe) {
            // REMOVE: // console.warn('[LinksBrain] .extrch-link-page-content-wrapper not found directly under previewEl. Searching deeper.');
        }
        
        isInitialLinkRender = false; // Subsequent calls are not initial
        return { previewEl: previewElInsideIframe, contentWrapperEl: contentWrapperEl };
    }

    function initializeLinksTab() {
        if (isInitialized) return;

        mainContainerEl = document.getElementById('bp-manage-link-page-links-container');
        if (!mainContainerEl) {
            // console.log("Links tab container not found. Skipping links initialization.");
            return;
        }

        inputEl = document.getElementById('link_page_links_json');
        addSectionBtn = document.getElementById('bp-links-add-section-btn');
        sectionsContainerEl = document.getElementById('bp-links-sections-list');
        
        // Modals
        expirationModal = document.getElementById('bp-link-expiration-modal');
        const expirationCloseBtn = document.querySelector('#bp-link-expiration-modal .bp-modal-close');
        const expirationSaveBtn = document.getElementById('bp-save-link-expiration');
        const expirationClearBtn = document.getElementById('bp-clear-link-expiration');
        const expirationCancelBtn = document.getElementById('bp-cancel-link-expiration');

        if (!inputEl || !addSectionBtn || !sectionsContainerEl || !expirationModal || !expirationCloseBtn || !expirationSaveBtn || !expirationClearBtn || !expirationCancelBtn) {
            console.error('One or more required elements for the links tab are missing from the DOM.');
            return;
        }

        // Load initial sections from the hidden input
        try {
            const initialData = JSON.parse(inputEl.value || '[]');
            if (Array.isArray(initialData)) {
                sections = initialData;
            }
        } catch (e) {
            console.error("Error parsing initial links JSON:", e);
            sections = [];
        }

        renderSections();
        updateInput(); // Initial render for preview if possible

        addSectionBtn.addEventListener('click', handleAddSection);
        sectionsContainerEl.addEventListener('click', handleSectionAction); // Delegated event listener

        // Expiration Modal Listeners
        expirationCloseBtn.addEventListener('click', closeExpirationModal);
        expirationSaveBtn.addEventListener('click', saveExpirationDateTime);
        expirationClearBtn.addEventListener('click', clearExpirationDateTime);
        expirationCancelBtn.addEventListener('click', closeExpirationModal);
        expirationModal.addEventListener('click', function(e) {
            if (e.target === expirationModal) closeExpirationModal();
        });
        
        // Initialize SortableJS for sections and links
        if (typeof Sortable !== 'undefined') {
            new Sortable(sectionsContainerEl, {
                animation: 150,
                handle: '.bp-section-drag-handle',
                onEnd: function (evt) {
                    if (evt.oldIndex === evt.newIndex) return;
                    const item = sections.splice(evt.oldIndex, 1)[0];
                    sections.splice(evt.newIndex, 0, item);
                    renderSections(); // Re-render to fix indices, which calls updateInput()
                }
            });

            // Initialize sortable for links within each section after sections are rendered
            document.querySelectorAll('.bp-link-list').forEach((listEl) => {
                if (!listEl.classList.contains('sortable-initialized')) {
                    new Sortable(listEl, {
                        animation: 150,
                        handle: '.bp-link-drag-handle',
                        group: 'linksGroup',
                        onEnd: function(evt) {
                            const fromSectionIdx = parseInt(evt.from.closest('.bp-link-section').dataset.sidx);
                            const toSectionIdx = parseInt(evt.to.closest('.bp-link-section').dataset.sidx);
                            const oldLinkIndex = evt.oldDraggableIndex;
                            const newLinkIndex = evt.newDraggableIndex;

                            if (fromSectionIdx === toSectionIdx) {
                                if (sections[fromSectionIdx] && sections[fromSectionIdx].links) {
                                    const item = sections[fromSectionIdx].links.splice(oldLinkIndex, 1)[0];
                                    sections[fromSectionIdx].links.splice(newLinkIndex, 0, item);
                                }
                            } else {
                                if (sections[fromSectionIdx] && sections[fromSectionIdx].links && sections[toSectionIdx] && sections[toSectionIdx].links) {
                                    const linkToMove = sections[fromSectionIdx].links.splice(oldLinkIndex, 1)[0];
                                    sections[toSectionIdx].links.splice(newLinkIndex, 0, linkToMove);
                                }
                            }
                            renderSections();
                        }
                    });
                    listEl.classList.add('sortable-initialized');
                }
            });
        } else {
            console.warn('SortableJS is not loaded. Drag and drop reordering for sections will not be available.');
        }

        isInitialized = true;
        // console.log("Links Tab Initialized.");
    }

    // Wait for the main manager to be ready before initializing
    document.addEventListener('ExtrchLinkPageManagerInitialized', function() {
        // console.log('[LinksBrain] ExtrchLinkPageManagerInitialized event received. Initializing Links Tab.');
        initializeLinksTab();
    });

    // Expose a re-init function on the correct namespace
    manager.linkSections.reInitialize = initializeLinksTab; 

    // Redundant DOMContentLoaded and duplicate ExtrchLinkPageManagerReady listeners should be removed if present.
    // The primary initialization is now handled by the ExtrchLinkPageManagerReady listener above.

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});