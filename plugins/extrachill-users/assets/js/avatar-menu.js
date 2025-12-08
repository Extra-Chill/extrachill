/**
 * Avatar Menu Dropdown Controller
 *
 * Handles user avatar dropdown menu toggling and click-outside behavior.
 * Provides keyboard accessibility with Escape key support.
 *
 * @package ExtraChillMultisite
 */
document.addEventListener('DOMContentLoaded', function() {
    const avatarToggle = document.querySelector('.user-avatar-toggle');
    const dropdownMenu = document.querySelector('.user-dropdown-menu');

    if (!avatarToggle || !dropdownMenu) {
        return;
    }

    function closeMenu() {
        dropdownMenu.classList.remove('show');
        avatarToggle.setAttribute('aria-expanded', 'false');
    }

    function toggleMenu(event) {
        event.preventDefault();
        event.stopPropagation();
        const isOpen = dropdownMenu.classList.toggle('show');
        avatarToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    avatarToggle.addEventListener('click', toggleMenu);

    avatarToggle.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            toggleMenu(e);
        }
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.user-avatar-container')) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && dropdownMenu.classList.contains('show')) {
            closeMenu();
            avatarToggle.focus();
        }
    });

    dropdownMenu.addEventListener('click', function(e) {
        e.stopPropagation();
    });
});
