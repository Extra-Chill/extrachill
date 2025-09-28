/**
 * Archive Page Functionality
 *
 * Handles post randomization and artist filtering on archive pages.
 * Manages URL parameters and scroll position restoration.
 */
document.addEventListener('DOMContentLoaded', function() {
    var randomizeButton = document.getElementById('randomize-posts');
    if (randomizeButton) {
        randomizeButton.addEventListener('click', function() {
            var currentUrl = new URL(window.location.href);
            var searchParams = currentUrl.searchParams;

            // Store the current scroll position in a query parameter
            searchParams.set('scrollPos', window.pageYOffset);

            // Set a new or existing randomize parameter with a random value
            searchParams.set('randomize', Math.random());

            // Update the URL with the randomize parameter and scroll position
            window.location.href = currentUrl.toString();
        });
    }

    // Restore the scroll position after the page is reloaded
    var savedScrollPos = new URLSearchParams(window.location.search).get('scrollPos');
    if (savedScrollPos) {
        window.scrollTo(0, parseInt(savedScrollPos));
    }
});

/**
 * Filter posts by artist taxonomy
 * @param {string} artistSlug - Artist slug to filter by, or 'all' to show all posts
 */
function filterPostsByArtist(artistSlug) {
    var baseUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
    baseUrl = baseUrl.replace(/\/page\/\d+/, ''); // Remove pagination from the base URL

    if (artistSlug === 'all') {
        window.location.href = baseUrl;
    } else {
        window.location.href = baseUrl + '?artist=' + artistSlug;
    }
}
