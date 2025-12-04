// Social Icons Management Module
(function() {
    'use strict';

    let isInitialSocialRender = true;
    let isInitialSortableSocialsEnd = true;

    let socialListEl, addSocialBtn, supportedTypes = {};
    let socialIconsPositionRadios = [];


    function isValidUrl(url) {
        if (!url || typeof url !== 'string') return false;
        try {
            new URL(url.startsWith('http') ? url : 'https://' + url);
            return true;
        } catch (e) {
            return false;
        }
    }

    function getSocialsDataFromDOM() {
        const socialsData = [];
        const socialTypeSelects = document.querySelectorAll('select[name^="social_type["]');
        const socialUrlInputs = document.querySelectorAll('input[name^="social_url["]');

        socialTypeSelects.forEach((typeSelect, index) => {
            const typeMatch = typeSelect.name.match(/social_type\[(\d+)\]/);
            if (typeMatch) {
                const socialIdx = parseInt(typeMatch[1]);
                const urlInput = document.querySelector(`input[name="social_url[${socialIdx}]"]`);

                if (typeSelect.value && urlInput && urlInput.value) {
                    socialsData.push({
                        type: typeSelect.value,
                        url: urlInput.value
                    });
                }
            }
        });

        return socialsData;
    }
    


    function initModule() {
        socialListEl = document.getElementById('bp-social-icons-list');
        addSocialBtn = document.getElementById('bp-add-social-icon-btn');
        socialIconsPositionRadios = document.querySelectorAll('input[name="link_page_social_icons_position"]');
        
        const socialContainer = document.querySelector('#bp-social-icons-list');
        const supportedTypesData = socialContainer ? socialContainer.dataset.supportedTypes : null;
        supportedTypes = supportedTypesData ? JSON.parse(supportedTypesData) : {};

        if (!socialListEl || !addSocialBtn) {
            console.warn('[SocialIcons] Essential DOM elements (list or add button) not found. Module will not function.');
            return;
        }

        const allSocialTypes = supportedTypes;
        const socialTypesArray = Object.keys(allSocialTypes).map(key => ({
            value: key,
            label: allSocialTypes[key].label,
            icon: allSocialTypes[key].icon
        }));
        const uniqueTypes = socialTypesArray.filter(type => type.value !== 'website' && type.value !== 'email').map(type => type);
        const repeatableTypes = socialTypesArray.filter(type => type.value === 'website' || type.value === 'email').map(type => type);

        isInitialSocialRender = true;
        isInitialSortableSocialsEnd = true;
        document.addEventListener('socialIconMoved', function() {
            if (!isInitialSortableSocialsEnd) {
                const socials = getSocialsDataFromDOM();
                document.dispatchEvent(new CustomEvent('socialIconsMoved', {
                    detail: { 
                        socials: socials
                    }
                }));
            } else {
                isInitialSortableSocialsEnd = false;
            }
        });

        socialListEl.addEventListener('blur', function(e) {
            if (e.target.classList.contains('bp-social-url-input')) {
                const url = e.target.value.trim();
                if (url) {
                    const socials = getSocialsDataFromDOM();
                    const position = getSocialIconsPositionFromDOM();
                    document.dispatchEvent(new CustomEvent('socialIconsChanged', {
                        detail: { 
                            socials: socials,
                            position: position
                        }
                    }));
                }
            }
        }, true);

        socialListEl.addEventListener('change', function(e) {
            if (e.target.classList.contains('bp-social-type-select')) {
                const socials = getSocialsDataFromDOM();
                const position = getSocialIconsPositionFromDOM();
                document.dispatchEvent(new CustomEvent('socialIconsChanged', {
                    detail: { 
                        socials: socials,
                        position: position
                    }
                }));
            }
        });

        socialListEl.addEventListener('click', function(e) {
            if (e.target.classList.contains('bp-remove-social-btn') || e.target.closest('.bp-remove-social-btn')) {
                e.preventDefault();
                const row = e.target.closest('.bp-social-row');
                if (row) {
                    const typeSelect = row.querySelector('select[name^="social_type["]');
                    const urlInput = row.querySelector('input[name^="social_url["]');
                    const socialData = {
                        type: typeSelect ? typeSelect.value : '',
                        url: urlInput ? urlInput.value : ''
                    };

                    row.remove();
                    document.dispatchEvent(new CustomEvent('socialIconDeleted', {
                        detail: { 
                            socialData: socialData
                        }
                    }));
                }
            }
        });
        if (addSocialBtn) {
            addSocialBtn.addEventListener('click', function() {
                // Add a new row with the first available type using AJAX template system
                const currentSocials = getSocialsDataFromDOM();
                const currentlyUsedUniqueTypes = currentSocials.filter(s => s.type !== 'website' && s.type !== 'email').map(s => s.type);
                
                // Filter available options to exclude already-used unique types
                const availableUniqueTypes = uniqueTypes.filter(st => !currentlyUsedUniqueTypes.includes(st.value));
                const filteredSocialTypesArray = [...availableUniqueTypes, ...repeatableTypes];
                
                let firstAvailable = availableUniqueTypes.length > 0 ? availableUniqueTypes[0] : repeatableTypes[0];
                
                if (firstAvailable && filteredSocialTypesArray.length > 0) {
                    const sectionsListEl = document.getElementById('bp-link-sections-list');
                    const linkPageId = sectionsListEl ? sectionsListEl.dataset.linkPageId : null;
                    
                    if (!linkPageId) {
                        console.error('Link page ID not found in DOM data attributes');
                        return;
                    }
                    
                    const index = currentSocials.length;
                    const socialData = {
                        type: firstAvailable.value,
                        url: ''
                    };
                    
                    const formData = new FormData();
                    formData.append('action', 'render_social_item_editor');
                    formData.append('link_page_id', linkPageId);
                    formData.append('index', index);
                    formData.append('social_data', JSON.stringify(socialData));
                    formData.append('available_options', JSON.stringify(filteredSocialTypesArray));
                    formData.append('current_socials', JSON.stringify(currentSocials));
                    formData.append('nonce', extraChillArtistPlatform.nonce);

                    fetch(extraChillArtistPlatform.ajaxUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(response => {
                        if (response.success && response.data.html) {
                            socialListEl.insertAdjacentHTML('beforeend', response.data.html);
                            
                            // Fire socialIconAdded event for preview updates
                            document.dispatchEvent(new CustomEvent('socialIconAdded', {
                                detail: { 
                                    socialData: socialData,
                                    html: response.data.html
                                }
                            }));
                        } else {
                            console.error('Failed to render social item:', response.data ? response.data.message : 'Unknown error');
                        }
                    })
                    .catch(() => {
                        console.error('Fetch request failed for social item rendering');
                    });
                } else {
                    if (filteredSocialTypesArray.length === 0) {
                        console.warn('[SocialIcons] All unique social types have been used. Only repeatable types (website, email) can be added.');
                    } else {
                        console.warn('[SocialIcons] No available social types to add.');
                    }
                }
            });
        }

        // Sortable initialization handled automatically by sortable.js module

        socialIconsPositionRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                const socials = getSocialsDataFromDOM();
                // Emit position change event with socials data
                document.dispatchEvent(new CustomEvent('socialIconsPositionChanged', {
                    detail: { 
                        position: this.value,
                        socials: socials
                    }
                }));
            });
        });

        // --- Initial hydration/preview update on page load ---
        // This ensures the preview and hidden input reflect the PHP-rendered state
        // on initial load, aligning with the canonical architecture.
        // updateSocialsHiddenInput(); // Directly update the hidden input on init as well to match controls for social links data
        // updateSocialsPreview(); // REMOVED - PHP handles initial preview render. JS only updates on user interaction.
        // --- End initial update ---

        // Make sure attachEventListeners, addSocialRow, populateTypeSelect are defined and called
        // attachEventListeners(); // This line is causing a ReferenceError and seems redundant
    }

    // Listen for sorting events - granular movement handled by sorting-preview.js
    document.addEventListener('socialIconMoved', function() {
        // Sorting preview handled by sorting-preview.js module
        // Only update indices for form management
        // No full preview re-render needed
    });

    // Auto-initialize when DOM is ready
    if (document.readyState !== 'loading') {
        initModule();
    } else {
        document.addEventListener('DOMContentLoaded', initModule);
    }

    // Helper to get the currently selected social icons position from the radio buttons
    function getSocialIconsPositionFromDOM() {
        const checkedRadio = document.querySelector('input[name="link_page_social_icons_position"]:checked');
        return checkedRadio ? checkedRadio.value : 'above'; // Default to 'above' if nothing is checked (shouldn't happen with defaults)
    }

    // Module is now self-initializing

})();