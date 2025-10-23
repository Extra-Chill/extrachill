/**
 * Archive Page Functionality
 *
 * Handles artist filtering on archive pages and scroll position restoration.
 */
document.addEventListener('DOMContentLoaded', function() {
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
