# JavaScript Architecture

Event-driven modular JavaScript system using IIFE patterns with standardized communication between management and preview modules.

## Module Architecture

### Self-Contained IIFE Pattern

All JavaScript modules use immediate function expressions for encapsulation:

```javascript
// Standard module pattern
(function() {
    'use strict';
    
    const ModuleName = {
        init: function() {
            this.bindEvents();
            this.initializeState();
        },
        
        bindEvents: function() {
            // Event binding logic
        },
        
        initializeState: function() {
            // Module initialization
        }
    };
    
    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', ModuleName.init.bind(ModuleName));
})();
```

## Event-Driven Communication

### CustomEvent System

Modules communicate through standardized CustomEvent dispatching:

```javascript
// Management module dispatching events
document.dispatchEvent(new CustomEvent('infoChanged', {
    detail: { 
        title: newTitle, 
        bio: newBio 
    }
}));

document.dispatchEvent(new CustomEvent('linksChanged', {
    detail: { 
        links: linkData 
    }
}));

// Preview module listening for events
document.addEventListener('infoChanged', function(e) {
    updatePreviewInfo(e.detail);
});

document.addEventListener('linksChanged', function(e) {
    updatePreviewLinks(e.detail.links);
});
```

### Event Standardization

Common event types across the system:

- `infoChanged`: Title and biography updates
- `linksChanged`: Link structure modifications
- `socialsChanged`: Social media link updates
- `colorsChanged`: Color scheme updates
- `fontsChanged`: Typography changes
- `backgroundChanged`: Background modifications
- `sizingChanged`: Layout adjustments
- `expirationChanged`: Link expiration updates

## Module Categories

### 1. Management Modules

Handle form interactions and dispatch events for data changes:

#### Info Management
Location: `inc/link-pages/management/assets/js/info.js`

```javascript
const InfoManager = {
    fields: {
        title: null,
        bio: null
    },
    
    init: function() {
        this.cacheFields();
        this.bindEvents();
    },
    
    cacheFields: function() {
        this.fields.title = document.getElementById('artist-title');
        this.fields.bio = document.getElementById('bio-textarea');
    },
    
    bindEvents: function() {
        if (this.fields.title) {
            this.fields.title.addEventListener('input', this.handleTitleChange.bind(this));
        }
        
        if (this.fields.bio) {
            this.fields.bio.addEventListener('input', this.handleBioChange.bind(this));
        }
    },
    
    handleTitleChange: function(e) {
        const newTitle = e.target.value;
        this.dispatchInfoUpdate();
    },
    
    handleBioChange: function(e) {
        const newBio = e.target.value;
        this.dispatchInfoUpdate();
    },
    
    dispatchInfoUpdate: function() {
        document.dispatchEvent(new CustomEvent('infoChanged', {
            detail: {
                title: this.fields.title ? this.fields.title.value : '',
                bio: this.fields.bio ? this.fields.bio.value : ''
            }
        }));
    }
};
```

#### Links Management
Location: `inc/link-pages/management/assets/js/links.js`

```javascript
const LinksManager = {
    container: null,
    sortable: null,
    
    init: function() {
        this.container = document.getElementById('links-container');
        if (!this.container) return;
        
        this.bindEvents();
        this.initializeSortable();
    },
    
    bindEvents: function() {
        // Add link button
        const addButton = document.getElementById('add-link');
        if (addButton) {
            addButton.addEventListener('click', this.addLinkItem.bind(this));
        }
        
        // Link item changes
        this.container.addEventListener('input', this.handleLinkChange.bind(this));
        this.container.addEventListener('click', this.handleLinkActions.bind(this));
    },
    
    handleLinkChange: function(e) {
        if (e.target.matches('.link-url, .link-text')) {
            this.dispatchLinksUpdate();
        }
    },
    
    dispatchLinksUpdate: function() {
        const linksData = this.collectLinksData();
        
        document.dispatchEvent(new CustomEvent('linksChanged', {
            detail: { links: linksData }
        }));
    },
    
    collectLinksData: function() {
        const sections = [];
        const sectionElements = this.container.querySelectorAll('.link-section');
        
        sectionElements.forEach(section => {
            const sectionTitle = section.querySelector('.section-title input');
            const linkItems = section.querySelectorAll('.link-item');
            
            const links = [];
            linkItems.forEach(item => {
                const url = item.querySelector('.link-url');
                const text = item.querySelector('.link-text');
                
                if (url && text && url.value.trim() && text.value.trim()) {
                    links.push({
                        link_url: url.value.trim(),
                        link_text: text.value.trim()
                    });
                }
            });
            
            sections.push({
                section_title: sectionTitle ? sectionTitle.value : '',
                links: links
            });
        });
        
        return sections;
    }
};
```

### 2. Preview Modules

Listen for events and update live preview DOM elements:

#### Info Preview
Location: `inc/link-pages/management/live-preview/assets/js/info-preview.js`

