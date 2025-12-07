/**
 * Network Dropdown Component
 *
 * Handles toggle, click-outside-close, and keyboard accessibility for
 * the network site-switcher dropdown in homepage breadcrumbs.
 * Follows the same patterns as share.js for consistency.
 *
 * @package ExtraChill
 * @since 1.1.8
 */
(function() {
	'use strict';

	/**
	 * Toggle dropdown state
	 *
	 * @param {HTMLElement} dropdown The .network-dropdown element
	 * @param {boolean} open Whether to open or close
	 */
	function toggleDropdown(dropdown, open) {
		var toggle = dropdown.querySelector('.network-dropdown-toggle');
		var menu = dropdown.querySelector('.network-dropdown-menu');

		if (!toggle || !menu) {
			return;
		}

		dropdown.setAttribute('aria-expanded', open ? 'true' : 'false');
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		menu.setAttribute('aria-hidden', open ? 'false' : 'true');
	}

	/**
	 * Close all dropdowns
	 */
	function closeAllDropdowns() {
		document.querySelectorAll('.network-dropdown').forEach(function(dropdown) {
			toggleDropdown(dropdown, false);
		});
	}

	/**
	 * Handle click events via delegation
	 */
	document.addEventListener('click', function(event) {
		var toggle = event.target.closest('.network-dropdown-toggle');
		var dropdown = event.target.closest('.network-dropdown');

		// Clicked on toggle button
		if (toggle && dropdown) {
			event.preventDefault();
			var isOpen = dropdown.getAttribute('aria-expanded') === 'true';

			// Close all other dropdowns first
			closeAllDropdowns();

			// Toggle this one
			if (!isOpen) {
				toggleDropdown(dropdown, true);
			}
			return;
		}

		// Clicked outside any dropdown - close all
		closeAllDropdowns();
	});

	/**
	 * Handle keyboard events for accessibility
	 */
	document.addEventListener('keydown', function(event) {
		// Escape key closes all dropdowns
		if (event.key === 'Escape') {
			closeAllDropdowns();
		}
	});
})();
