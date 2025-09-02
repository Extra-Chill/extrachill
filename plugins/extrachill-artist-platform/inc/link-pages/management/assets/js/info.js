/**
 * Manage Link Page - Info Tab (Title, Bio) - Self-Contained
 * Handles UI and dispatches events for live preview updates.
 */
(function() {
    'use strict';
    
    const InfoManager = {
    fields: {
        titleInput: null,
        bioTextarea: null,
    },

    init: function() {

        this.fields.titleInput = document.getElementById('artist_profile_title');
        this.fields.bioTextarea = document.getElementById('link_page_bio_text');
        
        this._attachEventListeners();
        this._loadInitialValues();
    },

    _loadInitialValues: function() {
        // Self-contained - no external dependencies needed
        console.log('[Info] Using form field values directly for title and bio');
    },

    _attachEventListeners: function() {
        if (this.fields.titleInput) {
            this.fields.titleInput.addEventListener('input', this._handleTitleChange.bind(this));
        }
        if (this.fields.bioTextarea) {
            this.fields.bioTextarea.addEventListener('input', this._handleBioChange.bind(this));
        }
    },

    _handleTitleChange: function(event) {
        const newTitle = event.target.value;
        document.dispatchEvent(new CustomEvent('titleChanged', {
            detail: { title: newTitle }
        }));
        // The actual artist_profile title update (and sync to artist_link_page post_title) happens server-side on form save.
        // No need to update hidden input for this, as 'artist_profile_title' is part of the form.
    },

    _handleBioChange: function(event) {
        const newBio = event.target.value;
        document.dispatchEvent(new CustomEvent('bioChanged', {
            detail: { bio: newBio }
        }));
        // 'link_page_bio_text' is part of the form, server handles saving.
    },

};

// No serialization needed - form fields handle all data persistence

    // Listen for info tab activation
    document.addEventListener('infoTabActivated', function(event) {
        InfoManager.init();
    });

    // Auto-initialize when DOM is ready (for default tab)
    if (document.readyState !== 'loading') {
        InfoManager.init();
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            InfoManager.init();
        });
    }

})(); 