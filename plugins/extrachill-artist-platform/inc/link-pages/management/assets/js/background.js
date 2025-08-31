// Link Page Background Customization Module
(function() {
    'use strict';
    
    let isInitialized = false;

    // Define the core background module functionality
    const defineBackgroundModule = () => {
        if (isInitialized) { // Avoid double definition
            return;
        }

        isInitialized = true;

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
            // Get CSS variables directly from document
            const rootStyle = getComputedStyle(document.documentElement);
            console.log('[Background] Reading CSS variables directly from DOM');

            if (typeSelectInput) {
                typeSelectInput.value = rootStyle.getPropertyValue('--link-page-background-type').trim();
            }
            if (bgColorInput) {
                bgColorInput.value = rootStyle.getPropertyValue('--link-page-background-color').trim();
            }
            if (gradStartInput) {
                gradStartInput.value = rootStyle.getPropertyValue('--link-page-background-gradient-start').trim();
            }
            if (gradEndInput) {
                gradEndInput.value = rootStyle.getPropertyValue('--link-page-background-gradient-end').trim();
            }
            if (gradDirInput) {
                gradDirInput.value = rootStyle.getPropertyValue('--link-page-background-gradient-direction').trim();
            }
            updateAdminImagePreview(); // Syncs the admin image preview element
        };

        const initializeBackgroundControls = () => {
            // console.log('[Background] initializeBackgroundControls called.'); // Comment out
            syncBackgroundInputValues();

            // Work directly with form fields
            const bgType = getComputedStyle(document.documentElement).getPropertyValue('--link-page-background-type').trim() || 'color';
            // console.log('[Background] Initial bgType from customVars:', bgType); // Comment out

            updateBackgroundTypeUI(bgType);
            // console.log('[Background] updateBackgroundTypeUI called with:', bgType); // Comment out (immediately after the call)
        };


        // --- Event Listeners ---
        if (typeSelectInput) {
            typeSelectInput.addEventListener('change', function() {
                const newType = this.value;
                updateBackgroundTypeUI(newType);
                
                // Update CSS variable directly
                const styleTag = document.getElementById('extrch-link-page-custom-vars');
                if (styleTag?.sheet) {
                    for (let rule of styleTag.sheet.cssRules) {
                        if (rule.selectorText === ':root') {
                            rule.style.setProperty('--link-page-background-type', newType);
                            break;
                        }
                    }
                }
                
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('backgroundTypeChanged', {
                    detail: { type: newType }
                }));
            });
        }

        if (bgColorInput) {
            bgColorInput.addEventListener('input', function() {
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('backgroundColorChanged', {
                    detail: { color: this.value }
                }));
            });
            bgColorInput.addEventListener('change', function() {
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('backgroundColorChanged', {
                    detail: { color: this.value }
                }));
            });
        }

        if (gradStartInput) {
            gradStartInput.addEventListener('input', function() {
                emitGradientChangeEvent();
            });
            gradStartInput.addEventListener('change', function() {
                emitGradientChangeEvent();
            });
        }

        if (gradEndInput) {
            gradEndInput.addEventListener('input', function() {
                emitGradientChangeEvent();
            });
            gradEndInput.addEventListener('change', function() {
                emitGradientChangeEvent();
            });
        }

        if (gradDirInput) {
            gradDirInput.addEventListener('change', function() {
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
            fetch(extraChillArtistPlatform.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data.url) {
                    // Use the server URL instead of data URL
                    const imageUrl = data.data.url;
                    
                    const currentType = typeSelectInput?.value || 'color';
                    const finalImageUrl = 'url(' + imageUrl + ')';
                    
                    // Emit event for preview module
                    document.dispatchEvent(new CustomEvent('backgroundImageChanged', {
                        detail: { imageUrl: finalImageUrl }
                    }));
                    
                    if (currentType !== 'image') {
                        // Update form controls
                        if (typeSelectInput) typeSelectInput.value = 'image';
                        updateBackgroundTypeUI('image');
                        
                        // Emit event for type change
                        document.dispatchEvent(new CustomEvent('backgroundTypeChanged', {
                            detail: { type: 'image' }
                        }));
                    }
                        
                        // Emit event for preview module
                        document.dispatchEvent(new CustomEvent('backgroundImageChanged', {
                            detail: { imageUrl: finalImageUrl }
                        }));
                    
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
                
                // Direct CSS manipulation without manager abstraction
                const currentType = getComputedStyle(document.documentElement).getPropertyValue('--link-page-background-type').trim();
                
                document.documentElement.style.setProperty('--link-page-background-image-url', cssValue);
                
                if (currentType !== 'image') {
                    document.documentElement.style.setProperty('--link-page-background-type', 'image');
                    updateBackgroundTypeUI('image');
                }
                
                // Emit event for preview module
                document.dispatchEvent(new CustomEvent('backgroundImageChanged', {
                    detail: { imageUrl: cssValue }
                }));
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

        // Self-contained initialization
        syncBackgroundInputValues();
    };
    
    // Auto-initialize when DOM is ready
    if (document.readyState !== 'loading') {
        defineBackgroundModule();
    } else {
        document.addEventListener('DOMContentLoaded', defineBackgroundModule);
    }

})();