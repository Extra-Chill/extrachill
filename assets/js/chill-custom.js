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

            searchParams.set('scrollPos', window.pageYOffset);
            searchParams.set('randomize', Math.random());
            window.location.href = currentUrl.toString();
        });
    }

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
    baseUrl = baseUrl.replace(/\/page\/\d+/, '');

    if (artistSlug === 'all') {
        window.location.href = baseUrl;
    } else {
        window.location.href = baseUrl + '?artist=' + artistSlug;
    }
}
