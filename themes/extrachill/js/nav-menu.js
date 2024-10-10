document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle-container');
    const searchToggle = document.querySelector('.header-search-icon');
    const primaryMenu = document.querySelector('#primary-menu');
    const searchForm = document.querySelector('.header-search');
    const headerContainer = document.querySelector('.header-container');

    if (!menuToggle || !searchToggle || !primaryMenu || !searchForm || !headerContainer) {
        console.error('One or more essential elements are missing.');
        return; // Exit if essential elements are missing.
    }

    menuToggle.addEventListener('click', function(e) {
        e.preventDefault(); // Prevent default action.
        const isSearchOpen = searchForm.style.display === 'block';
        if (isSearchOpen) searchForm.style.display = 'none'; // Close search form if open.
        primaryMenu.style.display = primaryMenu.style.display === 'block' ? 'none' : 'block'; // Toggle primary menu.
    });

    searchToggle.addEventListener('click', function(e) {
        e.preventDefault(); // Prevent default action.
        const isMenuOpen = primaryMenu.style.display === 'block';
        if (isMenuOpen) primaryMenu.style.display = 'none'; // Close menu if open.
        searchForm.style.display = searchForm.style.display === 'block' ? 'none' : 'block'; // Toggle search form.
    });

    // Handle submenu toggles without altering the structure significantly
    headerContainer.querySelectorAll('.menu-item-has-children').forEach(item => {
        const toggle = item.querySelector('a');
        const submenu = item.querySelector('.sub-menu');
        const indicator = item.querySelector('.submenu-indicator use');

        toggle.addEventListener('click', function(e) {
            e.preventDefault(); // Prevent navigating away.
            submenu.style.display = submenu.style.display === 'block' ? 'none' : 'block'; // Toggle submenu visibility.
            
            // Adjust the submenu indicator icon based on visibility
            let iconHref = submenu.style.display === 'block' ? '#angle-up-solid' : '#angle-down-solid';
            indicator.setAttribute('href', `/wp-content/themes/colormag-pro/fonts/fontawesome.svg?v1.7${iconHref}`); // Adjust path as needed, with dynamic versioning.
        });
    });

    // Close menu if click outside of menu
    document.addEventListener('click', function(e) {
        if (!headerContainer.contains(e.target) && primaryMenu.style.display === 'block') {
            primaryMenu.style.display = 'none';
        }
        if (!headerContainer.contains(e.target) && searchForm.style.display === 'block') {
            searchForm.style.display = 'none';
        }
    });
});
