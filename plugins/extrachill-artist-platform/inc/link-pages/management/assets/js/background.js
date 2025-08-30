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

        // Helper function to emit gradient change events
        const emitGradientChangeEvent = () => {
            if (gradStartInput && gradEndInput && gradDirInput) {
                document.dispatchEvent(new CustomEvent('backgroundGradientChanged', {
                    detail: { 
                        gradientData: {
                            startColor: gradStartInput.value,
                            endColor: gradEndInput.value,
                            direction: gradDirInput.value
                        }
                    }
                }));
            }
        };

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
            // Get CSS variables from centralized data source
            let centralCustomVars = {};
            if (manager.customization && typeof manager.customization.getCustomVars === 'function') {
                centralCustomVars = manager.customization.getCustomVars() || {};
                console.log('[Background] Using centralized CSS variables');
            } else {
                console.warn('[Background] Centralized CSS vars not available - this should not happen');
                return;
            }

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
                const newType = this.value;
                if (manager.customization?.updateSetting) {
                     manager.customization.updateSetting('--link-page-background-type', newType);
                }
                updateBackgroundTypeUI(newType);
                
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('backgroundTypeChanged', {
                    detail: { type: newType }
                }));
            });
        }

        if (bgColorInput) {
            bgColorInput.addEventListener('input', function() {
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-color', this.value);
                }
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('backgroundColorChanged', {
                    detail: { color: this.value }
                }));
            });
            bgColorInput.addEventListener('change', function() {
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-color', this.value);
                }
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('backgroundColorChanged', {
                    detail: { color: this.value }
                }));
            });
        }

        if (gradStartInput) {
            gradStartInput.addEventListener('input', function() {
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-gradient-start', this.value);
                }
                emitGradientChangeEvent();
            });
            gradStartInput.addEventListener('change', function() {
                 if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-gradient-start', this.value);
                }
                emitGradientChangeEvent();
            });
        }

        if (gradEndInput) {
            gradEndInput.addEventListener('input', function() {
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-gradient-end', this.value);
                }
                emitGradientChangeEvent();
            });
            gradEndInput.addEventListener('change', function() {
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-gradient-end', this.value);
                }
                emitGradientChangeEvent();
            });
        }

        if (gradDirInput) {
            gradDirInput.addEventListener('change', function() {
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    manager.customization.updateSetting('--link-page-background-gradient-direction', this.value);
                }
                emitGradientChangeEvent();
            });
        }

        if (bgImageUploadInput) {
            bgImageUploadInput.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (file) {
                    const fileSizeLimit = 500 * 1024; // 500KB threshold
                    
                    if (file.size > fileSizeLimit) {
                        // For large files, upload to server immediately and use URL
                        // This prevents memory issues with large data URLs and avoids
                        // redundant upload during form save
                        uploadImageToServer(file);
                    } else {
                        // For smaller files, process with canvas and resize if needed
                        // These become data URLs in CSS variables
                        processImageWithCanvas(file);
                    }
                } else { // File input cleared
                    if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                        manager.customization.updateSetting('--link-page-background-image-url', '');
                    }
                    // Emit event for preview module
                    document.dispatchEvent(new CustomEvent('backgroundImageChanged', {
                        detail: { imageUrl: '' }
                    }));
                }
            });
        }
        
        // Function to upload large images to server
        function uploadImageToServer(file) {
            // Create FormData for upload
            const formData = new FormData();
            formData.append('link_page_background_image_upload', file);
            formData.append('action', 'extrch_upload_background_image_ajax');
            
            // Get nonce from the form
            const nonceInput = document.querySelector('input[name="bp_save_link_page_nonce"]');
            if (nonceInput && nonceInput.value) {
                formData.append('nonce', nonceInput.value);
            } else {
                console.error('Nonce not found');
                alert('Security token not found. Please refresh the page.');
                return;
            }
            
            // Get current link page ID if available
            const linkPageIdInput = document.querySelector('input[name="link_page_id"]');
            if (linkPageIdInput && linkPageIdInput.value) {
                formData.append('link_page_id', linkPageIdInput.value);
            }
            
            // Show loading state
            const uploadInput = document.getElementById('link_page_background_image_upload');
            if (uploadInput) {
                uploadInput.disabled = true;
            }
            
            // Upload via AJAX
            fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data.url) {
                    // Use the server URL instead of data URL
                    const imageUrl = data.data.url;
                    
                    if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                        const currentType = manager.customization.getCustomVars()['--link-page-background-type'];
                        const finalImageUrl = 'url(' + imageUrl + ')';
                        
                        manager.customization.updateSetting('--link-page-background-image-url', finalImageUrl);
                        
                        if (currentType !== 'image') {
                            manager.customization.updateSetting('--link-page-background-type', 'image');
                            updateBackgroundTypeUI('image');
                        }
                        
                        // Emit event for preview module
                        document.dispatchEvent(new CustomEvent('backgroundImageChanged', {
                            detail: { imageUrl: finalImageUrl }
                        }));
                    }
                    
                    // Clear the file input since the file has been uploaded via AJAX
                    if (uploadInput) {
                        uploadInput.value = '';
                    }
                } else {
                    console.error('Image upload failed:', data.data || 'Unknown error');
                    alert('Image upload failed. Please try a smaller file.');
                }
            })
            .catch(error => {
                console.error('Upload error:', error);
                alert('Image upload failed. Please try again.');
            })
            .finally(() => {
                // Re-enable upload input
                if (uploadInput) {
                    uploadInput.disabled = false;
                }
            });
        }
        
        // Function to process smaller images with canvas, with resizing
        function processImageWithCanvas(file) {
            const img = new Image();
            img.onload = function() {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                
                // Calculate dimensions to limit canvas size (max 1200px width)
                const maxWidth = 1200;
                const maxHeight = 1200;
                let { width, height } = img;
                
                if (width > maxWidth || height > maxHeight) {
                    const ratio = Math.min(maxWidth / width, maxHeight / height);
                    width *= ratio;
                    height *= ratio;
                }
                
                // Set canvas dimensions
                canvas.width = width;
                canvas.height = height;
                
                // Draw resized image to canvas
                ctx.drawImage(img, 0, 0, width, height);
                
                // Get data URL with appropriate quality
                let format = 'image/jpeg'; // Use JPEG for better compression
                let quality = 0.8; // 80% quality
                
                // Keep PNG for images with transparency
                if (file.type === 'image/png') {
                    // Check if image has transparency
                    const imageData = ctx.getImageData(0, 0, width, height);
                    const hasTransparency = imageData.data.some((value, index) => index % 4 === 3 && value < 255);
                    
                    if (hasTransparency) {
                        format = 'image/png';
                        quality = undefined; // PNG doesn't use quality parameter
                    }
                }
                
                const dataUrl = quality !== undefined ? canvas.toDataURL(format, quality) : canvas.toDataURL(format);
                
                // Always wrap in url(...) if not already
                let cssValue = dataUrl;
                if (cssValue && !/^url\(/.test(cssValue)) {
                    cssValue = 'url(' + cssValue + ')';
                }
                
                if (manager.customization && typeof manager.customization.updateSetting === 'function') {
                    const currentType = manager.customization.getCustomVars()['--link-page-background-type'];
                    
                    manager.customization.updateSetting('--link-page-background-image-url', cssValue);
                    
                    if (currentType !== 'image') {
                        manager.customization.updateSetting('--link-page-background-type', 'image');
                        updateBackgroundTypeUI('image');
                    }
                    
                    // Emit event for preview module
                    document.dispatchEvent(new CustomEvent('backgroundImageChanged', {
                        detail: { imageUrl: cssValue }
                    }));
                }
            };
            
            img.onerror = function() {
                console.error('Failed to load image for canvas processing');
                alert('Failed to process image. Please try a different file.');
            };
            
            // Set the image source to trigger loading
            const reader = new FileReader();
            reader.onload = function(e) {
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
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
    // Self-initialize on DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function() {
        // Always define and initialize background module
        defineBackgroundModule();
        
        // Auto-initialize if module was successfully defined
        if (manager.background && typeof manager.background.init === 'function') {
            manager.background.init();
        }
    });

})(window.ExtrchLinkPageManager);