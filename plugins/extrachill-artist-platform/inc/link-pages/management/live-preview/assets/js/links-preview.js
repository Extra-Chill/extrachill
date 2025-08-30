// Links Preview Module - Handles live preview updates for links
(function(manager) {
    if (!manager) return;
    
    manager.linksPreview = manager.linksPreview || {};
    
    // Debounce utility for input updates
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Main links preview update function - Direct DOM manipulation
    function updateLinksPreview(sectionsData) {
        // If no data provided, try to get it from the management form
        if (!sectionsData && manager.links && typeof manager.links.getLinksData === 'function') {
            sectionsData = manager.links.getLinksData();
        }

        if (!sectionsData) return;

        const previewContainerParent = document.querySelector('.manage-link-page-preview-live'); const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        if (!previewEl) return;

        // Find the links container in the preview
        const linksContainer = previewEl.querySelector('.extrch-link-page-links');
        if (!linksContainer) return;

        // Clear existing links
        linksContainer.innerHTML = '';

        // Create links HTML directly
        sectionsData.forEach(section => {
            if (section.section_title) {
                const sectionTitle = document.createElement('h3');
                sectionTitle.className = 'extrch-link-section-title';
                sectionTitle.textContent = section.section_title;
                linksContainer.appendChild(sectionTitle);
            }

            if (section.links && section.links.length > 0) {
                section.links.forEach(link => {
                    if (link.link_text && link.link_url) {
                        const linkElement = document.createElement('a');
                        linkElement.href = link.link_url;
                        linkElement.textContent = link.link_text;
                        linkElement.className = 'extrch-link-item';
                        linkElement.target = '_blank';
                        linkElement.rel = 'noopener noreferrer';
                        linksContainer.appendChild(linkElement);
                    }
                });
            }
        });
    }

    // Debounced version for frequent updates
    const debouncedUpdateLinksPreview = debounce(updateLinksPreview, 300);

    // Event listeners for links updates from management forms
    document.addEventListener('linksChanged', function(e) {
        if (e.detail && e.detail.sectionsData) {
            updateLinksPreview(e.detail.sectionsData);
        } else {
            updateLinksPreview();
        }
    });

    // Expose functions on manager
    manager.linksPreview.update = updateLinksPreview;
    manager.linksPreview.updateDebounced = debouncedUpdateLinksPreview;

    // Initialize preview with centralized data on page load
    document.addEventListener('DOMContentLoaded', function() {
        if (manager.getLinks && typeof manager.getLinks === 'function') {
            const initialLinks = manager.getLinks();
            if (initialLinks && initialLinks.length > 0) {
                updateLinksPreview({ detail: { sectionsData: initialLinks } });
                console.log('[Links Preview] Initialized with centralized data');
            }
        }
    });

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});