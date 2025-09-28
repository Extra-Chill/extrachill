/**
 * Navigation Menu Controller
 *
 * Handles flyout menu toggling, search functionality, and submenu navigation.
 * Manages body scroll locking during menu open states.
 */
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle-container');
    const searchToggle = document.querySelector('.search-icon');
    const primaryMenu = document.querySelector('#primary-menu');
    const searchSection = primaryMenu.querySelector('.search-section');
    const menuItems = primaryMenu.querySelector('.menu-items');
    const body = document.body;

    let scrollPosition = 0;

    if (!menuToggle) console.error('menuToggle not found');
    if (!searchToggle) console.error('searchToggle not found');
    if (!primaryMenu) console.error('primaryMenu not found');
    if (!searchSection) console.error('searchSection not found');
    if (!menuItems) console.error('menuItems not found');

    if (!menuToggle || !searchToggle || !primaryMenu || !searchSection || !menuItems) {
        console.error('One or more essential elements are missing.');
        return;
    }

    menuToggle.addEventListener('click', function(e) {
        e.preventDefault();
        if (primaryMenu.classList.contains('search-open')) {
            primaryMenu.classList.remove('search-open');
            openMenu();
        } else if (primaryMenu.classList.contains('menu-open')) {
            resetMenu();
        } else {
            openMenu();
        }
    });

    searchToggle.addEventListener('click', function(e) {
        e.preventDefault();
        if (primaryMenu.classList.contains('menu-open') && menuItems.classList.contains('menu-open')) {
            resetMenu();
        } else if (primaryMenu.classList.contains('search-open')) {
            resetMenu();
        } else {
            openSearchOnly();
        }
    });

    // Add click event for submenu toggling
    menuItems.querySelectorAll('.menu-item-has-children > a').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const parentItem = item.parentElement;
            const submenu = parentItem.querySelector('.sub-menu');

            // Toggle the submenu-open class
            if (submenu) {
                submenu.classList.toggle('submenu-open');
                parentItem.classList.toggle('submenu-open');
            }
        });
    });

    function openSearchOnly() {
        primaryMenu.classList.add('search-open', 'menu-open');
        searchSection.classList.add('menu-open');
        searchToggle.classList.add('menu-open');
        body.classList.add('search-only-open');
    }

    function openMenu() {
        primaryMenu.classList.add('menu-open', 'menu-opened');
        searchSection.classList.add('menu-open');
        menuItems.classList.add('menu-open');
        menuToggle.classList.add('menu-open');
        body.classList.add('menu-open');
        lockBodyScroll();
    }

    function resetMenu() {
        primaryMenu.classList.remove('menu-open', 'search-open', 'menu-opened');
        searchSection.classList.remove('menu-open');
        menuItems.classList.remove('menu-open');
        menuToggle.classList.remove('menu-open');
        searchToggle.classList.remove('menu-open');
        body.classList.remove('menu-open', 'search-only-open');

        // Remove submenu-open class from all submenus and their parent items
        menuItems.querySelectorAll('.submenu-open').forEach(submenu => {
            submenu.classList.remove('submenu-open');
        });

        unlockBodyScroll();
    }

    function lockBodyScroll() {
        scrollPosition = window.pageYOffset || document.documentElement.scrollTop;
        body.classList.add('body-fixed');
        body.style.top = `-${scrollPosition}px`;
    }

    function unlockBodyScroll() {
        body.classList.remove('body-fixed');
        body.style.top = '';
        window.scrollTo(0, scrollPosition);
    }
});


