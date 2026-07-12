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

	var tooltip = null;

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
		var text = cell.getAttribute('data-ec-tip');
		if (!text) {
			return;
		}

		var tip = ensureTooltip();
		tip.textContent = text;
		tip.hidden = false;

		var rect = cell.getBoundingClientRect();
		var tipRect = tip.getBoundingClientRect();

		var left = rect.left + rect.width / 2 - tipRect.width / 2 + window.scrollX;
		var top = rect.top - tipRect.height - 8 + window.scrollY;

		// Clamp horizontally to the viewport.
		var maxLeft = window.scrollX + document.documentElement.clientWidth - tipRect.width - 4;
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
		var cell = event.target.closest('.ec-heatmap-cells .ec-heat-cell');
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
		var cell = event.target.closest('.ec-heatmap-cells .ec-heat-cell');
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
