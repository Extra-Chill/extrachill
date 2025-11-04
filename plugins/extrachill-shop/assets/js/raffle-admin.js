/**
 * Raffle Field Visibility
 *
 * Shows "Max Raffle Tickets" field only when product has "raffle" tag.
 * Uses MutationObserver for real-time tag monitoring with 500ms polling fallback.
 *
 * @package ExtraChillShop
 */

(function($) {
    'use strict';

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

    function toggleRaffleField() {
        const $raffleField = $('.raffle-max-tickets-field');

        if (hasRaffleTag()) {
            $raffleField.addClass('visible').fadeIn(300);
        } else {
            $raffleField.removeClass('visible').fadeOut(300);
        }
    }

    $(document).ready(function() {
        toggleRaffleField();

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

        setInterval(toggleRaffleField, 500);
    });

})(jQuery);
