// Link Page Background Customization Module
(function(manager) {
    // Ensure the manager exists first.
    if (!manager) {
        // console.error('ExtrchLinkPageManager is not defined. Background script cannot run.');
        return; // Cannot proceed without the manager
    }

    // Define the core background module functionality
    const defineBackgroundModule = () => {
        // console.log('[Background] defineBackgroundModule called.'); // Comment out
        if (manager.background) { // Avoid double definition
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

        // Function to update the visibility of background type controls
        const updateBackgroundTypeUI = (currentType) => {
            const typeToShow = currentType || (typeSelectInput ? typeSelectInput.value : 'color');

            if (colorControls) {
                colorControls.style.display = (typeToShow === 'color') ? '' : 'none';
            }
            if (gradientControls) {
                gradientControls.style.display = (typeToShow === 'gradient') ? '' : 'none';
            }
            if (imageControls) {
                imageControls.style.display = (typeToShow === 'image') ? '' : 'none';
            }
        };

        // Function to update the background image preview element in the control panel
        const updateAdminImagePreview = () => {
            const currentContainer = document.getElementById('background-image-preview');
            if (!currentContainer) {
                return;
            }

            if (!currentContainer.parentNode || !currentContainer.isConnected) {
                return;
            }

            currentContainer.innerHTML = '';
            const dynamicRemoveButton = currentContainer.parentNode.querySelector('button#dynamic-remove-bg-image-btn');
            if (dynamicRemoveButton) {
                dynamicRemoveButton.remove();
            }
        };

        // New function to sync all background input fields from customVars
        const syncBackgroundInputValues = () => {
            if (!manager || !manager.customization || typeof manager.customization.getCustomVars !== 'function') {
                // console.warn('syncBackgroundInputValues: manager.customization.getCustomVars is not available. Cannot sync background input values.');
                return; // Exit if dependency not met
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
        };

        const initializeBackgroundControls = () => {
            // console.log('[Background] initializeBackgroundControls called.'); // Comment out
            syncBackgroundInputValues();

            const centralCustomVars = manager.customization.getCustomVars ? manager.customization.getCustomVars() : {};
            const bgType = centralCustomVars['--link-page-background-type'] || 'color';
            // console.log('[Background] Initial bgType from customVars:', bgType); // Comment out

            updateBackgroundTypeUI(bgType);
            // console.log('[Background] updateBackgroundTypeUI called with:', bgType); // Comment out (immediately after the call)
        };

        // --- Event Listeners ---
        if (typeSelectInput) {
            typeSelectInput.addEventListener('change', function() {
                try {
                    const newType = this.value;
                    if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                         manager.customization.updateSetting('--link-page-background-type', newType);
                    }
                    updateBackgroundTypeUI(newType);
                } catch (e) {
                    // console.error("Error during background type change:", e);
                }
            });
        }

        if (bgColorInput) {
            bgColorInput.addEventListener('input', function() {
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-color', this.value);
                }
            });
            bgColorInput.addEventListener('change', function() {
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-color', this.value);
                }
            });
        }

        if (gradStartInput) {
            gradStartInput.addEventListener('input', function() {
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-gradient-start', this.value);
                }
            });
            gradStartInput.addEventListener('change', function() {
                 if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-gradient-start', this.value);
                }
            });
        }

        if (gradEndInput) {
            gradEndInput.addEventListener('input', function() {
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-gradient-end', this.value);
                }
            });
            gradEndInput.addEventListener('change', function() {
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-gradient-end', this.value);
                }
            });
        }

        if (gradDirInput) {
            gradDirInput.addEventListener('change', function() {
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
                        let dataUrl = e.target.result;
                        // Always wrap in url(...) if not already
                        if (dataUrl && !/^url\(/.test(dataUrl)) {
                            dataUrl = 'url(' + dataUrl + ')';
                        }
                        if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                            manager.customization.updateSetting('--link-page-background-image-url', dataUrl);

                            if (manager.customization.getCustomVars) {
                                const currentCV = manager.customization.getCustomVars();
                                if (currentCV['--link-page-background-type'] !== 'image') {
                                    manager.customization.updateSetting('--link-page-background-type', 'image');
                                    updateBackgroundTypeUI('image');
                                }
                            }
                        }
                    };
                    reader.readAsDataURL(file);
                } else { // File input cleared
                    if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                        manager.customization.updateSetting('--link-page-background-image-url', '');
                    }
                }
            });
        }

        // Public methods
        manager.background.init = function() {
            // console.log('[Background] Public init called. Dependencies should be met now.'); // Comment out
            syncBackgroundInputValues();
        };

        manager.background.updateAdminImagePreview = updateAdminImagePreview; // Expose if needed elsewhere
        manager.background.updateBackgroundTypeUI = updateBackgroundTypeUI; // Expose for customization.js if it needs to call this
        manager.background.syncBackgroundInputValues = syncBackgroundInputValues; // Expose for customization.js if it needs to call this

        // Add a public method to sync and update UI (for tab activation)
        manager.background.syncAndUpdateUI = function() {
            // console.log('[Background] syncAndUpdateUI called (e.g., on tab switch).'); // Comment out
            syncBackgroundInputValues(); // Syncs values from customVars
            const centralCustomVars = manager.customization.getCustomVars ? manager.customization.getCustomVars() : {};
            const bgType = centralCustomVars['--link-page-background-type'] || 'color';
            updateBackgroundTypeUI(bgType); // Update UI based on synced value
        };
    };

    // Check if customization is already ready when this script executes. If so, define the module.
    // Otherwise, wait for the customization module to signal readiness.
    if (manager.customization && typeof manager.customization.getCustomVars === 'function') {
        // console.log('[Background] Customization module found on script execution. Defining background module.'); // Comment out
        defineBackgroundModule();
        // Call public init via the main manager's DOMContentLoaded listener if it was defined
    } else {
        // console.log('[Background] Customization module not found on script execution. Waiting for extrchCustomizationInitialized event.'); // Comment out
        // Wait for a signal from the customization module
        document.addEventListener('extrchCustomizationInitialized', () => {
            // console.log('[Background] extrchCustomizationInitialized event received. Defining background module.'); // Comment out
            if (manager.customization && typeof manager.customization.getCustomVars === 'function') {
                 defineBackgroundModule();
                 // Call public init via the main manager's DOMContentLoaded listener if it was defined
            } else {
                 // console.error('[Background] extrchCustomizationInitialized event received, but customization module still not found.');
            }
        }, { once: true });
         // Fallback listener in case the customization module doesn't fire its specific event
        document.addEventListener('extrchLinkPageManagerInitialized', () => {
             // console.log('[Background] extrchLinkPageManagerInitialized event received. Checking customization again.'); // Comment out
             if (!manager.background && manager.customization && typeof manager.customization.getCustomVars === 'function') {
                  defineBackgroundModule();
                  // Call public init via the main manager's DOMContentLoaded listener if it was defined
             } else if (!manager.background) {
                  // console.warn('[Background] extrchLinkPageManagerInitialized event received, but customization module still not found. Background module not defined.');
             }
        }, { once: true });
    }

})(window.ExtrchLinkPageManager);