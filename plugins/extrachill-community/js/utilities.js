/**
 * Tooltip functionality for data-title elements
 */

document.addEventListener('DOMContentLoaded', function() {
    let tooltip;

    function createTooltip() {
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            document.body.appendChild(tooltip);
        }
    }

    function showTooltip(element) {
        createTooltip();
        tooltip.innerText = element.getAttribute('data-title');
        const rect = element.getBoundingClientRect();
        tooltip.style.left = `${rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)}px`;
        const verticalOffset = 5;
        tooltip.style.top = `${rect.bottom + window.scrollY + verticalOffset}px`;
        tooltip.style.display = 'block';
    }

    function hideTooltip() {
        if (tooltip) {
            tooltip.style.display = 'none';
        }
    }

    document.body.addEventListener('click', function(e) {
        const target = e.target.closest('[data-title]');
        if (target) {
            showTooltip(target);
            e.stopPropagation(); // Prevent immediate hide
        } else {
            hideTooltip();
        }
    });

    // Adjust for touch devices
    document.body.addEventListener('touchstart', function(e) {
        const target = e.target.closest('[data-title]');
        if (target) {
            e.preventDefault(); // Prevent the browser's default touch action
            showTooltip(target);
            // Consider not hiding the tooltip immediately upon touch end to improve experience
        }
    }, {passive: false}); // Ensure we can call preventDefault

    document.querySelectorAll('[data-title]').forEach(element => {
        element.addEventListener('mouseenter', function() {
            showTooltip(this);
        });
        element.addEventListener('mouseleave', hideTooltip);
    });
});




