/**
 * Centralized Sortable Management System
 * 
 * Provides unified drag-and-drop functionality for all management interface components.
 * Eliminates duplicate sorting logic scattered across links.js, socials.js, and other modules.
 */

(function() {
    'use strict';
    
    // Registry to track all active sortable instances
    const instances = new Map();
    
    /**
     * Initialize a sortable instance with unified configuration
     * 
     * @param {string|HTMLElement} container - Container selector or element
     * @param {Object} config - Sortable configuration object
     * @returns {Object} Sortable instance
     */
    function init(container, config = {}) {
        const element = typeof container === 'string' ? document.querySelector(container) : container;
        if (!element || typeof Sortable === 'undefined') {
            return null;
        }
        
        // Destroy existing instance if any
        const existingKey = getInstanceKey(element);
        if (instances.has(existingKey)) {
            instances.get(existingKey).destroy();
            instances.delete(existingKey);
        }
        
        // Default configuration
        const defaultConfig = {
            animation: 150,
            onEnd: null,
            onStart: null
        };
        
        // Merge configurations
        const finalConfig = Object.assign({}, defaultConfig, config);
        
        // Create sortable instance
        const sortableInstance = new Sortable(element, finalConfig);
        
        // Store instance for lifecycle management
        const instanceKey = getInstanceKey(element);
        instances.set(instanceKey, sortableInstance);
        
        return sortableInstance;
    }
    
    /**
     * Initialize sorting for link sections
     * 
     * @param {string|HTMLElement} container - Sections container
     * @param {Object} callbacks - Callback functions
     * @returns {Object} Sortable instance
     */
    function initSections(container, callbacks = {}) {
        return init(container, {
            handle: '.bp-section-drag-handle',
            onEnd: function(evt) {
                // Dispatch event for section reorder with movement details
                document.dispatchEvent(new CustomEvent('sectionMoved', {
                    detail: { 
                        oldIndex: evt.oldIndex, 
                        newIndex: evt.newIndex,
                        element: evt.item 
                    }
                }));
                
                // Call original callback if provided
                if (callbacks.onEnd) {
                    callbacks.onEnd(evt);
                }
            },
            onStart: callbacks.onStart || null
        });
    }
    
    /**
     * Initialize sorting for individual links within sections
     * 
     * @param {string|NodeList} containers - Link list containers (can be multiple)
     * @param {Object} callbacks - Callback functions
     * @returns {Array} Array of sortable instances
     */
    function initLinks(containers, callbacks = {}) {
        const instances = [];
        let elements;
        
        if (typeof containers === 'string') {
            elements = document.querySelectorAll(containers);
        } else if (containers instanceof NodeList || Array.isArray(containers)) {
            elements = containers;
        } else {
            elements = [containers]; // Single element
        }
        
        elements.forEach(element => {
            const instance = init(element, {
                handle: '.bp-link-drag-handle',
                group: 'linksGroup', // Allows dragging between sections
                onEnd: function(evt) {
                    // Dispatch event for link reorder with movement details
                    document.dispatchEvent(new CustomEvent('linkMoved', {
                        detail: { 
                            oldIndex: evt.oldIndex, 
                            newIndex: evt.newIndex,
                            element: evt.item,
                            fromContainer: evt.from,
                            toContainer: evt.to
                        }
                    }));
                    
                    // Call original callback if provided
                    if (callbacks.onEnd) {
                        callbacks.onEnd(evt);
                    }
                },
                onStart: callbacks.onStart || null
            });
            
            if (instance) {
                instances.push(instance);
            }
        });
        
        return instances;
    }
    
    /**
     * Initialize sorting for social links
     * 
     * @param {string|HTMLElement} container - Social links container
     * @param {Object} callbacks - Callback functions
     * @returns {Object} Sortable instance
     */
    function initSocials(container, callbacks = {}) {
        return init(container, {
            handle: '.bp-social-drag-handle',
            onEnd: function(evt) {
                // Dispatch event for social icon reorder with movement details
                document.dispatchEvent(new CustomEvent('socialIconMoved', {
                    detail: { 
                        oldIndex: evt.oldIndex, 
                        newIndex: evt.newIndex,
                        element: evt.item 
                    }
                }));
                
                // Call original callback if provided
                if (callbacks.onEnd) {
                    callbacks.onEnd(evt);
                }
            },
            onStart: callbacks.onStart || null
        });
    }
    
    /**
     * Destroy a specific sortable instance
     * 
     * @param {Object} instance - Sortable instance to destroy
     */
    function destroy(instance) {
        if (instance && typeof instance.destroy === 'function') {
            instance.destroy();
            
            // Remove from registry
            for (let [key, value] of instances.entries()) {
                if (value === instance) {
                    instances.delete(key);
                    break;
                }
            }
        }
    }
    
    /**
     * Destroy all sortable instances
     */
    function destroyAll() {
        instances.forEach(instance => {
            if (instance && typeof instance.destroy === 'function') {
                instance.destroy();
            }
        });
        instances.clear();
    }
    
    /**
     * Refresh/reinitialize sortables for dynamically added content
     * 
     * @param {string|HTMLElement} container - Container to refresh
     * @param {Object} config - Original configuration
     */
    function refresh(container, config) {
        const element = typeof container === 'string' ? document.querySelector(container) : container;
        if (!element) return null;
        
        // Destroy existing and recreate
        const existingKey = getInstanceKey(element);
        if (instances.has(existingKey)) {
            instances.get(existingKey).destroy();
            instances.delete(existingKey);
        }
        
        return init(element, config);
    }
    
    /**
     * Get instance registry key for an element
     * 
     * @param {HTMLElement} element - DOM element
     * @returns {string} Unique key for the element
     */
    function getInstanceKey(element) {
        // Use element ID if available, otherwise generate unique key
        return element.id || `sortable_${element.className.replace(/\s+/g, '_')}_${Date.now()}`;
    }
    
    /**
     * Get all active instances
     * 
     * @returns {Map} Map of all active sortable instances
     */
    function getInstances() {
        return new Map(instances);
    }
    
    // Auto-initialize based on available elements
    if (document.readyState !== 'loading') {
        initializeAvailableSortables();
    } else {
        document.addEventListener('DOMContentLoaded', initializeAvailableSortables);
    }
    
    // Listen for new sections being added and initialize sorting
    document.addEventListener('linksectionadded', function(e) {
        setTimeout(() => {
            // Re-initialize all link containers to include the new section
            const sectionsContainer = document.getElementById('bp-link-sections-list');
            if (sectionsContainer) {
                const linkContainers = sectionsContainer.querySelectorAll('.bp-link-list');
                if (linkContainers.length > 0) {
                    initLinks(linkContainers);
                }
            }
        }, 100); // Small delay to ensure DOM is fully updated
    });
    
    // Listen for new links being added and refresh link sorting
    document.addEventListener('linkItemCreated', function(e) {
        setTimeout(() => {
            // Re-initialize link sorting for the affected section
            const linkElement = e.detail?.linkElement;
            if (linkElement) {
                const linkContainer = linkElement.closest('.bp-link-list');
                if (linkContainer) {
                    initLinks([linkContainer]);
                }
            }
        }, 50); // Small delay to ensure DOM is fully updated
    });
    
    function initializeAvailableSortables() {
        // Initialize sections if present
        const sectionsContainer = document.getElementById('bp-link-sections-list');
        if (sectionsContainer) {
            initSections(sectionsContainer);
            
            // Initialize individual link containers within sections
            const linkContainers = sectionsContainer.querySelectorAll('.bp-link-list');
            if (linkContainers.length > 0) {
                initLinks(linkContainers);
            }
        }
        
        // Initialize social icons if present  
        const socialsContainer = document.getElementById('bp-social-icons-list');
        if (socialsContainer) {
            initSocials(socialsContainer);
        }
    }

})();