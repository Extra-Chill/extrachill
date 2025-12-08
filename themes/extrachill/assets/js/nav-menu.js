/**
 * Navigation Menu Controller
 *
 * Handles flyout menu toggling, search functionality, and submenu navigation.
 * Manages body scroll locking during menu open states.
 */
document.addEventListener('DOMContentLoaded', function() {
    const searchToggle = document.querySelector('.search-icon');
    const searchPanel = document.querySelector('.header-search-panel');
    const closeSearchButton = document.querySelector('.search-close-button');

    if (!searchToggle || !searchPanel) {
        return;
    }

    function openSearch(event) {
        event.preventDefault();
        searchPanel.classList.add('is-visible');
        document.body.classList.add('search-overlay-open');
        const input = searchPanel.querySelector('input[type="search"]');
        if (input) {
            input.focus();
        }
    }

    function closeSearch() {
        searchPanel.classList.remove('is-visible');
        document.body.classList.remove('search-overlay-open');
        searchToggle.focus();
    }

    searchToggle.addEventListener('click', openSearch);

    if (closeSearchButton) {
        closeSearchButton.addEventListener('click', function(event) {
            event.preventDefault();
            closeSearch();
        });
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && searchPanel.classList.contains('is-visible')) {
            closeSearch();
        }
    });

    searchPanel.addEventListener('click', function(event) {
        if (event.target === searchPanel) {
            closeSearch();
        }
    });
});


