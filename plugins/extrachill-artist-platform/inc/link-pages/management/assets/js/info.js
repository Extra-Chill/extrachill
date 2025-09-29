/**
 * Artist Profile Info Management
 *
 * Handles title and bio input fields with event-driven preview communication.
 * Part of the artist platform management interface.
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
        },

        _handleBioChange: function(event) {
            const newBio = event.target.value;
            document.dispatchEvent(new CustomEvent('bioChanged', {
                detail: { bio: newBio }
            }));
        },

    };


    document.addEventListener('infoTabActivated', function(event) {
        InfoManager.init();
    });

    if (document.readyState !== 'loading') {
        InfoManager.init();
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            InfoManager.init();
        });
    }

})(); 