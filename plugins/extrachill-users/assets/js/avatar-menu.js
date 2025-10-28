/**
 * Avatar Menu Dropdown Controller
 *
 * Handles user avatar dropdown menu toggling and click-outside behavior.
 * Provides keyboard accessibility with Escape key support.
 *
 * @package ExtraChillMultisite
 */
document.addEventListener('DOMContentLoaded', function() {
    const avatarLink = document.querySelector('.user-avatar-link');
    const dropdownMenu = document.querySelector('.user-dropdown-menu');

    if (!avatarLink || !dropdownMenu) {
        return;
    }

    // Toggle dropdown when clicking avatar link
    avatarLink.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropdownMenu.classList.toggle('show');
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.user-avatar-container')) {
            dropdownMenu.classList.remove('show');
        }
    });

    // Close dropdown with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && dropdownMenu.classList.contains('show')) {
            dropdownMenu.classList.remove('show');
            avatarLink.focus();
        }
    });

    // Prevent dropdown links from closing when clicked inside
    dropdownMenu.addEventListener('click', function(e) {
        e.stopPropagation();
    });
});
