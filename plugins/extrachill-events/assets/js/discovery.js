/**
 * Discovery Page Scope Tabs
 *
 * Intercepts scope tab clicks and swaps the calendar content via REST API
 * without a full page reload. Updates the URL, title, H1, breadcrumbs,
 * and active tab state client-side.
 *
 * @package ExtraChillEvents
 * @since 0.8.0
 */

( function () {
	'use strict';

	/** Scope labels for title/H1 generation. */
	var SCOPE_LABELS = {
		tonight: 'Tonight',
		'this-weekend': 'This Weekend',
		'this-week': 'This Week',
	};

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		var nav = document.querySelector( '.discovery-scope-nav' );
		if ( ! nav ) {
			return;
		}

		nav.addEventListener( 'click', function ( e ) {
			var link = e.target.closest( 'a[data-scope]' );
			if ( ! link ) {
				return;
			}

			e.preventDefault();

			var scope = link.getAttribute( 'data-scope' );
			var termId = nav.getAttribute( 'data-term-id' );
			var termName = nav.getAttribute( 'data-term-name' );
			var termLink = nav.getAttribute( 'data-term-link' );
			var targetUrl = link.getAttribute( 'href' );

			// Update active tab immediately for responsive feel.
			updateActiveTab( nav, link );

			// Update URL without reload.
			window.history.pushState( { scope: scope }, '', targetUrl );

			// Update page title and H1.
			updatePageText( termName, scope );

			// Fetch calendar events for new scope.
			fetchScopedCalendar( scope, termId, termName, termLink );
		} );

		// Handle browser back/forward navigation.
		window.addEventListener( 'popstate', function ( e ) {
			if ( e.state && typeof e.state.scope === 'string' ) {
				var scope = e.state.scope;
				var termId = nav.getAttribute( 'data-term-id' );
				var termName = nav.getAttribute( 'data-term-name' );
				var termLink = nav.getAttribute( 'data-term-link' );

				// Find the matching tab link.
				var tabLink = nav.querySelector(
					'a[data-scope="' + scope + '"]'
				);
				if ( tabLink ) {
					updateActiveTab( nav, tabLink );
				}

				updatePageText( termName, scope );
				fetchScopedCalendar( scope, termId, termName, termLink );
			}
		} );

		// Set initial state for popstate support.
		var activeLink = nav.querySelector( 'li.active a[data-scope]' );
		if ( activeLink ) {
			var initialScope = activeLink.getAttribute( 'data-scope' );
			window.history.replaceState(
				{ scope: initialScope },
				'',
				window.location.href
			);
		}
	}

	/**
	 * Update active tab styling.
	 */
	function updateActiveTab( nav, activeLink ) {
		// Remove active from all tabs.
		var items = nav.querySelectorAll( 'li' );
		for ( var i = 0; i < items.length; i++ ) {
			items[ i ].classList.remove( 'active' );
			var a = items[ i ].querySelector( 'a' );
			if ( a ) {
				a.removeAttribute( 'aria-current' );
			}
		}

		// Set new active tab.
		var li = activeLink.closest( 'li' );
		if ( li ) {
			li.classList.add( 'active' );
		}
		activeLink.setAttribute( 'aria-current', 'page' );
	}

	/**
	 * Update H1 and document title for the new scope.
	 */
	function updatePageText( termName, scope ) {
		var scopeLabel = SCOPE_LABELS[ scope ] || '';
		var h1Text = scopeLabel
			? 'Live Music in ' + termName + ' ' + scopeLabel
			: 'Live Music in ' + termName;

		// Update H1.
		var h1 = document.querySelector( '.page-title' );
		if ( h1 ) {
			h1.textContent = h1Text;
		}

		// Update document title.
		var siteSuffix = ' – Extra Chill Events';
		var titleBase = scopeLabel
			? 'Live Music in ' + termName + ' ' + scopeLabel
			: 'Live Music in ' + termName + ' Tonight & This Week';
		document.title = titleBase + siteSuffix;
	}

	/**
	 * Fetch scoped calendar events via REST API and swap DOM.
	 */
	function fetchScopedCalendar( scope, termId, termName, termLink ) {
		var calendar = document.querySelector(
			'.data-machine-events-calendar'
		);
		if ( ! calendar ) {
			return;
		}

		var content = calendar.querySelector(
			'.data-machine-events-content'
		);
		if ( ! content ) {
			return;
		}

		// Show loading state.
		content.classList.add( 'loading' );

		// Update the calendar's data-scope attribute.
		if ( scope ) {
			calendar.setAttribute( 'data-scope', scope );
		} else {
			calendar.removeAttribute( 'data-scope' );
		}

		// Build REST API URL.
		var params = new URLSearchParams();
		params.set( 'archive_taxonomy', 'location' );
		params.set( 'archive_term_id', termId );
		if ( scope ) {
			params.set( 'scope', scope );
		}

		// Preserve search/filter params from current URL.
		var urlParams = new URLSearchParams( window.location.search );
		var passthroughKeys = [ 'event_search', 'date_start', 'date_end' ];
		passthroughKeys.forEach( function ( key ) {
			var val = urlParams.get( key );
			if ( val ) {
				params.set( key, val );
			}
		} );

		// Taxonomy filters.
		for ( var pair of urlParams.entries() ) {
			if ( pair[ 0 ].indexOf( 'tax_filter[' ) === 0 ) {
				params.append( pair[ 0 ], pair[ 1 ] );
			}
		}

		var apiUrl =
			'/wp-json/datamachine/v1/events/calendar?' + params.toString();

		fetch( apiUrl, {
			method: 'GET',
			headers: { 'Content-Type': 'application/json' },
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Network response was not ok' );
				}
				return response.json();
			} )
			.then( function ( data ) {
				if ( data.success ) {
					// Swap events content.
					content.innerHTML = data.html;

					// Update pagination.
					var paginationEl = calendar.querySelector(
						'.data-machine-events-pagination'
					);
					if ( data.pagination && data.pagination.html ) {
						if ( paginationEl ) {
							paginationEl.outerHTML = data.pagination.html;
						} else {
							content.insertAdjacentHTML(
								'afterend',
								data.pagination.html
							);
						}
					} else if ( paginationEl ) {
						paginationEl.remove();
					}

					// Update counter.
					var counterEl = calendar.querySelector(
						'.data-machine-events-results-counter'
					);
					if ( data.counter ) {
						if ( counterEl ) {
							counterEl.outerHTML = data.counter;
						} else {
							content.insertAdjacentHTML(
								'afterend',
								data.counter
							);
						}
					} else if ( counterEl ) {
						counterEl.remove();
					}

					// Update navigation.
					var navEl = calendar.querySelector(
						'.data-machine-events-past-navigation'
					);
					if ( data.navigation && data.navigation.html ) {
						if ( navEl ) {
							navEl.outerHTML = data.navigation.html;
						} else {
							calendar.insertAdjacentHTML(
								'beforeend',
								data.navigation.html
							);
						}
					} else if ( navEl ) {
						navEl.remove();
					}

					// Re-trigger lazy render for new content.
					triggerLazyRender( calendar );
				}
			} )
			.catch( function ( error ) {
				console.error( 'Error fetching scoped events:', error );
				content.innerHTML =
					'<div class="data-machine-events-error"><p>Error loading events. Please try again.</p></div>';
			} )
			.finally( function () {
				content.classList.remove( 'loading' );
			} );
	}

	/**
	 * Re-trigger lazy render on new content.
	 *
	 * The data-machine-events calendar uses IntersectionObserver-based lazy
	 * rendering. After swapping the DOM, we dispatch a custom event that
	 * the calendar's lazy-render module can listen for, or we simply
	 * re-observe the new elements.
	 */
	function triggerLazyRender( calendar ) {
		// The calendar's own JS re-initializes via MutationObserver or
		// we can dispatch a synthetic event for it to pick up.
		var event = new CustomEvent( 'data-machine-calendar-content-updated', {
			bubbles: true,
			detail: { calendar: calendar },
		} );
		calendar.dispatchEvent( event );
	}
} )();
