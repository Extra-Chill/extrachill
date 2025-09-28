/**
 * Festival Wire JavaScript
 * Handles interaction for the Festival Wire feature
 */

(function($) {
    'use strict';

    let currentPage = 1; // Keep track of the current page loaded for AJAX
    let isLoading = false; // Prevent multiple simultaneous AJAX requests

    // Document ready
    $(document).ready(function() {
        // Filter functionality
        initFestivalFilter();
        

        // Load more posts functionality
        initLoadMore(); 

        // Initialize FAQ Accordion
        initFaqAccordion();
    });

    /**
     * Initialize the Festival Filter functionality
     */
    function initFestivalFilter() {
        const filterButton = $('#festival-filter-button');
        const festivalSelect = $('#festival-filter');
        const locationSelect = $('#location-filter');

        // Handle filter button click
        filterButton.on('click', function(e) {
            e.preventDefault();
            applyFestivalFilter();
        });

        // Also trigger on Enter key in dropdowns
        festivalSelect.on('keyup', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyFestivalFilter();
            }
        });

        locationSelect.on('keyup', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyFestivalFilter();
            }
        });

        /**
         * Apply the festival filter
         */
        function applyFestivalFilter() {
            const selectedFestival = festivalSelect.val();
            const selectedLocation = locationSelect.val();
            
            // If both filters are set to "all", just reload the page without parameters
            if (selectedFestival === 'all' && selectedLocation === 'all') {
                window.location.href = window.location.pathname;
                return;
            }
            
            // Create the filter URL with selected parameters
            const filterUrl = new URL(window.location.href);
            
            // Clear existing parameters
            filterUrl.searchParams.delete('festival');
            filterUrl.searchParams.delete('location');
            
            // Add parameters if not "all"
            if (selectedFestival !== 'all') {
                filterUrl.searchParams.set('festival', selectedFestival);
            }
            
            if (selectedLocation !== 'all') {
                filterUrl.searchParams.set('location', selectedLocation);
            }
            
            // Navigate to the filtered URL
            window.location.href = filterUrl.toString();
        }

        // Pre-select the current filters if present in URL
        function preSelectCurrentFilters() {
            const urlParams = new URLSearchParams(window.location.search);
            
            // Check festival parameter
            const currentFestival = urlParams.get('festival');
            if (currentFestival) {
                festivalSelect.val(currentFestival);
            }
            
            // Check location parameter
            const currentLocation = urlParams.get('location');
            if (currentLocation) {
                locationSelect.val(currentLocation);
            }
        }
        
        // Call this on page load
        preSelectCurrentFilters();
    }
    

    /**
     * Initialize Load More functionality for the archive page.
     */
    function initLoadMore() {
        const loadMoreButton = $('#festival-wire-load-more');
        const postsContainer = $('#festival-wire-posts-container'); // Ensure this ID exists in archive-festival-wire.php

        // Check if required elements and parameters exist. Only run on archive pages where these are expected.
        if (loadMoreButton.length === 0 || postsContainer.length === 0 || typeof festivalWireParams === 'undefined' || !festivalWireParams.load_more_nonce) {
             // If the button exists but other parts don't, hide it silently.
             if (loadMoreButton.length > 0) {
                 loadMoreButton.hide();
             }
            // console.log('Load More prerequisites not met.'); // Optional: for debugging
            return;
        }

        // Hide button immediately if there are no more pages initially
        if (currentPage >= festivalWireParams.max_pages) {
            loadMoreButton.hide();
            return;
        }

        loadMoreButton.on('click', function() {
            if (isLoading) {
                return; // Prevent multiple clicks while loading
            }

            isLoading = true;
            currentPage++; // Increment page number for the next set of posts

            // Optional: Change button text or show a spinner
            const originalButtonText = loadMoreButton.text();
            loadMoreButton.text('Loading...');
            loadMoreButton.prop('disabled', true);

            $.ajax({
                url: festivalWireParams.ajaxurl,
                type: 'POST',
                data: {
                    action: 'load_more_festival_wire', // Matches the PHP action hook
                    nonce: festivalWireParams.load_more_nonce,
                    page: currentPage,
                    query_vars: festivalWireParams.query_vars // Pass the original query vars JSON string
                },
                success: function(response) {
                    if (response && response.trim() !== '') {
                         // Append new posts
                         postsContainer.append(response);

                         // Check if it was the last page
                         if (currentPage >= festivalWireParams.max_pages) {
                            loadMoreButton.hide(); // Hide button if no more pages
                         } else {
                             // Restore button state if more pages exist
                            loadMoreButton.text(originalButtonText);
                            loadMoreButton.prop('disabled', false);
                         }
                    } else {
                        // No posts returned or empty response, assume end of content
                        loadMoreButton.hide();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Error loading more posts:', textStatus, errorThrown);
                    // Restore button state on error
                    loadMoreButton.text(originalButtonText);
                    loadMoreButton.prop('disabled', false);
                    // Optional: Show an error message to the user on the page
                    // postsContainer.append('<p class="error">Could not load more posts. Please try again later.</p>');
                },
                complete: function() {
                    isLoading = false; // Allow next request
                    // Ensure button is re-enabled unless it was hidden
                    if (currentPage < festivalWireParams.max_pages) {
                         loadMoreButton.prop('disabled', false);
                    }
                }
            });
        });
    }

    /**
     * Initialize FAQ Accordion functionality
     */
    function initFaqAccordion() {
        const accordionContainer = $('.faq-accordion');
        console.log('initFaqAccordion called. Found container:', accordionContainer.length);
        if (accordionContainer.length === 0) {
            return; // Exit if FAQ section not found
        }

        accordionContainer.find('.faq-question').on('click', function() {
            console.log('FAQ question clicked:', this);
            const $button = $(this);
            const $answer = $('#' + $button.attr('aria-controls'));
            console.log('Target answer element:', $answer.length > 0 ? $answer[0] : 'Not found');
            const isExpanded = $button.attr('aria-expanded') === 'true';
            console.log('Current expanded state:', isExpanded);

            // Toggle the current item
            $button.attr('aria-expanded', !isExpanded);
            $answer.prop('hidden', isExpanded);
            console.log('Toggled state. New expanded:', !isExpanded, 'New hidden prop:', isExpanded);

            // Optional: Close other items when one is opened (uncomment if desired)
            /*
            // ... existing code ...
            */
        });
    }

})(jQuery);
