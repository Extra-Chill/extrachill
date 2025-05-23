(function(manager) {
    if (!manager) {
        return;
    }
    manager.previewUpdater = manager.previewUpdater || {};

    // Removed livePreviewStyleTag variable as it's no longer used
    // let livePreviewStyleTag = null; // Reference to the live preview style tag - REMOVED

    // --- Helper to get Live Preview Container ---
function getLivePreviewContainer() {
    // The preview content is now directly in a div, not an iframe.
    // Select the div that wraps the preview content.
    const previewWrapper = document.querySelector('.manage-link-page-preview-live');
    if (!previewWrapper) {
        console.error('[PreviewUpdater] Live preview wrapper (.manage-link-page-preview-live) not found.');
        return null;
    }
    // Find the actual content container within the wrapper
    const previewContainer = previewWrapper.querySelector('.extrch-link-page-container');
    if (!previewContainer) {
        console.error('[PreviewUpdater] Live preview container (.extrch-link-page-container) not found within wrapper.');
        return null;
    }
    return previewContainer;
}

    // Helper to get the live preview style tag for CSS variables - REMOVED as it is no longer used
    // function getLivePreviewStyleTag() {
    //     if (!livePreviewStyleTag) {
    //         livePreviewStyleTag = document.getElementById('extrch-link-page-live-preview-custom-vars');
    //         if (!livePreviewStyleTag) {
    //             console.error('[PreviewUpdater] Live preview style tag (#extrch-link-page-live-preview-custom-vars) not found.');
    //         }
    //     }
    //     return livePreviewStyleTag;
    // } // REMOVED

    // Helper function to update the :root CSS variables in the style tag
    function updateRootCssVariables(allCustomVars) {
        const previewContainer = getLivePreviewContainer();
        if (!previewContainer || !allCustomVars) {
            // console.warn('[PreviewUpdater] updateRootCssVariables: Preview container not found or no customVars.'); // REMOVED log
            return;
        }

        // Apply each custom CSS variable as an inline style property on the container
        for (const key in allCustomVars) {
            if (allCustomVars.hasOwnProperty(key) && isCssVariable(key)) {
                 // Ensure value is not empty or null before setting property
                if (allCustomVars[key] !== '' && allCustomVars[key] !== null) {
                    previewContainer.style.setProperty(key, allCustomVars[key]);
                } else {
                    // Optionally remove the property if the value is empty
                    previewContainer.style.removeProperty(key);
                }
            }
        }

        // The style tag for :root variables is no longer updated by this function,
        // as variables are applied directly to the container element.
        // The initial style tag might still be used for defaults set by PHP.
    }

    // Helper to check if a string looks like a CSS variable
    function isCssVariable(key) {
        return typeof key === 'string' && key.startsWith('--');
    }

    // --- Registry for specific preview update functions ---
    const PREVIEW_UPDATERS = {};

    // --- Background Updaters ---
    // Helper function to apply all background styles based on current customVars
    function applyFullBackgroundStyles(previewContainerEl, bgVars) {
        if (!previewContainerEl) {
            return;
        }

        const type = bgVars.type || 'color';
        // Apply styles to the .extrch-link-page-container element
        previewContainerEl.style.backgroundImage = ''; // Clear previous image/gradient
        previewContainerEl.style.backgroundColor = ''; // Clear previous solid color
        // Reset image-specific styles initially
        previewContainerEl.style.backgroundSize = '';
        previewContainerEl.style.backgroundPosition = '';
        previewContainerEl.style.backgroundRepeat = '';

        if (type === 'color' && bgVars.color) {
            previewContainerEl.style.backgroundColor = bgVars.color;
        } else if (type === 'gradient' && bgVars.gradientStart && bgVars.gradientEnd && bgVars.gradientDirection) {
            const gradientCss = `linear-gradient(${bgVars.gradientDirection}, ${bgVars.gradientStart}, ${bgVars.gradientEnd})`;
            previewContainerEl.style.backgroundImage = gradientCss;
        } else if (type === 'image' && bgVars.imageUrl) {
            previewContainerEl.style.backgroundImage = `url("${bgVars.imageUrl}")`;
            previewContainerEl.style.backgroundSize = bgVars.imageSize || 'cover'; // Default to cover
            previewContainerEl.style.backgroundPosition = bgVars.imagePosition || 'center center'; // Default to center center
            previewContainerEl.style.backgroundRepeat = bgVars.imageRepeat || 'no-repeat'; // Default to no-repeat
        } else {
            // Default fallback if vars are incomplete
            // This fallback might be better handled by CSS defaults
            // previewContainerEl.style.backgroundColor = '#1a1a1a'; // A sensible default
        }
    }

    // Background updaters now just trigger the full background style application
    PREVIEW_UPDATERS['--link-page-background-type'] = function(value, previewEl, allCustomVars) {
        applyFullBackgroundStyles(getLivePreviewContainer(), {
            type: value,
            color: allCustomVars['--link-page-background-color'],
            gradientStart: allCustomVars['--link-page-background-gradient-start'],
            gradientEnd: allCustomVars['--link-page-background-gradient-end'],
            gradientDirection: allCustomVars['--link-page-background-gradient-direction'],
            imageUrl: allCustomVars['--link-page-background-image-url'],
            imageSize: allCustomVars['--link-page-background-image-size'],
            imagePosition: allCustomVars['--link-page-background-image-position'],
            imageRepeat: allCustomVars['--link-page-background-image-repeat']
        });
    };
    PREVIEW_UPDATERS['--link-page-background-color'] = function(value, previewEl, allCustomVars) {
        applyFullBackgroundStyles(getLivePreviewContainer(), {
            type: allCustomVars['--link-page-background-type'],
            color: value,
            gradientStart: allCustomVars['--link-page-background-gradient-start'],
            gradientEnd: allCustomVars['--link-page-background-gradient-end'],
            gradientDirection: allCustomVars['--link-page-background-gradient-direction'],
            imageUrl: allCustomVars['--link-page-background-image-url'],
            imageSize: allCustomVars['--link-page-background-image-size'],
            imagePosition: allCustomVars['--link-page-background-image-position'],
            imageRepeat: allCustomVars['--link-page-background-image-repeat']
        });
    };
    PREVIEW_UPDATERS['--link-page-background-gradient-start'] = function(value, previewEl, allCustomVars) {
        applyFullBackgroundStyles(getLivePreviewContainer(), {
            type: allCustomVars['--link-page-background-type'],
            color: allCustomVars['--link-page-background-color'],
            gradientStart: value,
            gradientEnd: allCustomVars['--link-page-background-gradient-end'],
            gradientDirection: allCustomVars['--link-page-background-gradient-direction'],
            imageUrl: allCustomVars['--link-page-background-image-url'],
            imageSize: allCustomVars['--link-page-background-image-size'],
            imagePosition: allCustomVars['--link-page-background-image-position'],
            imageRepeat: allCustomVars['--link-page-background-image-repeat']
        });
    };
    PREVIEW_UPDATERS['--link-page-background-gradient-end'] = function(value, previewEl, allCustomVars) {
        applyFullBackgroundStyles(getLivePreviewContainer(), {
            type: allCustomVars['--link-page-background-type'],
            color: allCustomVars['--link-page-background-color'],
            gradientStart: allCustomVars['--link-page-background-gradient-start'],
            gradientEnd: value,
            gradientDirection: allCustomVars['--link-page-background-gradient-direction'],
            imageUrl: allCustomVars['--link-page-background-image-url'],
            imageSize: allCustomVars['--link-page-background-image-size'],
            imagePosition: allCustomVars['--link-page-background-image-position'],
            imageRepeat: allCustomVars['--link-page-background-image-repeat']
        });
    };
    PREVIEW_UPDATERS['--link-page-background-gradient-direction'] = function(value, previewEl, allCustomVars) {
        applyFullBackgroundStyles(getLivePreviewContainer(), {
            type: allCustomVars['--link-page-background-type'],
            color: allCustomVars['--link-page-background-color'],
            gradientStart: allCustomVars['--link-page-background-gradient-start'],
            gradientEnd: allCustomVars['--link-page-background-gradient-end'],
            gradientDirection: value,
            imageUrl: allCustomVars['--link-page-background-image-url'],
            imageSize: allCustomVars['--link-page-background-image-size'],
            imagePosition: allCustomVars['--link-page-background-image-position'],
            imageRepeat: allCustomVars['--link-page-background-image-repeat']
        });
    };
    PREVIEW_UPDATERS['--link-page-background-image-url'] = function(value, previewEl, allCustomVars) {
        applyFullBackgroundStyles(getLivePreviewContainer(), {
            type: allCustomVars['--link-page-background-type'],
            color: allCustomVars['--link-page-background-color'],
            gradientStart: allCustomVars['--link-page-background-gradient-start'],
            gradientEnd: allCustomVars['--link-page-background-gradient-end'],
            gradientDirection: allCustomVars['--link-page-background-gradient-direction'],
            imageUrl: value,
            imageSize: allCustomVars['--link-page-background-image-size'],
            imagePosition: allCustomVars['--link-page-background-image-position'],
            imageRepeat: allCustomVars['--link-page-background-image-repeat']
        });
    };

    // --- Color Updaters ---
    // REMOVED individual updaters for CSS variables as they are handled by updateRootCssVariables
    // PREVIEW_UPDATERS['--link-page-text-color'] = function(value, previewEl) { }; // REMOVED
    // PREVIEW_UPDATERS['--link-page-link-text-color'] = function(value, previewEl) { }; // REMOVED
    // PREVIEW_UPDATERS['--link-page-button-hover-bg-color'] = function(value, previewEl) { }; // REMOVED
    // PREVIEW_UPDATERS['--link-page-button-bg-color'] = function(value, previewEl) { }; // REMOVED
    // PREVIEW_UPDATERS['--link-page-button-border-color'] = function(value, previewEl) { }; // REMOVED

    // --- Font & Size Updaters ---
    // REMOVED individual updaters for CSS variables as they are handled by updateRootCssVariables
    // PREVIEW_UPDATERS['--link-page-title-font-size'] = function(value, previewEl) { }; // REMOVED
    // PREVIEW_UPDATERS['--link-page-title-font-family'] = function(fontStack, previewEl) { }; // REMOVED
    // PREVIEW_UPDATERS['--link-page-body-font-family'] = function(fontStack, previewEl) { }; // REMOVED

    // --- Profile Image Updaters ---
    PREVIEW_UPDATERS['--link-page-profile-img-size'] = function(value, previewEl) {
        // This variable is now set on :root via updateRootCssVariables
        // if (previewEl) previewEl.style.setProperty('--link-page-profile-img-size', value); // REMOVED
    };
    PREVIEW_UPDATERS['_link_page_profile_img_shape'] = function(shape, previewEl) {
        // This updater needs to find the specific element and apply/remove classes
        const previewProfileImageDiv = previewEl ? previewEl.querySelector('.extrch-link-page-profile-img') : null;
        if (previewProfileImageDiv) {
            previewProfileImageDiv.classList.remove('shape-circle', 'shape-square', 'shape-rectangle');

            // Shape classes now control radius and aspect ratio via CSS variables in extrch-links.css
            // We just need to add the correct class.
            if (shape === 'circle') {
                previewProfileImageDiv.classList.add('shape-circle');
            } else if (shape === 'square') {
                previewProfileImageDiv.classList.add('shape-square');
            } else if (shape === 'rectangle') {
                previewProfileImageDiv.classList.add('shape-rectangle');
            } else {
                previewProfileImageDiv.classList.add('shape-square'); // Fallback default shape class
            }
            // Removed direct style setting for borderRadius and aspectRatio
            // Removed console logs for direct style setting

            const imgTag = previewProfileImageDiv.querySelector('img');
            if (imgTag) {
                imgTag.style.borderRadius = 'inherit';
                // Removed console log
            }

        } else {
            console.warn('[PreviewUpdater] previewProfileImageDiv NOT FOUND for shape update.');
        }
    };
    PREVIEW_UPDATERS['--link-page-profile-img-url'] = function(imgUrl, previewEl) {
        // This updater needs to find the specific element and update its src
        const previewProfileImg = previewEl ? previewEl.querySelector('.extrch-link-page-profile-img img') : null;
        if(previewProfileImg) {
            previewProfileImg.src = imgUrl || '';
            previewProfileImg.style.display = imgUrl ? 'block' : 'none';
        }
    };

    // --- Button Style Updaters ---
    // REMOVED individual updater for CSS variable as it is handled by updateRootCssVariables
    // PREVIEW_UPDATERS['--link-page-button-radius'] = function(value, previewEl) { }; // REMOVED

    // --- Overlay Updater ---
    PREVIEW_UPDATERS['overlay'] = function(overlayVal, previewEl) {
        // This updater needs to find the specific element and apply/remove classes
        const wrapper = previewEl ? previewEl.querySelector('.extrch-link-page-content-wrapper') : null;
        if (wrapper) {
            if (overlayVal === '1') {
                wrapper.classList.remove('no-overlay');
            } else {
                wrapper.classList.add('no-overlay');
            }
        }
    };

    // --- Public API for the PreviewUpdater ---

    /**
     * Updates a specific part of the preview.
     * @param {string} key The customVarKey that changed.
     * @param {any} value The new value.
     * @param {object} allCustomVars The complete current customVars state.
     */
    manager.previewUpdater.update = function(key, value, allCustomVars) {
        // Update the :root CSS variables style tag
        updateRootCssVariables(allCustomVars);

        // Call specific updater for elements not controlled by CSS variables (e.g., image src, classes)
        if (typeof PREVIEW_UPDATERS[key] === 'function') {
            try {
                // Pass the preview wrapper element to updaters that need to find specific children
                const previewWrapperEl = document.querySelector('.manage-link-page-preview-live');
                PREVIEW_UPDATERS[key](value, previewWrapperEl, allCustomVars);
            } catch (e) {
                console.error(`Error in PreviewUpdater for key ${key}:`, e);
            }
        }
    };

    /**
     * Refreshes the entire preview based on the provided customVars state.
     * @param {object} allCustomVars The complete current customVars state.
     */
    manager.previewUpdater.refreshFullPreview = function(allCustomVars) {
        const previewWrapperEl = document.querySelector('.manage-link-page-preview-live');
        if (!previewWrapperEl) {
            console.warn('[PreviewUpdater] refreshFullPreview: Preview wrapper not found.');
            return;
        }
        if (!allCustomVars || Object.keys(allCustomVars).length === 0) {
            console.warn('[PreviewUpdater] refreshFullPreview: No customVars provided.');
            return;
        }

        // Update the :root CSS variables style tag with all current variables
        updateRootCssVariables(allCustomVars);

        // Apply background styles to the correct container element
        applyFullBackgroundStyles(getLivePreviewContainer(), {
            type: allCustomVars['--link-page-background-type'],
            color: allCustomVars['--link-page-background-color'],
            gradientStart: allCustomVars['--link-page-background-gradient-start'],
            gradientEnd: allCustomVars['--link-page-background-gradient-end'],
            gradientDirection: allCustomVars['--link-page-background-gradient-direction'],
            imageUrl: allCustomVars['--link-page-background-image-url'],
            imageSize: allCustomVars['--link-page-background-image-size'],
            imagePosition: allCustomVars['--link-page-background-image-position'],
            imageRepeat: allCustomVars['--link-page-background-image-repeat']
        });

        // Call specific updaters for elements not controlled by CSS variables (e.g., image src, classes)
        // Iterate through all customVars and call the corresponding updater if it exists
        for (const key in allCustomVars) {
            if (allCustomVars.hasOwnProperty(key)) {
                // Skip background-related vars as they are handled by applyFullBackgroundStyles
                // Skip CSS variables as they are handled by updateRootCssVariables
                if (!key.startsWith('--link-page-background-') && !isCssVariable(key)) {
                    if (typeof PREVIEW_UPDATERS[key] === 'function') {
                        try {
                            PREVIEW_UPDATERS[key](allCustomVars[key], previewWrapperEl, allCustomVars);
                        } catch (e) {
                            console.error(`Error in preview updater during refreshFullPreview for key ${key}:`, e);
                        }
                    }
                }
            }
        }

        // Dispatch an event to signal that the preview has been refreshed
        const refreshedEvent = new CustomEvent('extrchLinkPagePreviewRefreshed', { detail: { allCustomVars } });
        document.dispatchEvent(refreshedEvent);
    };

    // Initialization logic for the preview updater itself
    function initPreviewUpdater() {
        // Get references to key elements early
        getLivePreviewContainer(); // Get the container element
        // Removed call to getLivePreviewStyleTag() as it's no longer used
        // getLivePreviewStyleTag(); // Get the style tag for variables - REMOVED

        // Dispatch an event to signal that the PreviewUpdater is initialized and ready
        const initializedEvent = new Event('extrchLinkPagePreviewUpdaterInitialized');
        document.dispatchEvent(initializedEvent);
        // console.log('[PreviewUpdater] Initialized.'); // REMOVED log
    }

    // Initialize when the DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPreviewUpdater);
    } else {
        initPreviewUpdater(); // DOM is already ready
    }

})(window.ExtrchLinkPageManager);