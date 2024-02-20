/* tabs */

jQuery(document).ready(function($) {
    // Function to show the tab content
    function showTabContent(hash) {
        $('.tabs li').removeClass('active'); // Remove active class from all tabs
        $('.tabs a[href="' + hash + '"]').parent('li').addClass('active'); // Add active class to the selected tab
        
        $('.tab-content').hide(); // Hide all tab contents
        $(hash).show(); // Show the selected tab content

        // Additional logic to update pagination when a tab is clicked
        // Assuming your pagination controls have class "pagination"
        $('.pagination').hide(); // Hide all pagination controls
        $(hash + ' .pagination').show(); // Show pagination for the selected tab content
    }

    // Add click event to tabs
    $('.tabs a').on('click', function(e) {
        e.preventDefault();
        var hash = $(this).attr('href');
        showTabContent(hash);
        window.location.hash = hash; // Optional: Update the URL hash
    });

    // Handle initial tab or hash change
    if (window.location.hash) {
        showTabContent(window.location.hash);
    } else {
        var firstTabHash = $('.tabs li:first-child a').attr('href');
        showTabContent(firstTabHash);
    }

    // Optional: Handle hash change in URL
    $(window).on('hashchange', function() {
        var hash = window.location.hash;
        showTabContent(hash);
    });
});