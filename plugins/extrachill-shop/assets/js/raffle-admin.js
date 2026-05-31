/**
 * Raffle Field Visibility
 *
 * Shows "Max Raffle Tickets" field only when product has "raffle" tag.
 * Uses MutationObserver for real-time tag monitoring with 500ms polling fallback.
 *
 * @package ExtraChillShop
 */

(function() {
    'use strict';

    function hasRaffleTag() {
        const tagInputs = document.querySelectorAll('.tagchecklist span a');
        for (let i = 0; i < tagInputs.length; i++) {
            const tagText = tagInputs[i].textContent.toLowerCase().replace(/[^a-z0-9]/g, '');
            if (tagText === 'raffle') {
                return true;
            }
        }
        return false;
    }

    function toggleRaffleField() {
        const raffleFields = document.querySelectorAll('.raffle-max-tickets-field');
        const visible = hasRaffleTag();

        for (let i = 0; i < raffleFields.length; i++) {
            raffleFields[i].classList.toggle('visible', visible);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        toggleRaffleField();

        const tagListContainer = document.querySelector('.tagchecklist');

        if (tagListContainer) {
            const observer = new MutationObserver(function() {
                toggleRaffleField();
            });

            observer.observe(tagListContainer, {
                childList: true,
                subtree: true
            });
        }

        setInterval(toggleRaffleField, 500);
    });

})();
