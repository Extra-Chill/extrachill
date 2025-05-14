document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle-container');
    const avatarButton = document.querySelector('.user-avatar-button');
    const dropdownMenu = document.querySelector('.user-dropdown-menu');
    const searchToggle = document.querySelector('.search-icon');
    const primaryMenu = document.querySelector('.main-navigation #primary-menu');
    const searchSection = primaryMenu.querySelector('.search-section');
    const menuItems = primaryMenu.querySelector('.menu-items');
    const body = document.body;

    let scrollPosition = 0;

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
            primaryMenu.classList.add('search-open', 'menu-open', 'menu-opened');
            searchSection.classList.add('menu-open');
            searchToggle.classList.add('menu-open');
            body.classList.add('menu-open');
            lockBodyScroll();
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

    // User dropdown menu functionality
    if (avatarButton && dropdownMenu) {
        avatarButton.addEventListener('click', function(e) {
            e.stopPropagation();
            const isExpanded = avatarButton.getAttribute('aria-expanded') === 'true';
            avatarButton.setAttribute('aria-expanded', !isExpanded);
            dropdownMenu.classList.toggle('show');
        });

        document.addEventListener('click', function() {
            dropdownMenu.classList.remove('show');
        });
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
        body.classList.remove('menu-open');

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

document.addEventListener('DOMContentLoaded', function () {
    // Create and append the progress bar to the masthead
    const progressBar = document.createElement('div');
    progressBar.id = 'reading-progress';
    document.getElementById('masthead').appendChild(progressBar);

    // Function to update the width of the progress bar based on scroll position
    function updateProgressBar() {
        const scrollPosition = window.scrollY;
        const documentHeight = document.body.scrollHeight - window.innerHeight;
        const scrollPercentage = (scrollPosition / documentHeight) * 100;

        // Update the width of the green fill in the progress bar
        progressBar.style.width = scrollPercentage + '%';
    }

    // Attach the update function to the scroll event
    window.addEventListener('scroll', updateProgressBar);
});