```javascript
const InfoPreview = {
    elements: {
        title: null,
        bio: null
    },
    
    init: function() {
        this.cacheElements();
        this.bindEvents();
    },
    
    cacheElements: function() {
        const previewFrame = document.getElementById('live-preview-iframe');
        if (!previewFrame || !previewFrame.contentDocument) return;
        
        const previewDoc = previewFrame.contentDocument;
        this.elements.title = previewDoc.querySelector('.preview-title');
        this.elements.bio = previewDoc.querySelector('.preview-bio');
    },
    
    bindEvents: function() {
        document.addEventListener('infoChanged', this.handleInfoUpdate.bind(this));
    },
    
    handleInfoUpdate: function(e) {
        const { title, bio } = e.detail;
        
        if (this.elements.title) {
            this.elements.title.textContent = title || 'Artist Name';
        }
        
        if (this.elements.bio) {
            // Handle bio formatting
            this.elements.bio.innerHTML = this.formatBio(bio);
        }
    },
    
    formatBio: function(bio) {
        if (!bio) return '';
        
        // Convert line breaks to paragraphs
        return bio.split('\n\n').map(paragraph => {
            return paragraph.trim() ? `<p>${paragraph.replace(/\n/g, '<br>')}</p>` : '';
        }).join('');
    }
};
```

#### Links Preview
Location: `inc/link-pages/management/live-preview/assets/js/links-preview.js`

```javascript
const LinksPreview = {
    container: null,
    
    init: function() {
        this.cacheElements();
        this.bindEvents();
    },
    
    cacheElements: function() {
        const previewFrame = document.getElementById('live-preview-iframe');
        if (!previewFrame || !previewFrame.contentDocument) return;
        
        this.container = previewFrame.contentDocument.querySelector('.preview-links');
    },
    
    bindEvents: function() {
        document.addEventListener('linksChanged', this.handleLinksUpdate.bind(this));
    },
    
    handleLinksUpdate: function(e) {
        if (!this.container) return;
        
        const { links } = e.detail;
        this.renderLinks(links);
    },
    
    renderLinks: function(sections) {
        if (!this.container) return;
        
        // Clear existing links
        this.container.innerHTML = '';
        
        sections.forEach(section => {
            if (section.links && section.links.length > 0) {
                // Add section title if present
                if (section.section_title) {
                    const titleElement = document.createElement('h3');
                    titleElement.className = 'section-title';
                    titleElement.textContent = section.section_title;
                    this.container.appendChild(titleElement);
                }
                
                // Add links
                section.links.forEach(link => {
                    const linkElement = this.createLinkElement(link);
                    this.container.appendChild(linkElement);
                });
            }
        });
    },
    
    createLinkElement: function(linkData) {
        const link = document.createElement('a');
        link.href = linkData.link_url;
        link.textContent = linkData.link_text;
        link.className = 'preview-link-button';
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        
        return link;
    }
};
```

### 3. Utility Modules

Shared functionality across components:

#### UI Utils
Location: `inc/link-pages/management/assets/js/ui-utils.js`

```javascript
const UIUtils = {
    // Responsive tab management
    initTabs: function(tabContainer) {
        const tabs = tabContainer.querySelectorAll('.tab-button');
        const panels = tabContainer.querySelectorAll('.tab-panel');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                
                const targetPanel = tab.getAttribute('data-tab');
                
                // Update active states
                tabs.forEach(t => t.classList.remove('active'));
                panels.forEach(p => p.classList.remove('active'));
                
                tab.classList.add('active');
                const panel = tabContainer.querySelector(`[data-panel="${targetPanel}"]`);
                if (panel) {
                    panel.classList.add('active');
                }
                
                // Dispatch tab change event
                document.dispatchEvent(new CustomEvent('tabChanged', {
                    detail: { tab: targetPanel }
                }));
            });
        });
    },
    
    // Copy to clipboard functionality
    copyToClipboard: function(text, successCallback) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function() {
                if (successCallback) successCallback();
            });
        } else {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            
            if (successCallback) successCallback();
        }
    },
    
    // Debounce utility
    debounce: function(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
};
```

### 4. Global Modules

Cross-component features:

#### Artist Switcher
Location: `assets/js/artist-switcher.js`

```javascript
const ArtistSwitcher = {
    selector: null,
    baseUrl: '',
    
    init: function() {
        this.selector = document.getElementById('artist-switcher-select');
        if (!this.selector) return;
        
        this.baseUrl = this.selector.getAttribute('data-base-url') || '';
        this.bindEvents();
    },
    
    bindEvents: function() {
        this.selector.addEventListener('change', this.handleArtistChange.bind(this));
    },
    
    handleArtistChange: function(e) {
        const selectedArtistId = e.target.value;
        
        if (selectedArtistId && this.baseUrl) {
            const newUrl = this.baseUrl.replace('ARTIST_ID', selectedArtistId);
            window.location.href = newUrl;
        }
    }
};
```

## Advanced Features

### Drag-and-Drop Integration

Location: `inc/link-pages/management/assets/js/sortable.js`

