// Link Sections Management Module
(function(manager) {
    if (!manager) {
        console.error('ExtrchLinkPageManager is not defined. Link sections script cannot run.');
        return;
    }
    manager.linkSections = manager.linkSections || {};

    manager.linkSections.init = function() {
        let sections = JSON.parse(JSON.stringify(manager.initialLinkSectionsData || []));
        if (sections.length && sections[0] && !sections[0].links) { // Backward compatibility for old flat link structure
            sections = [{ section_title: '', links: sections }];
        }
        const sectionsListEl = document.getElementById('bp-link-sections-list');
        const inputEl = document.getElementById('link_page_links_json');
        let isInitialLinkRender = true;

        let expirationModal = document.getElementById('bp-link-expiration-modal');
        if (!expirationModal) {
            expirationModal = document.createElement('div');
            expirationModal.id = 'bp-link-expiration-modal';
            expirationModal.className = 'bp-link-expiration-modal';
            expirationModal.style.display = 'none';
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
            expirationModal.style.display = 'block';
            expirationModalSectionIdx = sectionIdx;
            expirationModalLinkIdx = linkIdx;
            const dtInput = document.getElementById('bp-link-expiration-datetime');
            dtInput.value = currentValue || '';
            dtInput.focus();
        }
        function closeExpirationModal() {
            expirationModal.style.display = 'none';
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
                updateInput();
            }
            closeExpirationModal();
        };
        document.getElementById('bp-save-link-expiration').onclick = function() {
            const dtInput = document.getElementById('bp-link-expiration-datetime');
            if (expirationModalSectionIdx !== null && expirationModalLinkIdx !== null) {
                sections[expirationModalSectionIdx].links[expirationModalLinkIdx].expires_at = dtInput.value;
                updateInput();
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
                if (manager.updatePreviewViaAJAX) manager.updatePreviewViaAJAX(); // Update preview as this affects display
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
            });
        }

        function renderSections() {
            if (!sectionsListEl) return;
            sectionsListEl.innerHTML = '';
            const showSectionMoveButtons = sections.length > 1;

            sections.forEach((section, sidx) => {
                const sectionDiv = document.createElement('div');
                sectionDiv.className = 'bp-link-section';
                sectionDiv.style.marginBottom = '2em';

                const moveUpButtonHtml = showSectionMoveButtons ? `<button type="button" class="button bp-move-section-up-btn" data-sidx="${sidx}" title="Move Section Up" ${sidx === 0 ? 'disabled' : ''}>&#8593;</button>` : '';
                const moveDownButtonHtml = showSectionMoveButtons ? `<button type="button" class="button bp-move-section-down-btn" data-sidx="${sidx}" title="Move Section Down" ${sidx === sections.length - 1 ? 'disabled' : ''}>&#8595;</button>` : '';

                sectionDiv.innerHTML = `
                    <div class="bp-link-section-header" style="display:flex;align-items:center;gap:8px;margin-bottom:0.7em;">
                        <input type="text" class="bp-link-section-title" placeholder="Section Title (optional)" value="${section.section_title||''}" data-sidx="${sidx}" style="font-weight:700;font-size:1.08em;flex:1;min-width:0;">
                        <div class="bp-section-actions-group">
                            ${moveUpButtonHtml}
                            ${moveDownButtonHtml}
                            <a href="#" class="bp-remove-link-section-btn bp-remove-item-link" data-sidx="${sidx}" title="Remove Section" style="color:red;text-decoration:none;">&times;</a>
                        </div>
                    </div>
                    <div class="bp-link-list"></div>
                    <button type="button" class="button button-secondary bp-add-link-btn" data-sidx="${sidx}" style="margin-top:1em;"><i class="fas fa-plus"></i> Add Link</button>
                `;
                sectionDiv.querySelector('.bp-link-section-title').addEventListener('input', function() {
                    sections[sidx].section_title = this.value;
                    updateInput();
                });
                sectionDiv.querySelector('.bp-remove-link-section-btn').addEventListener('click', function(e) {
                    e.preventDefault();
                    sections.splice(sidx, 1);
                    renderSections(); // This will call updateInput() at the end
                });

                if (showSectionMoveButtons) {
                    const moveUpBtn = sectionDiv.querySelector('.bp-move-section-up-btn');
                    if (moveUpBtn) {
                        moveUpBtn.addEventListener('click', function() {
                            if (sidx === 0) return;
                            [sections[sidx-1], sections[sidx]] = [sections[sidx], sections[sidx-1]];
                            renderSections();
                        });
                    }
                    const moveDownBtn = sectionDiv.querySelector('.bp-move-section-down-btn');
                    if (moveDownBtn) {
                        moveDownBtn.addEventListener('click', function() {
                            if (sidx === sections.length-1) return;
                            [sections[sidx+1], sections[sidx]] = [sections[sidx], sections[sidx+1]];
                            renderSections();
                        });
                    }
                }

                sectionDiv.querySelector('.bp-add-link-btn').addEventListener('click', function() {
                    sections[sidx].links.push({link_text:'',link_url:'', link_is_active: true, expires_at: ''});
                    renderSections();
                });
                const listEl = sectionDiv.querySelector('.bp-link-list');
                if (!listEl) return; // Should not happen
                listEl.innerHTML = '';
                
                const numLinks = section.links.length;
                section.links.forEach((link, lidx) => {
                    const row = document.createElement('div');
                    row.className = 'bp-link-item';
                    row.style.display = 'flex';
                    row.style.alignItems = 'center';
                    row.style.gap = '8px';
                    let expirationIconHtml = '';
                    if (expirationEnabled) {
                        expirationIconHtml = `<span class="bp-link-expiration-icon" title="Set expiration date" style="cursor:pointer;font-size:1.2em;color:#b48a00;user-select:none;" data-sidx="${sidx}" data-lidx="${lidx}">&#x23F3;</span>`;
                    }
                    row.innerHTML = `
                        <input type="text" placeholder="Link Text" value="${link.link_text||''}" style="flex:2;">
                        <input type="url" placeholder="URL" value="${link.link_url||''}" style="flex:3;">
                        ${expirationIconHtml}
                        <button type="button" class="button bp-move-link-up-btn" title="Move Up" ${lidx === 0 ? 'disabled' : ''}>&#8593;</button>
                        <button type="button" class="button bp-move-link-down-btn" title="Move Down" ${lidx === numLinks - 1 ? 'disabled' : ''}>&#8595;</button>
                        <a href="#" class="bp-remove-link-btn bp-remove-item-link" title="Remove Link" style="color:red;text-decoration:none;margin-left:auto;">&times;</a>
                    `;
                    row.querySelector('input[placeholder="Link Text"]').addEventListener('input', function() {
                        sections[sidx].links[lidx].link_text = this.value;
                        updateInput();
                    });
                    row.querySelector('input[placeholder="URL"]').addEventListener('input', function() {
                        sections[sidx].links[lidx].link_url = this.value;
                        updateInput();
                    });
                    row.querySelector('.bp-remove-link-btn').addEventListener('click', function(e) {
                        e.preventDefault();
                        sections[sidx].links.splice(lidx, 1);
                        renderSections();
                    });
                    
                    const moveUpBtn = row.querySelector('.bp-move-link-up-btn');
                    if (moveUpBtn) {
                        moveUpBtn.addEventListener('click', function() {
                            if (lidx === 0) return;
                            [sections[sidx].links[lidx-1], sections[sidx].links[lidx]] = [sections[sidx].links[lidx], sections[sidx].links[lidx-1]];
                            renderSections();
                        });
                    }
                    const moveDownBtn = row.querySelector('.bp-move-link-down-btn');
                    if (moveDownBtn) {
                        moveDownBtn.addEventListener('click', function() {
                            if (lidx === sections[sidx].links.length-1) return;
                            [sections[sidx].links[lidx+1], sections[sidx].links[lidx]] = [sections[sidx].links[lidx], sections[sidx].links[lidx+1]];
                            renderSections();
                        });
                    }
                    if (expirationEnabled) {
                        const icon = row.querySelector('.bp-link-expiration-icon');
                        if (icon) {
                            icon.addEventListener('click', function() {
                                openExpirationModal(sidx, lidx, link.expires_at || '');
                            });
                            if (link.expires_at) {
                                icon.style.color = '#e74c3c'; // Red if expiration is set
                                icon.title = 'Expires: ' + new Date(link.expires_at).toLocaleString();
                            } else {
                                icon.style.color = '#b48a00'; // Default gold/yellow
                                icon.title = 'Set expiration date';
                            }
                        }
                    }
                    listEl.appendChild(row);
                });
                sectionsListEl.appendChild(sectionDiv);
            });
            updateInput(); // Final update after rendering all sections
            isInitialLinkRender = false;
        }

        function updateInput() {
            if (!inputEl) return;
            inputEl.value = JSON.stringify(sections);
            // Dispatch input event for other scripts (like main AJAX updater) to listen to
            const event = new Event('input', { bubbles: true, cancelable: true });
            inputEl.dispatchEvent(event);

            // Call the main AJAX preview update function if it exists and not initial render
            if (!isInitialLinkRender && manager.updatePreviewViaAJAX) {
                 if (!manager.debouncedUpdatePreviewLinks) {
                    manager.debouncedUpdatePreviewLinks = debounce(manager.updatePreviewViaAJAX, 350);
                }
                manager.debouncedUpdatePreviewLinks();
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

    // Initialize when the DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', manager.linkSections.init);
    } else {
        manager.linkSections.init();
    }

})(window.ExtrchLinkPageManager);