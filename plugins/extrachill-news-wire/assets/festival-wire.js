/**
 * Festival Wire JavaScript
 * Handles interaction for the Festival Wire feature
 */

(function() {
    'use strict';

    let currentPage = 1;
    let isLoading = false;

    document.addEventListener('DOMContentLoaded', function() {
        initFestivalFilter();
        initLoadMore();
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
     * Initialize Load More functionality for the archive page
     */
    function initLoadMore() {
        const loadMoreButton = document.getElementById('festival-wire-load-more');
        const postsContainer = document.getElementById('festival-wire-posts-container');

        if (!loadMoreButton || !postsContainer || typeof festivalWireParams === 'undefined' || !festivalWireParams.load_more_nonce) {
            if (loadMoreButton) {
                loadMoreButton.style.display = 'none';
            }
            return;
        }

        if (currentPage >= festivalWireParams.max_pages) {
            loadMoreButton.style.display = 'none';
            return;
        }

        loadMoreButton.addEventListener('click', function() {
            if (isLoading) {
                return;
            }

            isLoading = true;
            currentPage++;

            const originalButtonText = loadMoreButton.textContent;
            loadMoreButton.textContent = 'Loading...';
            loadMoreButton.disabled = true;

            fetch(festivalWireParams.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'load_more_festival_wire',
                    nonce: festivalWireParams.load_more_nonce,
                    page: currentPage,
                    query_vars: festivalWireParams.query_vars
                })
            })
            .then(response => response.text())
            .then(function(responseText) {
                if (responseText && responseText.trim() !== '') {
                    postsContainer.insertAdjacentHTML('beforeend', responseText);

                    if (currentPage >= festivalWireParams.max_pages) {
                        loadMoreButton.style.display = 'none';
                    } else {
                        loadMoreButton.textContent = originalButtonText;
                        loadMoreButton.disabled = false;
                    }
                } else {
                    loadMoreButton.style.display = 'none';
                }
            })
            .catch(function(error) {
                console.error('Error loading more posts:', error);
                loadMoreButton.textContent = originalButtonText;
                loadMoreButton.disabled = false;
            })
            .finally(function() {
                isLoading = false;
                if (currentPage < festivalWireParams.max_pages) {
                    loadMoreButton.disabled = false;
                }
            });
        });
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