```javascript
const SortableManager = {
    sortableInstance: null,
    
    init: function() {
        const container = document.getElementById('sortable-links-container');
        if (!container || typeof Sortable === 'undefined') return;
        
        this.initializeSortable(container);
    },
    
    initializeSortable: function(container) {
        this.sortableInstance = new Sortable(container, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            handle: '.drag-handle',
            onEnd: this.handleSortEnd.bind(this)
        });
    },
    
    handleSortEnd: function(evt) {
        // Update order and trigger preview update
        this.updateLinkOrder();
        
        // Dispatch event for preview update
        document.dispatchEvent(new CustomEvent('linksReordered', {
            detail: {
                oldIndex: evt.oldIndex,
                newIndex: evt.newIndex
            }
        }));
    }
};
```

### Join Flow Modal System

Location: `inc/join/assets/js/join-flow-ui.js`

```javascript
const JoinFlowModal = {
    modal: null,
    overlay: null,

    init: function() {
        this.cacheElements();
        this.bindEvents();
    },

    cacheElements: function() {
        this.modal = document.getElementById('join-flow-modal-content');
        this.overlay = document.getElementById('join-flow-modal-overlay');
    },

    bindEvents: function() {
        const existingAccountBtn = document.getElementById('join-flow-existing-account');
        const newAccountBtn = document.getElementById('join-flow-new-account');

        if (existingAccountBtn) {
            existingAccountBtn.addEventListener('click', this.handleExistingAccount.bind(this));
        }

        if (newAccountBtn) {
            newAccountBtn.addEventListener('click', this.handleNewAccount.bind(this));
        }
    },

    handleExistingAccount: function() {
        // Trigger login tab activation
        this.closeModal();
    },

    handleNewAccount: function() {
        // Trigger register tab activation with join flow parameter
        this.closeModal();
    },

    closeModal: function() {
        if (this.modal) this.modal.style.display = 'none';
        if (this.overlay) this.overlay.style.display = 'none';
    }
};
```

## AJAX Integration

### WordPress AJAX Patterns

Standard AJAX communication with WordPress backend:

```javascript
const AjaxManager = {
    performAjaxRequest: function(action, data, callbacks) {
        const ajaxData = {
            action: action,
            nonce: window.ajaxNonce || '',
            ...data
        };
        
        fetch(window.ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(ajaxData)
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                if (callbacks.success) callbacks.success(result.data);
            } else {
                if (callbacks.error) callbacks.error(result.data);
            }
        })
        .catch(error => {
            if (callbacks.error) callbacks.error('Network error');
        });
    }
};

// Usage example
AjaxManager.performAjaxRequest('save_link_data', {
    link_page_id: 123,
    links_json: JSON.stringify(linksData)
}, {
    success: function(data) {
        // Handle success
    },
    error: function(error) {
        // Handle error
    }
});
```

## Performance Optimization

### Lazy Loading

```javascript
// Lazy initialize modules based on tab visibility
document.addEventListener('tabShown', function(e) {
    const tabName = e.detail.tab;
    
    switch (tabName) {
        case 'analytics':
            if (typeof AnalyticsDashboard !== 'undefined') {
                AnalyticsDashboard.init();
            }
            break;
            
        case 'advanced':
            if (typeof AdvancedSettings !== 'undefined') {
                AdvancedSettings.init();
            }
            break;
    }
});
```

### Event Delegation

```javascript
// Use event delegation for dynamic content
document.addEventListener('click', function(e) {
    // Handle link removal
    if (e.target.matches('.remove-link-button')) {
        const linkItem = e.target.closest('.link-item');
        if (linkItem) {
            linkItem.remove();
            LinksManager.dispatchLinksUpdate();
        }
    }
    
    // Handle social icon removal
    if (e.target.matches('.remove-social-button')) {
        const socialItem = e.target.closest('.social-item');
        if (socialItem) {
            SocialsManager.removeSocialItem(socialItem);
        }
    }
});
```

## Asset Management Integration

### Context-Aware Loading

JavaScript modules are loaded conditionally based on context:

```php
// In ExtraChillArtistPlatform_Assets class
public function enqueue_management_assets() {
    // Core management scripts
    wp_enqueue_script('link-page-info', $this->get_asset_url('js/info.js'));
    wp_enqueue_script('link-page-links', $this->get_asset_url('js/links.js'));
    
    // Preview scripts (only on management pages)
    wp_enqueue_script('info-preview', $this->get_asset_url('live-preview/js/info-preview.js'));
    wp_enqueue_script('links-preview', $this->get_asset_url('live-preview/js/links-preview.js'));
    
    // Utility scripts
    wp_enqueue_script('ui-utils', $this->get_asset_url('js/ui-utils.js'));
}
```

## Browser Compatibility

### Modern API Usage with Fallbacks

```javascript
// Native Web Share API with fallbacks
const ShareManager = {
    shareLink: function(url, title) {
        if (navigator.share) {
            navigator.share({
                title: title,
                url: url
            });
        } else {
            // Fallback to copy to clipboard
            UIUtils.copyToClipboard(url, function() {
                alert('Link copied to clipboard');
            });
        }
    }
};

// Intersection Observer with fallback
const LazyLoader = {
    observeElements: function(elements, callback) {
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(callback);
            elements.forEach(el => observer.observe(el));
        } else {
            // Fallback: Load all elements immediately
            elements.forEach(callback);
        }
    }
};
```