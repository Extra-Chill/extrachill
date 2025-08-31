// Links Preview Module - Pure Event-Driven Architecture
(function() {
    'use strict';
    
    // Server-side template rendering for complete link sections (following social icons pattern)
    async function renderLinksPreviewTemplate(linksData) {
        if (!extraChillArtistPlatform?.ajaxUrl) {
            console.error('AJAX URL not available for links preview template rendering');
            return null;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'render_links_preview_template');
            formData.append('nonce', extraChillArtistPlatform.nonce || '');
            formData.append('links_data', JSON.stringify(linksData));
            formData.append('youtube_embed', '0');

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
                console.error('Links preview template rendering failed:', data.data?.message || 'Unknown error');
                return null;
            }
        } catch (error) {
            console.error('Error rendering links preview template:', error);
            return null;
        }
    }
    
    // Get preview containers
    function getPreviewContainers() {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
        const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        const contentWrapper = previewEl?.querySelector('.extrch-link-page-content-wrapper');
        
        return { previewEl, contentWrapper };
    }

    // Create or get the main links container
    function ensureLinksContainer() {
        const { contentWrapper } = getPreviewContainers();
        if (!contentWrapper) return null;

        let linksContainer = contentWrapper.querySelector('.extrch-link-page-links');
        if (!linksContainer) {
            linksContainer = document.createElement('div');
            linksContainer.className = 'extrch-link-page-links';
            
            // Position after social icons (if above) and before powered-by
            const socialIconsAbove = contentWrapper.querySelector('.extrch-link-page-socials:not(.extrch-socials-below)');
            const poweredByEl = contentWrapper.querySelector('.extrch-link-page-powered');
            
            if (socialIconsAbove) {
                contentWrapper.insertBefore(linksContainer, socialIconsAbove.nextSibling);
            } else if (poweredByEl) {
                contentWrapper.insertBefore(linksContainer, poweredByEl);
            } else {
                contentWrapper.appendChild(linksContainer);
            }
        }
        
        return linksContainer;
    }

    // Add new section to preview
    async function addSectionToPreview(sectionData) {
        const linksContainer = ensureLinksContainer();
        if (!linksContainer) return;

        // Create section element
        const sectionEl = document.createElement('div');
        sectionEl.className = 'extrch-link-page-section';
        sectionEl.dataset.sectionIndex = sectionData.sectionIndex || 0;

        // Add section title if present
        if (sectionData.section_title) {
            const titleEl = document.createElement('div');
            titleEl.className = 'extrch-link-page-section-title';
            titleEl.textContent = sectionData.section_title;
            sectionEl.appendChild(titleEl);
        }

        // Add links container for this section
        const sectionLinksContainer = document.createElement('div');
        sectionLinksContainer.className = 'extrch-link-page-section-links';
        sectionEl.appendChild(sectionLinksContainer);

        linksContainer.appendChild(sectionEl);
    }

    // Update section title in preview
    function updateSectionTitleInPreview(sectionIndex, title) {
        const linksContainer = ensureLinksContainer();
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
        const linksContainer = ensureLinksContainer();
        if (!linksContainer) return;

        const sectionEl = linksContainer.querySelector(`[data-section-index="${sectionIndex}"]`);
        if (sectionEl) {
            sectionEl.remove();
        }
    }

    // Update individual link in preview
    function updateLinkInPreview(sectionIndex, linkIndex, linkData) {
        const linksContainer = ensureLinksContainer();
        if (!linksContainer) return;

        // Find the specific link element in the preview
        // For now, we'll find all links and use indices - can be improved with better targeting
        const allLinks = Array.from(linksContainer.querySelectorAll('a.extrch-link-page-link'));
        
        // Calculate the global link index by counting through sections
        let globalIndex = 0;
        const sections = getLinksDataFromDOM();
        
        for (let s = 0; s < sectionIndex && s < sections.length; s++) {
            globalIndex += sections[s].links.length;
        }
        globalIndex += linkIndex;

        const linkElement = allLinks[globalIndex];
        if (linkElement && linkData) {
            // Update link text
            if (linkData.link_text) {
                linkElement.textContent = linkData.link_text;
            }
            // Update link URL
            if (linkData.link_url) {
                linkElement.href = linkData.link_url;
            }
        }
    }

    // Add link to preview
    async function addLinkToPreview(sectionIndex, linkData) {
        // For now, we'll do a targeted section refresh instead of full re-render
        // This is better than full refresh but not as granular as individual link insertion
        refreshSectionInPreview(sectionIndex);
    }

    // Remove link from preview  
    function removeLinkFromPreview(sectionIndex, linkIndex) {
        // For now, refresh the section instead of precise removal
        refreshSectionInPreview(sectionIndex);
    }

    // Refresh a specific section in preview (better than full refresh)
    async function refreshSectionInPreview(sectionIndex) {
        const linksContainer = ensureLinksContainer();
        if (!linksContainer) return;

        const sectionsData = getLinksDataFromDOM();
        if (sectionIndex >= sectionsData.length) return;

        const sectionData = sectionsData[sectionIndex];
        
        // Render just this section using template system
        try {
            const sectionHTML = await renderLinksPreviewTemplate([sectionData]);
            if (sectionHTML) {
                // Find and replace the specific section
                const existingSections = Array.from(linksContainer.children);
                if (existingSections[sectionIndex]) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = sectionHTML;
                    const newSectionContent = tempDiv.firstElementChild;
                    
                    if (newSectionContent) {
                        existingSections[sectionIndex].replaceWith(newSectionContent);
                    }
                }
            }
        } catch (error) {
            console.error('Error refreshing section in preview:', error);
        }
    }

    // Get links data from management form (for use in event handlers)
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
                
                if (textInput && urlInput && textInput.value.trim() && urlInput.value.trim()) {
                    sectionData.links.push({
                        link_text: textInput.value.trim(),
                        link_url: urlInput.value.trim()
                    });
                }
            });
            
            // Only include sections that have a title or at least one valid link
            if (sectionTitle || sectionData.links.length > 0) {
                linksData.push(sectionData);
            }
        });
        
        return linksData;
    }

    // Event listeners for all link events - granular updates
    document.addEventListener('linksectionadded', function(e) {
        // Section added - add to preview immediately with empty title
        const sectionIndex = e.detail?.sectionIndex ?? 0;
        addSectionToPreview({ 
            sectionIndex: sectionIndex,
            section_title: '' 
        });
    });

    document.addEventListener('linksectiondeleted', function(e) {
        // Section deleted - remove from preview immediately
        const sectionIndex = e.detail?.sectionIndex ?? 0;
        removeSectionFromPreview(sectionIndex);
    });

    document.addEventListener('linksectiontitleupdated', function(e) {
        // Section title updated - update title in preview immediately
        const sectionIndex = e.detail?.sectionIndex ?? 0;
        const title = e.detail?.title ?? '';
        updateSectionTitleInPreview(sectionIndex, title);
    });

    document.addEventListener('linkadded', function(e) {
        // Link added - refresh the affected section
        const sectionIndex = e.detail?.sectionIndex ?? 0;
        const linkData = e.detail?.link ?? {};
        addLinkToPreview(sectionIndex, linkData);
    });

    document.addEventListener('linkdeleted', function(e) {
        // Link deleted - refresh the affected section  
        const sectionIndex = e.detail?.sectionIndex ?? 0;
        const linkIndex = e.detail?.linkIndex ?? 0;
        removeLinkFromPreview(sectionIndex, linkIndex);
    });

    document.addEventListener('linkupdated', function(e) {
        // Link updated - update the specific link element
        const sectionIndex = e.detail?.sectionIndex ?? 0;
        const linkIndex = e.detail?.linkIndex ?? 0;
        const linkData = e.detail?.value ?? {};
        updateLinkInPreview(sectionIndex, linkIndex, linkData);
    });

    // Self-contained module - no global exposure needed

    // Initialize preview with centralized data on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Preview initializes from DOM events, not initial data
    });

})();