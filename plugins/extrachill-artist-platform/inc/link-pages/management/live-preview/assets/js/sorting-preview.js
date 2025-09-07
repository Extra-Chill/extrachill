// Sorting Preview Module - Granular DOM Movement for Live Preview
(function() {
    'use strict';
    
    // Get preview containers
    function getPreviewContainers() {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live');
        const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        const contentWrapper = previewEl?.querySelector('.extrch-link-page-content-wrapper');
        
        return { previewEl, contentWrapper };
    }

    // Move section element in preview
    function moveSectionInPreview(oldIndex, newIndex) {
        const { contentWrapper } = getPreviewContainers();
        if (!contentWrapper) return;

        const linksContainer = contentWrapper.querySelector('.extrch-link-page-links');
        if (!linksContainer) return;

        const sections = Array.from(linksContainer.children);
        if (oldIndex >= sections.length || newIndex >= sections.length) return;

        const sectionToMove = sections[oldIndex];
        if (!sectionToMove) return;

        // Remove the section from its current position
        sectionToMove.remove();

        // Insert at new position
        if (newIndex >= sections.length - 1) {
            // Insert at end
            linksContainer.appendChild(sectionToMove);
        } else {
            // Insert before the section that's currently at newIndex
            const referenceSection = sections[newIndex];
            if (referenceSection && referenceSection.parentNode === linksContainer) {
                linksContainer.insertBefore(sectionToMove, referenceSection);
            } else {
                // Fallback: append to end
                linksContainer.appendChild(sectionToMove);
            }
        }
    }

    // Move link element in preview (within or between sections)
    function moveLinkInPreview(oldIndex, newIndex, fromContainer, toContainer) {
        const { contentWrapper } = getPreviewContainers();
        if (!contentWrapper) return;

        const linksContainer = contentWrapper.querySelector('.extrch-link-page-links');
        if (!linksContainer) return;

        // Handle movement based on container elements from management interface
        const fromSectionIndex = getSectionIndexFromContainer(fromContainer);
        const toSectionIndex = getSectionIndexFromContainer(toContainer);
        
        if (fromSectionIndex === -1 || toSectionIndex === -1) return;

        // Get the section elements in preview
        const previewSections = Array.from(linksContainer.children);
        const fromSection = previewSections[fromSectionIndex];
        const toSection = previewSections[toSectionIndex];
        
        if (!fromSection || !toSection) return;

        // Get links within the source section
        const fromSectionLinks = fromSection.querySelectorAll('a.extrch-link-page-link');
        const linkToMove = fromSectionLinks[oldIndex];
        
        if (!linkToMove) return;

        // Remove the link from its current position
        linkToMove.remove();

        // Get target section links container
        let toSectionLinksContainer = toSection.querySelector('.extrch-link-page-links');
        if (!toSectionLinksContainer) {
            toSectionLinksContainer = document.createElement('div');
            toSectionLinksContainer.className = 'extrch-link-page-links';
            toSection.appendChild(toSectionLinksContainer);
        }

        // Insert at new position within target section
        const toSectionLinks = toSectionLinksContainer.querySelectorAll('a.extrch-link-page-link');
        
        if (newIndex >= toSectionLinks.length) {
            // Insert at end
            toSectionLinksContainer.appendChild(linkToMove);
        } else {
            // Insert before the link at newIndex
            const referenceLink = toSectionLinks[newIndex];
            toSectionLinksContainer.insertBefore(linkToMove, referenceLink);
        }
    }

    // Helper function to get section index from management container element
    function getSectionIndexFromContainer(container) {
        if (!container) return -1;
        
        const section = container.closest('.bp-link-section');
        if (!section) return -1;
        
        const sidx = section.dataset.sidx;
        return sidx !== undefined ? parseInt(sidx, 10) : -1;
    }

    // Move social icon element in preview
    function moveSocialInPreview(oldIndex, newIndex) {
        const { contentWrapper } = getPreviewContainers();
        if (!contentWrapper) return;

        const socialsContainer = contentWrapper.querySelector('.extrch-link-page-socials');
        if (!socialsContainer) return;

        const socialIcons = Array.from(socialsContainer.children);
        if (oldIndex >= socialIcons.length || newIndex >= socialIcons.length) return;

        const socialToMove = socialIcons[oldIndex];
        if (!socialToMove) return;

        // Remove the social icon from its current position
        socialToMove.remove();

        // Insert at new position
        if (newIndex >= socialIcons.length - 1) {
            // Insert at end
            socialsContainer.appendChild(socialToMove);
        } else {
            // Insert before the social icon that's currently at newIndex
            const referenceSocial = socialIcons[newIndex];
            if (referenceSocial && referenceSocial.parentNode === socialsContainer) {
                socialsContainer.insertBefore(socialToMove, referenceSocial);
            } else {
                // Fallback: append to end
                socialsContainer.appendChild(socialToMove);
            }
        }
    }

    // Event listeners for sortable movements
    document.addEventListener('sectionMoved', function(e) {
        const { oldIndex, newIndex } = e.detail;
        moveSectionInPreview(oldIndex, newIndex);
    });

    document.addEventListener('linkMoved', function(e) {
        const { oldIndex, newIndex, fromContainer, toContainer } = e.detail;
        moveLinkInPreview(oldIndex, newIndex, fromContainer, toContainer);
    });

    document.addEventListener('socialIconMoved', function(e) {
        const { oldIndex, newIndex } = e.detail;
        moveSocialInPreview(oldIndex, newIndex);
    });

})();