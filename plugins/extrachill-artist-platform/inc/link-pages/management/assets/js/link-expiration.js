// Link Expiration Management Module
// Self-contained module handling link expiration modal and functionality

(function() {
    'use strict';
    
    // Modal DOM elements
    let expirationModal, expirationDatetimeInput, saveExpirationBtn, clearExpirationBtn, cancelExpirationBtn;
    let currentEditingLinkItem = null; // Currently active link element
    
    /**
     * Initialize expiration modal DOM elements
     * @returns {boolean} True if all elements found, false otherwise
     */
    function initializeExpirationModalDOM() {
        expirationModal = document.getElementById('bp-link-expiration-modal');
        if (!expirationModal) {
            return false;
        }
        
        expirationDatetimeInput = document.getElementById('bp-link-expiration-datetime');
        saveExpirationBtn = document.getElementById('bp-save-link-expiration');
        clearExpirationBtn = document.getElementById('bp-clear-link-expiration');
        cancelExpirationBtn = document.getElementById('bp-cancel-link-expiration');

        if (!expirationDatetimeInput || !saveExpirationBtn || !clearExpirationBtn || !cancelExpirationBtn) {
            console.error('[Link Expiration] One or more modal controls not found');
            return false;
        }
        
        return true;
    }
    
    /**
     * Open expiration modal for a specific link
     * @param {HTMLElement} linkItem - The link DOM element
     */
    function openExpirationModal(linkItem) {
        if (!expirationModal || !expirationDatetimeInput || !linkItem) return;
        
        currentEditingLinkItem = linkItem;
        const currentExpiration = linkItem.dataset.expiresAt || '';
        expirationDatetimeInput.value = currentExpiration;
        expirationModal.style.display = 'flex';
        expirationDatetimeInput.focus();
    }
    
    /**
     * Close expiration modal and reset state
     */
    function closeExpirationModal() {
        if (!expirationModal) return;
        
        expirationModal.style.display = 'none';
        currentEditingLinkItem = null;
    }
    
    /**
     * Save expiration date to link element and dispatch update event
     */
    function saveLinkExpiration() {
        if (!currentEditingLinkItem || !expirationDatetimeInput) return;
        
        const newExpirationValue = expirationDatetimeInput.value;
        const oldExpirationValue = currentEditingLinkItem.dataset.expiresAt || '';
        
        // Update DOM element
        currentEditingLinkItem.dataset.expiresAt = newExpirationValue;
        
        // Update hidden input if it exists
        const expirationInput = currentEditingLinkItem.querySelector('input[name*="link_expires_at"]');
        if (expirationInput) {
            expirationInput.value = newExpirationValue;
        }
        
        // Dispatch event for other systems
        document.dispatchEvent(new CustomEvent('linkExpirationUpdated', {
            detail: { 
                linkElement: currentEditingLinkItem,
                oldValue: oldExpirationValue,
                newValue: newExpirationValue,
                action: 'set'
            }
        }));
        
        closeExpirationModal();
    }
    
    /**
     * Clear expiration date from link element and dispatch update event  
     */
    function clearLinkExpiration() {
        if (!currentEditingLinkItem) return;
        
        const oldExpirationValue = currentEditingLinkItem.dataset.expiresAt || '';
        
        // Clear DOM element
        currentEditingLinkItem.dataset.expiresAt = '';
        
        // Clear hidden input if it exists
        const expirationInput = currentEditingLinkItem.querySelector('input[name*="link_expires_at"]');
        if (expirationInput) {
            expirationInput.value = '';
        }
        
        // Dispatch event for other systems
        document.dispatchEvent(new CustomEvent('linkExpirationUpdated', {
            detail: { 
                linkElement: currentEditingLinkItem,
                oldValue: oldExpirationValue,
                newValue: '',
                action: 'clear'
            }
        }));
        
        closeExpirationModal();
    }
    
    /**
     * Check if link expiration is enabled globally
     * @returns {boolean} True if expiration is enabled
     */
    function getLinkExpirationEnabled() {
        // Prefer the global config, fallback to DOM data attribute
        if (extraChillArtistPlatform && typeof extraChillArtistPlatform.linkExpirationEnabled !== 'undefined') {
            return extraChillArtistPlatform.linkExpirationEnabled;
        }
        
        const sectionsListEl = document.getElementById('bp-link-sections-list');
        return sectionsListEl && sectionsListEl.dataset.expirationEnabled === 'true';
    }
    
    /**
     * Initialize modal event listeners
     */
    function initializeModalEventListeners() {
        if (!expirationModal) return;
        
        // Button event listeners
        saveExpirationBtn.addEventListener('click', saveLinkExpiration);
        clearExpirationBtn.addEventListener('click', clearLinkExpiration);
        cancelExpirationBtn.addEventListener('click', closeExpirationModal);
        
        // Modal backdrop click to close
        expirationModal.addEventListener('click', function(e) {
            if (e.target === expirationModal) {
                closeExpirationModal();
            }
        });
        
        // Escape key to close
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && expirationModal.style.display === 'flex') {
                closeExpirationModal();
            }
        });
    }
    
    /**
     * Create and add expiration icon to a link element
     * @param {HTMLElement} linkElement - The link DOM element
     */
    function addExpirationIconToLink(linkElement) {
        if (!linkElement || linkElement.querySelector('.bp-link-expiration-icon')) {
            return; // Icon already exists
        }
        
        const sidx = linkElement.dataset.sidx || '0';
        const lidx = linkElement.dataset.lidx || '0';
        
        const iconElement = document.createElement('span');
        iconElement.className = 'bp-link-expiration-icon';
        iconElement.title = 'Set expiration date';
        iconElement.setAttribute('data-sidx', sidx);
        iconElement.setAttribute('data-lidx', lidx);
        iconElement.innerHTML = '&#x23F3;';
        
        // Insert before the remove button
        const removeBtn = linkElement.querySelector('.bp-remove-link-btn');
        if (removeBtn) {
            linkElement.insertBefore(iconElement, removeBtn);
        } else {
            linkElement.appendChild(iconElement);
        }
    }
    
    /**
     * Remove expiration icon from a link element
     * @param {HTMLElement} linkElement - The link DOM element
     */
    function removeExpirationIconFromLink(linkElement) {
        if (!linkElement) return;
        
        const iconElement = linkElement.querySelector('.bp-link-expiration-icon');
        if (iconElement) {
            iconElement.remove();
        }
    }
    
    /**
     * Toggle expiration icons for all existing links
     * @param {boolean} enabled - Whether expiration is enabled
     */
    function toggleExpirationIcons(enabled) {
        const allLinkItems = document.querySelectorAll('.bp-link-item');
        
        allLinkItems.forEach(function(linkItem) {
            if (enabled) {
                addExpirationIconToLink(linkItem);
            } else {
                removeExpirationIconFromLink(linkItem);
            }
        });
        
        // Update the container data attribute
        const sectionsListEl = document.getElementById('bp-link-sections-list');
        if (sectionsListEl) {
            sectionsListEl.dataset.expirationEnabled = enabled ? 'true' : 'false';
        }
        
        // Dispatch event for other systems that might need to know
        document.dispatchEvent(new CustomEvent('expirationIconsToggled', {
            detail: { enabled: enabled }
        }));
    }
    
    /**
     * Listen for requests to open expiration modal and setting changes
     */
    function initializeEventListeners() {
        // Listen for expiration icon clicks from the links system
        document.addEventListener('linkExpirationRequested', function(e) {
            if (e.detail && e.detail.linkElement) {
                openExpirationModal(e.detail.linkElement);
            }
        });
        
        // Listen for expiration setting changes from Advanced tab
        document.addEventListener('expirationSettingChanged', function(e) {
            if (e.detail && typeof e.detail.enabled === 'boolean') {
                toggleExpirationIcons(e.detail.enabled);
            }
        });
        
        // Listen for new link creation to add icons if enabled
        document.addEventListener('linkItemCreated', function(e) {
            if (e.detail && e.detail.linkElement && getLinkExpirationEnabled()) {
                addExpirationIconToLink(e.detail.linkElement);
            }
        });
    }
    
    /**
     * Initialize the link expiration module
     */
    function init() {
        if (!initializeExpirationModalDOM()) {
            console.warn('[Link Expiration] Modal not found - expiration functionality disabled');
            return;
        }
        
        initializeModalEventListeners();
        initializeEventListeners();
        
        console.log('[Link Expiration] Module initialized successfully');
    }
    
    // No global manager exposure - modules use event-driven architecture
    
    // Auto-initialize when DOM is ready
    if (document.readyState !== 'loading') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }

})();