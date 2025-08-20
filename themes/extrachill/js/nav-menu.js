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

// Removing the JavaScript block for the newsletter form submission
document.addEventListener('DOMContentLoaded', function () {
    const newsletterForm = document.querySelector('.newsletter-form');
    const emailInput = document.querySelector('#newsletter-email-nav');
    const submitButton = newsletterForm.querySelector('button[type="submit"]');
    const feedback = document.createElement('p'); // Feedback message element
    feedback.classList.add('newsletter-feedback');
    newsletterForm.appendChild(feedback); // Append feedback message

    newsletterForm.addEventListener('submit', function (e) {
        e.preventDefault();

        // Disable submit button to prevent multiple submissions
        submitButton.disabled = true;

        // Prepare data for AJAX
        const formData = new FormData(newsletterForm);
        formData.append('action', 'subscribe_to_sendy');
        formData.append('subscribe_nonce', ajax_object.subscribe_nonce); // Add nonce dynamically

        // Send AJAX request
        fetch(ajax_object.ajax_url, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                feedback.textContent = data.data.message; // Success message
                feedback.style.color = 'green';
                emailInput.value = ''; // Clear the email input
                localStorage.setItem('subscribed', 'true');
            } else {
                feedback.textContent = data.data.message; // Error message
                feedback.style.color = 'red';
            }
            submitButton.disabled = false; // Re-enable submit button
        })
        .catch(error => {
            feedback.textContent = 'An error occurred. Please try again.';
            feedback.style.color = 'red';
            submitButton.disabled = false;
        });
    });
});

