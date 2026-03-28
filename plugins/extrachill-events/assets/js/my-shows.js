/**
 * My Shows — Load More and Year Filter Interactions
 *
 * Handles "Load More" button for past shows pagination.
 * Uses wp.apiFetch for REST calls.
 *
 * @package ExtraChillEvents
 * @since 0.18.0
 */

( function () {
	'use strict';

	var loadMoreContainer = document.getElementById( 'my-shows-load-more' );
	if ( ! loadMoreContainer ) {
		return;
	}

	var button = loadMoreContainer.querySelector( 'button' );
	if ( ! button ) {
		return;
	}

	var pastList = document.getElementById( 'my-shows-past-list' );
	if ( ! pastList ) {
		return;
	}

	button.addEventListener( 'click', function () {
		var currentPage = parseInt( button.getAttribute( 'data-page' ), 10 );
		var totalPages = parseInt( button.getAttribute( 'data-pages' ), 10 );
		var userId = parseInt( button.getAttribute( 'data-user-id' ), 10 );
		var year = button.getAttribute( 'data-year' ) || '';

		var nextPage = currentPage + 1;

		if ( nextPage > totalPages ) {
			return;
		}

		button.disabled = true;
		button.textContent = 'Loading...';

		var path = '/extrachill/v1/concert-tracking/user/' + userId + '/shows?period=past&page=' + nextPage + '&per_page=20';
		if ( year ) {
			path += '&year=' + year;
		}

		wp.apiFetch( { path: path } ).then( function ( response ) {
			if ( response.shows && response.shows.length ) {
				response.shows.forEach( function ( show ) {
					var card = buildShowCard( show );
					pastList.appendChild( card );
				} );
			}

			button.setAttribute( 'data-page', nextPage );

			if ( nextPage >= totalPages ) {
				loadMoreContainer.remove();
			} else {
				button.disabled = false;
				button.textContent = 'Load More';
			}
		} ).catch( function () {
			button.disabled = false;
			button.textContent = 'Load More';
		} );
	} );

	/**
	 * Build a show card DOM element from API response data.
	 *
	 * @param {Object} show Show data object.
	 * @return {HTMLElement} Anchor element.
	 */
	function buildShowCard( show ) {
		var card = document.createElement( 'a' );
		card.className = 'my-shows-card';
		card.href = show.permalink || '#';

		// Date.
		var dateEl = document.createElement( 'span' );
		dateEl.className = 'my-shows-card__date';
		if ( show.event_date ) {
			var d = new Date( show.event_date + 'T00:00:00' );
			var months = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];
			dateEl.textContent = months[ d.getMonth() ] + ' ' + d.getDate();
		}
		card.appendChild( dateEl );

		// Details.
		var details = document.createElement( 'span' );
		details.className = 'my-shows-card__details';

		var artistEl = document.createElement( 'span' );
		artistEl.className = 'my-shows-card__artist';
		if ( show.artists && show.artists.length ) {
			artistEl.textContent = show.artists.map( function ( a ) { return a.name; } ).join( ', ' );
		} else {
			artistEl.textContent = show.title || '';
		}
		details.appendChild( artistEl );

		var venueParts = [];
		if ( show.venue && show.venue.name ) {
			venueParts.push( show.venue.name );
		}
		if ( show.city && show.city.name ) {
			venueParts.push( show.city.name );
		}
		if ( venueParts.length ) {
			var venueEl = document.createElement( 'span' );
			venueEl.className = 'my-shows-card__venue';
			venueEl.textContent = venueParts.join( ' \u00b7 ' );
			details.appendChild( venueEl );
		}

		card.appendChild( details );
		return card;
	}
} )();
