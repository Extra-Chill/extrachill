// Links Preview Module - Pure Event-Driven Architecture
(function() {
    'use strict';
    
    // Get preview containers
    function getPreviewContainers() {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
        const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        const contentWrapper = previewEl?.querySelector('.extrch-link-page-content-wrapper');
        
        return { previewEl, contentWrapper };
    }

    // Simple helper to get main links container for remaining event-driven functions
    function getLinksContainer() {
        const { contentWrapper } = getPreviewContainers();
        return contentWrapper?.querySelector('.extrch-link-page-links');
    }

    // Add new section to preview
    async function addSectionToPreview(sectionData) {
        const { contentWrapper } = getPreviewContainers();
        if (!contentWrapper) return;
        
        let linksContainer = contentWrapper.querySelector('.extrch-link-page-links');
        if (!linksContainer) {
            linksContainer = document.createElement('div');
            linksContainer.className = 'extrch-link-page-links';
            contentWrapper.appendChild(linksContainer);
        }

        // Create section element
        const sectionEl = document.createElement('div');
        sectionEl.className = 'extrch-link-page-section';
        sectionEl.dataset.sectionIndex = sectionData.sectionIndex || 0;

        // Always add section title div (even if empty) to match server template structure
        const titleEl = document.createElement('div');
        titleEl.className = 'extrch-link-page-section-title';
        titleEl.textContent = sectionData.section_title || '';
        sectionEl.appendChild(titleEl);

        // Add links container inside each section
        const sectionLinksContainer = document.createElement('div');
        sectionLinksContainer.className = 'extrch-link-page-links';
        sectionEl.appendChild(sectionLinksContainer);

        linksContainer.appendChild(sectionEl);
    }

    // Update section title in preview with direct DOM updates
    function updateSectionTitleInPreview(sectionIndex, title) {
        const linksContainer = getLinksContainer();
        if (!linksContainer) return;

        const sectionEl = linksContainer.querySelector(`[data-section-index="${sectionIndex}"]`);
        if (!sectionEl) return;

        let titleEl = sectionEl.querySelector('.extrch-link-page-section-title');
        
        if (title && title.trim()) {
            if (!titleEl) {
                titleEl = document.createElement('div');
                titleEl.className = 'extrch-link-page-section-title';
                sectionEl.insertBefore(titleEl, sectionEl.firstChild);
            }
            titleEl.textContent = title;
        } else if (titleEl) {
            titleEl.remove();
        }
    }

    // Remove section from preview
    function removeSectionFromPreview(sectionIndex) {
        const linksContainer = getLinksContainer();
        if (!linksContainer) return;

        const sectionEl = linksContainer.querySelector(`[data-section-index="${sectionIndex}"]`);
        if (sectionEl) {
            sectionEl.remove();
        }
    }

    // Update individual link in preview
    function updateLinkInPreview(sectionIndex, linkIndex, linkData) {
        const linksContainer = getLinksContainer();
        if (!linksContainer) return;

        // Find the specific section
        const sectionEl = linksContainer.querySelector(`[data-section-index="${sectionIndex}"]`);
        if (!sectionEl) return;

        // Find all link elements in this section
        const linkElements = Array.from(sectionEl.querySelectorAll('a.extrch-link-page-link'));
        const linkElement = linkElements[linkIndex];
        
        if (linkElement && linkData) {
            if (linkData.link_text !== undefined) {
                const textSpan = linkElement.querySelector('.extrch-link-page-link-text');
                if (textSpan) {
                    textSpan.textContent = linkData.link_text;
                }
                const shareButton = linkElement.querySelector('.extrch-share-trigger');
                if (shareButton) {
                    const shareTitle = linkData.link_text || 'Untitled Link';
                    shareButton.setAttribute('data-share-title', shareTitle);
                }
            }
            if (linkData.link_url !== undefined) {
                linkElement.href = linkData.link_url || '#';
                const shareButton = linkElement.querySelector('.extrch-share-trigger');
                if (shareButton) {
                    shareButton.setAttribute('data-share-url', linkData.link_url || '#');
                }
            }
        }
    }

    // Remove link from preview  
    function removeLinkFromPreview(sectionIndex, linkIndex) {
        const linksContainer = getLinksContainer();
        if (!linksContainer) return;

        // Find the specific section
        const sectionEl = linksContainer.querySelector(`[data-section-index="${sectionIndex}"]`);
        if (!sectionEl) return;

        // Find all link elements in this section
        const linkElements = Array.from(sectionEl.querySelectorAll('a.extrch-link-page-link'));
        
        // Remove the specific link element
        if (linkElements[linkIndex]) {
            linkElements[linkIndex].remove();
        }
    }



    // Event listeners for link management
    document.addEventListener('linksectionadded', function(e) {
        const sectionIndex = e.detail?.sectionIndex ?? 0;
        addSectionToPreview({
            sectionIndex: sectionIndex,
            section_title: ''
        });
    });

    document.addEventListener('linksectiondeleted', function(e) {
        const sectionIndex = e.detail?.sectionIndex ?? 0;
        removeSectionFromPreview(sectionIndex);
    });

    document.addEventListener('linksectiontitleupdated', function(e) {
        const sectionIndex = e.detail?.sectionIndex ?? 0;
        const title = e.detail?.title ?? '';
        updateSectionTitleInPreview(sectionIndex, title);
    });

    document.addEventListener('linkdeleted', function(e) {
        const sectionIndex = e.detail?.sectionIndex ?? 0;
        const linkIndex = e.detail?.linkIndex ?? 0;
        removeLinkFromPreview(sectionIndex, linkIndex);
    });

    document.addEventListener('linkupdated', function(e) {
        const sectionIndex = e.detail?.sectionIndex ?? 0;
        const linkIndex = e.detail?.linkIndex ?? 0;
        const field = e.detail?.field;
        const value = e.detail?.value;

        const linkData = {};
        if (field === 'link_text') {
            linkData.link_text = value;
        } else if (field === 'link_url') {
            linkData.link_url = value;
        }

        updateLinkInPreview(sectionIndex, linkIndex, linkData);
    });

    document.addEventListener('DOMContentLoaded', function() {
        // Preview initializes from DOM events
    });

})();