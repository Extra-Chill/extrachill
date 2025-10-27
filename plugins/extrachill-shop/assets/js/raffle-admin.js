/**
 * Raffle Admin Field Conditional Display
 *
 * Shows/hides the "Max Raffle Tickets" field based on presence of "raffle" tag.
 *
 * @package ExtraChillShop
 */

(function($) {
    'use strict';

    /**
     * Check if product has raffle tag
     */
    function hasRaffleTag() {
        const tagInputs = $('.tagchecklist span a');
        for (let i = 0; i < tagInputs.length; i++) {
            const tagText = $(tagInputs[i]).text().toLowerCase().replace(/[^a-z0-9]/g, '');
            if (tagText === 'raffle') {
                return true;
            }
        }
        return false;
    }

    /**
     * Toggle raffle field visibility
     */
    function toggleRaffleField() {
        const $raffleField = $('.raffle-max-tickets-field');

        if (hasRaffleTag()) {
            $raffleField.addClass('visible').fadeIn(300);
        } else {
            $raffleField.removeClass('visible').fadeOut(300);
        }
    }

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Initial check
        toggleRaffleField();

        // Monitor tag changes using MutationObserver
        const tagListContainer = document.querySelector('.tagchecklist');

        if (tagListContainer) {
            const observer = new MutationObserver(function(mutations) {
                toggleRaffleField();
            });

            observer.observe(tagListContainer, {
                childList: true,
                subtree: true
            });
        }

        // Fallback: Poll for changes every 500ms
        setInterval(toggleRaffleField, 500);
    });

})(jQuery);
