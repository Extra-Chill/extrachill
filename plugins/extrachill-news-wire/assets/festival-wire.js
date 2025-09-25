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
        
        // Tip form submission
        initTipForm();

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
     * Initialize the Festival Wire tip form
     */
    function initTipForm() {
        const form = $('#festival-wire-tip-form');
        const messageDiv = form.find('.festival-wire-tip-message');
        const submitButton = form.find('.festival-wire-tip-submit');
        const textarea = $('#festival-wire-tip-content');
        const charCount = $('#char-count');

        if (form.length === 0) {
            return; // Form not found, exit
        }

        // Character counter functionality
        if (textarea.length && charCount.length) {
            textarea.on('input', function() {
                const currentLength = $(this).val().length;
                charCount.text(currentLength);
                
                // Change color when approaching limit
                const parent = charCount.parent();
                if (currentLength > 900) {
                    parent.css('color', '#d32f2f');
                } else if (currentLength > 800) {
                    parent.css('color', '#f57c00');
                } else {
                    parent.css('color', '#666');
                }
            });
        }

        // Form visibility is now determined server-side via WordPress authentication
        // No need for dynamic cookie checking since WordPress manages authentication state

        form.on('submit', function(e) {
            e.preventDefault();

            // Clear previous message
            messageDiv.removeClass('success error').text('');

            // Get form data
            const content = $('#festival-wire-tip-content').val();
            const email = $('#festival-wire-tip-email').val();
            const isCommunityMember = form.data('community-member') === true;

            // Basic validation
            if (!content) {
                messageDiv.addClass('error').text('Please enter your tip.');
                return;
            }

            // Email validation for non-community members
            if (!isCommunityMember) {
                if (!email) {
                    messageDiv.addClass('error').text('Email address is required.');
                    return;
                }
                // Basic email validation
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(email)) {
                    messageDiv.addClass('error').text('Please enter a valid email address.');
                    return;
                }
            }

            // Show loading state
            const originalBtnText = submitButton.text();
            submitButton.prop('disabled', true).text('Submitting...');

            // Get turnstile response if it exists
            let turnstileResponse = '';
            if (typeof turnstile !== 'undefined') {
                turnstileResponse = turnstile.getResponse();
            } else {
                // Try to get value from hidden input if present
                const cfInput = form.find('input[name="cf-turnstile-response"]');
                if (cfInput.length) {
                    turnstileResponse = cfInput.val();
                }
            }

            // Prepare data for AJAX
            const data = {
                action: 'festival_wire_tip_submission',
                content: content,
                'cf-turnstile-response': turnstileResponse
            };

            // Add email if not community member
            if (!isCommunityMember && email) {
                data.email = email;
            }

            // Add honeypot field
            const websiteField = $('input[name="website"]');
            if (websiteField.length) {
                data.website = websiteField.val();
            }

            // Add nonce if present
            const nonceField = form.find('input[name="festival_wire_tip_nonce_field"]');
            if (nonceField.length) {
                data.nonce = nonceField.val();
            }

            // Send AJAX request
            $.ajax({
                url: typeof festivalWireParams !== 'undefined' ? festivalWireParams.ajaxurl : (window.ajaxurl || '/wp-admin/admin-ajax.php'),
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        messageDiv.addClass('success').text(response.data.message || 'Thank you for your tip!');
                        // Reset form
                        form[0].reset();
                        // Reset character counter
                        if (charCount.length) {
                            charCount.text('0');
                            charCount.parent().css('color', '#666');
                        }
                        // Reset turnstile if it exists
                        if (typeof turnstile !== 'undefined') {
                            turnstile.reset();
                        }
                    } else {
                        // Show error message
                        messageDiv.addClass('error').text(response.data && response.data.message ? response.data.message : 'Submission failed. Please try again.');
                        // Reset turnstile if it exists
                        if (typeof turnstile !== 'undefined') {
                            turnstile.reset();
                        }
                    }
                },
                error: function() {
                    // Show generic error message
                    messageDiv.addClass('error').text('An error occurred. Please try again.');
                    // Reset turnstile if it exists
                    if (typeof turnstile !== 'undefined') {
                        turnstile.reset();
                    }
                },
                complete: function() {
                    // Restore button state
                    submitButton.prop('disabled', false).text(originalBtnText);
                }
            });
        });
    }

    /**
     * Smooth scroll for "Drop us a tip" form
     */
    $('.tip-form-link').on('click', function(e) {
        e.preventDefault();
        const targetElement = $('.festival-wire-tip-form-container');
        
        if (targetElement.length) {
            $('html, body').animate({
                scrollTop: targetElement.offset().top - 100
            }, 800);
        }
    });

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
