// Subscription Settings Management Module ("Subscribe Brain")
// Handles real-time preview updates for the subscribe card in the Advanced tab
(function(manager) {
    if (!manager) return;
    manager.subscribe = manager.subscribe || {};

    // Helper to get the preview iframe and content wrapper
    function getPreviewEls() {
        const previewEl = manager.getPreviewEl ? manager.getPreviewEl() : null;
        const contentWrapperEl = previewEl ? previewEl.querySelector('.extrch-link-page-content-wrapper') : null;
        return { previewEl, contentWrapperEl };
    }

    // Helper to get the band name for the subscribe header
    function getBandName() {
        // Try to get from a data attribute on the preview container, or from the page
        const previewEl = manager.getPreviewEl ? manager.getPreviewEl() : null;
        if (previewEl) {
            const h1 = previewEl.querySelector('.extrch-link-page-title');
            if (h1 && h1.textContent.trim() !== '') {
                return h1.textContent.trim();
            }
        }
        // Fallback: empty string
        return '';
    }

    // Update the subscribe preview (mode: 'icon_modal', 'inline_form', 'disabled'; description: string)
    manager.subscribe.updateSubscribePreview = function(mode, description) {
        const { previewEl, contentWrapperEl } = getPreviewEls();
        if (!previewEl || !contentWrapperEl) return;
        // Remove any existing subscribe UI
        // Remove bell icon
        const bell = contentWrapperEl.querySelector('.extrch-bell-page-trigger');
        if (bell) bell.remove();
        // Remove inline form
        const inlineForm = contentWrapperEl.querySelector('.extrch-link-page-subscribe-inline-form-container');
        if (inlineForm) inlineForm.remove();
        // Insert as needed
        const bandName = getBandName();
        const subscribeHeader = bandName ? `Subscribe to ${manager.escapeHTML(bandName)}` : 'Subscribe';
        if (mode === 'icon_modal') {
            // Insert bell icon in header
            const header = contentWrapperEl.querySelector('.extrch-link-page-header-content');
            if (header) {
                const bellBtn = document.createElement('button');
                bellBtn.className = 'extrch-share-trigger extrch-subscribe-icon-trigger extrch-bell-page-trigger';
                bellBtn.setAttribute('aria-label', subscribeHeader + ' (preview)');
                bellBtn.innerHTML = '<i class="fas fa-bell"></i>';
                // Insert as first child (before profile image/title)
                header.insertBefore(bellBtn, header.firstChild);
            }
        } else if (mode === 'inline_form') {
            // Insert inline form after links, before powered by or socials-below
            const powered = contentWrapperEl.querySelector('.extrch-link-page-powered');
            const socialsBelow = contentWrapperEl.querySelector('.extrch-link-page-socials.extrch-socials-below');
            const formContainer = document.createElement('div');
            formContainer.className = 'extrch-link-page-subscribe-inline-form-container';
            formContainer.style.margin = '2em auto 0 auto';
            formContainer.style.maxWidth = '350px';
            formContainer.style.textAlign = 'center';
            formContainer.innerHTML =
                `<h3 style=\"margin-bottom:0.5em;\">${subscribeHeader}</h3>` +
                '<p style="margin-bottom:1em; color:#888; font-size:0.97em;">' +
                (description && description.trim() !== '' ? manager.escapeHTML(description) : `Enter your email address to receive occasional news and updates from ${manager.escapeHTML(bandName)}`) +
                '</p>' +
                '<form class="extrch-subscribe-form" onsubmit="return false;">' +
                '<input type="email" placeholder="Your email address" style="width:100%;max-width:250px;">' +
                '<button type="submit" class="button button-primary extrch-subscribe-btn" style="margin-top:0.5em;">Subscribe</button>' +
                '</form>';
            if (socialsBelow) {
                contentWrapperEl.insertBefore(formContainer, socialsBelow);
            } else if (powered) {
                contentWrapperEl.insertBefore(formContainer, powered);
            } else {
                contentWrapperEl.appendChild(formContainer);
            }
        }
        // If disabled, nothing is inserted
    };

    // Utility for escaping HTML (copied from other brains)
    manager.escapeHTML = manager.escapeHTML || function(str) {
        if (typeof str !== 'string') return '';
        return str.replace(/[&<"'`]/g, function (match) {
            switch (match) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#39;';
                case '`': return '&#96;';
                default: return match;
            }
        });
    };

    // Main init function
    manager.subscribe.init = function() {
        // Get DOM elements
        const radioInputs = document.querySelectorAll('input[name="link_page_subscribe_display_mode"]');
        const descTextarea = document.getElementById('link_page_subscribe_description');
        if (!radioInputs.length || !descTextarea) return;
        // Helper to get current values
        function getCurrentMode() {
            const checked = Array.from(radioInputs).find(r => r.checked);
            return checked ? checked.value : 'icon_modal';
        }
        function getCurrentDesc() {
            return descTextarea.value;
        }
        // Update preview on radio change
        radioInputs.forEach(radio => {
            radio.addEventListener('change', function() {
                manager.subscribe.updateSubscribePreview(getCurrentMode(), getCurrentDesc());
            });
        });
        // Update preview on description input
        descTextarea.addEventListener('input', function() {
            if (getCurrentMode() === 'inline_form') {
                manager.subscribe.updateSubscribePreview('inline_form', getCurrentDesc());
            }
        });
        // Initial sync
        // manager.subscribe.updateSubscribePreview(getCurrentMode(), getCurrentDesc());
    };

    // Optionally, auto-init if not using main manager
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', manager.subscribe.init);
    } else {
        manager.subscribe.init();
    }

})(window.ExtrchLinkPageManager = window.ExtrchLinkPageManager || {}); 