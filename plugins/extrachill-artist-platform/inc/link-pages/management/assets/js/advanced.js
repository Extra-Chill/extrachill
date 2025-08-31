// JavaScript for Advanced Tab - Manage Link Page

(function() {
    'use strict';

    const redirectEnabledCheckbox = document.getElementById('bp-enable-temporary-redirect');
    const redirectTargetContainer = document.getElementById('bp-temporary-redirect-target-container');
    const redirectTargetSelect = document.getElementById('bp-temporary-redirect-target');

    function populateRedirectTargetDropdownIfNeeded() {
        if (!redirectTargetSelect) return;
        if (!redirectEnabledCheckbox || !redirectEnabledCheckbox.checked) {
            // Clear options if redirect is disabled or elements are missing
            redirectTargetSelect.innerHTML = '<option value="">-- Select a Link --</option>';
            return;
        }

        // Get links data from centralized source
        let linksData = [];
        if (extraChillArtistPlatform.linkPageData && Array.isArray(extraChillArtistPlatform.linkPageData.links)) {
            linksData = extraChillArtistPlatform.linkPageData.links;
        } else {
            console.warn('[Advanced] Centralized links data not available - this should not happen');
        }

        if (!linksData.length) {
            redirectTargetSelect.innerHTML = '<option value="">-- No Links Available --</option>';
            return;
        }

        const firstOption = redirectTargetSelect.options[0];
        redirectTargetSelect.innerHTML = ''; // Clear existing options

        if (firstOption && firstOption.value === '') {
            redirectTargetSelect.appendChild(firstOption); // Keep placeholder if it was there
        } else {
            const placeholder = document.createElement('option');
            placeholder.value = "";
            placeholder.textContent = "-- Select a Link --";
            redirectTargetSelect.appendChild(placeholder);
        }
        
        linksData.forEach(section => {
            if (section && Array.isArray(section.links)) {
                section.links.forEach(link => {
                    // Use link.id for value, link.link_text for display
                    if (link?.id && link.link_text && link.link_url) {
                        const option = document.createElement('option');
                        // IMPORTANT: The redirect target URL must be the link's actual URL, not its ID.
                        option.value = link.link_url; 
                        option.textContent = link.link_text + ' (' + link.link_url + ')';
                        redirectTargetSelect.appendChild(option);
                    }
                });
            }
        });
        
        // After populating all options, set the selected value.
        // Prioritize the URL from the data-php-redirect-url attribute.
        const phpRedirectUrl = redirectTargetSelect.dataset.phpRedirectUrl; 
        if (phpRedirectUrl && redirectTargetSelect.querySelector('option[value="' + phpRedirectUrl.replace(/"/g, '\"') + '"]')) { // Ensure option exists
            redirectTargetSelect.value = phpRedirectUrl;
        }
        // If not, the "-- Select a Link --" default will remain.
    }

    function initializeAdvancedTab() {
        // Initialize redirect functionality
        if (redirectEnabledCheckbox && redirectTargetContainer && redirectTargetSelect) {
            // The data-php-redirect-url is set by tab-advanced.php and will be read directly in populateRedirectTargetDropdownIfNeeded
            // No need to copy it to another data attribute here.

            const updateDisplayAndPopulate = () => {
                const isChecked = redirectEnabledCheckbox.checked;
                redirectTargetContainer.style.display = isChecked ? 'block' : 'none';
                redirectTargetSelect.disabled = !isChecked;
                if (isChecked) {
                    populateRedirectTargetDropdownIfNeeded();
                } else {
                     // Clear dropdown when unchecking
                    redirectTargetSelect.innerHTML = '<option value="">-- Select a Link --</option>';
                }
            };

            redirectEnabledCheckbox.addEventListener('change', updateDisplayAndPopulate);
            updateDisplayAndPopulate(); // Initial call
        }

        // Initialize expiration functionality
        const expirationEnabledCheckbox = document.getElementById('bp-enable-link-expiration-advanced');
        if (expirationEnabledCheckbox) {
            expirationEnabledCheckbox.addEventListener('change', function() {
                const isEnabled = this.checked;
                
                // Dispatch event to notify expiration system of setting change
                document.dispatchEvent(new CustomEvent('expirationSettingChanged', {
                    detail: { enabled: isEnabled }
                }));
                
                console.log('[Advanced] Link expiration setting changed:', isEnabled);
            });
        }

        // Listen for custom event that indicates links have been updated
        // This ensures the dropdown is repopulated if links are added/removed/edited in another tab
        document.addEventListener('bpLinkPageLinksRefreshed', function() {
            if (redirectEnabledCheckbox && redirectEnabledCheckbox.checked) {
                populateRedirectTargetDropdownIfNeeded();
            }
        });
    }

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAdvancedTab);
    } else {
        initializeAdvancedTab();
    }

})(); // Self-contained module