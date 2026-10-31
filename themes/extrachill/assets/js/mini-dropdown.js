/**
 * Mini Dropdown Component
 *
 * Unified dropdown behavior for ec-mini-dropdown components.
 * Handles toggle, click-outside-close, and keyboard accessibility.
 */
(function() {
	'use strict';

	function toggleDropdown(dropdown, open) {
		dropdown.setAttribute('aria-expanded', open ? 'true' : 'false');
	}

	function closeAllDropdowns() {
		document.querySelectorAll('.ec-mini-dropdown').forEach(function(dropdown) {
			toggleDropdown(dropdown, false);
		});
	}

	document.addEventListener('click', function(event) {
		const toggle = event.target.closest('.ec-mini-dropdown-toggle');
		const dropdown = event.target.closest('.ec-mini-dropdown');

		if (toggle && dropdown) {
			event.preventDefault();
			const isOpen = dropdown.getAttribute('aria-expanded') === 'true';

			closeAllDropdowns();

			if (!isOpen) {
				toggleDropdown(dropdown, true);
			}
			return;
		}

		closeAllDropdowns();
	});

	document.addEventListener('keydown', function(event) {
		if (event.key === 'Escape') {
			closeAllDropdowns();
		}
	});
})();
