// Subscription Preview Module - Handles live preview updates for subscription forms
(function() {
    'use strict';
    
    // Helper function to escape HTML
    function escapeHTML(str) {
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
    }

    // Helper to get the preview iframe and content wrapper
    function getPreviewEls() {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live'); 
        const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        const contentWrapperEl = previewEl ? previewEl.querySelector('.extrch-link-page-content-wrapper') : null;
        return { previewEl, contentWrapperEl };
    }

    // Helper to get the band name for the subscribe header
    function getBandName() {
        const previewContainerParent = document.querySelector('.manage-link-page-preview-live'); 
        const previewEl = previewContainerParent?.querySelector('.extrch-link-page-preview-container');
        if (previewEl) {
            const h1 = previewEl.querySelector('.extrch-link-page-title');
            if (h1 && h1.textContent.trim() !== '') {
                return h1.textContent.trim();
            }
        }
        return '';
    }

    // Server-side template rendering for subscription forms
    async function renderSubscribeTemplate(templateType, artistName, description) {
        if (!extraChillArtistPlatform?.ajaxUrl) {
            console.error('AJAX URL not available for subscription template rendering');
            return null;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'render_subscribe_template');
            formData.append('nonce', extraChillArtistPlatform.nonce || '');
            formData.append('template_type', templateType);
            formData.append('artist_id', extraChillArtistPlatform.linkPageData?.artist_id || '1');
            formData.append('artist_name', artistName || '');
            formData.append('description', description || '');

            const response = await fetch(extraChillArtistPlatform.ajaxUrl, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            
            if (data.success && data.data.html) {
                return data.data.html;
            } else {
                console.error('Subscription template rendering failed:', data.data?.message || 'Unknown error');
                return null;
            }
        } catch (error) {
            console.error('Error rendering subscription template:', error);
            return null;
        }
    }

    // Update the subscribe preview (mode: 'icon_modal', 'inline_form', 'disabled'; description: string)
    async function updateSubscribePreview(mode, description) {
        const { previewEl, contentWrapperEl } = getPreviewEls();
        if (!previewEl || !contentWrapperEl) return;
        
        // Remove any existing subscribe UI
        const bell = contentWrapperEl.querySelector('.extrch-bell-page-trigger');
        if (bell) bell.remove();
        const inlineForm = contentWrapperEl.querySelector('.extrch-link-page-subscribe-inline-form-container');
        if (inlineForm) inlineForm.remove();
        
        const bandName = getBandName();
        
        if (mode === 'icon_modal') {
            // Insert bell icon in header
            const header = contentWrapperEl.querySelector('.extrch-link-page-header-content');
            if (header) {
                const bellBtn = document.createElement('button');
                bellBtn.className = 'extrch-share-trigger extrch-subscribe-icon-trigger extrch-bell-page-trigger';
                bellBtn.setAttribute('aria-label', `Subscribe to ${escapeHTML(bandName)} (preview)`);
                bellBtn.innerHTML = '<i class="fas fa-bell"></i>';
                header.insertBefore(bellBtn, header.firstChild);
            }
        } else if (mode === 'inline_form') {
            // Use server-side template for inline form
            try {
                const html = await renderSubscribeTemplate('inline_form', bandName, description);
                if (html) {
                    // Insert inline form after links, before powered by or socials-below
                    const powered = contentWrapperEl.querySelector('.extrch-link-page-powered');
                    const socialsBelow = contentWrapperEl.querySelector('.extrch-link-page-socials.extrch-socials-below');
                    
                    if (socialsBelow) {
                        socialsBelow.insertAdjacentHTML('beforebegin', html);
                    } else if (powered) {
                        powered.insertAdjacentHTML('beforebegin', html);
                    } else {
                        contentWrapperEl.insertAdjacentHTML('beforeend', html);
                    }
                }
            } catch (error) {
                console.error('Error rendering inline subscription form:', error);
            }
        }
        // If disabled, nothing is inserted
    }

    // Event listeners for subscription changes
    document.addEventListener('subscriptionModeChanged', function(e) {
        const { mode, description } = e.detail;
        updateSubscribePreview(mode, description);
    });

    document.addEventListener('subscriptionDescriptionChanged', function(e) {
        const { mode, description } = e.detail;
        updateSubscribePreview(mode, description);
    });

    // Self-contained module - no global exposure needed

    // Initialize event listeners only - no rendering on page load
    document.addEventListener('DOMContentLoaded', function() {
        console.log('[Subscribe Preview] Event listeners initialized - no initial rendering');
    });

})();