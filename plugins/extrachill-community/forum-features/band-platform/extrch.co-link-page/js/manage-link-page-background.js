// Link Page Background Customization Module
(function(manager) {
    if (!manager) {
        console.error('ExtrchLinkPageManager is not defined. Background script cannot run.');
        return;
    }
    manager.background = manager.background || {};

    // --- DOM Elements ---
    const typeSelectInput = document.getElementById('link_page_background_type');
    const bgColorInput = document.getElementById('link_page_background_color');
    const gradStartInput = document.getElementById('link_page_background_gradient_start');
    const gradEndInput = document.getElementById('link_page_background_gradient_end');
    const gradDirInput = document.getElementById('link_page_background_gradient_direction');
    
    const colorControls = document.getElementById('background-color-controls');
    const gradientControls = document.getElementById('background-gradient-controls');
    const imageControls = document.getElementById('background-image-controls');
    
    const bgImageUploadInput = document.getElementById('link_page_background_image_upload');
    const bgImagePreview = document.getElementById('background-image-preview-img');
    const removeBgImageButton = document.getElementById('remove-background-image-button');
    const bgImageIdInput = document.getElementById('link_page_background_image_id');

    // --- Initialization ---
    function initializeBackgroundControls() {
        if (!manager.initialData) {
            console.error('Initial data not available for background controls.');
            return;
        }

        // 1. Determine initial values from manager.initialData to set form inputs
        const initialType = manager.initialData.background_type || 'color';
        const initialColor = (typeof manager.initialData.background_color !== 'undefined') 
                               ? manager.initialData.background_color 
                               : '#1a1a1a'; 
        const initialGradStart = (typeof manager.initialData.background_gradient_start !== 'undefined') 
                                 ? manager.initialData.background_gradient_start 
                                 : '#0b5394';
        const initialGradEnd = (typeof manager.initialData.background_gradient_end !== 'undefined') 
                               ? manager.initialData.background_gradient_end 
                               : '#53940b';
        const initialGradDir = manager.initialData.background_gradient_direction || 'to right';
        const initialImgId = manager.initialData.background_image_id || '';
        const initialImgUrl = manager.initialData.background_image_url || '';

        // 2. Set DOM input elements
        if (typeSelectInput) typeSelectInput.value = initialType;
        if (bgColorInput) bgColorInput.value = initialColor;
        if (gradStartInput) gradStartInput.value = initialGradStart;
        if (gradEndInput) gradEndInput.value = initialGradEnd;
        if (gradDirInput) gradDirInput.value = initialGradDir;
        if (bgImageIdInput) bgImageIdInput.value = initialImgId;

        if (bgImagePreview) {
            if (initialImgUrl) {
                bgImagePreview.src = initialImgUrl;
                bgImagePreview.style.display = 'block';
                if (removeBgImageButton) removeBgImageButton.style.display = 'inline-block';
            } else {
                bgImagePreview.src = '#';
                bgImagePreview.style.display = 'none';
                if (removeBgImageButton) removeBgImageButton.style.display = 'none';
            }
        }
        
        // 3. Update UI visibility for controls
        updateBackgroundTypeUI(initialType);

        // 4. Apply the EXACT server-rendered style string for the initial preview state.
        // This ensures JS initialization matches the server-rendered preview exactly.
        // The key 'container_style_for_preview' should hold the complete 'background-color: ...;' or 'background-image: ...;' string.
        const previewContainer = document.querySelector('.extrch-link-page-preview-container');
        if (previewContainer) {
            const styleStringFromServer = manager.initialData.container_style_for_preview || manager.initialData.background_style; // Check both possible keys
            if (typeof styleStringFromServer === 'string' && styleStringFromServer.trim() !== '') {
                previewContainer.style.cssText = styleStringFromServer;
            } else {
                // Fallback if background_style string is missing from initialData
                previewContainer.style.backgroundColor = initialColor; // Use the determined initial color
                previewContainer.style.backgroundImage = 'none';
                if(initialType === 'gradient') {
                     const gradient = `linear-gradient(${initialGradDir}, ${initialGradStart}, ${initialGradEnd})`;
                     previewContainer.style.backgroundImage = gradient;
                     previewContainer.style.backgroundColor = 'transparent';
                } else if (initialType === 'image' && initialImgUrl) {
                    previewContainer.style.backgroundImage = `url('${initialImgUrl}')`;
                    container.style.backgroundSize = 'cover';
                    container.style.backgroundPosition = 'center';
                    container.style.backgroundRepeat = 'no-repeat';
                    previewContainer.style.backgroundColor = 'transparent';
                }
            }
        }
    }

    // --- UI Update Functions ---
    function updateBackgroundTypeUI(currentType) {
        const val = currentType || (typeSelectInput ? typeSelectInput.value : 'color');
        if(colorControls) colorControls.style.display = (val === 'color') ? '' : 'none';
        if(gradientControls) gradientControls.style.display = (val === 'gradient') ? '' : 'none';
        if(imageControls) imageControls.style.display = (val === 'image') ? '' : 'none';
    }

    // This function is ONLY called by event handlers after user interaction.
    // It reads current values from DOM inputs and updates the preview.
    // It is NO LONGER called by initializeBackgroundControls.
    function updatePreviewFromDOMInputs() {
        const container = document.querySelector('.extrch-link-page-preview-container');
        if (!container) return;

        const bgType = typeSelectInput ? typeSelectInput.value : 'color';
        const colorValue = bgColorInput ? bgColorInput.value : '#1a1a1a';
        const gradStartValue = gradStartInput ? gradStartInput.value : '#0b5394';
        const gradEndValue = gradEndInput ? gradEndInput.value : '#53940b';
        const gradDirValue = gradDirInput ? gradDirInput.value : 'to right';
        
        let imageUrlToUse = null;
        if (manager.liveState && manager.liveState.bgImgUrl && manager.liveState.bgImgUrl.startsWith('data:image')) {
            imageUrlToUse = manager.liveState.bgImgUrl; 
        } else if (bgImagePreview && bgImagePreview.src && bgImagePreview.src !== window.location.href && !bgImagePreview.src.endsWith('#') && bgImagePreview.style.display !== 'none') {
            imageUrlToUse = bgImagePreview.src; 
        }

        if (bgType === 'color') {
            container.style.backgroundColor = (colorValue === '') ? 'transparent' : colorValue;
            container.style.backgroundImage = 'none';
        } else if (bgType === 'gradient') {
            const gradient = `linear-gradient(${gradDirValue}, ${gradStartValue}, ${gradEndValue})`;
            container.style.backgroundImage = gradient;
            container.style.backgroundColor = 'transparent';
        } else if (bgType === 'image') {
            if (imageUrlToUse) {
                container.style.backgroundImage = `url('${imageUrlToUse}')`;
                container.style.backgroundSize = 'cover';
                container.style.backgroundPosition = 'center';
                container.style.backgroundRepeat = 'no-repeat';
                container.style.backgroundColor = 'transparent';
            } else {
                container.style.backgroundImage = 'none';
                container.style.backgroundColor = 'transparent'; 
            }
        } else { 
            container.style.backgroundImage = 'none';
            container.style.backgroundColor = 'transparent';
        }
    }
    
    // --- Event Handlers ---
    function handleBackgroundInputChange(event) {
        const target = event.target;

        if (target === typeSelectInput) {
            updateBackgroundTypeUI(typeSelectInput.value);
        }
        
        // Always update the preview locally from DOM inputs first for instant visual feedback
        updatePreviewFromDOMInputs();

        // Trigger AJAX update ONLY for specific controls that require server-side processing
        // or represent a more significant state change for the preview.
        // Color adjustments (bgColorInput, gradStartInput, gradEndInput) will NOT trigger AJAX here.
        // Their new values will be picked up by the next AJAX call triggered by another control or by form save.
        if (manager.updatePreviewViaAJAX) {
            if (target === typeSelectInput || target === gradDirInput) {
                // Changing type or gradient direction might benefit from server recalculating the style string
                manager.updatePreviewViaAJAX();
            }
            // Image uploads/removals are handled by their own event listeners below,
            // which DO trigger manager.updatePreviewViaAJAX() as they are discrete, significant actions.
        }
    }

    // Image Upload Specific Handling
    if (bgImageUploadInput) {
        bgImageUploadInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (bgImagePreview) {
                        bgImagePreview.src = e.target.result;
                        bgImagePreview.style.display = 'block';
                    }
                    if (removeBgImageButton) removeBgImageButton.style.display = 'inline-block';
                    if (bgImageIdInput) bgImageIdInput.value = ''; 

                    manager.liveState = manager.liveState || {};
                    manager.liveState.bgImgUrl = e.target.result; 
                    
                    updatePreviewFromDOMInputs(); // This updates the client-side preview style
                    // if (manager.updatePreviewViaAJAX) manager.updatePreviewViaAJAX(); // Removed AJAX call
                }
                reader.readAsDataURL(file);
            }
        });
    }

    if (removeBgImageButton) {
        removeBgImageButton.addEventListener('click', function() {
            if (bgImageUploadInput) bgImageUploadInput.value = ''; 
            if (bgImagePreview) {
                bgImagePreview.src = '#';
                bgImagePreview.style.display = 'none';
            }
            if (bgImageIdInput) bgImageIdInput.value = ''; 
            this.style.display = 'none';

            manager.liveState = manager.liveState || {};
            manager.liveState.bgImgUrl = null; 

            updatePreviewFromDOMInputs(); // Update client-side preview to remove image
            // If removing an image should also trigger a full AJAX update to ensure server state consistency:
            // if (manager.updatePreviewViaAJAX) manager.updatePreviewViaAJAX(); // Consider if needed for removal
        });
    }

    // --- Attach Event Listeners ---
    const backgroundInputsToListen = [
        {el: typeSelectInput, event: 'change'},
        {el: bgColorInput, event: 'input'},
        {el: gradStartInput, event: 'input'},
        {el: gradEndInput, event: 'input'},
        {el: gradDirInput, event: 'change'}
    ];
    backgroundInputsToListen.forEach(item => {
        if (item.el) {
            item.el.addEventListener(item.event, handleBackgroundInputChange);
        }
    });

    // --- Public Methods / API for this module ---
    manager.background.init = initializeBackgroundControls;
    // Expose updatePreviewFromDOMInputs if other modules need to trigger a background refresh based on DOM
    manager.background.updatePreview = updatePreviewFromDOMInputs; 

    // --- Initial Call ---
    document.addEventListener('DOMContentLoaded', function() {
        if (manager.isInitialized) { 
            initializeBackgroundControls();
        } else {
            document.addEventListener('extrchLinkPageManagerInitialized', initializeBackgroundControls);
        }
    });

})(window.ExtrchLinkPageManager);