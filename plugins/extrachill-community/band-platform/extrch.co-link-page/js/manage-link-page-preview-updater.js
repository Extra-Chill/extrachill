/**
 * [2024-06 Refactor] This module now aligns with @refactor-link-page-preview.mdc:
 * - On page load, PHP outputs all CSS vars and preview is styled.
 * - JS provides functions for targeted updates (single CSS var or preview element) by updating the STYLE TAG IN THE IFRAME HEAD.
 * - No full preview refresh or rehydration on load (initial state is PHP-driven).
 */
(function(manager) {
    if (!manager) {
        return;
    }
    manager.previewUpdater = manager.previewUpdater || {};

    // --- Helper to get Live Preview Content ---
    function getLivePreviewContent() {
        // Always get the style tag from the document head (canonical location)
        const previewContainer = document.querySelector('.extrch-link-page-container');
        const styleTag = document.getElementById('extrch-link-page-custom-vars');
        return { previewContainer, styleTag };
    }

    // Helper function to update the CSS variables in the style tag in the HEAD
    function updateRootCssVariable(key, value) {
        const { styleTag } = getLivePreviewContent();
        if (!styleTag) {
            console.error('[PreviewUpdater] Cannot update CSS variable: Style tag not found.');
            return;
        }
        let sheet = styleTag.sheet;
        if (!sheet) {
            console.error('[PreviewUpdater] No sheet found on style tag.');
            return;
        }
        let rootRule = null;
        for (let i = 0; i < sheet.cssRules.length; i++) {
            if (sheet.cssRules[i].selectorText === ':root') {
                rootRule = sheet.cssRules[i];
                break;
            }
        }
        if (!rootRule) {
            // If :root rule doesn't exist, create it
            try {
                sheet.insertRule(':root {}', sheet.cssRules.length);
                rootRule = sheet.cssRules[sheet.cssRules.length - 1];
            } catch (e) {
                console.error('[PreviewUpdater] Failed to insert :root rule:', e);
                return;
            }
        }
        rootRule.style.setProperty(key, value);
    }

    // Helper to check if a string looks like a CSS variable
    function isCssVariable(key) {
        return typeof key === 'string' && key.startsWith('--');
    }

    // --- Registry for specific preview update functions ---
    const PREVIEW_UPDATERS = {};

    // Background updaters now just trigger the update of the specific CSS variable
    PREVIEW_UPDATERS['--link-page-background-type'] = function(value, previewWrapperEl, allCustomVars) {
        updateRootCssVariable('--link-page-background-type', value);
        const { previewContainer } = getLivePreviewContent();
        if (previewContainer) {
            previewContainer.dataset.bgType = value;
        }
    };
    PREVIEW_UPDATERS['--link-page-background-color'] = function(value) {
        updateRootCssVariable('--link-page-background-color', value);
    };
    PREVIEW_UPDATERS['--link-page-background-gradient-start'] = function(value) { updateRootCssVariable('--link-page-background-gradient-start', value); };
    PREVIEW_UPDATERS['--link-page-background-gradient-end'] = function(value) { updateRootCssVariable('--link-page-background-gradient-end', value); };
    PREVIEW_UPDATERS['--link-page-background-gradient-direction'] = function(value) { updateRootCssVariable('--link-page-background-gradient-direction', value); };
    PREVIEW_UPDATERS['--link-page-background-image-url'] = function(value) {
        // Always wrap in url(...) if not already
        let cssValue = value;
        if (cssValue && !/^url\(/.test(cssValue)) {
            cssValue = 'url(' + cssValue + ')';
        }
        updateRootCssVariable('--link-page-background-image-url', cssValue);
    };
    PREVIEW_UPDATERS['--link-page-background-image-size'] = function(value) { updateRootCssVariable('--link-page-background-image-size', value); };
    PREVIEW_UPDATERS['--link-page-background-image-position'] = function(value) { updateRootCssVariable('--link-page-background-image-position', value); };
    PREVIEW_UPDATERS['--link-page-background-image-repeat'] = function(value) { updateRootCssVariable('--link-page-background-image-repeat', value); };

    // Profile Image Updaters
    PREVIEW_UPDATERS['--link-page-profile-img-size'] = function(value) { updateRootCssVariable('--link-page-profile-img-size', value); };
    PREVIEW_UPDATERS['_link_page_profile_img_shape'] = function(shape, previewWrapperEl) {
        const { previewContainer } = getLivePreviewContent();
         const previewProfileImageDiv = previewContainer ? previewContainer.querySelector('.extrch-link-page-profile-img') : null;
        if (previewProfileImageDiv) {
            previewProfileImageDiv.classList.remove('shape-circle', 'shape-square', 'shape-rectangle');
            if (shape === 'circle') {
                previewProfileImageDiv.classList.add('shape-circle');
            } else if (shape === 'square') {
                previewProfileImageDiv.classList.add('shape-square');
            } else if (shape === 'rectangle') {
                previewProfileImageDiv.classList.add('shape-rectangle');
            } else {
                previewProfileImageDiv.classList.add('shape-square');
            }
            const imgTag = previewProfileImageDiv.querySelector('img');
            if (imgTag) {
                imgTag.style.borderRadius = 'inherit';
            }
        } else {
            console.warn('[PreviewUpdater] previewProfileImageDiv NOT FOUND for shape update.');
        }
    };
    PREVIEW_UPDATERS['--link-page-profile-img-url'] = function(imgUrl) {
        const { previewContainer } = getLivePreviewContent();
        const previewProfileImg = previewContainer ? previewContainer.querySelector('.extrch-link-page-profile-img img') : null;
        if(previewProfileImg) {
            previewProfileImg.src = imgUrl || '';
            previewProfileImg.style.display = imgUrl ? 'block' : 'none';
        }
    };

    // Button Style Updaters
    PREVIEW_UPDATERS['--link-page-button-radius'] = function(value) { updateRootCssVariable('--link-page-button-radius', value); };
    PREVIEW_UPDATERS['--link-page-button-border-width'] = function(value) { updateRootCssVariable('--link-page-button-border-width', value); };
    PREVIEW_UPDATERS['--link-page-button-border-color'] = function(value) { updateRootCssVariable('--link-page-button-border-color', value); };

    // Overlay Updater
    PREVIEW_UPDATERS['overlay'] = function(overlayVal) {
        const { previewContainer } = getLivePreviewContent();
        const wrapper = previewContainer ? previewContainer.querySelector('.extrch-link-page-content-wrapper') : null;
        if (wrapper) {
            if (overlayVal === '1') {
                wrapper.classList.remove('no-overlay');
            } else {
                wrapper.classList.add('no-overlay');
            }
        }
    };

    // Text/Color/Font Updaters
    PREVIEW_UPDATERS['--link-page-text-color'] = function(value) { updateRootCssVariable('--link-page-text-color', value); };
    PREVIEW_UPDATERS['--link-page-link-text-color'] = function(value) { updateRootCssVariable('--link-page-link-text-color', value); };
    PREVIEW_UPDATERS['--link-page-card-bg-color'] = function(value) { updateRootCssVariable('--link-page-card-bg-color', value); };
    PREVIEW_UPDATERS['--link-page-muted-text-color'] = function(value) { updateRootCssVariable('--link-page-muted-text-color', value); };
    PREVIEW_UPDATERS['--link-page-title-font-family'] = function(value) { updateRootCssVariable('--link-page-title-font-family', value); };
    PREVIEW_UPDATERS['--link-page-title-font-size'] = function(value) { updateRootCssVariable('--link-page-title-font-size', value); };
    PREVIEW_UPDATERS['--link-page-body-font-family'] = function(value) { updateRootCssVariable('--link-page-body-font-family', value); };
    PREVIEW_UPDATERS['--link-page-body-font-size'] = function(value) { updateRootCssVariable('--link-page-body-font-size', value); };

    // --- Public API for the PreviewUpdater ---
    manager.previewUpdater.update = function(key, value, allCustomVars) {
        if (typeof PREVIEW_UPDATERS[key] === 'function') {
            try {
                PREVIEW_UPDATERS[key](value, null, allCustomVars);
            } catch (e) {
                console.error(`Error in PreviewUpdater.update for key ${key}:`, e);
            }
        } else if (key && value) {
            updateRootCssVariable(key, value);
        }
    };

    manager.previewUpdater.refreshFullPreview = function(hydratedState) {
        if (!hydratedState || !hydratedState.customVars) {
            console.warn('[PreviewUpdater] refreshFullPreview: No hydrated state or customVars provided.');
            return;
        }
        const allCustomVars = hydratedState.customVars;
        for (const key in allCustomVars) {
             if (allCustomVars.hasOwnProperty(key) && !['_link_page_profile_img_shape', '--link-page-profile-img-url', 'overlay', '--link-page-background-type'].includes(key)) {
                  relevantKeys.push(key);
             }
         }
        for (const key of relevantKeys) {
            const value = allCustomVars[key];
                    if (typeof PREVIEW_UPDATERS[key] === 'function') {
                        try {
                    PREVIEW_UPDATERS[key](value, null, allCustomVars);
                        } catch (e) {
                            console.error(`Error in preview updater during refreshFullPreview for key ${key}:`, e);
                }
            }
        }
        const refreshedEvent = new CustomEvent('extrchLinkPagePreviewRefreshed', { detail: { hydratedState } });
        document.dispatchEvent(refreshedEvent);
         console.log('[PreviewUpdater] Full preview refreshed.');
    };

    manager.previewUpdater.initPreview = function(hydratedState) {
        if (!hydratedState || !hydratedState.customVars) {
            console.warn('[PreviewUpdater] No hydrated state or customVars provided to initPreview.');
        }
        manager.setHydratedState(hydratedState);
        manager.previewUpdater.refreshFullPreview(hydratedState);
        console.log('[PreviewUpdater] initPreview called. PreviewUpdater ready for dynamic updates.');
    };

    // No iframe/DOM ready logic needed; everything is in the main document

})(window.ExtrchLinkPageManager);