/**
 * Festival Wire JavaScript
 * Handles filters and FAQ accordion for the Festival Wire archive.
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        initFestivalFilter();
        initFaqAccordion();
    });

    /**
     * Initialize the Festival Filter functionality
     */
    function initFestivalFilter() {
        const filterButton = document.getElementById('festival-filter-button');
        const festivalSelect = document.getElementById('festival-filter');
        const locationSelect = document.getElementById('location-filter');

        if (!filterButton || !festivalSelect || !locationSelect) {
            return;
        }

        filterButton.addEventListener('click', function(e) {
            e.preventDefault();
            applyFestivalFilter();
        });

        festivalSelect.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyFestivalFilter();
            }
        });

        locationSelect.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyFestivalFilter();
            }
        });

        function applyFestivalFilter() {
            const selectedFestival = festivalSelect.value;
            const selectedLocation = locationSelect.value;

            if (selectedFestival === 'all' && selectedLocation === 'all') {
                window.location.href = window.location.pathname;
                return;
            }

            const filterUrl = new URL(window.location.href);

            filterUrl.searchParams.delete('festival');
            filterUrl.searchParams.delete('location');

            if (selectedFestival !== 'all') {
                filterUrl.searchParams.set('festival', selectedFestival);
            }

            if (selectedLocation !== 'all') {
                filterUrl.searchParams.set('location', selectedLocation);
            }

            window.location.href = filterUrl.toString();
        }

        function preSelectCurrentFilters() {
            const urlParams = new URLSearchParams(window.location.search);

            const currentFestival = urlParams.get('festival');
            if (currentFestival) {
                festivalSelect.value = currentFestival;
            }

            const currentLocation = urlParams.get('location');
            if (currentLocation) {
                locationSelect.value = currentLocation;
            }
        }

        preSelectCurrentFilters();
    }

    /**
     * Initialize FAQ Accordion functionality
     */
    function initFaqAccordion() {
        const accordionContainer = document.querySelector('.faq-accordion');
        if (!accordionContainer) {
            return;
        }

        const faqQuestions = accordionContainer.querySelectorAll('.faq-question');

        faqQuestions.forEach(function(button) {
            button.addEventListener('click', function() {
                const answerId = button.getAttribute('aria-controls');
                const answer = document.getElementById(answerId);

                if (!answer) {
                    return;
                }

                const isExpanded = button.getAttribute('aria-expanded') === 'true';

                button.setAttribute('aria-expanded', !isExpanded);
                answer.hidden = isExpanded;
            });
        });
    }

})();
