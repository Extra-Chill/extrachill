/**
 * Link Sections Management - drag-and-drop functionality with event-driven preview communication.
 */
(function() {
    'use strict';
    
    let sectionsListEl, addSectionBtn;
    let initialized = false;
    let clickHandler = null;
    
    
    /**
     * AJAX template rendering with server-side validation
     */
    async function renderLinkItemTemplate(sectionIndex, linkIndex, linkData = {}) {
        const linkPageId = sectionsListEl?.dataset.linkPageId;
        if (!linkPageId) {
            console.error('Link page ID not found in DOM data attributes');
            return null;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'render_link_item_editor');
            formData.append('link_page_id', linkPageId);
            formData.append('sidx', sectionIndex);
            formData.append('lidx', linkIndex);
            formData.append('link_data', linkData);
            formData.append('expiration_enabled', false);
            formData.append('nonce', extraChillArtistPlatform.nonce);
            
            const response = await fetch(extraChillArtistPlatform.ajaxUrl, {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                return data.data;
            } else {
                console.error('Link item template rendering failed:', data.data?.message || 'Unknown error');
                return null;
            }
        } catch (error) {
            console.error('AJAX request failed for link item rendering:', error);
            return null;
        }
    }
    
    async function renderSectionTemplate(sectionIndex, sectionData = {}) {
        const linkPageId = sectionsListEl?.dataset.linkPageId;
        if (!linkPageId) {
            console.error('Link page ID not found in DOM data attributes');
            return null;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'render_link_section_editor');
            formData.append('link_page_id', linkPageId);
            formData.append('sidx', sectionIndex);
            formData.append('section_data', sectionData);
            formData.append('expiration_enabled', false);
            formData.append('nonce', extraChillArtistPlatform.nonce);
            
            const response = await fetch(extraChillArtistPlatform.ajaxUrl, {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success && data.data.html) {
                return data.data.html;
            } else {
                console.error('Section template rendering failed:', data.data?.message || 'Unknown error');
                return null;
            }
        } catch (error) {
            console.error('AJAX request failed for section rendering:', error);
            return null;
        }
    }

    async function addSection() {
        if (!sectionsListEl) return;
        
        const sectionIndex = sectionsListEl.children.length;
        const html = await renderSectionTemplate(sectionIndex, {});
        
        if (html) {
            sectionsListEl.insertAdjacentHTML('beforeend', html);
            document.dispatchEvent(new CustomEvent('linksectionadded', {
                detail: { sectionIndex, title: '' }
            }));
        }
    }
    
    function removeSection(sectionElement) {
        if (!sectionElement) return;
        
        const sectionIndex = getSectionIndex(sectionElement);
        sectionElement.remove();
        document.dispatchEvent(new CustomEvent('linksectiondeleted', {
            detail: { sectionIndex }
        }));
    }
    
    function updateSectionTitle(sectionElement, title) {
        if (!sectionElement) return;
        
        const sectionIndex = getSectionIndex(sectionElement);
        document.dispatchEvent(new CustomEvent('linksectiontitleupdated', {
            detail: { sectionIndex, title }
        }));
    }
    
    // Link Management
    
    async function addLink(sectionElement) {
        if (!sectionElement) return;
        
        const sectionIndex = getSectionIndex(sectionElement);
        const linkIndex = sectionElement.querySelector('.bp-link-list').children.length;
        
        try {
            const response = await renderLinkItemTemplate(sectionIndex, linkIndex, {});
            
            if (response && response.action === 'add_link') {
                const editorTarget = document.querySelector(response.editor_target_selector);
                if (editorTarget) {
                    editorTarget.insertAdjacentHTML('beforeend', response.editor_html);
                }

                const previewTarget = document.querySelector(response.preview_target_selector);
                if (previewTarget) {
                    previewTarget.insertAdjacentHTML('beforeend', response.preview_html);
                }

                const newElement = editorTarget?.lastElementChild;
                if (newElement) {
                    document.dispatchEvent(new CustomEvent('linkElementAdded', {
                        detail: { element: newElement }
                    }));
                }
            }
        } catch (error) {
            console.error('Add link failed:', error);
        }
    }
    
    function removeLink(linkElement) {
        if (!linkElement) return;
        
        const sectionIndex = getSectionIndex(linkElement);
        const linkIndex = getLinkIndex(linkElement);
        
        linkElement.remove();
        document.dispatchEvent(new CustomEvent('linkdeleted', {
            detail: { sectionIndex, linkIndex }
        }));
    }
    
    function updateLinkText(linkElement, text) {
        if (!linkElement) return;
        
        const sectionIndex = getSectionIndex(linkElement);
        const linkIndex = getLinkIndex(linkElement);
        
        document.dispatchEvent(new CustomEvent('linkupdated', {
            detail: { sectionIndex, linkIndex, field: 'link_text', value: text }
        }));
    }
    
    function updateLinkUrl(linkElement, url) {
        if (!linkElement) return;
        
        const sectionIndex = getSectionIndex(linkElement);
        const linkIndex = getLinkIndex(linkElement);
        
        document.dispatchEvent(new CustomEvent('linkupdated', {
            detail: { sectionIndex, linkIndex, field: 'link_url', value: url }
        }));
    }
    
    function handleLinkExpiration(linkElement) {
        if (!linkElement) return;
        
        document.dispatchEvent(new CustomEvent('linkExpirationRequested', {
            detail: { linkElement }
        }));
    }
    
    
    // DOM Utilities
    
    function getSectionIndex(element) {
        const sectionElement = element.closest('.bp-link-section');
        return sectionElement ? parseInt(sectionElement.dataset.sidx) || 0 : 0;
    }
    
    function getLinkIndex(element) {
        const linkElement = element.closest('.bp-link-item');
        return linkElement ? parseInt(linkElement.dataset.lidx) || 0 : 0;
    }
    
    function updateDataAttributes() {
        if (!sectionsListEl) return;

        let sectionIndex = 0;
        sectionsListEl.querySelectorAll('.bp-link-section').forEach(sectionEl => {
            sectionEl.dataset.sidx = sectionIndex;

            let linkIndex = 0;
            sectionEl.querySelectorAll('.bp-link-item').forEach(linkEl => {
                linkEl.dataset.sidx = sectionIndex;
                linkEl.dataset.lidx = linkIndex;
                linkIndex++;
            });
            
            sectionIndex++;
        });
    }
    
    // Event Listeners
    
    function attachEventListeners() {
        if (!sectionsListEl) return;

        if (clickHandler) {
            sectionsListEl.removeEventListener('click', clickHandler);
        }
        clickHandler = function(e) {
            const target = e.target;
            
            if (target.matches('.bp-add-link-section-btn') || target.closest('.bp-add-link-section-btn')) {
                e.preventDefault();
                addSection();
            }
            else if (target.matches('.bp-remove-link-section-btn') || target.closest('.bp-remove-link-section-btn')) {
                e.preventDefault();
                removeSection(target.closest('.bp-link-section'));
            }
            else if (target.matches('.bp-add-link-btn') || target.closest('.bp-add-link-btn')) {
                e.preventDefault();
                addLink(target.closest('.bp-link-section'));
            }
            else if (target.matches('.bp-remove-link-btn') || target.closest('.bp-remove-link-btn')) {
                e.preventDefault();
                removeLink(target.closest('.bp-link-item'));
            }
            else if (target.matches('.bp-link-expiration-icon') || target.closest('.bp-link-expiration-icon')) {
                e.preventDefault();
                handleLinkExpiration(target.closest('.bp-link-item'));
            }
        };

        sectionsListEl.addEventListener('click', clickHandler);
        sectionsListEl.addEventListener('input', function(e) {
            const target = e.target;
            
            if (target.matches('.bp-link-section-title')) {
                updateSectionTitle(target.closest('.bp-link-section'), target.value);
            }
            else if (target.matches('.bp-link-text-input')) {
                updateLinkText(target.closest('.bp-link-item'), target.value);
            }
            else if (target.matches('.bp-link-url-input')) {
                updateLinkUrl(target.closest('.bp-link-item'), target.value);
            }
        });
    }
    
    // Initialization
    
    function init() {
        if (initialized) return;

        sectionsListEl = document.getElementById('bp-link-sections-list');
        addSectionBtn = document.getElementById('bp-add-link-section-btn');

        if (addSectionBtn) {
            addSectionBtn.addEventListener('click', function(e) {
                e.preventDefault();
                addSection();
            });
        }

        if (sectionsListEl) {
            attachEventListeners();
        }

        initialized = true;
    }

    document.addEventListener('sharedTabActivated', function(event) {
        if (event.detail.tabId === 'manage-link-page-tab-links') {
            init();
        }
    });

    document.addEventListener('linkMoved', function() {
        updateDataAttributes();
    });

    document.addEventListener('sectionMoved', function() {
        updateDataAttributes();
    });
    
})();