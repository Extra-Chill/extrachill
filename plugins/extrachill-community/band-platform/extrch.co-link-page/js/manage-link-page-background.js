// Link Page Background Customization Module
(function(manager) {
    if (!manager || !manager.customization) {
        console.error('ExtrchLinkPageManager or its customization module is not defined. Background script cannot run.');
        return;
    }
    manager.background = manager.background || {};

    // --- DOM Elements (Inputs that are stable) ---
    const typeSelectInput = document.getElementById('link_page_background_type');
    const bgColorInput = document.getElementById('link_page_background_color');
    const gradStartInput = document.getElementById('link_page_background_gradient_start');
    const gradEndInput = document.getElementById('link_page_background_gradient_end');
    const gradDirInput = document.getElementById('link_page_background_gradient_direction');
    
    const colorControls = document.getElementById('background-color-controls');
    const gradientControls = document.getElementById('background-gradient-controls');
    const imageControls = document.getElementById('background-image-controls');
    
    const bgImageUploadInput = document.getElementById('link_page_background_image_upload');
    // Removed module-scoped bgImagePreviewContainer, bgImagePreviewImg, localRemoveBgImageButton

    // Helper to get the central customVars; ensures customization module is ready
    // function getCentralCustomVars() { // REMOVE THIS FUNCTION DEFINITION
    //     if (manager.customization && manager.customization.customVars) {
    //         return manager.customization.customVars;
    //     }
    //     if (manager.customization && typeof manager.customization.getCustomVarsJson === 'function') {
    //         try {
    //             return JSON.parse(manager.customization.getCustomVarsJson());
    //         } catch (e) {
    //             console.error("Error parsing customVars JSON:", e);
    //             return {};
    //         }
    //     }
    //     console.warn('getCentralCustomVars: customVars not found on manager.customization.');
    //     return {};
    // }
    
    // Function to update the visibility of background type controls
    function updateBackgroundTypeUI(currentType) {
        const typeToShow = currentType || (typeSelectInput ? typeSelectInput.value : 'color');
        if(colorControls) colorControls.style.display = (typeToShow === 'color') ? '' : 'none';
        if(gradientControls) gradientControls.style.display = (typeToShow === 'gradient') ? '' : 'none';
        if(imageControls) imageControls.style.display = (typeToShow === 'image') ? '' : 'none';
    }

    // Function to update the background image preview element in the control panel
    function updateAdminImagePreview() {
        const currentContainer = document.getElementById('background-image-preview');
        if (!currentContainer) {
            // console.warn('updateAdminImagePreview: background-image-preview container not found in DOM.');
            return;
        }

        // Critical check: If currentContainer is detached or has no parent, operations like insertBefore will fail.
        if (!currentContainer.parentNode || !currentContainer.isConnected) {
            // console.error('updateAdminImagePreview: background-image-preview container is detached from DOM or has no parent. Cannot update admin preview.');
            return; 
        }

        // --- Logic for admin preview image and remove button is now removed/commented out ---
        // Ensure the container is empty if it previously held these elements
        currentContainer.innerHTML = ''; 
        const dynamicRemoveButton = currentContainer.parentNode.querySelector('button#dynamic-remove-bg-image-btn');
        if (dynamicRemoveButton) {
            dynamicRemoveButton.remove();
        }

        /*
        if (!manager.customization || typeof manager.customization.getCustomVars !== 'function') {
            console.warn('updateAdminImagePreview: manager.customization.getCustomVars is not available.');
            return;
        }
        const centralCustomVars = manager.customization.getCustomVars();
        const imgUrl = centralCustomVars['--link-page-background-image-url'] || '';

        // Manage the image element inside the container
        let previewImg = currentContainer.querySelector('img.dynamic-preview-img');
        if (!previewImg) {
            currentContainer.innerHTML = ''; // Clear potentially old/stale content if we're creating a new img
            previewImg = document.createElement('img');
            previewImg.className = 'dynamic-preview-img'; // Add a class for easier querying
            previewImg.style.maxWidth = '100%';
            previewImg.style.maxHeight = '150px';
            currentContainer.appendChild(previewImg);
        }
        
        // Manage the remove button element, which is a sibling to the container
        let removeButton = currentContainer.parentNode.querySelector('button#dynamic-remove-bg-image-btn');
        if (!removeButton) {
            removeButton = document.createElement('button');
            removeButton.id = 'dynamic-remove-bg-image-btn';
            removeButton.type = 'button';
            removeButton.className = 'button button-link extrch-remove-image-btn';
            removeButton.textContent = 'Remove Image';
            removeButton.style.marginTop = '5px';
            
            removeButton.addEventListener('click', function() {
                const uploadInput = document.getElementById('link_page_background_image_upload'); 
                if (uploadInput) {
                    uploadInput.value = null; // Clear file input
                }
                manager.customization.updateSetting('--link-page-background-image-url', '');
            });
            currentContainer.parentNode.insertBefore(removeButton, currentContainer.nextSibling);
        }
        
        if (imgUrl) {
            previewImg.src = imgUrl;
            previewImg.style.display = 'block';
            if (removeButton) removeButton.style.display = 'inline-block';
        } else {
            previewImg.src = '#'; // Placeholder for no image
            previewImg.style.display = 'none';
            if (removeButton) removeButton.style.display = 'none';
        }
        */
    }

    // New function to sync all background input fields from customVars
    function syncBackgroundInputValues() {
        // const centralCustomVars = getCentralCustomVars(); // OLD
        if (!manager || !manager.customization || typeof manager.customization.getCustomVars !== 'function') {
            console.warn('syncBackgroundInputValues: manager.customization.getCustomVars is not available.');
            return;
        }
        const centralCustomVars = manager.customization.getCustomVars();

        if (typeSelectInput) {
            typeSelectInput.value = centralCustomVars['--link-page-background-type'] || 'color';
        }
        if (bgColorInput) {
            bgColorInput.value = centralCustomVars['--link-page-background-color'] || '#1a1a1a';
        }
        if (gradStartInput) {
            gradStartInput.value = centralCustomVars['--link-page-background-gradient-start'] || '#0b5394';
        }
        if (gradEndInput) {
            gradEndInput.value = centralCustomVars['--link-page-background-gradient-end'] || '#53940b';
        }
        if (gradDirInput) {
            gradDirInput.value = centralCustomVars['--link-page-background-gradient-direction'] || 'to right';
        }
        updateAdminImagePreview(); // Syncs the admin image preview element
    }

    function initializeBackgroundControls() {
        // 1. Populate all input fields from customVars
        syncBackgroundInputValues(); 
        // 2. NOW, use the value that syncBackgroundInputValues just set in the typeSelectInput
        // to determine which sections to show.
        if (typeSelectInput) {
            updateBackgroundTypeUI(typeSelectInput.value); 
        } else {
            updateBackgroundTypeUI('color'); // Fallback
        }
    }

    // --- Event Listeners ---
    if (typeSelectInput) {
        typeSelectInput.addEventListener('change', function() {
            try {
                const newType = this.value;
                // 1. Update customVars with the new type. This also triggers reapplyStyles for the live preview.
                // manager.customization.handleControlChange('--link-page-background-type', newType, false, true); // OLD
                manager.customization.updateSetting('--link-page-background-type', newType);
                
                // 2. syncBackgroundInputValues() will re-populate ALL background inputs based on the
                //    updated customVars (including the new type in typeSelectInput).
                // syncBackgroundInputValues(); // <-- POTENTIALLY PROBLEMATIC - Commented out for testing

                // 3. Update visibility of control sections based on the now definitive newType from the input.
                updateBackgroundTypeUI(newType); 
            } catch (e) {
                console.error("Error during background type change:", e);
            }
        });
    }

    if (bgColorInput) {
        bgColorInput.addEventListener('input', function() {
            // Update customVars immediately for smoother live preview
            // const centralCustomVars = getCentralCustomVars(); // OLD
            // const currentCV = (manager.customization && typeof manager.customization.getCustomVars === 'function') ? manager.customization.getCustomVars() : null; // NEW
            // if (currentCV) { // UPDATED CHECK
                // currentCV['--link-page-background-color'] = this.value;
                // currentCV.backgroundColorValue = this.value; // Ensure this is also set for reapplyStyles
                // if (livePreviewContainer) {
                    // livePreviewContainer.style.backgroundColor = this.value || 'transparent';
                    // livePreviewContainer.style.backgroundImage = 'none'; 
                // }
            // }
            // With the new model, 'input' events should also go through updateSetting
            // to ensure the central state is always up-to-date and specific updaters are called.
            if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                manager.customization.updateSetting('--link-page-background-color', this.value);
            }
        });
        bgColorInput.addEventListener('change', function() {
            // Final update on change, ensures handleControlChange is called
            // No need to fetch customVars here as handleControlChange will use the central one
            // manager.customization.handleControlChange('backgroundColorValue', this.value, false, true); // OLD - backgroundColorValue is not a direct customVar
            // manager.customization.handleControlChange('--link-page-background-color', this.value, false, true); // OLD
            if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                manager.customization.updateSetting('--link-page-background-color', this.value);
            }
        });
    }

    if (gradStartInput) {
        gradStartInput.addEventListener('input', function() {
            // const centralCustomVars = getCentralCustomVars(); // OLD
            // const currentCV = (manager.customization && typeof manager.customization.getCustomVars === 'function') ? manager.customization.getCustomVars() : null; // NEW
            // if (currentCV) { // UPDATED CHECK
                // currentCV['--link-page-background-gradient-start'] = this.value;
                // if (currentCV['--link-page-background-type'] === 'gradient' && typeof manager.customization.updatePreviewBackground === 'function') {
                    // manager.customization.updatePreviewBackground(); // Call central background update
                // }
                // if (typeof manager.customization.updateCustomVarsAndHiddenInput === 'function') {
                    // manager.customization.updateCustomVarsAndHiddenInput();
                // }
            // }
            if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                manager.customization.updateSetting('--link-page-background-gradient-start', this.value);
            }
        });
        gradStartInput.addEventListener('change', function() {
            // const centralCustomVars = getCentralCustomVars(); // OLD
            // const centralCustomVars = (manager.customization && typeof manager.customization.getCustomVars === 'function') ? manager.customization.getCustomVars() : {}; // NEW
            // const gradEnd = centralCustomVars.backgroundGradientEnd || '#ffffff';
            // const gradientValue = `linear-gradient(to bottom right, ${this.value}, ${gradEnd})`;
            // manager.customization.handleControlChange('--link-page-background-gradient-start', this.value, false, true); // OLD
            if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                manager.customization.updateSetting('--link-page-background-gradient-start', this.value);
            }
        });
    }

    if (gradEndInput) {
        gradEndInput.addEventListener('input', function() {
            // const centralCustomVars = getCentralCustomVars(); // OLD
            // const currentCV = (manager.customization && typeof manager.customization.getCustomVars === 'function') ? manager.customization.getCustomVars() : null; // NEW
            // if (currentCV) { // UPDATED CHECK
                // currentCV['--link-page-background-gradient-end'] = this.value;
                // const gradStart = currentCV.backgroundGradientStart || '#000000'; // Use existing or default
                // if (currentCV['--link-page-background-type'] === 'gradient' && typeof manager.customization.updatePreviewBackground === 'function') {
                    // manager.customization.updatePreviewBackground(); 
                // }
                // if (typeof manager.customization.updateCustomVarsAndHiddenInput === 'function') {
                    // manager.customization.updateCustomVarsAndHiddenInput();
                // }
            // }
            if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                manager.customization.updateSetting('--link-page-background-gradient-end', this.value);
            }
        });
        gradEndInput.addEventListener('change', function() {
            // const centralCustomVars = getCentralCustomVars(); // OLD
            // const centralCustomVars = (manager.customization && typeof manager.customization.getCustomVars === 'function') ? manager.customization.getCustomVars() : {}; // NEW
            // const gradStart = centralCustomVars.backgroundGradientStart || '#000000';
            // const gradientValue = `linear-gradient(to bottom right, ${gradStart}, ${this.value})`;
            // manager.customization.handleControlChange('--link-page-background-gradient-end', this.value, false, true); // OLD
            if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                manager.customization.updateSetting('--link-page-background-gradient-end', this.value);
            }
        });
    }

    if (gradDirInput) {
        gradDirInput.addEventListener('change', function() {
            // manager.customization.handleControlChange('--link-page-background-gradient-direction', this.value, false, true); // OLD
            if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                manager.customization.updateSetting('--link-page-background-gradient-direction', this.value);
            }
        });
    }

    if (bgImageUploadInput) {
        bgImageUploadInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const dataUrl = e.target.result;
                    if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                        manager.customization.updateSetting('--link-page-background-image-url', dataUrl);
                        
                        if (manager.customization.getCustomVars) {
                            const currentCV = manager.customization.getCustomVars();
                            if (currentCV['--link-page-background-type'] !== 'image') {
                                manager.customization.updateSetting('--link-page-background-type', 'image');
                            }
                        }
                    }
                    updateAdminImagePreview(); 
                };
                reader.readAsDataURL(file);
            } else { // File input cleared
                // manager.customization.handleControlChange('--link-page-background-image-url', '', false, true); // OLD
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-image-url', '');
                }
                updateAdminImagePreview();
                // Optionally switch type back to 'color' if desired:
                // manager.customization.handleControlChange('--link-page-background-type', 'color', false, true);
            }
        });
    }
    
    // Public methods
    manager.background.init = function() {
        if (manager.customization && 
            typeof manager.customization.handleControlChange === 'function' && // This check is no longer relevant
            typeof manager.customization.getCustomVars === 'function' && 
            typeof manager.customization.updateSetting === 'function') { 
            initializeBackgroundControls();
        } else {
            document.addEventListener('extrchLinkPageManagerInitialized', function initBackgroundOnceReady() {
                if (manager.customization && 
                    typeof manager.customization.getCustomVars === 'function' && 
                    typeof manager.customization.updateSetting === 'function') { 
                    initializeBackgroundControls();
                    document.removeEventListener('extrchLinkPageManagerInitialized', initBackgroundOnceReady);
                } else {
                    console.error('Background.js: extrchLinkPageManagerInitialized event fired, but critical customization functions are still missing.');
                }
            });
        }
    };
    manager.background.updateAdminImagePreview = updateAdminImagePreview; // Expose if needed elsewhere
    manager.background.updateBackgroundTypeUI = updateBackgroundTypeUI; // Expose for customization.js if it needs to call this
    manager.background.syncBackgroundInputValues = syncBackgroundInputValues; // Expose for customization.js if it needs to call this

    // --- Initial Call to arm the init logic ---
    manager.background.init();

})(window.ExtrchLinkPageManager);