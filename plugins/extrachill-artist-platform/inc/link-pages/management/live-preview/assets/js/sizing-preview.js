// Sizing Preview Module - Handles live preview updates for sizing, radius, and shape styling
(function(manager) {
    if (!manager) return;
    
    manager.sizingPreview = manager.sizingPreview || {};
    
    // Main sizing preview update function - Direct DOM manipulation
    function updateSizingPreview(sizingData) {
        const previewEl = manager.getPreviewEl ? manager.getPreviewEl() : null;
        if (!previewEl) return;

        // Apply sizing changes to CSS custom properties on the preview element
        if (sizingData.property && sizingData.value) {
            previewEl.style.setProperty(sizingData.property, sizingData.value);
        }
    }

    // Update profile image shape - Direct DOM class manipulation
    function updateProfileImageShape(shape) {
        const previewEl = manager.getPreviewEl ? manager.getPreviewEl() : null;
        if (!previewEl) return;

        const previewProfileImageDiv = previewEl.querySelector('.extrch-link-page-profile-img');
        if (previewProfileImageDiv) {
            // Remove existing shape classes
            previewProfileImageDiv.classList.remove('shape-circle', 'shape-square', 'shape-rectangle');
            
            // Add new shape class
            if (shape === 'circle') {
                previewProfileImageDiv.classList.add('shape-circle');
            } else if (shape === 'square') {
                previewProfileImageDiv.classList.add('shape-square');
            } else if (shape === 'rectangle') {
                previewProfileImageDiv.classList.add('shape-rectangle');
            } else {
                // Default to square
                previewProfileImageDiv.classList.add('shape-square');
            }
            
            // Update border radius on img tag
            const imgTag = previewProfileImageDiv.querySelector('img');
            if (imgTag) {
                imgTag.style.borderRadius = 'inherit';
            }
        }
    }

    // Event listeners for specific sizing changes
    document.addEventListener('titleFontSizeChanged', function(e) {
        if (e.detail && e.detail.size) {
            updateSizingPreview({
                property: '--link-page-title-font-size',
                value: e.detail.size
            });
        }
    });

    document.addEventListener('bodyFontSizeChanged', function(e) {
        if (e.detail && e.detail.size) {
            updateSizingPreview({
                property: '--link-page-body-font-size',
                value: e.detail.size
            });
        }
    });

    document.addEventListener('profileImageSizeChanged', function(e) {
        if (e.detail && e.detail.size) {
            updateSizingPreview({
                property: '--link-page-profile-img-size',
                value: e.detail.size
            });
        }
    });

    document.addEventListener('buttonRadiusChanged', function(e) {
        if (e.detail && e.detail.radius) {
            updateSizingPreview({
                property: '--link-page-button-radius',
                value: e.detail.radius
            });
        }
    });

    document.addEventListener('buttonBorderWidthChanged', function(e) {
        if (e.detail && e.detail.width) {
            updateSizingPreview({
                property: '--link-page-button-border-width',
                value: e.detail.width
            });
        }
    });

    // Event listener for profile image shape changes
    document.addEventListener('profileImageShapeChanged', function(e) {
        if (e.detail && e.detail.shape) {
            updateProfileImageShape(e.detail.shape);
        }
    });

    // Generic sizing change event listener
    document.addEventListener('sizingChanged', function(e) {
        if (e.detail && e.detail.property && e.detail.value) {
            updateSizingPreview({
                property: e.detail.property,
                value: e.detail.value
            });
        }
    });

    // Expose functions on manager
    manager.sizingPreview.updateSizing = updateSizingPreview;
    manager.sizingPreview.updateProfileImageShape = updateProfileImageShape;

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {});