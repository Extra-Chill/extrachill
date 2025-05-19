(function(manager) {
    if (!manager) {
        return;
    }
    manager.previewUpdater = manager.previewUpdater || {};

    // let livePreviewContainer = null; // REMOVE CACHING for debugging

    // --- Helper to get Live Preview Container ---
    function getLivePreviewContainer() {
        const container = document.querySelector('.manage-link-page-preview-live .extrch-link-page-preview-container');
        if (!container) {
            return null;
        }
        return container;
    }
    
    // Call it once on script load to cache, assuming .manage-link-page-preview-live exists
    // Or, ensure it's called before any updater function that needs it.
    // DOMContentLoaded might be a good place if this script is loaded in head.
    // For now, relying on first call.

    // --- Registry for specific preview update functions ---
    const PREVIEW_UPDATERS = {};

    // --- Background Updaters --- 
    // Helper function to apply all background styles based on current customVars
    function applyFullBackgroundStyles(previewEl, bgVars) {
        if (!previewEl) return;

        const type = bgVars.type || 'color';
        previewEl.style.backgroundImage = ''; // Clear previous image/gradient
        previewEl.style.backgroundColor = ''; // Clear previous solid color
        // Reset image-specific styles initially
        previewEl.style.backgroundSize = '';
        previewEl.style.backgroundPosition = '';
        previewEl.style.backgroundRepeat = '';

        if (type === 'color' && bgVars.color) {
            previewEl.style.backgroundColor = bgVars.color;
        } else if (type === 'gradient' && bgVars.gradientStart && bgVars.gradientEnd && bgVars.gradientDirection) {
            const gradientCss = `linear-gradient(${bgVars.gradientDirection}, ${bgVars.gradientStart}, ${bgVars.gradientEnd})`;
            previewEl.style.backgroundImage = gradientCss;
        } else if (type === 'image' && bgVars.imageUrl) {
            previewEl.style.backgroundImage = `url("${bgVars.imageUrl}")`;
            previewEl.style.backgroundSize = bgVars.imageSize || 'cover'; // Default to cover
            previewEl.style.backgroundPosition = bgVars.imagePosition || 'center center'; // Default to center center
            previewEl.style.backgroundRepeat = bgVars.imageRepeat || 'no-repeat'; // Default to no-repeat
        } else {
            // Default fallback if vars are incomplete
            previewEl.style.backgroundColor = '#1a1a1a'; // A sensible default
        }
    }

    PREVIEW_UPDATERS['--link-page-background-type'] = function(value, previewEl, allCustomVars) {
        applyFullBackgroundStyles(previewEl, {
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

        // Explicitly re-apply the font family from the current state, as changing background
        // inline styles might cause the browser to temporarily lose other CSS variables on the same element.
        if (allCustomVars['--link-page-title-font-family'] && typeof PREVIEW_UPDATERS['--link-page-title-font-family'] === 'function') {
            PREVIEW_UPDATERS['--link-page-title-font-family'](allCustomVars['--link-page-title-font-family'], previewEl, allCustomVars);
        }
    };
    PREVIEW_UPDATERS['--link-page-background-color'] = function(value, previewEl, allCustomVars) {
        if (allCustomVars['--link-page-background-type'] === 'color') {
            applyFullBackgroundStyles(previewEl, {
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
        }
    };
    PREVIEW_UPDATERS['--link-page-background-gradient-start'] = function(value, previewEl, allCustomVars) {
        if (allCustomVars['--link-page-background-type'] === 'gradient') {
            applyFullBackgroundStyles(previewEl, {
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
        }
    };
    PREVIEW_UPDATERS['--link-page-background-gradient-end'] = function(value, previewEl, allCustomVars) {
        if (allCustomVars['--link-page-background-type'] === 'gradient') {
            applyFullBackgroundStyles(previewEl, {
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
        }
    };
    PREVIEW_UPDATERS['--link-page-background-gradient-direction'] = function(value, previewEl, allCustomVars) {
        if (allCustomVars['--link-page-background-type'] === 'gradient') {
            applyFullBackgroundStyles(previewEl, {
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
        }
    };
    PREVIEW_UPDATERS['--link-page-background-image-url'] = function(value, previewEl, allCustomVars) {
        if (allCustomVars['--link-page-background-type'] === 'image') {
            applyFullBackgroundStyles(previewEl, {
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
        }
    };

    // --- Color Updaters ---
    PREVIEW_UPDATERS['--link-page-text-color'] = function(value, previewEl) {
        if (previewEl) previewEl.style.setProperty('--link-page-text-color', value);
    };
    PREVIEW_UPDATERS['--link-page-link-text-color'] = function(value, previewEl) {
        if (previewEl) previewEl.style.setProperty('--link-page-link-text-color', value);
    };
    PREVIEW_UPDATERS['--link-page-button-hover-bg-color'] = function(value, previewEl) {
        if (previewEl) previewEl.style.setProperty('--link-page-button-hover-bg-color', value);
    };
    PREVIEW_UPDATERS['--link-page-button-bg-color'] = function(value, previewEl) {
        if (previewEl) previewEl.style.setProperty('--link-page-button-bg-color', value);
    };
    PREVIEW_UPDATERS['--link-page-button-border-color'] = function(value, previewEl) {
        if (previewEl) previewEl.style.setProperty('--link-page-button-border-color', value);
    };

    // --- Font & Size Updaters ---
    PREVIEW_UPDATERS['--link-page-title-font-size'] = function(value, previewEl) {
        if (previewEl) previewEl.style.setProperty('--link-page-title-font-size', value);
    };
    PREVIEW_UPDATERS['--link-page-title-font-family'] = function(fontStack, previewEl) {
        if (previewEl) {
            previewEl.style.setProperty('--link-page-title-font-family', fontStack);
        }
    };

    PREVIEW_UPDATERS['--link-page-body-font-family'] = function(fontStack, previewEl) {
        if (previewEl) {
            previewEl.style.setProperty('--link-page-body-font-family', fontStack);
        }
    };

    // --- Profile Image Updaters ---
    PREVIEW_UPDATERS['--link-page-profile-img-size'] = function(value, previewEl) {
        if (previewEl) previewEl.style.setProperty('--link-page-profile-img-size', value);
    };
    PREVIEW_UPDATERS['_link_page_profile_img_shape'] = function(shape, previewEl) {
        const previewProfileImageDiv = previewEl ? previewEl.querySelector('.extrch-link-page-profile-img') : null;
        if (previewProfileImageDiv) {
            previewProfileImageDiv.classList.remove('shape-circle', 'shape-square', 'shape-rectangle');
            
            let newRadius = '8px'; // Default square-ish radius
            let newAspectRatio = '1/1'; // Default aspect ratio

            if (shape === 'circle') {
                previewProfileImageDiv.classList.add('shape-circle');
                newRadius = '50%';
                newAspectRatio = '1/1';
                console.log('[PreviewUpdater] Added class: shape-circle', 'New classes:', previewProfileImageDiv.className);
            } else if (shape === 'square') {
                previewProfileImageDiv.classList.add('shape-square');
                newRadius = '8px'; 
                newAspectRatio = '1/1';
                console.log('[PreviewUpdater] Added class: shape-square', 'New classes:', previewProfileImageDiv.className);
            } else if (shape === 'rectangle') {
                previewProfileImageDiv.classList.add('shape-rectangle');
                newRadius = '12px'; 
                newAspectRatio = '16/9'; // Correct aspect ratio for rectangle
                console.log('[PreviewUpdater] Added class: shape-rectangle', 'New classes:', previewProfileImageDiv.className);
            } else {
                previewProfileImageDiv.classList.add('shape-square'); // Fallback default shape class
                newRadius = '8px';
                newAspectRatio = '1/1';
                console.log('[PreviewUpdater] Added default class: shape-square', 'New classes:', previewProfileImageDiv.className);
            }
            // Directly set styles for debugging
            previewProfileImageDiv.style.borderRadius = newRadius;
            previewProfileImageDiv.style.aspectRatio = newAspectRatio;
            console.log(`[PreviewUpdater] Directly set borderRadius to: ${newRadius}, aspectRatio to: ${newAspectRatio}`);
            
            const imgTag = previewProfileImageDiv.querySelector('img');
            if (imgTag) {
                imgTag.style.borderRadius = 'inherit'; 
                console.log('[PreviewUpdater] Ensured img tag inherits border-radius.');
            }

        } else {
            console.warn('[PreviewUpdater] previewProfileImageDiv NOT FOUND for shape update.');
        }
    };
    PREVIEW_UPDATERS['--link-page-profile-img-url'] = function(imgUrl, previewEl) {
        const previewProfileImg = previewEl ? previewEl.querySelector('.extrch-link-page-profile-img img') : null;
        if(previewProfileImg) {
            previewProfileImg.src = imgUrl || '';
            previewProfileImg.style.display = imgUrl ? 'block' : 'none';
        }
    };

    // --- Button Style Updaters ---
    PREVIEW_UPDATERS['--link-page-button-radius'] = function(value, previewEl) {
        if (previewEl) previewEl.style.setProperty('--link-page-button-radius', value);
    };

    // --- Overlay Updater ---
    PREVIEW_UPDATERS['overlay'] = function(overlayVal, previewEl) {
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
        const previewEl = getLivePreviewContainer();
        if (!previewEl) {
            return;
        }
        if (typeof PREVIEW_UPDATERS[key] === 'function') {
            try {
                PREVIEW_UPDATERS[key](value, previewEl, allCustomVars);
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
        const previewEl = getLivePreviewContainer();
        if (!previewEl) {
            return;
        }
        if (!allCustomVars || Object.keys(allCustomVars).length === 0) {
            return;
        }

        applyFullBackgroundStyles(previewEl, {
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

        // Apply all other custom vars directly
        for (const key in allCustomVars) {
            if (allCustomVars.hasOwnProperty(key)) {
                // Skip background-related vars as they are handled by applyFullBackgroundStyles
                if (!key.startsWith('--link-page-background-')) {
                    if (typeof PREVIEW_UPDATERS[key] === 'function') {
                        try {
                            PREVIEW_UPDATERS[key](allCustomVars[key], previewEl, allCustomVars);
                        } catch (e) {
                            console.error(`Error in preview updater during refreshFullPreview for key ${key}:`, e);
                        }
                    }
                }
            }
        }
        // Ensure overlay is updated based on the full set of vars as well
        if (allCustomVars.hasOwnProperty('overlay')) {
            PREVIEW_UPDATERS['overlay'](allCustomVars['overlay'], previewEl);
        } else if (allCustomVars.hasOwnProperty('--link-page-overlay-toggle')) {
            // Fallback for older key if it exists, though 'overlay' is canonical
            PREVIEW_UPDATERS['overlay'](allCustomVars['--link-page-overlay-toggle'], previewEl);
        }
        // Dispatch an event to signal that the preview has been refreshed
        const refreshedEvent = new CustomEvent('extrchLinkPagePreviewRefreshed', { detail: { allCustomVars } });
        document.dispatchEvent(refreshedEvent);
    };
    
    // Initialization logic for the preview updater itself, if any, could go here.
    // For now, it mainly provides functions to be called by other modules.
    // Caching the livePreviewContainer on DOMContentLoaded might be good.
    function initPreviewUpdater() {
        getLivePreviewContainer(); // Cache it early
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPreviewUpdater);
    } else {
        initPreviewUpdater();
    }

})(window.ExtrchLinkPageManager); 