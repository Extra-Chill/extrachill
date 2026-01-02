/**
 * Mini Dropdown Component
 *
 * Unified dropdown behavior for ec-mini-dropdown components.
 * Handles toggle, click-outside-close, and keyboard accessibility.
 *
 * @package ExtraChill
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
		var toggle = event.target.closest('.ec-mini-dropdown-toggle');
		var dropdown = event.target.closest('.ec-mini-dropdown');

		if (toggle && dropdown) {
			event.preventDefault();
			var isOpen = dropdown.getAttribute('aria-expanded') === 'true';

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
