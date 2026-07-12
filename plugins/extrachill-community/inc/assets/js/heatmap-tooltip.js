/**
 * Contribution Heatmap Tooltip
 *
 * Instant hover/focus tooltip for heatmap day cells. The cells live inside an
 * overflow-x scroll container, so a CSS ::after tooltip would be clipped —
 * instead one shared tooltip element is appended to <body> and positioned
 * above the hovered cell (GitHub's approach). Works for mouse hover and
 * keyboard focus (cells carry tabindex="0").
 */
(function () {
	'use strict';

	let tooltip = null;

	function ensureTooltip() {
		if (tooltip) {
			return tooltip;
		}
		tooltip = document.createElement('div');
		tooltip.className = 'ec-heatmap-tooltip';
		tooltip.setAttribute('role', 'tooltip');
		tooltip.hidden = true;
		document.body.appendChild(tooltip);
		return tooltip;
	}

	function show(cell) {
		const text = cell.getAttribute('data-ec-tip');
		if (!text) {
			return;
		}

		const tip = ensureTooltip();
		tip.textContent = text;
		tip.hidden = false;

		const rect = cell.getBoundingClientRect();
		const tipRect = tip.getBoundingClientRect();

		let left = rect.left + rect.width / 2 - tipRect.width / 2 + window.scrollX;
		const top = rect.top - tipRect.height - 8 + window.scrollY;

		// Clamp horizontally to the viewport.
		const maxLeft = window.scrollX + document.documentElement.clientWidth - tipRect.width - 4;
		left = Math.max(window.scrollX + 4, Math.min(left, maxLeft));

		tip.style.left = left + 'px';
		tip.style.top = top + 'px';
	}

	function hide() {
		if (tooltip) {
			tooltip.hidden = true;
		}
	}

	document.addEventListener('mouseover', function (event) {
		const cell = event.target.closest('.ec-heatmap-cells .ec-heat-cell');
		if (cell) {
			show(cell);
		}
	});

	document.addEventListener('mouseout', function (event) {
		if (event.target.closest('.ec-heatmap-cells .ec-heat-cell')) {
			hide();
		}
	});

	document.addEventListener('focusin', function (event) {
		const cell = event.target.closest('.ec-heatmap-cells .ec-heat-cell');
		if (cell) {
			show(cell);
		}
	});

	document.addEventListener('focusout', function (event) {
		if (event.target.closest('.ec-heatmap-cells .ec-heat-cell')) {
			hide();
		}
	});

	// Hide on scroll so a stale tooltip never floats detached from its cell.
	document.addEventListener('scroll', hide, true);
})();
