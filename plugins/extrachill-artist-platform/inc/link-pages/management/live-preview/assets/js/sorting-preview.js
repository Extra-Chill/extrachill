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

        // For now, we'll handle same-section movement
        // Cross-section movement is more complex and can be added later
        if (fromContainer !== toContainer) return;

        const linksContainer = contentWrapper.querySelector('.extrch-link-page-links');
        if (!linksContainer) return;

        // Find the section container
        const sectionContainers = linksContainer.querySelectorAll('.extrch-link-page-section-links, .extrch-link-page-links > a');
        
        // Find links within the section - this is simplified for now
        // A more robust implementation would match the management structure exactly
        const allLinks = Array.from(linksContainer.querySelectorAll('a.extrch-link-page-link'));
        
        if (oldIndex >= allLinks.length || newIndex >= allLinks.length) return;

        const linkToMove = allLinks[oldIndex];
        if (!linkToMove) return;

        // Remove the link from its current position
        linkToMove.remove();

        // Insert at new position
        if (newIndex >= allLinks.length - 1) {
            // Insert at end of parent
            if (linkToMove.parentNode) {
                linkToMove.parentNode.appendChild(linkToMove);
            }
        } else {
            // Insert before the link that's currently at newIndex
            const referenceLink = allLinks[newIndex];
            if (referenceLink && referenceLink.parentNode) {
                referenceLink.parentNode.insertBefore(linkToMove, referenceLink);
            }
        }
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