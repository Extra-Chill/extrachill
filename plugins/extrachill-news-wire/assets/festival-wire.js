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
     * Add searchable option lists while preserving the native GET form.
     */
    function initFestivalFilter() {
        const festivalSelect = document.getElementById('festival-filter');
        const locationSelect = document.getElementById('location-filter');
        const festivalSearch = document.getElementById('festival-search');
        const locationSearch = document.getElementById('location-search');

        if (!festivalSelect || !locationSelect || !festivalSearch || !locationSearch) {
            return;
        }

        function makeSelectSearchable(searchInput, select, statusId, itemLabel) {
            if (select.disabled) {
                return;
            }

            const options = Array.from(select.options).map(function(option) {
                return {
                    value: option.value,
                    text: option.text,
                    disabled: option.disabled,
                };
            });
            const status = document.getElementById(statusId);

            searchInput.hidden = false;
            if (searchInput.labels.length) {
                searchInput.labels[0].hidden = false;
            }
            searchInput.addEventListener('input', function() {
                const query = searchInput.value.trim().toLocaleLowerCase();
                const selectedValue = select.value;
                const matches = options.filter(function(option, index) {
                    return index === 0 || option.text.toLocaleLowerCase().includes(query);
                });

                select.replaceChildren();
                matches.forEach(function(optionData) {
                    const option = document.createElement('option');
                    option.text = optionData.text;
                    option.value = optionData.value;
                    option.disabled = optionData.disabled;
                    option.selected = optionData.value === selectedValue;
                    select.add(option);
                });

                if (status) {
                    const resultCount = Math.max(0, matches.length - 1);
                    status.textContent = resultCount + ' ' + itemLabel + (resultCount === 1 ? '' : 's') + ' found.';
                }
            });
        }

        makeSelectSearchable(festivalSearch, festivalSelect, 'festival-search-status', 'festival');
        makeSelectSearchable(locationSearch, locationSelect, 'location-search-status', 'location');
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
