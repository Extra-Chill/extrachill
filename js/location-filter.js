document.addEventListener('DOMContentLoaded', function () {
    const filterButton = document.getElementById('filter-location-btn');
    const locationFilters = document.getElementById('location-filters');
    const closeButton = document.getElementById('close-filter');
    const saveFiltersButtons = document.querySelectorAll('.save-filters-btn'); // Target all save buttons
    const body = document.body;
    const headingElement = document.querySelector('#primary h2');
    const articleContainer = document.querySelector('.site-main');
    const paginationContainer = document.querySelector('.wp-pagenavi'); // Container for pagination

    let selectedLocations = [];

    // Ensure all required elements exist
    if (!filterButton || !locationFilters || !closeButton || saveFiltersButtons.length === 0 || !headingElement || !articleContainer) {
        console.error('One or more required elements are missing:', {
            filterButton,
            locationFilters,
            closeButton,
            saveFiltersButtons,
            headingElement,
            articleContainer
        });
        return;
    }
    

    // Open the filter pop-up
    filterButton.addEventListener('click', function () {
        locationFilters.style.display = 'flex';
        locationFilters.style.opacity = '0';
        body.classList.add('no-scroll');

        setTimeout(function () {
            locationFilters.style.opacity = '1';
        }, 10);
    });

    // Close the filter pop-up when clicking the close button or the background
    closeButton.addEventListener('click', closeFilterPopup);
    locationFilters.addEventListener('click', function (event) {
        if (event.target === locationFilters) {
            closeFilterPopup();
        }
    });

    // Prevent clicks inside the pop-up content from closing it
    const locationFiltersContent = document.querySelector('.location-filters-content');
    if (locationFiltersContent) {
        locationFiltersContent.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    }

    // Function to close the filter pop-up
    function closeFilterPopup() {
        locationFilters.style.opacity = '0';
        setTimeout(function () {
            locationFilters.style.display = 'none';
            body.classList.remove('no-scroll');
        }, 300);
    }

    // Function to load filtered posts via AJAX
    function loadFilteredPosts(paged = 1) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', locationFilterData.ajax_url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            if (xhr.status === 200) {
                const response = JSON.parse(xhr.responseText);
                const postContent = response.data.posts;
                const locationNames = response.data.location_names;
                const paginationHtml = response.data.pagination;

                // Replace content in the article container with filtered results
                articleContainer.innerHTML = postContent;

                // Update the heading based on selected locations
                if (locationNames.length > 0) {
                    headingElement.textContent = `Posts from: ${locationNames.join(', ')}`;
                } else {
                    headingElement.textContent = 'Latest Posts';
                }

                // Update the pagination links
                if (paginationContainer) {
                    paginationContainer.innerHTML = paginationHtml;
                }

                // Reattach event listeners for pagination links
                attachPaginationListeners();

                // Close the filter pop-up
                closeFilterPopup();
            } else {
                console.error('AJAX request failed:', xhr.statusText);
            }
        };

        const params = new URLSearchParams({
            action: 'filter_posts_by_location',
            nonce: locationFilterData.nonce,
            locations: JSON.stringify(selectedLocations),
            paged: paged
        });

        xhr.send(params.toString());
    }

    // Handle the Save Filters button click
    saveFiltersButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            selectedLocations = [];
            const checkboxes = document.querySelectorAll('.location-filters input[type="checkbox"]:checked');

            checkboxes.forEach(function (checkbox) {
                selectedLocations.push(checkbox.value);
            });

            // Load the filtered posts (start at page 1)
            loadFilteredPosts(1);
        });
    });

    // Attach event listeners for pagination links
    function attachPaginationListeners() {
        const paginationLinks = document.querySelectorAll('.wp-pagenavi a'); // Ensure the selector matches your pagination structure
        paginationLinks.forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();

                // Extract the page number from the link using regex
                const paged = link.href.match(/\/page\/(\d+)/);
                if (paged && paged[1]) {
                    loadFilteredPosts(parseInt(paged[1]));
                } else {
                    // Handle the first page when the URL structure doesn’t have `/page/`
                    loadFilteredPosts(1);
                }
            });
        });
    }

    // Initial pagination listeners
    attachPaginationListeners();
});
